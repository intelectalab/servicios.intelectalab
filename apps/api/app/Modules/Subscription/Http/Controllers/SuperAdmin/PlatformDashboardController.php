<?php

namespace App\Modules\Subscription\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\PlatformAuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * Panel de la consola de plataforma. Lee con el usuario de base propio de la
 * consola (conexión pgsql_platform, rol erp_platform), que solo puede ejecutar
 * platform_dashboard(): totales de empresas, suscripciones, uso en conteos y
 * salud. No abre clientes, comprobantes ni documentos de las empresas.
 */
class PlatformDashboardController extends Controller
{
    public const CONNECTION = 'pgsql_platform';

    /** Tablas del panel que se pueden exportar (cada exportación queda auditada). */
    public const EXPORTS = ['trials', 'usage', 'plans', 'audit', 'tenants', 'subscriptions', 'platform_audit', 'tenant_modules', 'tenant_subscriptions', 'tenant_status'];

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['soon_days' => ['sometimes', 'integer', 'min:1', 'max:60']]);

        try {
            $row = DB::connection(self::CONNECTION)
                ->selectOne('SELECT platform_dashboard(?) AS data', [(int) ($data['soon_days'] ?? 7)]);
        } catch (QueryException|PDOException $e) {
            report($e);

            return response()->json([
                'code' => 'PLATFORM_DB_UNAVAILABLE',
                'message' => 'La consola no pudo conectarse con su usuario de base de datos.',
            ], 503);
        }

        $dashboard = json_decode($row->data, true);
        $dashboard['health']['mail'] = config('mail.default');
        $dashboard['health']['queue'] = config('queue.default');

        return response()->json($dashboard);
    }

    public function export(Request $request): JsonResponse
    {
        $data = $request->validate([
            'table' => ['required', 'in:'.implode(',', self::EXPORTS)],
            'format' => ['required', 'in:xlsx,csv'],
            'rows' => ['required', 'integer', 'min:0', 'max:100000'],
            'selection' => ['sometimes', 'boolean'],
        ]);

        PlatformAuditLogger::log(
            $request->user()->id,
            'platform.export',
            'Dashboard',
            metadata: [
                'table' => $data['table'],
                'format' => $data['format'],
                'rows' => $data['rows'],
                'selection' => (bool) ($data['selection'] ?? false),
            ],
            connection: self::CONNECTION,
        );

        return response()->json(['recorded' => true]);
    }
}
