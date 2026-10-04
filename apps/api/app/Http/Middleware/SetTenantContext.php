<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // Buscamos a qué tenant(s) pertenece este usuario.
        // Por ahora tomamos el primero (más adelante se puede
        // permitir elegir tenant si el usuario pertenece a varios).
        $tenantId = DB::selectOne(
            'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
            [$user->id]
        )?->tenant_id;

        if (! $tenantId) {
            // El super admin no pertenece a ningún tenant: pasa sin contexto
            // (sin app.tenant_id ni permisos de tenant) para poder usar /me y /logout.
            if ($user->is_super_admin) {
                return $next($request);
            }

            return response()->json(['message' => 'El usuario no pertenece a ningún tenant activo.'], 403);
        }

        $tenantId = (string) $tenantId;
        DB::statement("SET app.tenant_id = '{$tenantId}'");
        $request->attributes->set('tenant_id', $tenantId);

        return $next($request);
    }
}