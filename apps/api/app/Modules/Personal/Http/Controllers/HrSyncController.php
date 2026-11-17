<?php

namespace App\Modules\Personal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Credentials\Models\CredentialAccessLog;
use App\Modules\Credentials\Models\CredentialRecord;
use App\Modules\Personal\HrSaas\HrSaasSync;
use App\Modules\Personal\Support\Personal;
use App\Services\AuditLogger;
use App\Services\UnsafeWebhookTarget;
use App\Services\VaultCrypto;
use App\Services\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Personal → Sincronización con el SaaS de RRHH (P-HRM-05, D-45). Una conexión por empresa:
 *
 * 1. Se registra la dirección del SaaS de RRHH y el token de servicio que entrega RRHH (va cifrado
 *    al Credential Vault). El ERP genera la clave de firma de los webhooks y la muestra una sola
 *    vez, junto con la dirección del webhook y el ID de la conexión, para cargarlos en RRHH.
 * 2. Activar exige la carga inicial (RN-HRM-07): se empareja por documento y lo dudoso va a la
 *    bandeja. Si RRHH no responde, la conexión no se activa.
 * 3. Desactivar no borra nada: el personal vuelve a editarse en el ERP (RN-HRM-08).
 */
class HrSyncController extends Controller
{
    public const RUN_KINDS = ['INITIAL_MATCH' => 'Carga inicial', 'WEBHOOK' => 'Cambios recibidos', 'FULL_RESYNC' => 'Relectura completa'];

    public const REASONS = ['NO_MATCH' => 'Sin pareja en el ERP', 'MULTIPLE_MATCH' => 'Más de una persona con ese documento', 'CONFLICT' => 'La persona ya está vinculada con otro registro'];

    public function status(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $connection = DB::table('hr_connections')->where('tenant_id', $tenantId)->first();

        return response()->json([
            'mode' => $connection?->status === 'ACTIVE' ? 'HR_SAAS_MASTER' : 'ERP_MASTER',
            'connection' => $connection ? [
                'id' => $connection->id, 'base_url' => $connection->base_url, 'status' => $connection->status,
                'has_token' => (bool) $connection->token_credential_id, 'has_secret' => (bool) $connection->secret_credential_id,
                'initial_match_at' => $connection->initial_match_at, 'activated_at' => $connection->activated_at,
                'deactivated_at' => $connection->deactivated_at, 'last_event_at' => $connection->last_event_at,
                'lock_version' => (int) $connection->lock_version,
            ] : null,
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/webhooks/hr-saas',
            'tenant_ruc' => HrSaasSync::tenantRuc($tenantId),
            'simulated' => config('services.hr_saas.driver') === 'fake' && ! app()->environment('production'),
            'last_run' => ($last = DB::table('hr_sync_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.started_by')->where('r.tenant_id', $tenantId)
                ->orderByDesc('r.started_at')->select('r.*', 'u.name as started_by_name')->first()) ? $this->runRow($last) : null,
            'counts' => [
                'unmatched' => DB::table('hr_sync_unmatched')->where('tenant_id', $tenantId)->where('status', 'OPEN')->count(),
                'employees_hr' => DB::table('employees')->where('tenant_id', $tenantId)->where('source', 'HR_SAAS')->count(),
                'employees_local' => DB::table('employees')->where('tenant_id', $tenantId)->where('source', 'LOCAL')->where('status', 'ACTIVE')->count(),
                'outbound_pending' => DB::table('hr_outbound_events')->where('tenant_id', $tenantId)->whereIn('status', ['PENDING', 'RETRY'])->count(),
                'outbound_dead' => DB::table('hr_outbound_events')->where('tenant_id', $tenantId)->where('status', 'DEAD')->count(),
                'inbound_failed' => DB::table('hr_inbound_events')->where('tenant_id', $tenantId)->where('status', 'FAILED')->count(),
            ],
            'max_attempts' => HrSaasSync::MAX_ATTEMPTS,
        ]);
    }

