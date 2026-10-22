<?php

namespace App\Http\Middleware;

use App\Services\PlatformAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consola de plataforma: solo superadmin activo con una sesión que pasó la
 * verificación en dos pasos. Cada intento rechazado de una cuenta con sesión
 * queda en la auditoría de plataforma.
 */
class EnsureIsSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin || $user->status !== 'ACTIVE') {
            if ($user) {
                $this->denied($request, 'not_super_admin');
            }

            return response()->json([
                'code' => 'PLATFORM_FORBIDDEN',
                'message' => 'No autorizado. Se requiere acceso de Super Administrador.',
            ], 403);
        }

        $token = $user->currentAccessToken();
        if (! $token instanceof PersonalAccessToken || ! $token->mfa) {
            $this->denied($request, 'mfa_missing');

            return response()->json([
                'code' => 'MFA_REQUIRED',
                'message' => 'La consola de plataforma exige una sesión con verificación en dos pasos.',
            ], 403);
        }

        return $next($request);
    }

    private function denied(Request $request, string $reason): void
    {
        PlatformAuditLogger::log(
            $request->user()->id,
            'platform.access_denied',
            'User',
            $request->user()->id,
            metadata: ['reason' => $reason, 'method' => $request->method(), 'path' => '/'.ltrim($request->path(), '/')],
        );
    }
}
