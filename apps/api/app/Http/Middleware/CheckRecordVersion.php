<?php

namespace App\Http\Middleware;

use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\RecurringInstance;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Operations\Models\AgendaEvent;
use App\Modules\Operations\Models\CostEntry;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\TimeEntry;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Portal\Models\PortalRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\Order;
use App\Modules\Services\Models\Quotation;
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
        'portalUser' => 'portal_users',
        'lead' => 'crm_leads',
        'opportunity' => 'crm_opportunities',
        'activity' => 'crm_activities',
        'campaign' => 'crm_campaigns',
        'contractedService' => 'contracted_services',
        'workOrder' => 'work_orders',
        'checklist' => 'checklists',
        'project' => 'projects',
        'timeEntry' => 'time_entries',
        'costEntry' => 'cost_entries',
        'agendaEvent' => 'agenda_events',
        'billableEvent' => 'billable_events',
        'invoice' => 'invoices',
        'payment' => 'payments',
        'recurring' => 'recurring_service_instances',
        'bankAccount' => 'financial_accounts',
        'purchaseOrder' => 'purchase_orders',
        'supplierInvoice' => 'supplier_invoices',
        'costCenter' => 'cost_centers',
        'budget' => 'budgets',
        'account' => 'acc_accounts',
        'ticket' => 'support_tickets',
        'claim' => 'warranty_claims',
        'document' => 'documents',
    ];

    /** Tablas cuyo modelo filtra por el alcance del rol. */
    private const SCOPED = [
        'quotations' => Quotation::class,
        'contracts' => Contract::class,
        'orders' => Order::class,
        'portal_requests' => PortalRequest::class,
        'crm_leads' => CrmLead::class,
        'crm_opportunities' => CrmOpportunity::class,
        'crm_activities' => CrmActivity::class,
        'contracted_services' => ContractedService::class,
        'work_orders' => WorkOrder::class,
        'projects' => Project::class,
        'time_entries' => TimeEntry::class,
        'cost_entries' => CostEntry::class,
        'agenda_events' => AgendaEvent::class,
        'billable_events' => BillableEvent::class,
        'invoices' => Invoice::class,
        'payments' => Payment::class,
        'recurring_service_instances' => RecurringInstance::class,
        'purchase_orders' => PurchaseOrder::class,
        'supplier_invoices' => SupplierInvoice::class,
        'cost_centers' => \App\Modules\Accounting\Models\CostCenter::class,
        'support_tickets' => \App\Modules\Support\Models\SupportTicket::class,
        'warranty_claims' => \App\Modules\Support\Models\WarrantyClaim::class,
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
            if ($bumped === 0 && $expected !== null && $this->visible($table, $id)) {
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

    /**
     * ¿Existe el registro para quien hace la petición? Uno fuera de su alcance (lo propio,
     * equipo, sede) no debe delatarse con un 409: el controlador responderá 404.
     */
    private function visible(string $table, string $id): bool
    {
        $model = self::SCOPED[$table] ?? null;

        return $model ? $model::whereKey($id)->exists() : DB::table($table)->where('id', $id)->exists();
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