    /** Alta o cambio de la conexión (queda inactiva hasta la carga inicial). */
    public function saveConnection(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $current = DB::table('hr_connections')->where('tenant_id', $tenantId)->first();
        $data = $request->validate([
            'base_url' => ['required', 'string', 'max:300', 'starts_with:https://'],
            'token' => [$current?->token_credential_id ? 'nullable' : 'required', 'string', 'min:16', 'max:500'],
        ], [
            'base_url.required' => 'Escribe la dirección del SaaS de RRHH.',
            'base_url.starts_with' => 'La dirección debe empezar con https://',
            'token.required' => 'Pega el token de servicio que te dio el SaaS de RRHH.',
            'token.min' => 'El token parece incompleto.',
        ]);
        $baseUrl = rtrim(trim($data['base_url']), '/');
        if (! $this->simulated()) {
            try {
                Webhooks::safeTarget($baseUrl.'/api/v1/erp-sync/employees');
            } catch (UnsafeWebhookTarget $e) {
                throw ValidationException::withMessages(['base_url' => [$e->getMessage()]]);
            }
        }
        if ($current?->status === 'ACTIVE' && $current->base_url !== $baseUrl) {
            throw ValidationException::withMessages(['base_url' => ['Desactiva la conexión antes de cambiar la dirección.']]);
        }

        $secret = null;
        DB::transaction(function () use ($request, $tenantId, $current, $data, $baseUrl, &$secret) {
            $userId = $request->user()->id;
            $tokenId = $current?->token_credential_id;
            if (! empty($data['token'])) {
                $tokenId = $this->storeSecret($tenantId, $userId, $tokenId, 'Token del SaaS de RRHH', 'API_KEY', trim($data['token']), $current ? 'Cambio del token' : 'Alta de la conexión con RRHH');
            }
            $secretId = $current?->secret_credential_id;
            if (! $secretId) {
                $secret = 'whsec_'.bin2hex(random_bytes(24));
                $secretId = $this->storeSecret($tenantId, $userId, null, 'Clave de firma del SaaS de RRHH', 'WEBHOOK', $secret, 'Alta de la conexión con RRHH');
            }
            if ($current) {
                DB::table('hr_connections')->where('id', $current->id)->update([
                    'base_url' => $baseUrl, 'token_credential_id' => $tokenId, 'secret_credential_id' => $secretId, 'updated_at' => now(),
                ]);
            } else {
                DB::table('hr_connections')->insert([
                    'tenant_id' => $tenantId, 'base_url' => $baseUrl, 'status' => 'INACTIVE', 'token_credential_id' => $tokenId,
                    'secret_credential_id' => $secretId, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            AuditLogger::log(tenantId: $tenantId, userId: $userId, action: $current ? 'hr_connection.update' : 'hr_connection.create', entityType: 'HrConnection',
                afterData: ['base_url' => $baseUrl, 'token' => ! empty($data['token']) ? 'actualizado' : 'sin cambios']);
        });

        return response()->json(['status' => $this->status($request)->getData(true), 'secret' => $secret], $current ? 200 : 201);
    }

    /** Nueva clave de firma: la anterior deja de valer y la nueva se muestra una sola vez. */
    public function rotateSecret(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $connection = $this->connection($tenantId);
        $secret = 'whsec_'.bin2hex(random_bytes(24));
        DB::transaction(function () use ($request, $tenantId, $connection, $secret) {
            $id = $this->storeSecret($tenantId, $request->user()->id, $connection->secret_credential_id, 'Clave de firma del SaaS de RRHH', 'WEBHOOK', $secret, 'Rotación desde Sincronización');
            DB::table('hr_connections')->where('id', $connection->id)->update(['secret_credential_id' => $id, 'updated_at' => now()]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_connection.rotate', entityType: 'HrConnection', entityId: $connection->id);
        });

        return response()->json(['secret' => $secret]);
    }

    /** Activar = carga inicial correcta + modo RRHH maestro (RN-HRM-07). */
    public function activate(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $connection = $this->connection($tenantId);
        if ($connection->status === 'ACTIVE') {
            throw ValidationException::withMessages(['status' => ['La conexión ya está activa.']]);
        }
        $run = HrSaasSync::pull($tenantId, $connection, 'INITIAL_MATCH', $request->user()->id);
        if ($run->status !== 'COMPLETED') {
            throw ValidationException::withMessages(['status' => ['La carga inicial no terminó: '.($run->error ?: 'el SaaS de RRHH no respondió.').' La conexión sigue desactivada.']]);
        }
        DB::table('hr_connections')->where('id', $connection->id)->update([
            'status' => 'ACTIVE', 'initial_match_at' => now(), 'activated_at' => now(), 'deactivated_at' => null, 'updated_at' => now(),
        ]);
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_connection.activate', entityType: 'HrConnection', entityId: $connection->id,
            afterData: ['received' => $run->received, 'applied' => $run->applied, 'unmatched' => $run->unmatched]);

        return response()->json(['run' => $this->runRow($run), 'status' => $this->status($request)->getData(true)]);
    }

    /** Desactivar no borra: lo de RRHH vuelve a editarse en el ERP y conserva su external_id (RN-HRM-08). */
    public function deactivate(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $connection = $this->connection($tenantId);
        if ($connection->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => ['La conexión ya está desactivada.']]);
        }
        DB::transaction(function () use ($request, $tenantId, $connection) {
            DB::table('hr_connections')->where('id', $connection->id)->update(['status' => 'INACTIVE', 'deactivated_at' => now(), 'updated_at' => now()]);
            $employees = DB::table('employees')->where('tenant_id', $tenantId)->where('source', 'HR_SAAS')->update(['source' => 'LOCAL', 'updated_at' => now()]);
            DB::table('hr_positions')->where('tenant_id', $tenantId)->where('source', 'HR_SAAS')->update(['source' => 'LOCAL']);
            DB::table('hr_leave_requests')->where('tenant_id', $tenantId)->where('source', 'HR_SAAS')->update(['source' => 'LOCAL']);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_connection.deactivate', entityType: 'HrConnection', entityId: $connection->id,
                afterData: ['employees_back_to_erp' => $employees]);
        });

        return response()->json($this->status($request)->getData(true));
    }

