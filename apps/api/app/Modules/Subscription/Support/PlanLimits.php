<?php

namespace App\Modules\Subscription\Support;

use Illuminate\Support\Facades\DB;

/**
 * Límites del plan de la empresa: cuánto permite, cuánto usa y el aviso al acercarse.
 *
 * Al llegar al límite no se puede crear más: los usuarios activos los controla la app
 * (UserController); lo demás, la base (migración 2026_11_03_000100_plan_limits_guard), que
 * responde PLAN_LIMIT_REACHED y aquí se convierte en un mensaje claro.
 */
class PlanLimits
{
    /** Desde qué porcentaje de uso se avisa. */
    public const WARNING_PERCENT = 80;

    /** Clave => [texto, tabla, singular]. */
    public const LIMITS = [
        'users' => ['Usuarios activos', 'tenant_users', 'usuario activo'],
        'clients' => ['Clientes', 'customers', 'cliente'],
        'services' => ['Servicios del catálogo', 'services', 'servicio'],
        'work_orders' => ['Órdenes de trabajo', 'work_orders', 'orden de trabajo'],
        'projects' => ['Proyectos', 'projects', 'proyecto'],
        'documents' => ['Documentos', 'documents', 'documento'],
        'portal_users' => ['Usuarios del portal', 'portal_users', 'usuario del portal'],
        'crm_leads' => ['Leads', 'crm_leads', 'lead'],
        'crm_opportunities' => ['Oportunidades', 'crm_opportunities', 'oportunidad'],
        'branches' => ['Sedes', 'branches', 'sede'],
    ];

    /** Límites del plan vigente que se pueden medir: clave => número. */
    public static function limits(string $tenantId): array
    {
        $raw = DB::table('tenant_subscriptions')
            ->join('saas_plans', 'saas_plans.id', '=', 'tenant_subscriptions.plan_id')
            ->where('tenant_subscriptions.tenant_id', $tenantId)
            ->where('tenant_subscriptions.status', 'ACTIVE')
            ->orderByDesc('tenant_subscriptions.starts_at')
            ->value('saas_plans.limits');
        $limits = json_decode((string) $raw, true) ?: [];

        return collect(self::LIMITS)
            ->map(fn ($definition, string $key) => $limits[$key] ?? null)
            ->filter(fn ($value) => is_numeric($value) && $value > 0)
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /** Uso frente a cada límite del plan, con el porcentaje y si ya avisa o bloquea. */
    public static function usage(string $tenantId): array
    {
        return collect(self::limits($tenantId))->map(function (int $limit, string $key) use ($tenantId) {
            [$label, $table] = self::LIMITS[$key];
            $used = $key === 'users'
                ? DB::table('tenant_users')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count()
                : DB::table($table)->where('tenant_id', $tenantId)->count();
            $percent = (int) floor($used * 100 / $limit);

            return [
                'key' => $key,
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'percent' => $percent,
                'enforced' => true,
                'level' => $used >= $limit ? 'reached' : ($percent >= self::WARNING_PERCENT ? 'near' : 'ok'),
            ];
        })->values()->all();
    }

    /** Los límites al 80 % o más, para el aviso de la cabecera. */
    public static function warnings(string $tenantId): array
    {
        return array_values(array_filter(self::usage($tenantId), fn (array $row) => $row['level'] !== 'ok'));
    }

    /** Mensaje para quien intenta crear algo y el plan ya no lo permite. */
    public static function message(string $key, int $limit): string
    {
        $label = mb_strtolower(self::LIMITS[$key][0] ?? $key);

        return "Llegaste al límite de tu plan: {$limit} {$label}. Para crear más, pide un plan mayor en Administración → Módulos y plan.";
    }

    /**
     * Si un error de la base es el de límite del plan, devuelve [clave, límite]; si no, null.
     * La base lo manda como "PLAN_LIMIT_REACHED" con el detalle "clave|límite".
     */
    public static function fromDatabaseError(string $message): ?array
    {
        if (! str_contains($message, 'PLAN_LIMIT_REACHED') || ! preg_match('/DETAIL:\s+([a-z_]+)\|(\d+)/', $message, $match)) {
            return null;
        }

        return [$match[1], (int) $match[2]];
    }
}
