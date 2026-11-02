<?php

namespace App\Modules\Services\Support;

use App\Modules\Services\Models\Quotation;
use App\Services\AuditLogger;
use App\Services\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Autorización interna por niveles (Administración → Reglas de aprobación).
 *
 * Una cotización en borrador que supera una regla de la empresa (monto total en soles o
 * descuento de una línea en %) necesita autorización, nivel por nivel, antes de enviarse. Cada
 * nivel lo autorizan los roles que la empresa eligió. Quien creó la cotización no la autoriza y
 * una misma persona no decide dos niveles (lo garantiza también la base). Si cambian el monto o
 * los descuentos, la autorización se anula y hay que pedirla de nuevo. Sin reglas, nada cambia.
 */
class QuotationAuthorization
{
    public const METRICS = ['total' => 'Monto total (S/)', 'discount' => 'Descuento de línea (%)'];

    /** Reglas y niveles de la empresa; por defecto, sin reglas y con los niveles del prototipo. */
    public static function settings(string $tenantId): array
    {
        $configuration = json_decode(DB::table('tenants')->where('id', $tenantId)->value('configuration') ?: '{}', true) ?: [];
        $approval = $configuration['approval'] ?? [];
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->get(['id', 'code']);
        $byCode = fn (string $code) => $roles->filter(fn ($role) => strtoupper((string) $role->code) === $code)->pluck('id')->values()->all();

        $levels = $approval['levels'] ?? [
            '1' => ['name' => 'Gerencia comercial', 'role_ids' => $byCode('GERENCIA')],
            '2' => ['name' => 'Gerencia general', 'role_ids' => $byCode('ADMIN')],
        ];

        return [
            'levels' => [
                1 => ['name' => (string) ($levels['1']['name'] ?? $levels[1]['name'] ?? 'Nivel 1'), 'role_ids' => array_values($levels['1']['role_ids'] ?? $levels[1]['role_ids'] ?? [])],
                2 => ['name' => (string) ($levels['2']['name'] ?? $levels[2]['name'] ?? 'Nivel 2'), 'role_ids' => array_values($levels['2']['role_ids'] ?? $levels[2]['role_ids'] ?? [])],
            ],
            'rules' => array_values($approval['rules'] ?? []),
        ];
    }

    /** Nivel que exige la cotización según las reglas, con sus motivos y su huella. */
    public static function requirement(Quotation $quotation): array
    {
        $items = $quotation->items()->get(['service_id', 'quantity', 'unit_price', 'discount']);
        $maxDiscount = 0.0;
        foreach ($items as $item) {
            $gross = (float) $item->quantity * (float) $item->unit_price;
            $maxDiscount = max($maxDiscount, $gross > 0 ? round((float) $item->discount / $gross * 100, 2) : 0);
        }
        $totalPen = round((float) $quotation->total * ($quotation->currency === 'USD' ? (float) ($quotation->exchange_rate ?: 0) : 1), 2);

        $level = 0;
        $reasons = [];
        foreach (self::settings($quotation->tenant_id)['rules'] as $rule) {
            $value = $rule['metric'] === 'discount' ? $maxDiscount : $totalPen;
            if ($value > (float) $rule['value']) {
                $level = max($level, (int) $rule['level']);
                $reasons[] = $rule['name'].' ('.($rule['metric'] === 'discount' ? self::number($maxDiscount).' %' : Notifier::money($totalPen)).')';
            }
        }

        $signature = hash('sha256', json_encode([
            $quotation->currency, (string) $quotation->total,
            $items->map(fn ($item) => [$item->service_id, (string) $item->quantity, (string) $item->unit_price, (string) $item->discount])->sortBy(fn ($row) => json_encode($row))->values()->all(),
        ]));

        return ['level' => $level, 'reasons' => $reasons, 'signature' => $signature, 'total_pen' => $totalPen, 'max_discount' => $maxDiscount];
    }

    /** Lo que muestra la ficha: lo exigido, la última solicitud, sus pasos y si la persona puede decidir. */
    public static function summary(Quotation $quotation, ?string $userId): array
    {
        $required = self::requirement($quotation);
        $current = self::latest($quotation);
        $valid = $current && $current->signature === $required['signature'] && $current->status !== 'VOID';
        $steps = $current ? DB::table('quotation_authorization_steps as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')
            ->where('s.authorization_id', $current->id)->orderBy('s.level')
            ->get(['s.level', 's.decision', 's.comment', 's.created_at', 's.user_id', 'u.name as user_name'])
            ->map(fn ($step) => (array) $step)->all() : [];
        $settings = self::settings($quotation->tenant_id);
        $next = $current && $current->status === 'PENDING' ? self::nextLevel($current) : null;

        return [
            'required_level' => $required['level'],
            'reasons' => $required['reasons'],
            'status' => $required['level'] === 0 && ! $valid ? 'NOT_REQUIRED' : ($valid ? $current->status : 'MISSING'),
            'request' => $current ? [
                'id' => $current->id, 'status' => $current->status, 'required_level' => (int) $current->required_level,
                'requested_at' => $current->requested_at, 'void_reason' => $current->void_reason, 'stale' => ! $valid && $current->status !== 'VOID',
            ] : null,
            'steps' => $steps,
            'next_level' => $next ? ['level' => $next, 'name' => $settings['levels'][$next]['name'], 'roles' => self::roleNames($settings['levels'][$next]['role_ids'])] : null,
            'can_decide' => $next && $userId ? self::canDecide($quotation, $current, $next, $userId) === null : false,
            'decide_blocked' => $next && $userId ? self::canDecide($quotation, $current, $next, $userId) : null,
            'levels' => collect($settings['levels'])->map(fn ($level, $number) => ['level' => $number, 'name' => $level['name'], 'roles' => self::roleNames($level['role_ids'])])->values()->all(),
        ];
    }

