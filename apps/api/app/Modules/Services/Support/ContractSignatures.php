<?php

namespace App\Modules\Services\Support;

use App\Mail\ContractSignatureCodeMail;
use App\Mail\ContractSignatureMail;
use App\Models\User;
use App\Modules\Portal\Support\PortalAccess;
use App\Modules\Portal\Support\PortalCustomer;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Firma electrónica simple de contratos (kit RN-COM-09 y RN-COM-13, prototipo).
 *
 * La empresa envía a firma un contrato en borrador: se guarda la huella SHA-256 de su contenido y el
 * firmante del cliente es un Portal Admin activo. Firman el cliente (desde el portal) y la empresa,
 * cada uno con su nombre, un código que llega a su correo y la aceptación; queda la evidencia (fecha,
 * IP, navegador y una huella de la firma). Si el contrato cambió después del envío, la firma se
 * rechaza. Una solicitud pendiente por contrato; vence a los 15 días; el cliente puede rechazarla
 * con motivo y la empresa anularla.
 *
 * El proveedor es "SIMPLE" (dentro del sistema). Un proveedor acreditado se conectaría aquí.
 */
class ContractSignatures
{
    public const EXPIRES_DAYS = 15;

    private const CODE_MINUTES = 10;

    private const CODE_ATTEMPTS = 5;

    public const STATES = ['PENDING' => 'Pendiente', 'SIGNED' => 'Firmada', 'DECLINED' => 'Rechazada', 'EXPIRED' => 'Vencida', 'CANCELLED' => 'Anulada'];

