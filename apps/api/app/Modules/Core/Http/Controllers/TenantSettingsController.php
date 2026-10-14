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
        return response()->json(self::settings($request->attributes->get('tenant_id')));
    }

    public function update(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'segregation' => ['sometimes', 'boolean'],
            'direct_order' => ['sometimes', 'boolean'],
            // Cruce de 3 vías de Compras: diferencia máxima entre lo facturado y lo recibido.
            'match_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:10'],
        ], [
            'match_tolerance.max' => 'La tolerancia del cruce va de 0 % a 10 %.',
            'match_tolerance.min' => 'La tolerancia del cruce va de 0 % a 10 %.',
        ]);

        $configuration = self::configuration($tenantId);
        $before = self::settings($tenantId);
        foreach ($data as $key => $value) {
            $configuration[$key] = $key === 'match_tolerance' ? round((float) $value, 1) : (bool) $value;
        }

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
            beforeData: $before,
            afterData: self::settings($tenantId),
        );

        return response()->json(self::settings($tenantId));
    }

    /** Ajustes vigentes; las reglas están activas mientras la empresa no las desactive. */
    public static function settings(string $tenantId): array
    {
        $configuration = self::configuration($tenantId);

        return [
            // Segregación de funciones: quien crea una cotización no la aprueba.
            'segregation' => (bool) ($configuration['segregation'] ?? true),
            // Orden directa: convertir una cotización aprobada en orden sin pasar por contrato.
            'direct_order' => (bool) ($configuration['direct_order'] ?? true),
            // Tolerancia del cruce de 3 vías, en porcentaje (2 % si la empresa no la cambió).
            'match_tolerance' => (float) ($configuration['match_tolerance'] ?? 2),
        ];
    }

    private static function configuration(string $tenantId): array
    {
        return json_decode(DB::table('tenants')->where('id', $tenantId)->value('configuration') ?: '{}', true) ?: [];
    }
}
