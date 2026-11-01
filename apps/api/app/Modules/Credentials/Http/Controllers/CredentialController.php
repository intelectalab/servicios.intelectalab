<?php

namespace App\Modules\Credentials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Credentials\Http\Requests\StoreCredentialRequest;
use App\Modules\Credentials\Models\CredentialAccessLog;
use App\Modules\Credentials\Models\CredentialRecord;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\VaultCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Credential Vault (Control → Credential Vault): accesos de servidores, dominios, hosting, API keys…
 * El valor se guarda cifrado (con la APP_KEY) en versiones que no se borran. Ver los datos
 * generales, usar (copiar sin mostrar), revelar, rotar y revocar son permisos distintos; cada
 * acceso pide motivo y queda registrado, nunca el valor.
 */
class CredentialController extends Controller
{
    private const VERSION = '(SELECT max(v.version) FROM credential_versions v WHERE v.credential_id = credential_records.id)';

    private const ROTATED_AT = '(SELECT max(v.created_at) FROM credential_versions v WHERE v.credential_id = credential_records.id)';

    /** Lista solo datos generales, nunca el secreto. */
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');

        $query = CredentialRecord::query()
            ->select('credential_records.*')
            ->selectRaw(self::VERSION.' AS version')
            ->selectRaw(self::ROTATED_AT.' AS rotated_at')
            ->selectRaw(CredentialRecord::STATUS_SQL.' AS effective_status')
            ->when(isset(CredentialRecord::TYPES[$type]), fn ($q) => $q->where('credential_type', $type))
            ->when(isset(CredentialRecord::STATES[$status]), fn ($q) => $q->whereRaw(CredentialRecord::STATUS_SQL.' = ?', [$status]))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'ILIKE', $like)->orWhere('owner_label', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'credential' => ColumnFilters::text("concat_ws(' ', credential_records.name, credential_records.owner_label)"),
            'version' => ColumnFilters::number(self::VERSION),
            'expires' => ColumnFilters::date("(credential_records.expires_at AT TIME ZONE 'America/Lima')"),
        ]);

        ListSort::apply($query, $request, [
            'credential' => 'credential_records.name',
            'version' => self::VERSION,
            'expires' => 'credential_records.expires_at',
            'status' => ListSort::labels('('.CredentialRecord::STATUS_SQL.')', CredentialRecord::STATES),
        ], fn ($q) => $q->orderBy('credential_records.name'), 'credential_records.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $users = DB::table('users')->whereIn('id', $page->getCollection()->pluck('created_by')->filter()->unique()->all())->pluck('name', 'id');
        $page->getCollection()->transform(fn (CredentialRecord $credential) => $this->row($credential, $users[$credential->created_by] ?? null));

        return response()->json($page);
    }

    public function show(string $credential)
    {
        $record = $this->find($credential);

        return response()->json($this->row($record, DB::table('users')->where('id', $record->created_by)->value('name')));
    }

    /** Versiones y accesos (quién, qué, cuándo y por qué), sin valores. */
    public function history(string $credential)
    {
        $record = $this->find($credential);

        $versions = DB::table('credential_versions as v')->leftJoin('users as u', 'u.id', '=', 'v.created_by')
            ->where('v.credential_id', $record->id)->orderByDesc('v.version')
            ->get(['v.version', 'v.reason', 'v.created_at', 'u.name as user']);

        $accesses = DB::table('credential_access_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.credential_id', $record->id)->orderByDesc('a.created_at')->limit(200)
            ->get(['a.action', 'a.reason', 'a.success', 'a.created_at', 'u.name as user']);

        return response()->json(['versions' => $versions, 'accesses' => $accesses]);
    }

    public function store(StoreCredentialRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $credential = DB::transaction(function () use ($request, $tenantId) {
            $credential = CredentialRecord::create([
                'tenant_id' => $tenantId,
                'name' => trim($request->name),
                'credential_type' => $request->credential_type,
                'owner_label' => trim((string) $request->owner_label) ?: null,
                'status' => 'ACTIVE',
                'expires_at' => self::endOfDay($request->expires_at),
                'created_by' => $request->user()->id,
            ]);

            VaultCrypto::storeVersion($tenantId, $credential->id, $request->secret, $request->user()->id, 1, 'Alta');
            $this->logAccess($request, $credential, 'create', true, null);

            // Auditoría general: solo datos generales, nunca el secreto.
            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'credential.create',
                entityType: 'CredentialRecord',
                entityId: $credential->id,
                metadata: ['name' => $credential->name, 'credential_type' => $credential->credential_type, 'owner_label' => $credential->owner_label],
            );

            return $credential;
        });

        return response()->json($this->row($this->find($credential->id), $request->user()->name), 201);
    }

    /** Muestra el valor (la pantalla lo oculta a los 20 segundos). */
    public function reveal(string $credential, Request $request)
    {
        return $this->readSecret($credential, $request, 'reveal');
    }

    /** Entrega el valor para copiarlo al portapapeles; la pantalla no lo muestra. */
    public function use(string $credential, Request $request)
    {
        return $this->readSecret($credential, $request, 'use');
    }

    /** Nuevo valor = nueva versión; las anteriores quedan en el historial. Una revocada no se rota. */
    public function rotate(string $credential, Request $request)
    {
        $data = $request->validate([
            'secret' => ['required', 'string', 'max:10000'],
            'reason' => ['required', 'string', 'max:500'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ], [], ['secret' => 'valor nuevo', 'reason' => 'motivo', 'expires_at' => 'fecha de vencimiento']);

        $tenantId = $request->attributes->get('tenant_id');

        $record = DB::transaction(function () use ($credential, $request, $data, $tenantId) {
            $record = $this->find($credential, lock: true);
            if ($record->status === 'REVOKED') {
                throw ValidationException::withMessages(['status' => ['Una credencial revocada no se rota; crea una nueva.']]);
            }

            $version = (int) DB::table('credential_versions')->where('credential_id', $record->id)->max('version') + 1;
            VaultCrypto::storeVersion($tenantId, $record->id, $data['secret'], $request->user()->id, $version, trim($data['reason']));

            $record->update(['status' => 'ACTIVE', 'expires_at' => self::endOfDay($data['expires_at'] ?? null)]);
            $this->logAccess($request, $record, 'rotate', true, $data['reason']);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'credential.rotate',
                entityType: 'CredentialRecord',
                entityId: $record->id,
                metadata: ['version' => $version, 'reason' => trim($data['reason']), 'expires_at' => $record->expires_at?->toIso8601String()],
            );

            return $record;
        });

        return response()->json($this->row($this->find($record->id), DB::table('users')->where('id', $record->created_by)->value('name')));
    }

    public function revoke(string $credential, Request $request)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        $tenantId = $request->attributes->get('tenant_id');

        $record = DB::transaction(function () use ($credential, $request, $data, $tenantId) {
            $record = $this->find($credential, lock: true);
            if ($record->status === 'REVOKED') {
                throw ValidationException::withMessages(['status' => ['La credencial ya está revocada.']]);
            }

            $from = $record->effectiveStatus();
            $record->update(['status' => 'REVOKED']);
            $this->logAccess($request, $record, 'revoke', true, $data['reason']);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'credential.revoke',
                entityType: 'CredentialRecord',
                entityId: $record->id,
                metadata: ['from' => $from, 'to' => 'REVOKED', 'reason' => trim($data['reason'])],
            );

            return $record;
        });

        return response()->json($this->row($this->find($record->id), DB::table('users')->where('id', $record->created_by)->value('name')));
    }

    /** Revelar o usar: solo una activa (no vencida ni revocada). El intento fallido también queda. */
    private function readSecret(string $credential, Request $request, string $action)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'motivo']);
        $record = $this->find($credential);
        $status = $record->effectiveStatus();

        if ($status !== 'ACTIVE') {
            $this->logAccess($request, $record, $action, false, $data['reason']);
            throw ValidationException::withMessages(['status' => [$status === 'EXPIRED'
                ? 'La credencial está expirada: rótala para volver a usarla.'
                : 'La credencial está revocada.']]);
        }

        $secret = VaultCrypto::revealLatest($record->id);

        DB::transaction(function () use ($request, $record, $action, $data) {
            $this->logAccess($request, $record, $action, true, $data['reason']);
            // Se registra que se accedió, nunca el valor.
            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: "credential.{$action}",
                entityType: 'CredentialRecord',
                entityId: $record->id,
                metadata: ['reason' => trim($data['reason'])],
            );
        });

        return response()->json(['secret' => $secret]);
    }

    private function find(string $id, bool $lock = false): CredentialRecord
    {
        // Un id que no es uuid (o de otra empresa, que el RLS oculta) es 404.
        abort_unless(\Illuminate\Support\Str::isUuid($id), 404);

        return CredentialRecord::query()->select('credential_records.*')
            ->selectRaw(self::VERSION.' AS version')->selectRaw(self::ROTATED_AT.' AS rotated_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->findOrFail($id);
    }

    private function logAccess(Request $request, CredentialRecord $record, string $action, bool $success, ?string $reason): void
    {
        CredentialAccessLog::create([
            'tenant_id' => $record->tenant_id,
            'credential_id' => $record->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'success' => $success,
            'reason' => $reason === null ? null : trim($reason),
        ]);
    }

    /** "Expira el 15/12" = sirve todo ese día (hora de Lima). */
    private static function endOfDay(?string $date): ?Carbon
    {
        return $date ? Carbon::parse($date, 'America/Lima')->endOfDay()->utc() : null;
    }

    private function row(CredentialRecord $credential, ?string $creator): array
    {
        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'type' => $credential->credential_type,
            'owner_label' => $credential->owner_label,
            'status' => $credential->effectiveStatus(),
            'version' => (int) ($credential->version ?? 1),
            'rotated_at' => $credential->rotated_at ? Carbon::parse($credential->rotated_at)->toIso8601String() : null,
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'creator' => $creator,
            'created_at' => $credential->created_at?->toIso8601String(),
        ];
    }
}
