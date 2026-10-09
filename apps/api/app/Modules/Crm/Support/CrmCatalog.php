<?php

namespace App\Modules\Crm\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo que el CRM de una empresa necesita para empezar: su embudo de ventas con las
 * etapas del prototipo y las fuentes de sus leads. Se crean la primera vez que la
 * empresa entra al CRM. Requiere app.tenant_id (las tablas están bajo RLS).
 */
class CrmCatalog
{
    private const PIPELINE = ['VENTAS', 'Ventas'];

    /** código, nombre, probabilidad de cierre */
    private const STAGES = [
        ['PROSPECCION', 'Prospección', 10],
        ['CALIFICACION', 'Calificación', 25],
        ['PROPUESTA', 'Propuesta', 50],
        ['NEGOCIACION', 'Negociación', 75],
    ];

    /** Fuente de las oportunidades que no nacen de un lead. */
    public const EXISTING_CUSTOMER = 'CLIENTE_EXISTENTE';

    private const SOURCES = [
        'WEB' => 'Web',
        'REFERIDO' => 'Referido',
        'EVENTO' => 'Evento',
        'LINKEDIN_ADS' => 'LinkedIn Ads',
        'CAMPANA_EMAIL' => 'Campaña email',
        'PORTAL_CLIENTE' => 'Portal cliente',
        self::EXISTING_CUSTOMER => 'Cliente existente',
    ];

    /** Embudo de la empresa (el primero activo); si no tiene ninguno, se crea el del prototipo. */
    public static function pipelineId(string $tenantId): string
    {
        $pipeline = fn () => DB::table('crm_pipelines')->where('tenant_id', $tenantId)->where('active', true)->orderBy('code')->value('id');

        $id = $pipeline();
        if (! $id) {
            DB::table('crm_pipelines')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => self::PIPELINE[0], 'name' => self::PIPELINE[1],
            ]);
            $id = $pipeline();
        }

        if (! DB::table('crm_pipeline_stages')->where('pipeline_id', $id)->exists()) {
            foreach (self::STAGES as $position => [$code, $name, $probability]) {
                DB::table('crm_pipeline_stages')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'pipeline_id' => $id,
                    'code' => $code, 'name' => $name, 'position' => $position + 1, 'probability' => $probability,
                ]);
            }
        }

        return $id;
    }

    /** Etapas activas del embudo, en orden. */
    public static function stages(string $tenantId): Collection
    {
        return DB::table('crm_pipeline_stages')
            ->where('pipeline_id', self::pipelineId($tenantId))
            ->where('active', true)
            ->orderBy('position')
            ->get(['id', 'pipeline_id', 'name', 'position', 'probability'])
            ->map(function ($stage) {
                $stage->probability = (float) $stage->probability;

                return $stage;
            });
    }

    /** Fuentes activas de la empresa; si no tiene ninguna, se crean las del prototipo. */
    public static function sources(string $tenantId): Collection
    {
        if (! DB::table('crm_sources')->where('tenant_id', $tenantId)->exists()) {
            foreach (self::SOURCES as $code => $name) {
                DB::table('crm_sources')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                ]);
            }
        }

        return DB::table('crm_sources')->where('tenant_id', $tenantId)->where('active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }
}
