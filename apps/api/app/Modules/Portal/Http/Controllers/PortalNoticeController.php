<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Avisos del portal (Administración → Usuarios del portal → Avisos): a un cliente o a todos,
 * con vencimiento opcional. Se retiran, no se borran. En el portal se ven los tres más recientes.
 */
class PortalNoticeController extends Controller
{
    private const CUSTOMER = "(SELECT COALESCE(o.trade_name, o.legal_name, concat_ws(' ', p.given_names, p.family_name))
        FROM customers c LEFT JOIN organizations o ON o.party_id = c.party_id LEFT JOIN persons p ON p.party_id = c.party_id
        WHERE c.id = portal_notices.customer_id)";

    public function index()
    {
        return response()->json(
            DB::table('portal_notices')->leftJoin('users', 'users.id', '=', 'portal_notices.created_by')
                ->select('portal_notices.*', 'users.name as author')->selectRaw(self::CUSTOMER.' AS customer_name')
                ->orderByDesc('portal_notices.created_at')->limit(200)->get()
                ->map(fn ($row) => $this->present($row)),
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'uuid'],
            'body' => ['required', 'string', 'max:500'],
            'link' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [
            'body.required' => 'Escribe el aviso.',
            'link.regex' => 'El enlace debe empezar con https:// o con /.',
            'expires_at.after' => 'El aviso debe vencer después de hoy.',
        ]);
        if (trim($data['body']) === '') {
            throw ValidationException::withMessages(['body' => ['Escribe el aviso.']]);
        }
        if (! empty($data['customer_id']) && ! DB::table('customers')->where('id', $data['customer_id'])->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['Elige un cliente activo, o déjalo vacío para avisar a todos.']]);
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($request, $data, $id) {
            DB::table('portal_notices')->insert([
                'id' => $id, 'tenant_id' => $request->attributes->get('tenant_id'), 'customer_id' => $data['customer_id'] ?? null,
                'body' => trim($data['body']), 'link' => $data['link'] ?? null,
                // Vence al terminar el día elegido.
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'), userId: $request->user()->id, action: 'portal_notice.create',
                entityType: 'PortalNotice', entityId: $id, metadata: array_filter(['customer_id' => $data['customer_id'] ?? null, 'body' => Str::limit(trim($data['body']), 120)]),
            );
        });

        return response()->json($this->present($this->row($id)), 201);
    }

    public function withdraw(string $notice, Request $request)
    {
        abort_unless(Str::isUuid($notice), 404);
        $row = DB::table('portal_notices')->where('id', $notice)->first() ?? abort(404);
        if ($row->withdrawn_at) {
            throw ValidationException::withMessages(['notice' => ['El aviso ya fue retirado.']]);
        }

        DB::transaction(function () use ($request, $notice) {
            DB::table('portal_notices')->where('id', $notice)->update(['withdrawn_at' => now(), 'withdrawn_by' => $request->user()->id]);
            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'), userId: $request->user()->id, action: 'portal_notice.withdraw',
                entityType: 'PortalNotice', entityId: $notice,
            );
        });

        return response()->json($this->present($this->row($notice)));
    }

    private function row(string $id): object
    {
        return DB::table('portal_notices')->leftJoin('users', 'users.id', '=', 'portal_notices.created_by')
            ->select('portal_notices.*', 'users.name as author')->selectRaw(self::CUSTOMER.' AS customer_name')
            ->where('portal_notices.id', $id)->first();
    }

    private function present(object $row): array
    {
        $expired = $row->expires_at && Carbon::parse($row->expires_at)->isPast();

        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'customer_name' => $row->customer_name,
            'body' => $row->body,
            'link' => $row->link,
            'expires_at' => $row->expires_at ? Carbon::parse($row->expires_at)->toIso8601String() : null,
            'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            'author' => $row->author,
            'status' => $row->withdrawn_at ? 'WITHDRAWN' : ($expired ? 'EXPIRED' : 'ACTIVE'),
            'withdrawn_at' => $row->withdrawn_at ? Carbon::parse($row->withdrawn_at)->toIso8601String() : null,
        ];
    }
}
