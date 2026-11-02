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
            'require_signature' => ['sometimes', 'boolean'],
            // Cruce de 3 vías de Compras: diferencia máxima entre lo facturado y lo recibido.
            'match_tolerance' => ['sometimes', 'numeric', 'min:0', 'max:10'],
            // Límite de crédito del cliente al aprobar o activar: solo avisar (con motivo) o bloquear.
            'credit_limit_mode' => ['sometimes', 'in:WARN,BLOCK'],
            // Días de vigencia con los que nace una cotización si no se indica otra fecha.
            'quotation_validity_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ], [
            'quotation_validity_days.min' => 'La vigencia va de 1 a 365 días.',
            'quotation_validity_days.max' => 'La vigencia va de 1 a 365 días.',
            'credit_limit_mode.in' => 'Elige "Solo avisar" o "Bloquear".',
            'match_tolerance.max' => 'La tolerancia del cruce va de 0 % a 10 %.',
            'match_tolerance.min' => 'La tolerancia del cruce va de 0 % a 10 %.',
        ]);

        $configuration = self::configuration($tenantId);
        $before = self::settings($tenantId);
        foreach ($data as $key => $value) {
            $configuration[$key] = match ($key) {
                'match_tolerance' => round((float) $value, 1),
                'credit_limit_mode' => $value,
                'quotation_validity_days' => (int) $value,
                default => (bool) $value,
            };
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
            // Firma electrónica: el contrato solo se activa firmado por el cliente y la empresa (no, si no se activa).
            'require_signature' => (bool) ($configuration['require_signature'] ?? false),
            // Tolerancia del cruce de 3 vías, en porcentaje (2 % si la empresa no la cambió).
            'match_tolerance' => (float) ($configuration['match_tolerance'] ?? 2),
            // Límite de crédito: "Solo avisar" si la empresa no eligió otra cosa.
            'credit_limit_mode' => ($configuration['credit_limit_mode'] ?? 'WARN') === 'BLOCK' ? 'BLOCK' : 'WARN',
            // Vigencia por defecto de las cotizaciones, en días (15 si la empresa no la cambió).
            'quotation_validity_days' => max(1, min(365, (int) ($configuration['quotation_validity_days'] ?? 15))),
        ];
    }

    /**
     * Identidad y región (Configuración): nombre comercial, razón social, RUC, zona horaria y moneda
     * base. Solo el nombre comercial se cambia aquí: razón social y RUC son los fiscales (los fija la
     * plataforma al dar de alta la empresa), y la hora y la contabilidad del sistema son de Perú, en soles.
     */
    public function identity(Request $request)
    {
        return response()->json(self::identityOf((string) $request->attributes->get('tenant_id')));
    }

    public function updateIdentity(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $request->validate(['trade_name' => ['required', 'string', 'max:255']], ['trade_name.required' => 'Escribe el nombre comercial.']);
        $before = self::identityOf($tenantId);
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->first(['id']);
        abort_unless($company, 404);

        // El nombre comercial es también el nombre con el que el sistema muestra a la empresa
        // (cabecera, menú, correos, consola).
        DB::transaction(function () use ($tenantId, $company, $data) {
            DB::table('companies')->where('id', $company->id)->update(['trade_name' => trim($data['trade_name']), 'updated_at' => now()]);
            DB::table('tenants')->where('id', $tenantId)->update(['name' => trim($data['trade_name']), 'updated_at' => now()]);
        });
        AuditLogger::log(
            tenantId: $tenantId, userId: $request->user()->id, action: 'tenant.identity.update', entityType: 'Tenant', entityId: $tenantId,
            beforeData: ['trade_name' => $before['trade_name']], afterData: ['trade_name' => trim($data['trade_name'])],
        );

        return response()->json(self::identityOf($tenantId));
    }

    private static function identityOf(string $tenantId): array
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['name', 'timezone', 'currency']);
        $company = DB::table('companies')->where('tenant_id', $tenantId)->orderBy('created_at')->first(['legal_name', 'trade_name', 'tax_identifier']);

        return [
            'trade_name' => $company->trade_name ?? $tenant->name,
            // Con el que se muestra la empresa; si no coincide con el nombre comercial, guardar lo iguala.
            'display_name' => $tenant->name,
            'legal_name' => $company->legal_name ?? null,
            'ruc' => $company->tax_identifier ?? null,
            'timezone' => $tenant->timezone ?: 'America/Lima',
            'currency' => $tenant->currency ?: 'PEN',
        ];
    }

    private static function configuration(string $tenantId): array
    {
        return json_decode(DB::table('tenants')->where('id', $tenantId)->value('configuration') ?: '{}', true) ?: [];
    }
}
