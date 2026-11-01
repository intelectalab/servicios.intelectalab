<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exportar es un permiso aparte de ver: quien ve un listado no por eso se lo lleva entero
 * en un archivo.
 *
 * - Las páginas que la pantalla pide para armar el archivo llegan marcadas con `?export=1`
 *   y exigen el permiso `<recurso>.export`.
 * - Ningún listado entrega más de MAX_PER_PAGE filas por página, se pida lo que se pida.
 * - Cada exportación queda en auditoría (ver `record`): quién, qué listado, cuántas filas.
 */
class ListExport
{
    public const MAX_PER_PAGE = 200;

    /** Listado (ruta) => recurso cuyo permiso `.export` exige. */
    public const RESOURCES = [
        'api/v1/parties' => 'core',
        'api/v1/services' => 'services',
        'api/v1/quotations' => 'quotations',
        'api/v1/contracts' => 'contracts',
        'api/v1/orders' => 'orders',
        'api/v1/portal-requests' => 'portal',
        'api/v1/portal-users' => 'portal_users',
        'api/v1/crm/leads' => 'leads',
        'api/v1/crm/opportunities' => 'opportunities',
        'api/v1/crm/activities' => 'activities',
        'api/v1/crm/campaigns' => 'campaigns',
        'api/v1/contracted-services' => 'contracted_services',
        'api/v1/work-orders' => 'work_orders',
        'api/v1/checklists' => 'checklists',
        'api/v1/projects' => 'projects',
        'api/v1/time-entries' => 'time_entries',
        'api/v1/cost-entries' => 'cost_entries',
        'api/v1/billable-events' => 'billing',
        'api/v1/invoices' => 'billing',
        'api/v1/receivables' => 'receivables',
        'api/v1/payments' => 'payments',
        'api/v1/recurring' => 'recurring',
        'api/v1/bank-movements' => 'treasury',
        'api/v1/cash-expenses' => 'treasury',
        'api/v1/purchase-orders' => 'purchases',
        'api/v1/supplier-invoices' => 'payables',
        'api/v1/payables' => 'payables',
        'api/v1/supplier-payments' => 'payables',
        'api/v1/journal-entries' => 'accounting',
        'api/v1/ledger/accounts/{code}' => 'accounting',
        'api/v1/accounting-accounts' => 'accounting',
        'api/v1/budgets/execution' => 'budgets',
        'api/v1/tickets' => 'tickets',
        'api/v1/warranty-claims' => 'warranty',
        'api/v1/documents' => 'documents',
        'api/v1/credentials' => 'credentials',
        'api/v1/reports/{report}' => 'reports',
        'api/v1/users' => 'users',
        'api/v1/audit' => 'audit',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        if ($request->query->getInt('per_page') > self::MAX_PER_PAGE) {
            $request->query->set('per_page', self::MAX_PER_PAGE);
        }

        $resource = self::RESOURCES[$request->route()?->uri() ?? ''] ?? null;
        if ($resource && $request->boolean('export') && ! CheckPermission::granted($request, "{$resource}.export")) {
            return self::denied($resource);
        }

        return $next($request);
    }

    /** Registro de una exportación (la pantalla lo pide antes de generar el archivo). */
    public static function record(Request $request)
    {
        $data = $request->validate([
            'resource' => ['required', 'in:'.implode(',', array_unique(self::RESOURCES))],
            'format' => ['required', 'in:xlsx,csv,pdf'],
            'rows' => ['required', 'integer', 'min:0'],
            'selection' => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if (! CheckPermission::granted($request, "{$data['resource']}.export")) {
            return self::denied($data['resource']);
        }

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: "{$data['resource']}.export",
            entityType: 'Export',
            metadata: [
                'resource' => $data['resource'],
                'format' => $data['format'],
                'rows' => $data['rows'],
                'selection' => (bool) ($data['selection'] ?? false),
                'filters' => $data['filters'] ?? null,
            ],
        );

        return response()->json(['recorded' => true]);
    }

    private static function denied(string $resource)
    {
        return response()->json([
            'message' => "No tienes el permiso requerido: {$resource}.export",
            'code' => 'PERMISSION_DENIED',
        ], 403);
    }
}
