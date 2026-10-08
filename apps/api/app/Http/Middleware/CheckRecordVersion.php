<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ediciones simultáneas: evita que dos personas se pisen el mismo registro.
 *
 * Cada registro editable lleva `lock_version`. Toda petición que lo modifica
 * (editar, cambiar de estado, convertir…) sube esa versión. Si la petición trae
 * la cabecera `If-Match` con la versión que la persona tenía a la vista y ya no
 * es la vigente, se responde 409 y no se cambia nada.
 *
 * La comprobación y la subida son una sola sentencia, así que dos peticiones a la
 * vez con la misma versión no pasan las dos. La petición completa corre en una
 * transacción: si termina en error, la versión no sube y nada queda a medias.
 */
class CheckRecordVersion
{
    /** Parámetro de la ruta => tabla con `lock_version`. */
    private const RECORDS = [
        'party' => 'parties',
        'service' => 'services',
        'quotation' => 'quotations',
        'contract' => 'contracts',
        'order' => 'orders',
        'portalRequest' => 'portal_requests',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        [$table, $id] = $this->target($request);
        if (! $table) {
            return $next($request);
        }

        $expected = $this->expectedVersion($request);

        DB::beginTransaction();
        try {
            $record = DB::table($table)->where('id', $id);
            if ($expected !== null) {
                $record->where('lock_version', $expected);
            }
            $bumped = $record->update(['lock_version' => DB::raw('lock_version + 1')]);

            // No subió: o el registro no existe para esta empresa (el controlador responde 404),
            // o existe con otra versión (alguien lo cambió antes).
            if ($bumped === 0 && $expected !== null && DB::table($table)->where('id', $id)->exists()) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Otra persona modificó este registro mientras lo tenías abierto. Actualiza la página para ver la versión vigente y vuelve a intentarlo.',
                    'code' => 'CONFLICT',
                ], 409);
            }

            $response = $next($request);

            $response->getStatusCode() < 400 ? DB::commit() : DB::rollBack();

            return $response;
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /** @return array{0: ?string, 1: ?string} tabla e id del registro que modifica la ruta */
    private function target(Request $request): array
    {
        foreach (self::RECORDS as $parameter => $table) {
            $id = $request->route($parameter);
            if (is_string($id) && Str::isUuid($id)) {
                return [$table, $id];
            }
        }

        return [null, null];
    }

    private function expectedVersion(Request $request): ?int
    {
        $header = trim((string) $request->header('If-Match', ''), " \t\"");

        return ctype_digit($header) ? (int) $header : null;
    }
}