    /** Antes de enviar: si las reglas la alcanzan, necesita su autorización aprobada y vigente. */
    public static function assertReady(Quotation $quotation): void
    {
        $required = self::requirement($quotation);
        if ($required['level'] === 0) {
            return;
        }
        $current = self::latest($quotation);
        if ($current && $current->status === 'APPROVED' && $current->signature === $required['signature'] && $current->required_level >= $required['level']) {
            return;
        }

        throw ValidationException::withMessages(['authorization' => [
            'Necesita autorización interna hasta el nivel '.$required['level'].' antes de enviarse: '.implode('; ', $required['reasons']).'.',
        ]]);
    }

    /** Al editar el borrador: si cambiaron montos o descuentos, la autorización deja de valer. */
    public static function refresh(Request $request, Quotation $quotation): void
    {
        $current = self::latest($quotation);
        if (! $current || ! in_array($current->status, ['PENDING', 'APPROVED'], true)) {
            return;
        }
        if ($current->signature !== self::requirement($quotation)['signature']) {
            self::void($request, $quotation, $current, 'La cotización cambió de monto o descuentos después de pedir la autorización.');
        }
    }

    public static function request(Request $request, Quotation $quotation): void
    {
        if ($quotation->status !== 'DRAFT') {
            throw ValidationException::withMessages(['authorization' => ['Solo se pide autorización para una cotización en borrador.']]);
        }
        $required = self::requirement($quotation);
        if ($required['level'] === 0) {
            throw ValidationException::withMessages(['authorization' => ['Esta cotización no necesita autorización interna.']]);
        }
        $current = self::latest($quotation);
        if ($current && in_array($current->status, ['PENDING', 'APPROVED'], true) && $current->signature === $required['signature']) {
            throw ValidationException::withMessages(['authorization' => ['La autorización ya está pedida.']]);
        }

        DB::transaction(function () use ($request, $quotation, $required, $current) {
            if ($current && in_array($current->status, ['PENDING', 'APPROVED'], true)) {
                self::void($request, $quotation, $current, 'Se pidió de nuevo con los montos actuales.');
            }
            $id = (string) Str::uuid();
            DB::table('quotation_authorizations')->insert([
                'id' => $id, 'tenant_id' => $quotation->tenant_id, 'quotation_id' => $quotation->id,
                'required_level' => $required['level'], 'reasons' => json_encode($required['reasons']), 'signature' => $required['signature'],
                'status' => 'PENDING', 'requested_by' => $request->user()->id, 'requested_at' => DB::raw('clock_timestamp()'),
            ]);
            self::audit($request, $quotation, 'quotation.authorization_request', ['note' => 'Nivel '.$required['level'].': '.implode('; ', $required['reasons'])]);
            self::notifyLevel($quotation, $id, 1);
        });
    }

