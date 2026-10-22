<?php

namespace App\Http\Middleware;

use App\Modules\Portal\Support\PortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sección del portal (`portal.section:quotes`): el portal debe estar activo en la empresa y el
 * rol del portal debe abrir la sección. Va después de `portal.user`, que deja el contexto.
 */
class EnsurePortalSection
{
    public function handle(Request $request, Closure $next, string $section): Response
    {
        if (! $request->attributes->get('portal_enabled')) {
            return response()->json(['message' => 'La empresa desactivó el portal. Contacta a tu ejecutivo.', 'code' => 'PORTAL_DISABLED'], 403);
        }

        if (! PortalAccess::can($request, $section)) {
            $role = $request->attributes->get('portal_user')?->role;

            return response()->json([
                'message' => 'Tu rol del portal ('.PortalAccess::roleName((string) $role).') no incluye esta sección.',
                'code' => 'PERMISSION_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
