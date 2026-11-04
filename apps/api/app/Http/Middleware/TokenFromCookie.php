<?php

namespace App\Http\Middleware;

use App\Services\SessionCookie;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La web ya no manda el token en el encabezado: viaja en la cookie HttpOnly (SessionCookie). Aquí
 * se toma de la cookie cuando la petición no trae su propio "Authorization" (las demás apps y las
 * pruebas lo siguen mandando).
 *
 * - Para escribir con la cookie se exige el encabezado X-Requested-With, que una página ajena no
 *   puede agregar sin permiso del navegador: así un formulario de otro sitio no actúa con la
 *   sesión de la persona (CSRF). La cookie además es SameSite=Lax.
 * - Si la sesión de la cookie ya no vale (401), la respuesta la borra.
 */
class TokenFromCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookie = (string) $request->cookies->get(SessionCookie::NAME, '');
        $fromCookie = $cookie !== '' && ! $request->bearerToken();

        if ($fromCookie) {
            if (! $request->isMethodSafe() && $request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
                return response()->json([
                    'message' => 'La petición no viene de la aplicación. Vuelve a cargar la página.',
                    'code' => 'CSRF_HEADER_REQUIRED',
                ], 403);
            }
            $request->headers->set('Authorization', 'Bearer '.$cookie);
        }

        $response = $next($request);

        if ($fromCookie && $response->getStatusCode() === 401) {
            $response->headers->setCookie(SessionCookie::forget());
        }

        return $response;
    }
}