    public static function decide(Request $request, Quotation $quotation, bool $approve, ?string $comment): void
    {
        $current = self::latest($quotation);
        if (! $current || $current->status !== 'PENDING') {
            throw ValidationException::withMessages(['authorization' => ['No hay una autorización pendiente.']]);
        }
        if ($current->signature !== self::requirement($quotation)['signature']) {
            self::void($request, $quotation, $current, 'La cotización cambió de monto o descuentos después de pedir la autorización.');
            throw ValidationException::withMessages(['authorization' => ['La cotización cambió: pide la autorización de nuevo.']]);
        }
        $level = self::nextLevel($current);
        $blocked = self::canDecide($quotation, $current, $level, $request->user()->id);
        if ($blocked) {
            abort(response()->json(['message' => $blocked, 'errors' => ['authorization' => [$blocked]]], 403));
        }
        $comment = trim((string) $comment);
        if (! $approve && $comment === '') {
            throw ValidationException::withMessages(['comment' => ['Indica el motivo del rechazo.']]);
        }

        DB::transaction(function () use ($request, $quotation, $current, $level, $approve, $comment) {
            DB::table('quotation_authorization_steps')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $quotation->tenant_id, 'authorization_id' => $current->id,
                'level' => $level, 'decision' => $approve ? 'APPROVED' : 'REJECTED', 'user_id' => $request->user()->id,
                'comment' => $comment ?: null, 'created_at' => now(),
            ]);
            $settings = self::settings($quotation->tenant_id);
            $label = "Nivel {$level} · {$settings['levels'][$level]['name']}";

            if (! $approve) {
                DB::table('quotation_authorizations')->where('id', $current->id)->update(['status' => 'REJECTED', 'closed_at' => now()]);
                self::audit($request, $quotation, 'quotation.authorization_reject', ['note' => "{$label}: {$comment}"]);
                Notifier::send('quotation.authorization_rejected', 'Autorización rechazada', "{$quotation->quotation_number} · {$label}: {$comment}",
                    "/quotations/{$quotation->id}", [$quotation->created_by, $current->requested_by]);

                return;
            }

            if ($level >= (int) $current->required_level) {
                DB::table('quotation_authorizations')->where('id', $current->id)->update(['status' => 'APPROVED', 'closed_at' => now()]);
                self::audit($request, $quotation, 'quotation.authorization_approve', ['note' => "{$label} · autorización completa"]);
                Notifier::send('quotation.authorized', 'Cotización autorizada', "{$quotation->quotation_number} ya puede enviarse",
                    "/quotations/{$quotation->id}", [$quotation->created_by, $current->requested_by]);
            } else {
                self::audit($request, $quotation, 'quotation.authorization_approve', ['note' => $label]);
                self::notifyLevel($quotation, $current->id, $level + 1);
            }
        });
    }

    /** null si puede decidir el nivel; si no, el motivo. */
    private static function canDecide(Quotation $quotation, object $current, int $level, string $userId): ?string
    {
        if ($quotation->created_by === $userId) {
            return 'Quien creó la cotización no la autoriza.';
        }
        if (DB::table('quotation_authorization_steps')->where('authorization_id', $current->id)->where('user_id', $userId)->exists()) {
            return 'Ya autorizaste un nivel de esta cotización: el siguiente lo decide otra persona.';
        }
        $roles = self::settings($quotation->tenant_id)['levels'][$level]['role_ids'];
        $has = $roles && DB::table('user_roles')->where('tenant_id', $quotation->tenant_id)->where('user_id', $userId)->whereIn('role_id', $roles)->exists();

        return $has ? null : 'Tu rol no autoriza el nivel '.$level.'.';
    }

    private static function nextLevel(object $current): int
    {
        $done = DB::table('quotation_authorization_steps')->where('authorization_id', $current->id)->where('decision', 'APPROVED')->max('level');

        return (int) $done + 1;
    }

    /** Aviso a quienes pueden autorizar el nivel y ven la cotización. */
    private static function notifyLevel(Quotation $quotation, string $authorizationId, int $level): void
    {
        $settings = self::settings($quotation->tenant_id);
        $roles = $settings['levels'][$level]['role_ids'];
        $users = $roles ? DB::table('user_roles')->where('tenant_id', $quotation->tenant_id)->whereIn('role_id', $roles)->pluck('user_id')->unique()->all() : [];
        $decided = DB::table('quotation_authorization_steps')->where('authorization_id', $authorizationId)->pluck('user_id')->all();
        $viewers = Notifier::withPermission('quotations.view', $quotation);
        $recipients = array_values(array_diff(array_intersect($users, $viewers), [$quotation->created_by], $decided));

        Notifier::send('quotation.authorization_requested', 'Cotización por autorizar',
            "{$quotation->quotation_number} · Nivel {$level} · {$settings['levels'][$level]['name']}", "/quotations/{$quotation->id}", $recipients);
    }

    private static function void(Request $request, Quotation $quotation, object $current, string $reason): void
    {
        DB::table('quotation_authorizations')->where('id', $current->id)->update(['status' => 'VOID', 'closed_at' => now(), 'void_reason' => $reason]);
        self::audit($request, $quotation, 'quotation.authorization_void', ['note' => $reason]);
    }

    private static function latest(Quotation $quotation): ?object
    {
        // Si dos solicitudes empatan en la hora, manda la abierta.
        return DB::table('quotation_authorizations')->where('quotation_id', $quotation->id)->orderByDesc('requested_at')
            ->orderByRaw("CASE status WHEN 'PENDING' THEN 0 WHEN 'APPROVED' THEN 1 WHEN 'REJECTED' THEN 2 ELSE 3 END")->first();
    }

    private static function roleNames(array $roleIds): array
    {
        return $roleIds ? DB::table('roles')->whereIn('id', $roleIds)->orderBy('name')->pluck('name')->all() : [];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function audit(Request $request, Quotation $quotation, string $action, array $metadata): void
    {
        AuditLogger::log(
            tenantId: $quotation->tenant_id, userId: $request->user()->id, action: $action,
            entityType: 'Quotation', entityId: $quotation->id, metadata: $metadata,
        );
    }
}
