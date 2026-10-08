<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Envíos repetidos: un alta que llega dos veces (doble clic, reintento por mala
 * conexión) se registra una sola vez.
 *
 * Quien envía marca la operación con la cabecera `Idempotency-Key`. La primera vez
 * se procesa y se guarda la respuesta; si la misma clave vuelve a llegar con el
 * mismo contenido, se devuelve esa respuesta sin repetir la operación.
 *  - Misma clave con otro contenido: 409 IDEMPOTENCY_CONFLICT.
 *  - Misma clave mientras la primera aún se procesa: 409 IN_PROGRESS.
 * Solo se recuerdan las operaciones que terminaron bien; una que falló se puede reintentar.
 */
class Idempotency
{
    private const HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        $tenantId = $request->attributes->get('tenant_id');

        if ($key === '' || strlen($key) > 200 || ! $tenantId || ! $request->isMethod('POST')) {
            return $next($request);
        }

        $userId = $request->user()?->id;
        // La clave es de quien la envió: otra persona con la misma clave no ve su respuesta.
        $storedKey = hash('sha256', $userId.'|'.$key);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $reserved = DB::table('idempotency_keys')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'idempotency_key' => $storedKey,
            'request_hash' => $hash,
            'expires_at' => now()->addHours(self::HOURS),
        ]);

        if ($reserved === 0) {
            $previous = DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('idempotency_key', $storedKey)->first();

            // Una clave vencida deja de contar: se reutiliza para esta petición.
            if ($previous && $previous->expires_at && now()->greaterThan($previous->expires_at)) {
                DB::table('idempotency_keys')->where('id', $previous->id)->update([
                    'request_hash' => $hash, 'response_status' => null, 'response_body' => null,
                    'expires_at' => now()->addHours(self::HOURS),
                ]);
            } elseif ($previous && $previous->request_hash !== $hash) {
                return response()->json([
                    'message' => 'Esta operación ya se envió con otros datos. Vuelve a intentarlo.',
                    'code' => 'IDEMPOTENCY_CONFLICT',
                ], 409);
            } elseif ($previous && $previous->response_status === null) {
                return response()->json([
                    'message' => 'Esta operación ya se está procesando.',
                    'code' => 'IN_PROGRESS',
                ], 409);
            } elseif ($previous) {
                return response()
                    ->json(json_decode($previous->response_body, true), $previous->response_status)
                    ->header('Idempotent-Replay', 'true');
            }
        }

        $response = $next($request);

        $stored = DB::table('idempotency_keys')->where('tenant_id', $tenantId)->where('idempotency_key', $storedKey);
        if ($response instanceof JsonResponse && $response->getStatusCode() < 300) {
            $stored->update(['response_status' => $response->getStatusCode(), 'response_body' => $response->getContent()]);
        } else {
            $stored->delete();
        }

        return $response;
    }
}