    /** Huella del contenido del contrato: lo que el cliente lee y firma. */
    public static function hash(string $contractId): string
    {
        $contract = DB::table('contracts')->where('id', $contractId)
            ->first(['contract_number', 'customer_id', 'currency', 'start_date', 'end_date', 'sla_level', 'terms', 'total_value']);
        $items = DB::table('contract_items')->where('contract_id', $contractId)->orderBy('created_at')->orderBy('id')
            ->get(['description', 'quantity', 'unit_price', 'line_total'])
            ->map(fn ($item) => [$item->description, self::number($item->quantity), self::number($item->unit_price), self::number($item->line_total)]);

        return hash('sha256', json_encode([
            'contract' => $contract->contract_number,
            'customer' => $contract->customer_id,
            'currency' => $contract->currency,
            'start' => substr((string) $contract->start_date, 0, 10),
            'end' => substr((string) $contract->end_date, 0, 10),
            'sla' => $contract->sla_level,
            'terms' => (string) $contract->terms,
            'total' => self::number($contract->total_value),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Vencida a los 15 días: se marca al consultarla (no hay tarea programada). */
    public static function expireStale(string $contractId): void
    {
        $stale = DB::table('contract_signature_requests')->where('contract_id', $contractId)->where('status', 'PENDING')
            ->where('expires_at', '<', now())->first(['id', 'tenant_id']);
        if (! $stale) {
            return;
        }
        DB::transaction(function () use ($stale, $contractId) {
            DB::table('contract_signature_requests')->where('id', $stale->id)->update(['status' => 'EXPIRED']);
            DB::table('contracts')->where('id', $contractId)->update(['signature_status' => 'NONE']);
            AuditLogger::log(tenantId: $stale->tenant_id, userId: null, action: 'contract.signature.expired', entityType: 'Contract', entityId: $contractId,
                metadata: ['note' => 'Venció la solicitud de firma sin completarse']);
        });
    }

    /** Portal Admin activos del cliente: quienes pueden firmar por él. */
    public static function signers(string $customerId)
    {
        return DB::table('portal_users')->join('users', 'users.id', '=', 'portal_users.user_id')
            ->where('portal_users.customer_id', $customerId)->where('portal_users.role', 'PORTAL_ADMIN')->where('portal_users.status', 'ACTIVE')
            ->orderBy('users.name')->get(['portal_users.id', 'users.name', 'users.email']);
    }

    /** Enviar a firma (contrato en borrador; firmante: un Portal Admin activo del cliente). */
    public static function send(string $contractId, string $byUserId, ?string $portalUserId): string
    {
        $contract = DB::table('contracts')->where('id', $contractId)->first(['id', 'tenant_id', 'customer_id', 'contract_number', 'status']);
        self::expireStale($contractId);

        if ($contract->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => ['Solo se envía a firma un contrato en borrador.']]);
        }
        if (! in_array(PortalAccess::MODULE, PortalAccess::modules($contract->tenant_id), true)) {
            throw ValidationException::withMessages(['status' => ['El cliente firma desde el portal: activa el módulo Portal de clientes.']]);
        }
        if (DB::table('contract_signature_requests')->where('contract_id', $contractId)->whereIn('status', ['PENDING', 'SIGNED'])->exists()) {
            throw ValidationException::withMessages(['status' => ['El contrato ya tiene una solicitud de firma vigente o firmada.']]);
        }
        $signers = self::signers($contract->customer_id);
        $signer = $portalUserId ? $signers->firstWhere('id', $portalUserId) : $signers->first();
        if (! $signer) {
            throw ValidationException::withMessages(['portal_user_id' => [$portalUserId
                ? 'El firmante debe ser un Portal Admin activo del cliente.'
                : 'El cliente no tiene un usuario Portal Admin activo que pueda firmar. Invítalo desde Usuarios del portal.']]);
        }

        $id = (string) Str::uuid();
        $hash = self::hash($contractId);
        $expires = now()->addDays(self::EXPIRES_DAYS);
        DB::transaction(function () use ($id, $contract, $byUserId, $signer, $hash, $expires) {
            DB::table('contract_signature_requests')->insert([
                'id' => $id, 'tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'customer_id' => $contract->customer_id,
                'status' => 'PENDING', 'document_hash' => $hash, 'provider' => 'SIMPLE', 'expires_at' => $expires,
                // Hora exacta: dos envíos del mismo contrato no empatan al buscar el más reciente.
                'created_by' => $byUserId, 'created_at' => DB::raw('clock_timestamp()'), 'updated_at' => now(),
            ]);
            DB::table('contract_signature_signers')->insert([
                ['id' => (string) Str::uuid(), 'tenant_id' => $contract->tenant_id, 'request_id' => $id, 'side' => 'CLIENTE',
                    'portal_user_id' => $signer->id, 'name' => $signer->name, 'email' => $signer->email],
                ['id' => (string) Str::uuid(), 'tenant_id' => $contract->tenant_id, 'request_id' => $id, 'side' => 'EMPRESA',
                    'portal_user_id' => null, 'name' => null, 'email' => null],
            ]);
            DB::table('contracts')->where('id', $contract->id)->update(['signature_status' => 'PENDING']);
            AuditLogger::log(tenantId: $contract->tenant_id, userId: $byUserId, action: 'contract.signature.request', entityType: 'Contract', entityId: $contract->id,
                metadata: ['signer' => $signer->email, 'hash' => $hash, 'note' => "Enviado a firma electrónica de {$signer->name}"]);
        });

        try {
            Mail::to($signer->email)->send(new ContractSignatureMail(
                PortalCustomer::companyName($contract->tenant_id), $contract->contract_number,
                ['Contrato' => $contract->contract_number, 'Firmante' => $signer->name, 'Vence' => $expires->format('d/m/Y')],
                rtrim((string) config('app.frontend_url'), '/').'/portal/signatures',
            ));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar del contrato por firmar.', ['request_id' => $id, 'error' => $e->getMessage()]);
        }

        return $id;
    }

    /** Código de un solo uso al correo de quien va a firmar. */
    public static function sendCode(object $request, User $user, string $side): void
    {
        self::assertSignable($request, $user, $side);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put(self::codeKey($request->id, $user->id), ['hash' => Hash::make($code), 'attempts' => 0], now()->addMinutes(self::CODE_MINUTES));
        $number = DB::table('contracts')->where('id', $request->contract_id)->value('contract_number');
        Mail::to($user->email)->send(new ContractSignatureCodeMail($code, self::CODE_MINUTES, $number));
    }

    /**
     * Firma de una de las partes: nombre exacto (el cliente), código del correo y aceptación. Si el
     * contrato cambió después del envío, no se firma. Con las dos firmas, la solicitud queda firmada.
     */
    public static function sign(object $request, User $user, string $side, array $data, ?string $ip, ?string $agent): void
    {
        $signer = self::assertSignable($request, $user, $side);

        if (! ($data['accept'] ?? false)) {
            throw ValidationException::withMessages(['accept' => ['Debes aceptar los términos del contrato.']]);
        }
        $typed = trim((string) ($data['typed_name'] ?? ''));
        if ($side === 'CLIENTE' && mb_strtolower($typed) !== mb_strtolower(trim((string) $signer->name))) {
            throw ValidationException::withMessages(['typed_name' => ["Escribe tu nombre completo tal como aparece: {$signer->name}."]]);
        }
        if (self::hash($request->contract_id) !== $request->document_hash) {
            throw ValidationException::withMessages(['status' => ['El contrato cambió después de enviarse a firma. La empresa debe anular esta solicitud y enviarlo de nuevo.']]);
        }
        self::checkCode($request->id, $user->id, (string) ($data['code'] ?? ''));

        $now = now();
        DB::transaction(function () use ($request, $user, $side, $signer, $typed, $now, $ip, $agent) {
            DB::table('contract_signature_signers')->where('id', $signer->id)->update([
                'status' => 'SIGNED', 'user_id' => $user->id, 'name' => $signer->name ?? $user->name, 'email' => $signer->email ?? $user->email,
                'typed_name' => $side === 'CLIENTE' ? $typed : ($typed ?: $user->name),
                'otp_verified' => true, 'signed_at' => $now, 'ip_address' => $ip, 'user_agent' => Str::limit((string) $agent, 480, ''),
                'evidence_hash' => hash('sha256', implode('|', [$request->document_hash, $side, $user->id, $now->toIso8601String(), $ip])),
            ]);
            AuditLogger::log(tenantId: $request->tenant_id, userId: $user->id, action: $side === 'CLIENTE' ? 'contract.signature.customer' : 'contract.signature.company',
                entityType: 'Contract', entityId: $request->contract_id,
                metadata: ['signer' => $signer->name ?? $user->name, 'note' => ($side === 'CLIENTE' ? 'Firmó el cliente: ' : 'Firmó la empresa: ').($signer->name ?? $user->name)]);

            $pending = DB::table('contract_signature_signers')->where('request_id', $request->id)->where('status', '!=', 'SIGNED')->exists();
            if (! $pending) {
                DB::table('contract_signature_requests')->where('id', $request->id)->update(['status' => 'SIGNED', 'completed_at' => $now]);
                DB::table('contracts')->where('id', $request->contract_id)->update(['signature_status' => 'SIGNED']);
                AuditLogger::log(tenantId: $request->tenant_id, userId: $user->id, action: 'contract.signature.completed', entityType: 'Contract', entityId: $request->contract_id,
                    metadata: ['hash' => $request->document_hash, 'note' => 'Contrato firmado por ambas partes · huella '.substr($request->document_hash, 0, 16)]);
            }
        });
        Cache::forget(self::codeKey($request->id, $user->id));
    }

    /** El cliente rechaza la firma con motivo. */
    public static function decline(object $request, User $user, string $reason): void
    {
        $signer = self::assertSignable($request, $user, 'CLIENTE');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Indica el motivo.']]);
        }
        DB::transaction(function () use ($request, $user, $signer, $reason) {
            DB::table('contract_signature_signers')->where('id', $signer->id)->update(['status' => 'DECLINED', 'user_id' => $user->id]);
            DB::table('contract_signature_requests')->where('id', $request->id)->update([
                'status' => 'DECLINED', 'declined_reason' => trim($reason), 'declined_at' => now(),
            ]);
            DB::table('contracts')->where('id', $request->contract_id)->update(['signature_status' => 'DECLINED']);
            AuditLogger::log(tenantId: $request->tenant_id, userId: $user->id, action: 'contract.signature.declined', entityType: 'Contract', entityId: $request->contract_id,
                metadata: ['note' => 'El cliente rechazó la firma: '.trim($reason)]);
        });
    }

    /** La empresa anula una solicitud pendiente o rechazada (para editar y volver a enviar). */
    public static function cancel(object $request, string $byUserId): void
    {
        if (! in_array($request->status, ['PENDING', 'DECLINED'], true)) {
            throw ValidationException::withMessages(['status' => ['Solo se anula una solicitud pendiente o rechazada.']]);
        }
        DB::transaction(function () use ($request, $byUserId) {
            DB::table('contract_signature_requests')->where('id', $request->id)->update([
                'status' => 'CANCELLED', 'cancelled_at' => now(), 'cancelled_by' => $byUserId,
            ]);
            DB::table('contracts')->where('id', $request->contract_id)->update(['signature_status' => 'NONE']);
            AuditLogger::log(tenantId: $request->tenant_id, userId: $byUserId, action: 'contract.signature.cancel', entityType: 'Contract', entityId: $request->contract_id,
                metadata: ['note' => 'Solicitud de firma anulada']);
        });
    }

    /** Solicitudes de un contrato, la más reciente primero. */
    public static function forContract(string $contractId)
    {
        self::expireStale($contractId);

        return DB::table('contract_signature_requests')->where('contract_id', $contractId)->orderByDesc('created_at')->get();
    }

    /** Solicitud con sus firmantes. El portal no recibe IP ni navegador de la empresa. */
    public static function present(object $request, bool $forPortal = false): array
    {
        $signers = DB::table('contract_signature_signers')->where('request_id', $request->id)->orderByRaw("CASE side WHEN 'CLIENTE' THEN 0 ELSE 1 END")->get();
        $current = $request->status === 'PENDING' ? self::hash($request->contract_id) : null;

        return [
            'id' => $request->id,
            'contract_id' => $request->contract_id,
            'status' => $request->status,
            'provider' => $request->provider,
            'document_hash' => $request->document_hash,
            // La firma ya no procede: el contrato cambió después de enviarse.
            'changed' => $current !== null && $current !== $request->document_hash,
            'expires_at' => Carbon::parse($request->expires_at)->toIso8601String(),
            'created_at' => Carbon::parse($request->created_at)->toIso8601String(),
            'completed_at' => $request->completed_at ? Carbon::parse($request->completed_at)->toIso8601String() : null,
            'declined_reason' => $request->declined_reason,
            'signers' => $signers->map(fn ($signer) => [
                'side' => $signer->side,
                'portal_user_id' => $signer->portal_user_id,
                'name' => $signer->name,
                'status' => $signer->status,
                'signed_at' => $signer->signed_at ? Carbon::parse($signer->signed_at)->toIso8601String() : null,
                'otp_verified' => (bool) $signer->otp_verified,
                'ip_address' => $forPortal ? null : $signer->ip_address,
                'evidence_hash' => $signer->evidence_hash,
            ])->values(),
        ];
    }

    /** Quien firma por cada lado: el cliente es el firmante asignado; la empresa, quien tenga permiso. */
    private static function assertSignable(object $request, User $user, string $side): object
    {
        self::expireStale($request->contract_id);
        $fresh = DB::table('contract_signature_requests')->where('id', $request->id)->value('status');
        if ($fresh !== 'PENDING') {
            throw ValidationException::withMessages(['status' => [$fresh === 'EXPIRED'
                ? 'La solicitud venció. Pide a tu ejecutivo que la reenvíe.'
                : 'La solicitud de firma no está pendiente.']]);
        }
        $signer = DB::table('contract_signature_signers')->where('request_id', $request->id)->where('side', $side)->first();
        if ($signer->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => [$side === 'CLIENTE' ? 'El cliente ya firmó este contrato.' : 'La empresa ya firmó este contrato.']]);
        }
        if ($side === 'CLIENTE' && DB::table('portal_users')->where('id', $signer->portal_user_id)->value('user_id') !== $user->id) {
            throw ValidationException::withMessages(['status' => ["La firma está asignada a {$signer->name}."]]);
        }

        return $signer;
    }

    private static function checkCode(string $requestId, string $userId, string $code): void
    {
        $key = self::codeKey($requestId, $userId);
        $challenge = Cache::get($key);
        if (! $challenge) {
            throw ValidationException::withMessages(['code' => ['Pide un código y escríbelo para firmar.']]);
        }
        if (! Hash::check(trim($code), $challenge['hash'])) {
            $challenge['attempts']++;
            if ($challenge['attempts'] >= self::CODE_ATTEMPTS) {
                Cache::forget($key);
                throw ValidationException::withMessages(['code' => ['Demasiados intentos. Pide un código nuevo.']]);
            }
            Cache::put($key, $challenge, now()->addMinutes(self::CODE_MINUTES));
            throw ValidationException::withMessages(['code' => ['El código no es correcto.']]);
        }
    }

    private static function codeKey(string $requestId, string $userId): string
    {
        return "contract-sign:{$requestId}:{$userId}";
    }

    private static function number($value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