    public function resync(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $connection = $this->connection($tenantId);
        if ($connection->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => ['Activa la conexión primero.']]);
        }
        $run = HrSaasSync::pull($tenantId, $connection, 'FULL_RESYNC', $request->user()->id);
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_connection.resync', entityType: 'HrConnection', entityId: $connection->id,
            afterData: ['status' => $run->status, 'received' => $run->received, 'applied' => $run->applied]);

        return response()->json(['run' => $this->runRow($run)]);
    }

    public function runs(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $page = DB::table('hr_sync_runs as r')->leftJoin('users as u', 'u.id', '=', 'r.started_by')->where('r.tenant_id', $tenantId)
            ->when(isset(self::RUN_KINDS[(string) $request->query('kind')]), fn ($q) => $q->where('r.kind', $request->query('kind')))
            ->select('r.*', 'u.name as started_by_name')->orderByDesc('r.started_at')
            ->paginate(min(max($request->integer('per_page', 10), 1), 100));
        $page->getCollection()->transform(fn ($run) => $this->runRow($run));

        return response()->json($page);
    }

    public function unmatched(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $status = $request->query('status', 'OPEN');
        $page = DB::table('hr_sync_unmatched as m')->leftJoin('users as u', 'u.id', '=', 'm.resolved_by')->where('m.tenant_id', $tenantId)
            ->when(in_array($status, ['OPEN', 'LINKED', 'CREATED', 'IGNORED'], true), fn ($q) => $q->where('m.status', $status))
            ->select('m.*', 'u.name as resolved_by_name')->orderByDesc('m.created_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));
        $candidates = DB::table('employees as e')->join('persons as p', 'p.party_id', '=', 'e.person_party_id')
            ->whereIn('e.person_party_id', $page->getCollection()->flatMap(fn ($m) => json_decode((string) $m->candidate_ids, true) ?: [])->unique()->all())
            ->orWhereIn('e.id', $page->getCollection()->flatMap(fn ($m) => json_decode((string) $m->candidate_ids, true) ?: [])->unique()->all())
            ->get(['e.id', 'e.person_party_id', 'e.employee_code', DB::raw("btrim(p.given_names || ' ' || p.family_name) as name")]);
        $page->getCollection()->transform(function ($m) use ($candidates) {
            $ids = json_decode((string) $m->candidate_ids, true) ?: [];
            $payload = json_decode((string) $m->payload, true) ?: [];

            return [
                'id' => $m->id, 'external_id' => $m->external_id, 'full_name' => $m->full_name,
                'document' => trim(($m->doc_type ?? '').' '.($m->doc_number ?? '')), 'reason' => $m->reason, 'reason_label' => self::REASONS[$m->reason] ?? $m->reason,
                'status' => $m->status, 'email' => $payload['email'] ?? null, 'hourly_cost' => $payload['hourly_cost'] ?? null,
                'candidates' => $candidates->filter(fn ($c) => in_array($c->person_party_id, $ids, true) || in_array($c->id, $ids, true))
                    ->map(fn ($c) => ['id' => $c->id, 'code' => $c->employee_code, 'name' => $c->name])->values(),
                'employee_id' => $m->employee_id, 'resolved_by' => $m->resolved_by_name, 'resolved_at' => $m->resolved_at, 'created_at' => $m->created_at,
            ];
        });

        return response()->json($page);
    }

    public function resolve(Request $request, string $item)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $request->validate([
            'action' => ['required', Rule::in(['link', 'create', 'ignore'])],
            'employee_id' => ['required_if:action,link', 'nullable', 'uuid'],
        ], ['action.required' => 'Elige qué hacer.', 'employee_id.required_if' => 'Elige el trabajador del ERP.']);
        $row = DB::table('hr_sync_unmatched')->where('tenant_id', $tenantId)->where('id', $item)->first()
            ?? abort(response()->json(['message' => 'Caso no encontrado.', 'code' => 'NOT_FOUND'], 404));
        if ($row->status !== 'OPEN') {
            throw ValidationException::withMessages(['action' => ['Este caso ya se resolvió.']]);
        }
        DB::transaction(function () use ($request, $tenantId, $row, $data) {
            HrSaasSync::resolve($tenantId, $row, $data['action'], $data['employee_id'] ?? null, $request->user()->id);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_unmatched.resolve', entityType: 'HrConnection', entityId: $row->id,
                afterData: ['action' => $data['action'], 'external_id' => $row->external_id, 'name' => $row->full_name]);
        });

        return response()->json(['ok' => true]);
    }

    /** Mensajes recibidos y enviados más recientes (para revisar qué pasó). */
    public function events(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $direction = $request->query('direction', 'in');
        if ($direction === 'out') {
            $page = DB::table('hr_outbound_events')->where('tenant_id', $tenantId)
                ->when(in_array($request->query('status'), ['PENDING', 'RETRY', 'SENT', 'DEAD'], true), fn ($q) => $q->where('status', $request->query('status')))
                ->orderByDesc('created_at')->select('id', 'event_id', 'event_type', 'status', 'attempts', 'response_code', 'last_error', 'sent_at', 'next_attempt_at', 'created_at')
                ->paginate(min(max($request->integer('per_page', 10), 1), 100));
        } else {
            $page = DB::table('hr_inbound_events')->where('tenant_id', $tenantId)
                ->when(in_array($request->query('status'), ['RECEIVED', 'APPLIED', 'SKIPPED', 'UNMATCHED', 'FAILED'], true), fn ($q) => $q->where('status', $request->query('status')))
                ->orderByDesc('received_at')->select('id', 'event_id', 'event_type', 'status', 'result', 'occurred_at', 'received_at', 'processed_at')
                ->paginate(min(max($request->integer('per_page', 10), 1), 100));
        }

        return response()->json($page);
    }

    /** Volver a enviar un mensaje fallido (desde la cola de fallidos). */
    public function retry(Request $request, string $event)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $row = DB::table('hr_outbound_events')->where('tenant_id', $tenantId)->where('id', $event)->first()
            ?? abort(response()->json(['message' => 'Mensaje no encontrado.', 'code' => 'NOT_FOUND'], 404));
        if ($row->status !== 'DEAD') {
            throw ValidationException::withMessages(['status' => ['Solo se reenvía un mensaje fallido.']]);
        }
        DB::table('hr_outbound_events')->where('id', $row->id)->update(['status' => 'RETRY', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null]);
        AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'hr_outbound.retry', entityType: 'HrConnection', entityId: $row->id, afterData: ['event' => $row->event_type]);
        $status = HrSaasSync::deliver($tenantId, $row->id);

        return response()->json(['status' => $status]);
    }

    // ------------------------------------------------------------------ Apoyo

    private function connection(string $tenantId): object
    {
        return DB::table('hr_connections')->where('tenant_id', $tenantId)->first()
            ?? throw ValidationException::withMessages(['base_url' => ['Primero registra la conexión con el SaaS de RRHH.']]);
    }

    private function storeSecret(string $tenantId, string $userId, ?string $credentialId, string $name, string $type, string $value, string $reason): string
    {
        if ($credentialId && ($record = CredentialRecord::find($credentialId))) {
            $version = (int) DB::table('credential_versions')->where('credential_id', $credentialId)->max('version') + 1;
            VaultCrypto::storeVersion($tenantId, $credentialId, $value, $userId, $version, $reason);
            CredentialAccessLog::create(['tenant_id' => $tenantId, 'credential_id' => $credentialId, 'user_id' => $userId, 'action' => 'rotate', 'success' => true, 'reason' => $reason]);

            return $record->id;
        }
        $record = CredentialRecord::create([
            'tenant_id' => $tenantId, 'name' => $name, 'credential_type' => $type, 'owner_label' => 'Personal', 'status' => 'ACTIVE', 'created_by' => $userId,
        ]);
        VaultCrypto::storeVersion($tenantId, $record->id, $value, $userId, 1, $reason);
        CredentialAccessLog::create(['tenant_id' => $tenantId, 'credential_id' => $record->id, 'user_id' => $userId, 'action' => 'create', 'success' => true, 'reason' => $reason]);

        return $record->id;
    }

    private function runRow(object $run): array
    {
        return [
            'id' => $run->id, 'kind' => $run->kind, 'kind_label' => self::RUN_KINDS[$run->kind] ?? $run->kind, 'status' => $run->status,
            'received' => (int) $run->received, 'applied' => (int) $run->applied, 'skipped' => (int) $run->skipped, 'unmatched' => (int) $run->unmatched,
            'error' => $run->error, 'started_by' => $run->started_by_name ?? null, 'started_at' => $run->started_at, 'finished_at' => $run->finished_at,
        ];
    }

    private function simulated(): bool
    {
        return config('services.hr_saas.driver') === 'fake' && ! app()->environment('production');
    }
}
