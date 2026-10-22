<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Support\PortalAccess;
use App\Services\TenantAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalUser
{
    /**
     * Contexto del portal del cliente: la empresa y el cliente salen de la cuenta del portal,
     * nunca de algo que el cliente envíe. Así un cliente jamás puede pedir datos de otro
     * cliente (ni de otra empresa) cambiando un ID.
     *
     * Las rutas internas usan otro middleware (`tenant.context`), que no reconoce a las
     * cuentas del portal: una sesión del portal no abre ninguna pantalla interna.
     *
     * Qué sección abre cada rol lo comprueba después `portal.section` (EnsurePortalSection).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenantId = $user && ! $user->is_super_admin
            ? DB::selectOne('SELECT resolve_portal_tenant_for_user(?) AS tenant_id', [$user->id])?->tenant_id
            : null;

        if (! $tenantId) {
            return response()->json(['message' => 'No tienes acceso al portal del cliente.', 'code' => 'NOT_PORTAL_USER'], 403);
        }
        $tenantId = (string) $tenantId;

        if ($blocked = TenantAccess::blocked($tenantId)) {
            return response()->json([
                'message' => $blocked['message'],
                'code' => TenantAccess::BLOCKED_CODE,
                'tenant_status' => $blocked['status'],
            ], 403);
        }

        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);

        $portalUser = DB::table('portal_users')->where('user_id', $user->id)->where('status', 'ACTIVE')->first();
        $modules = PortalAccess::modules($tenantId);
        $enabled = in_array(PortalAccess::MODULE, $modules, true);

        $request->attributes->add([
            'tenant_id' => $tenantId,
            'portal_customer_id' => (string) $portalUser->customer_id,
            'portal_user' => $portalUser,
            'portal_enabled' => $enabled,
            'portal_sections' => $enabled ? PortalAccess::sections($portalUser->role, $modules) : [],
        ]);

        return $next($request);
    }
}
