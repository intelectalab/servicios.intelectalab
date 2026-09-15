<?php

namespace App\Modules\Credentials\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Credentials\Http\Requests\StoreCredentialRequest;
use App\Modules\Credentials\Models\CredentialAccessLog;
use App\Modules\Credentials\Models\CredentialRecord;
use App\Services\AuditLogger;
use App\Services\VaultCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CredentialController extends Controller
{
    /**
     * Lista SOLO metadatos, nunca el secreto en sí.
     */
    public function index(Request $request)
    {
        $query = CredentialRecord::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $credential)
    {
        return response()->json(CredentialRecord::findOrFail($credential));
    }

    public function store(StoreCredentialRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $credential = DB::transaction(function () use ($request, $tenantId) {
            $credential = CredentialRecord::create([
                'tenant_id' => $tenantId,
                'name' => $request->name,
                'credential_type' => $request->credential_type,
                'status' => 'ACTIVE',
                'owner_entity_type' => $request->owner_entity_type,
                'owner_entity_id' => $request->owner_entity_id,
                'expires_at' => $request->expires_at,
                'created_by' => $request->user()->id,
            ]);

            VaultCrypto::storeVersion($tenantId, $credential->id, $request->secret, $request->user()->id, 1);

            CredentialAccessLog::create([
                'tenant_id' => $tenantId,
                'credential_id' => $credential->id,
                'user_id' => $request->user()->id,
                'action' => 'create',
                'success' => true,
            ]);

            // Auditoría general: SOLO metadatos, nunca el secreto.
            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'credential.create',
                entityType: 'CredentialRecord',
                entityId: $credential->id,
                metadata: ['name' => $request->name, 'credential_type' => $request->credential_type],
            );

            return $credential;
        });

        return response()->json($credential, 201);
    }

    /**
     * Revela el secreto real. Requiere permiso específico (vault.reveal),
     * separado del permiso de solo ver metadatos.
     */
    public function reveal(string $credential, Request $request)
    {
        $credentialModel = CredentialRecord::findOrFail($credential);

        if ($credentialModel->status !== 'ACTIVE') {
            CredentialAccessLog::create([
                'tenant_id' => $request->attributes->get('tenant_id'),
                'credential_id' => $credentialModel->id,
                'user_id' => $request->user()->id,
                'action' => 'reveal',
                'success' => false,
            ]);

            throw ValidationException::withMessages([
                'status' => ["No se puede revelar una credencial en estado {$credentialModel->status}."],
            ]);
        }

        $secret = VaultCrypto::revealLatest($credentialModel->id);

        CredentialAccessLog::create([
            'tenant_id' => $request->attributes->get('tenant_id'),
            'credential_id' => $credentialModel->id,
            'user_id' => $request->user()->id,
            'action' => 'reveal',
            'success' => true,
        ]);

        // Auditoría general: se registra QUE se reveló, nunca el valor.
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'credential.reveal',
            entityType: 'CredentialRecord',
            entityId: $credentialModel->id,
        );

        return response()->json(['secret' => $secret]);
    }

    /**
     * Rota la credencial: guarda un nuevo secreto como nueva versión,
     * conservando el historial de versiones anteriores.
     */
    public function rotate(string $credential, Request $request)
    {
        $request->validate(['secret' => ['required', 'string']]);

        $tenantId = $request->attributes->get('tenant_id');
        $credentialModel = CredentialRecord::findOrFail($credential);

        $lastVersion = DB::table('credential_versions')
            ->where('credential_id', $credentialModel->id)
            ->max('version') ?? 0;

        VaultCrypto::storeVersion($tenantId, $credentialModel->id, $request->secret, $request->user()->id, $lastVersion + 1);

        $credentialModel->update(['status' => 'ACTIVE']);

        CredentialAccessLog::create([
            'tenant_id' => $tenantId,
            'credential_id' => $credentialModel->id,
            'user_id' => $request->user()->id,
            'action' => 'rotate',
            'success' => true,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'credential.rotate',
            entityType: 'CredentialRecord',
            entityId: $credentialModel->id,
            metadata: ['new_version' => $lastVersion + 1],
        );

        return response()->json($credentialModel->fresh());
    }

    public function revoke(string $credential, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $credentialModel = CredentialRecord::findOrFail($credential);

        $credentialModel->update(['status' => 'REVOKED']);

        CredentialAccessLog::create([
            'tenant_id' => $tenantId,
            'credential_id' => $credentialModel->id,
            'user_id' => $request->user()->id,
            'action' => 'revoke',
            'success' => true,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'credential.revoke',
            entityType: 'CredentialRecord',
            entityId: $credentialModel->id,
        );

        return response()->json($credentialModel);
    }
}