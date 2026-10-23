<?php

namespace App\Http\Middleware;

use App\Modules\Subscription\Support\TenantModules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissionCode): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permissionCode)) {
            return response()->json([
                'message' => "No tienes el permiso requerido: {$permissionCode}",
                'code' => 'PERMISSION_DENIED',
            ], 403);
        }

        // El permiso solo vale si la empresa tiene el módulo al que pertenece: el candado
        // "Plan" del menú no es la defensa, la defensa está aquí.
        $tenantId = $request->attributes->get('tenant_id');
        if ($tenantId && ! self::moduleAvailable($tenantId, $permissionCode)) {
            return response()->json([
                'message' => 'Este módulo no está incluido en el plan de tu empresa.',
                'code' => 'MODULE_DISABLED',
            ], 403);
        }

        return $next($request);
    }

    /**
     * La misma regla, para decidir dentro de una pantalla qué partes se muestran (p. ej. las
     * pestañas de la vista del cliente): el rol tiene el permiso y la empresa tiene su módulo.
     */
    public static function granted(Request $request, string $permissionCode): bool
    {
        $user = $request->user();
        $tenantId = $request->attributes->get('tenant_id');

        return $user && $tenantId
            && $user->hasPermission($permissionCode)
            && self::moduleAvailable($tenantId, $permissionCode);
    }

    /**
     * Un permiso "cotizaciones.algo" pertenece a los módulos del catálogo que declaran ese
     * prefijo. Si ninguno lo declara (usuarios, roles, ajustes…) no depende del plan.
     */
    public static function moduleAvailable(string $tenantId, string $permissionCode): bool
    {
        $prefix = strtolower(strtok($permissionCode, '.'));

        $owners = DB::table('module_catalog')
            ->where('active', true)
            ->whereJsonContains('permission_prefixes', $prefix)
            ->pluck('code')
            ->map(fn (string $code) => strtoupper($code));

        if ($owners->isEmpty()) {
            return true;
        }

        return $owners->intersect(TenantModules::enabledCodes($tenantId))->isNotEmpty();
    }
}
