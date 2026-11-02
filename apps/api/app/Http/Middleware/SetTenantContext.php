<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\SecurityPolicy;
use App\Services\TenantAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // La cuenta de plataforma nunca toma contexto de un tenant, aunque tenga
        // una membresía antigua: pasa sin app.tenant_id ni permisos de tenant
        // (le alcanza para /me) y no ve datos de negocio de ninguna empresa.
        if ($user->is_super_admin) {
            return $next($request);
        }

        // Buscamos a qué tenant(s) pertenece este usuario.
        // Por ahora tomamos el primero (más adelante se puede
        // permitir elegir tenant si el usuario pertenece a varios).
        $tenantId = DB::selectOne(
            'SELECT resolve_active_tenant_for_user(?) AS tenant_id',
            [$user->id]
        )?->tenant_id;

        if (! $tenantId) {
            return response()->json(['message' => 'El usuario no pertenece a ningún tenant activo.'], 403);
        }

        $tenantId = (string) $tenantId;

        // Una empresa que no está activa tampoco puede seguir usando sesiones ya
        // abiertas; el código le indica al frontend que muestre la pantalla de bloqueo.
        if ($blocked = TenantAccess::blocked($tenantId)) {
            return response()->json([
                'message' => $blocked['message'],
                'code' => TenantAccess::BLOCKED_CODE,
                'tenant_status' => $blocked['status'],
            ], 403);
        }

        DB::statement("SET app.tenant_id = '{$tenantId}'");
        $request->attributes->set('tenant_id', $tenantId);

        // Política de la empresa: la sesión sin uso por más del tiempo elegido se cierra.
        $token = $user->currentAccessToken();
        // Solo sesiones reales (las de prueba de Sanctum no tienen identificador).
        if ($token instanceof PersonalAccessToken && is_numeric($token->getKey())) {
            $idle = SecurityPolicy::for($tenantId)['idle_minutes'];
            $last = $token->last_activity_at ?? $token->created_at;
            if ($idle > 0 && $last && Carbon::parse($last)->lt(now()->subMinutes($idle))) {
                AuditLogger::log($tenantId, $user->id, 'auth.session_idle', 'User', $user->id, metadata: ['minutes' => $idle]);
                $token->delete();

                return response()->json([
                    'message' => "Tu sesión se cerró tras {$idle} minutos sin uso. Vuelve a ingresar.",
                    'code' => 'SESSION_IDLE',
                ], 401);
            }
            // Se anota como mucho una vez por minuto.
            if (! $token->last_activity_at || Carbon::parse($token->last_activity_at)->lt(now()->subMinute())) {
                $token->forceFill(['last_activity_at' => now()])->saveQuietly();
            }
        }

        return $next($request);
    }
}