<?php

namespace App\Http\Middleware;

use App\Modules\Subscription\Support\SubscriptionState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suscripción vencida y fuera de la gracia: solo lectura. Se puede ver y exportar, pero no
 * crear ni modificar nada de la empresa hasta renovar (ver SubscriptionState).
 *
 * Siguen permitidos: lo propio de cada persona (contraseña, nombre, sesiones, notificaciones),
 * dejar constancia de una exportación y pedir el cambio o la renovación del plan.
 * Va después de tenant.context o portal.user, que fijan la empresa.
 */
class EnsureSubscriptionWritable
{
    public const CODE = 'SUBSCRIPTION_READ_ONLY';

    /** Escrituras que no son datos de la empresa o que sirven para salir del bloqueo. */
    private const ALLOWED = [
        'api/v1/me/*',
        'api/v1/exports',
        'api/v1/tenant/plan/request',
        'api/v1/portal/me/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (! $tenantId || $request->isMethodSafe() || $request->is(...self::ALLOWED)
            || ! SubscriptionState::isReadOnly((string) $tenantId)) {
            return $next($request);
        }

        return response()->json([
            'message' => SubscriptionState::readOnlyMessage((string) $tenantId),
            'code' => self::CODE,
        ], 403);
    }
}
