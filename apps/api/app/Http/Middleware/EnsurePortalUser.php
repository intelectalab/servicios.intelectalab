<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalUser
{
    /**
     * Resuelve a qué customer_id pertenece este usuario del portal,
     * y lo deja disponible en el request. Todos los endpoints del
     * portal deben usar SIEMPRE este customer_id, nunca uno recibido
     * por parámetro o body — así un cliente jamás puede pedir datos
     * de otro cliente cambiando un ID.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $portalUser = DB::table('portal_users')
            ->where('user_id', $request->user()->id)
            ->where('status', 'ACTIVE')
            ->first();

        if (! $portalUser) {
            return response()->json(['message' => 'No tienes acceso al portal del cliente.'], 403);
        }

        $request->attributes->set('portal_customer_id', (string) $portalUser->customer_id);

        return $next($request);
    }
}