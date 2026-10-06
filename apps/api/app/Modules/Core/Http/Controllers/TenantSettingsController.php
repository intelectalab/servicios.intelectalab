<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ajustes de la empresa que viven en tenants.configuration. */
class TenantSettingsController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($this->settings($request->attributes->get('tenant_id')));
    }

    public function update(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate(['segregation' => ['required', 'boolean']]);

        $configuration = $this->configuration($tenantId);
        $before = (bool) ($configuration['segregation'] ?? true);
        $configuration['segregation'] = (bool) $data['segregation'];

        DB::table('tenants')->where('id', $tenantId)->update([
            'configuration' => json_encode($configuration),
            'updated_at' => now(),
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'tenant.settings.update',
            entityType: 'Tenant',
            entityId: $tenantId,
            beforeData: ['segregation' => $before],
            afterData: ['segregation' => $configuration['segregation']],
        );

        return response()->json($this->settings($tenantId));
    }

    private function settings(string $tenantId): array
    {
        // Segregación de funciones: activa mientras la empresa no la desactive.
        return ['segregation' => (bool) ($this->configuration($tenantId)['segregation'] ?? true)];
    }

    private function configuration(string $tenantId): array
    {
        return json_decode(DB::table('tenants')->where('id', $tenantId)->value('configuration') ?: '{}', true) ?: [];
    }
}
