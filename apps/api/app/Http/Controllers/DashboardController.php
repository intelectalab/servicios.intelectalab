<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckPermission;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Crm\Support\CrmCatalog;
use App\Modules\Finance\Http\Controllers\ReceivableController;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Models\RecurringInstance;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\Task;
use App\Modules\Operations\Models\TimeEntry;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Portal\Models\PortalRequest;
use App\Modules\Purchasing\Models\Payable;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\Quotation;
use App\Modules\Support\Models\SupportTicket;
use App\Services\AccessScope;
use App\Services\AuditCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Panel de control (P-DASH-01): qué ocurre, qué requiere atención y qué resultados hay, por persona.
 *
 * Cada bloque se calcula aquí con los permisos, el alcance y el plan de quien lo mira: los
 * modelos con alcance ya devuelven solo lo que la persona alcanza (propio, equipo, sede o toda
 * la empresa). Sin el permiso, el bloque no sale. Con el permiso pero sin el módulo en el plan,
 * sale con candado (`locked`) y sin datos.
 */
class DashboardController extends Controller
{
    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];

    private const SCOPE_LABELS = ['OWN' => 'Propios', 'TEAM' => 'Equipo', 'BRANCH' => 'Sede', 'TENANT' => 'Toda la empresa'];

    /** Estados de un comprobante que cuentan como facturado. */
    private const BILLED = ['ISSUED', 'CORRECTED', 'PAID', 'PARTIALLY_PAID', 'OVERDUE'];

    private string $tenantId;

    private string $userId;

    /** @var string[] */
    private array $codes = [];

    /** @var array<string, bool> */
    private array $modules = [];

    private float $rate = 0;

    public function show(Request $request)
    {
        $this->tenantId = (string) $request->attributes->get('tenant_id');
        $this->userId = (string) $request->user()->id;
        $this->codes = $request->user()->permissionCodes();
        $this->rate = (float) (ExchangeRate::current('USD')?->sell ?? 0);

        return response()->json([
            'hero' => $this->hero($request),
            'kpis' => array_values(array_filter($this->kpis())),
            'attention' => array_values(array_filter($this->attention(), fn ($item) => $item && $item['count'] > 0)),
            'scope' => $this->scopeNote(),
            'charts' => array_values(array_filter($this->charts())),
            'health' => $this->health(),
            'recent' => $this->recent(),
        ]);
    }

    // ------------------------------------------------------------------ Bloques

    private function hero(Request $request): array
    {
        $role = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.tenant_id', $this->tenantId)->where('user_roles.user_id', $this->userId)->value('roles.name');

        // El botón principal, como en el prototipo: lo primero que el rol puede crear.
        $cta = match (true) {
            $this->on('quotations.create') => ['label' => 'Nueva cotización', 'href' => '/quotations/new'],
            $this->on('work_orders.create') => ['label' => 'Nueva orden de trabajo', 'href' => '/work-orders'],
            $this->on('payments.create') => ['label' => 'Registrar cobro', 'href' => '/payments'],
            $this->on('tickets.create') => ['label' => 'Nuevo ticket', 'href' => '/tickets'],
            $this->isTechnician() => ['label' => 'Mis órdenes', 'href' => '/campo'],
            default => null,
        };

        return [
            'name' => (string) $request->user()->name,
            'role' => $role,
            'company' => (string) DB::table('tenants')->where('id', $this->tenantId)->value('name'),
            'date' => today()->toDateString(),
            'cta' => $cta,
        ];
    }

    private function kpis(): array
    {
        $kpis = [];

        $opportunities = $this->access('opportunities.view');
        $quotations = $this->access('quotations.view');
        if ($opportunities === 'on' || ($opportunities === 'locked' && $quotations !== 'on')) {
            if ($opportunities === 'locked') {
                $kpis[] = $this->locked('pipeline', 'Pipeline abierto', '/crm/opportunities');
            } else {
                $row = CrmOpportunity::query()->toBase()
                    ->selectRaw("count(*) FILTER (WHERE status = 'OPEN') AS open_count")
                    ->selectRaw("coalesce(sum(expected_value) FILTER (WHERE status = 'OPEN'), 0) AS open_amount")
                    ->selectRaw("coalesce(sum(expected_value * probability / 100) FILTER (WHERE status = 'OPEN'), 0) AS weighted")
                    ->selectRaw("count(*) FILTER (WHERE status = 'WON') AS won")
                    ->selectRaw("count(*) FILTER (WHERE status IN ('WON', 'LOST')) AS closed")
                    ->first();
                $kpis[] = ['key' => 'pipeline', 'label' => 'Pipeline abierto', 'value' => (float) $row->open_amount, 'format' => 'money',
                    'sub' => "{$row->open_count} oportunidades · ponderado ".self::short((float) $row->weighted), 'tone' => 'b', 'icon' => 'funnel', 'href' => '/crm/opportunities'];
                $kpis[] = ['key' => 'close_rate', 'label' => 'Tasa de cierre', 'value' => $row->closed ? (int) round($row->won / $row->closed * 100) : null, 'format' => 'percent',
                    'sub' => "{$row->won} ganadas / {$row->closed} cerradas", 'tone' => 'g', 'icon' => 'target', 'href' => null];
            }
        } elseif ($quotations) {
            if ($quotations === 'locked') {
                $kpis[] = $this->locked('quotations', 'Cotizaciones abiertas', '/quotations');
            } else {
                $pen = $this->inPen('quotations.total', 'quotations.currency', 'quotations.exchange_rate');
                $row = Quotation::query()->toBase()
                    ->selectRaw("count(*) FILTER (WHERE status IN ('DRAFT', 'SUBMITTED')) AS open_count")
                    ->selectRaw("coalesce(sum({$pen}) FILTER (WHERE status IN ('DRAFT', 'SUBMITTED')), 0) AS open_amount")
                    ->selectRaw("count(*) FILTER (WHERE status = 'APPROVED') AS approved")
                    ->selectRaw("count(*) FILTER (WHERE status IN ('APPROVED', 'REJECTED', 'EXPIRED')) AS decided")
                    ->first();
                $kpis[] = ['key' => 'quotations', 'label' => 'Cotizaciones abiertas', 'value' => (float) $row->open_amount, 'format' => 'money',
                    'sub' => "{$row->open_count} en borrador o aprobación", 'tone' => 'b', 'icon' => 'file', 'href' => '/quotations'];
                $kpis[] = ['key' => 'quotation_rate', 'label' => 'Aprobación de cotizaciones', 'value' => $row->decided ? (int) round($row->approved / $row->decided * 100) : null, 'format' => 'percent',
                    'sub' => "{$row->approved} aprobadas / {$row->decided} decididas", 'tone' => 'g', 'icon' => 'check', 'href' => null];
            }
        }

        $kpis[] = $this->block('billing.view', 'billed', 'Facturado este mes', '/invoices', fn () => [
            'value' => $this->billedByMonth(1)[0]['value'], 'format' => 'money',
            'sub' => 'Comprobantes emitidos (neto de NC)', 'tone' => 'p', 'icon' => 'receipt',
        ]);

        $kpis[] = $this->block('receivables.view', 'receivables', 'Cuentas por cobrar', '/receivables', function () {
            $summary = $this->receivables();

            return ['value' => $summary['outstanding'], 'format' => 'money', 'sub' => self::short($summary['overdue']).' vencido',
                'sub_tone' => $summary['overdue'] > 0 ? 'danger' : null, 'tone' => $summary['overdue'] > 0 ? 'r' : 'o', 'icon' => 'coins'];
        });

        $kpis[] = $this->block('work_orders.view', 'work_orders', 'Órdenes de trabajo', '/work-orders', function () {
            $row = WorkOrder::query()->toBase()
                ->selectRaw("count(*) FILTER (WHERE status IN ('ASSIGNED', 'IN_PROGRESS', 'BLOCKED')) AS running")
                ->selectRaw("count(*) FILTER (WHERE status <> 'CANCELLED' AND scheduled_start::date = CURRENT_DATE) AS today")
                ->selectRaw("count(*) FILTER (WHERE status = 'COMPLETED') AS completed")
                ->first();

            return ['value' => (int) $row->running, 'format' => 'text', 'text' => "{$row->running} en curso",
                'sub' => "{$row->today} programadas hoy · {$row->completed} completadas", 'tone' => 'c', 'icon' => 'wrench'];
        });

        $kpis[] = $this->block('tickets.view', 'sla', 'Cumplimiento SLA', '/tickets', function () {
            $row = SupportTicket::query()->toBase()
                ->selectRaw('count(*) FILTER (WHERE resolved_at IS NOT NULL AND resolution_due_at IS NOT NULL) AS closed')
                ->selectRaw('count(*) FILTER (WHERE resolved_at IS NOT NULL AND resolved_at <= resolution_due_at) AS met')
                ->selectRaw("count(*) FILTER (WHERE status IN ('".implode("','", SupportTicket::UNRESOLVED)."')) AS open")
                ->selectRaw("count(*) FILTER (WHERE priority = 'P1' AND status IN ('".implode("','", SupportTicket::UNRESOLVED)."')) AS critical")
                ->first();

            return ['value' => $row->closed ? (int) round($row->met / $row->closed * 100) : null, 'format' => 'percent',
                'sub' => "{$row->open} tickets abiertos · {$row->critical} críticos", 'tone' => 'g', 'icon' => 'life'];
        });

        $kpis[] = $this->block('projects.view', 'projects', 'Proyectos en ejecución', '/projects', function () {
            $row = Project::query()->toBase()
                ->selectRaw("count(*) FILTER (WHERE status = 'IN_PROGRESS') AS running")
                ->selectRaw("count(*) FILTER (WHERE status = 'ON_HOLD') AS on_hold")
                ->selectRaw("count(*) FILTER (WHERE status IN ('DRAFT', 'PLANNED')) AS planned")
                ->first();

            return ['value' => (int) $row->running, 'format' => 'number', 'sub' => "{$row->planned} por iniciar · {$row->on_hold} en pausa", 'tone' => 'o', 'icon' => 'kanban'];
        });

        if ($this->isTechnician() && $this->access('time_entries.view')) {
            $kpis[] = $this->block('time_entries.view', 'my_hours', 'Mis horas (7 días)', '/timesheets', function () {
                $row = TimeEntry::ignoringAccessScope()->where('user_id', $this->userId)->where('status', '<>', 'REJECTED')
                    ->where('started_at', '>', now()->subDays(7))->toBase()
                    ->selectRaw('coalesce(sum(hours), 0) AS hours')->selectRaw("count(*) FILTER (WHERE status = 'SUBMITTED') AS pending")->first();

                return ['value' => round((float) $row->hours, 1), 'format' => 'hours', 'sub' => "{$row->pending} por aprobar", 'tone' => 'c', 'icon' => 'clock'];
            });
        }

        return $kpis;
    }

    /** "Requiere tu atención": solo lo que la persona puede atender y alcanza. */
    private function attention(): array
    {
        $items = [];
        $item = fn (string $key, string $tone, string $icon, int $count, string $text, ?string $href) => compact('key', 'tone', 'icon', 'count', 'text', 'href');
        $today = today()->toDateString();

        if ($this->on('quotations.approve')) {
            $items[] = $item('quotations', 'info', 'file', Quotation::where('status', 'SUBMITTED')->where('created_by', '<>', $this->userId)->count(),
                'cotizaciones esperan tu aprobación', '/quotations');
        }
        if ($this->on('tickets.view')) {
            $open = fn () => SupportTicket::query()->whereIn('support_tickets.status', SupportTicket::UNRESOLVED);
            $sla = '('.SupportTicket::SLA_SQL.')';
            $items[] = $item('tickets_breached', 'danger', 'life', $open()->whereRaw("{$sla} = 'breached'")->count(), 'tickets con SLA vencido', '/tickets');
            $items[] = $item('tickets_risk', 'warning', 'life', $open()->whereRaw("{$sla} = 'risk'")->count(), 'tickets con SLA en riesgo', '/tickets');
            $items[] = $item('tickets_triage', 'info', 'life', SupportTicket::where('status', 'OPEN')->count(), 'tickets sin clasificar (triage)', '/tickets');
        }
        if ($this->on('receivables.view')) {
            $summary = $this->receivables();
            $items[] = $item('receivables', 'danger', 'coins', Receivable::whereIn('status', Receivable::OPEN)->whereRaw(Receivable::DAYS_OVERDUE.' > 0')->count(),
                'cuentas vencidas por '.self::money($summary['overdue']), '/receivables');
            // Clientes cuya deuda pendiente ya pasa su límite de crédito (entre los que la persona alcanza).
            $reached = Receivable::whereIn('status', Receivable::OPEN)->distinct()->pluck('customer_id');
            $overLimit = \App\Modules\Core\Models\Customer::whereIn('id', $reached)->whereNotNull('credit_limit')->get()
                ->filter(fn ($customer) => \App\Modules\Finance\Support\CreditLimit::status($customer)['exceeds'] ?? false)->count();
            $items[] = $item('credit_limit', 'warning', 'coins', $overLimit, 'clientes sobre su límite de crédito', '/receivables');
        }
        if ($this->on('billing.view')) {
            $items[] = $item('billable', 'info', 'bolt', BillableEvent::where('status', 'PENDING')->count(), 'eventos facturables por facturar', '/billable-events');
            $items[] = $item('sunat_rejected', 'danger', 'receipt', Invoice::where('status', 'ISSUED')->where('fiscal_status', 'REJECTED')->count(), 'comprobantes rechazados por SUNAT', '/invoices');
        }
        if ($this->on('work_orders.view')) {
            $items[] = $item('wo_blocked', 'warning', 'wrench', WorkOrder::where('status', 'BLOCKED')->count(), 'órdenes de trabajo bloqueadas', '/work-orders');
            $items[] = $item('wo_unassigned', 'info', 'wrench', WorkOrder::where('status', 'PLANNED')->whereNull('assigned_user_id')->count(), 'órdenes sin técnico asignado', '/work-orders');
        }
        if ($this->on('time_entries.approve')) {
            $items[] = $item('hours', 'info', 'clock', TimeEntry::where('status', 'SUBMITTED')->where('user_id', '<>', $this->userId)->count(), 'registros de horas por aprobar', '/timesheets');
        }
        if ($this->on('cost_entries.approve')) {
            $items[] = $item('costs', 'info', 'clock', \App\Modules\Operations\Models\CostEntry::where('status', 'SUBMITTED')->where('created_by', '<>', $this->userId)->count(),
                'costos de proyecto por aprobar', '/timesheets');
        }
        if ($this->on('integrations.view')) {
            $items[] = $item('dlq', 'danger', 'plug', DB::table('integration_dead_letters')->where('tenant_id', $this->tenantId)->whereNull('resolved_at')->count(),
                'eventos en la DLQ de integraciones', null);
        }
        if ($this->on('credentials.view')) {
            $items[] = $item('credentials', 'warning', 'lock', DB::table('credential_records')->where('tenant_id', $this->tenantId)
                ->where(fn ($q) => $q->where('status', 'EXPIRED')->orWhere(fn ($soon) => $soon->where('status', 'ACTIVE')->where('expires_at', '<', now()->addDays(30))))
                ->count(), 'credenciales expiradas o por expirar (30 días)', null);
        }
        if ($this->on('recurring.view')) {
            $items[] = $item('renewals', 'info', 'repeat', RecurringInstance::where('status', 'ACTIVE')->whereNotNull('end_date')
                ->where('end_date', '<', today()->addDays(RecurringInstance::RENEWAL_ALERT_DAYS)->toDateString())->count(),
                'servicios recurrentes por renovar ('.RecurringInstance::RENEWAL_ALERT_DAYS.' días)', '/recurring');
            $items[] = $item('cycles', 'warning', 'repeat', RecurringInstance::where('status', 'ACTIVE')->where('next_cycle_date', '<=', $today)->count(),
                'ciclos recurrentes vencidos por procesar', '/recurring');
        }
        if ($this->on('leads.view')) {
            $items[] = $item('leads', 'info', 'target', CrmLead::where('status', 'NEW')->count(), 'leads nuevos sin contactar', '/crm/leads');
        }
        if ($this->on('portal.view')) {
            $items[] = $item('portal', 'info', 'globe', PortalRequest::whereIn('status', ['SUBMITTED', 'IN_REVIEW'])->count(), 'solicitudes del portal por atender', '/portal-requests');
        }
        if ($this->on('purchases.approve')) {
            $items[] = $item('purchases', 'info', 'box', PurchaseOrder::where('status', 'SUBMITTED')->where('created_by', '<>', $this->userId)->count(),
                'órdenes de compra por aprobar', '/purchase-orders');
        }
        if ($this->on('payables.approve')) {
            $items[] = $item('supplier_invoices', 'info', 'receipt', SupplierInvoice::where('status', 'REGISTERED')->count(), 'comprobantes de proveedor por aprobar', '/supplier-invoices');
            $items[] = $item('payables', 'danger', 'wallet', Payable::whereIn('status', Payable::OPEN)->where('due_date', '<', $today)->count(), 'cuentas por pagar vencidas', '/payables');
        }
        if ($this->on('treasury.view')) {
            $items[] = $item('bank', 'warning', 'coins', DB::table('bank_movements')->where('tenant_id', $this->tenantId)->where('reconciled', false)->where('ref_type', '<>', 'OPENING')->count(),
                'movimientos bancarios por conciliar', '/banks');
        }
        if ($this->on('contracts.approve')) {
            $signed = fn (string $side, bool $done) => fn ($q) => $q->select(DB::raw(1))->from('contract_signature_signers as s')
                ->whereColumn('s.request_id', 'r.id')->where('s.side', $side)->where('s.status', $done ? '=' : '<>', 'SIGNED');
            $items[] = $item('signatures', 'info', 'contract', DB::table('contract_signature_requests as r')->where('r.status', 'PENDING')->where('r.expires_at', '>', now())
                ->whereIn('r.contract_id', Contract::query()->select('contracts.id'))
                ->whereExists($signed('CLIENTE', true))->whereExists($signed('EMPRESA', false))->count(),
                'contratos esperan la firma de la empresa', '/contracts');
        }
        if ($this->isTechnician()) {
            $items[] = $item('my_work_orders', 'info', 'wrench', WorkOrder::where('assigned_user_id', $this->userId)->whereIn('status', ['ASSIGNED', 'IN_PROGRESS'])->count(),
                'órdenes asignadas a ti', '/campo');
            if ($this->on('projects.view')) {
                $items[] = $item('my_tasks', 'info', 'kanban', Task::where('assigned_user_id', $this->userId)->whereIn('status', ['TODO', 'IN_PROGRESS'])
                    ->whereIn('project_id', Project::query()->select('projects.id'))->count(), 'tareas abiertas a tu cargo', '/projects');
            }
        }

        return $items;
    }

    /** "Tu alcance": cómo ve la persona los registros principales. */
    private function scopeNote(): array
    {
        $labels = ['quotations' => 'Cotizaciones', 'work_orders' => 'OT', 'tickets' => 'Tickets', 'leads' => 'Leads'];

        return collect($labels)->filter(fn ($label, $resource) => $this->on("{$resource}.view"))
            ->map(fn ($label, $resource) => ['label' => $label, 'scope' => self::SCOPE_LABELS[AccessScope::current($resource) ?? 'TENANT']])
            ->values()->all();
    }

    private function charts(): array
    {
        $charts = [];

        $charts[] = $this->block('billing.view', 'billing', 'Facturación de los últimos 6 meses', '/invoices', function () {
            $collected = null;
            if ($this->on('payments.view')) {
                $pen = $this->inPen('(payments.amount - coalesce(payments.refunded_amount, 0))', 'payments.currency', 'payments.exchange_rate');
                $collected = round((float) Payment::query()->whereIn('payments.status', Payment::LIVE)
                    ->where('paid_at', '>=', now()->subDays(180))->toBase()->selectRaw("coalesce(sum({$pen}), 0) AS v")->value('v'), 2);
            }

            return ['type' => 'columns', 'rows' => $this->billedByMonth(6), 'collected' => $collected];
        });

        $charts[] = $this->block('receivables.view', 'aging', 'Antigüedad de cuentas por cobrar', '/receivables', function () {
            $tones = ['CURRENT' => 'success', 'D30' => 'warning'];

            return ['type' => 'bars', 'format' => 'money', 'rows' => collect($this->receivables()['aging'])
                ->map(fn ($bucket) => ['label' => $bucket['label'], 'value' => $bucket['amount'], 'tone' => $tones[$bucket['key']] ?? 'danger'])->all()];
        });

        $charts[] = $this->block('opportunities.view', 'pipeline', 'Pipeline por etapa', '/crm/opportunities', function () {
            $amounts = CrmOpportunity::where('status', 'OPEN')->toBase()->groupBy('stage_id')
                ->selectRaw('stage_id, coalesce(sum(expected_value), 0) AS amount')->pluck('amount', 'stage_id');

            return ['type' => 'bars', 'format' => 'money', 'rows' => CrmCatalog::stages($this->tenantId)
                ->map(fn ($stage) => ['label' => $stage->name, 'value' => round((float) ($amounts[$stage->id] ?? 0), 2), 'tone' => 'info'])->values()->all()];
        });

        $charts[] = $this->block('tickets.view', 'tickets', 'Tickets abiertos por estado de SLA', '/tickets', function () {
            $counts = SupportTicket::query()->whereIn('support_tickets.status', SupportTicket::UNRESOLVED)->toBase()
                ->selectRaw('('.SupportTicket::SLA_SQL.') AS sla, count(*) AS total')->groupBy('sla')->pluck('total', 'sla');
            $keys = [['ok', 'A tiempo', 'success'], ['risk', 'En riesgo', 'warning'], ['breached', 'Vencido', 'danger'], ['paused', 'En pausa', 'neutral'], ['none', 'Sin triage', 'info']];

            return ['type' => 'bars', 'format' => 'number', 'rows' => array_map(fn ($key) => ['label' => $key[1], 'value' => (int) ($counts[$key[0]] ?? 0), 'tone' => $key[2]], $keys)];
        });

        return $charts;
    }

    /** "Qué está funcionando": conexiones y workflows de la empresa (pantallas aún no disponibles). */
    private function health(): ?array
    {
        if (! $this->on('integrations.view')) {
            return null;
        }

        $connections = DB::table('integration_connections')->where('tenant_id', $this->tenantId)->get();
        $rows = $connections->map(fn ($connection) => ['name' => $connection->name ?? 'Conexión', 'status' => strtoupper((string) ($connection->status ?? '')), 'detail' => null])->all();
        $workflows = DB::table('workflow_definitions')->where('tenant_id', $this->tenantId)->count();

        return [
            'available' => false,
            'rows' => [
                ...$rows,
                ...($rows ? [] : [['name' => 'Integraciones', 'status' => 'UNAVAILABLE', 'detail' => 'Aún no disponible: sin conexiones configuradas']]),
                ['name' => 'Workflows', 'status' => 'UNAVAILABLE', 'detail' => $workflows ? "{$workflows} reglas registradas · aún no disponible" : 'Aún no disponible'],
            ],
        ];
    }

    /** Actividad reciente de la auditoría (quien tiene Auditoría ve toda la empresa). */
    private function recent(): ?array
    {
        if (! $this->on('audit.view')) {
            return null;
        }

        return DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.tenant_id', $this->tenantId)
            ->orderByDesc('audit_logs.created_at')->limit(6)
            ->get(['audit_logs.id', 'audit_logs.action', 'audit_logs.entity_type', 'audit_logs.correlation_id', 'audit_logs.created_at', 'users.name'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'actor' => $row->name ?? 'Sistema',
                'action' => AuditCatalog::actionLabel($row->action),
                'entity' => AuditCatalog::entityLabel($row->entity_type) ?? $row->entity_type,
                'correlation_id' => $row->correlation_id,
                'at' => Carbon::parse($row->created_at)->toIso8601String(),
            ])->all();
    }

    // ------------------------------------------------------------------ Apoyo

    /** null = sin permiso; 'locked' = con permiso pero el módulo no está en el plan; 'on' = disponible. */
    private function access(string $permission): ?string
    {
        if (! in_array($permission, $this->codes, true)) {
            return null;
        }
        $prefix = strtok($permission, '.');

        return ($this->modules[$prefix] ??= CheckPermission::moduleAvailable($this->tenantId, $permission)) ? 'on' : 'locked';
    }

    private function on(string $permission): bool
    {
        return $this->access($permission) === 'on';
    }

    /** Un bloque del panel según el permiso: sin permiso no sale; sin módulo, con candado. */
    private function block(string $permission, string $key, string $label, ?string $href, callable $data): ?array
    {
        return match ($this->access($permission)) {
            null => null,
            'locked' => $this->locked($key, $label, $href),
            default => ['key' => $key, 'label' => $label, 'href' => $href, 'locked' => false, ...$data()],
        };
    }

    private function locked(string $key, string $label, ?string $href): array
    {
        return ['key' => $key, 'label' => $label, 'href' => $href, 'locked' => true];
    }

    /** Quien ejecuta órdenes de trabajo sin planificarlas ni asignarlas. */
    private function isTechnician(): bool
    {
        return $this->on('work_orders.manage') && ! in_array('work_orders.approve', $this->codes, true);
    }

    /** @return array<int, array{label: string, value: float}> */
    private function billedByMonth(int $months): array
    {
        $from = today()->startOfMonth()->subMonths($months - 1);
        $pen = $this->inPen('invoices.total', 'invoices.currency', 'invoices.exchange_rate');
        $sums = Invoice::query()->whereIn('invoices.status', self::BILLED)->where('issue_date', '>=', $from->toDateString())->toBase()
            ->selectRaw("to_char(issue_date, 'YYYY-MM') AS month")
            ->selectRaw("coalesce(sum(CASE WHEN invoice_type = 'CREDIT_NOTE' THEN -1 ELSE 1 END * {$pen}), 0) AS total")
            ->groupBy('month')->pluck('total', 'month');

        return collect(range(0, $months - 1))->map(function (int $i) use ($from, $sums) {
            $month = $from->copy()->addMonths($i);

            return ['label' => self::MONTHS[$month->month - 1], 'month' => $month->format('Y-m'), 'value' => round((float) ($sums[$month->format('Y-m')] ?? 0), 2)];
        })->all();
    }

    private ?array $receivables = null;

    private function receivables(): array
    {
        return $this->receivables ??= app(ReceivableController::class)->summary()->getData(true);
    }

    /** Importe en soles: lo que está en dólares, con el tipo de cambio que guarda el documento o el vigente. */
    private function inPen(string $amount, string $currency, string $rate): string
    {
        return "CASE WHEN {$currency} = 'USD' THEN {$amount} * coalesce({$rate}, {$this->rate}) ELSE {$amount} END";
    }

    private static function money(float $amount): string
    {
        return 'S/ '.number_format($amount, 2);
    }

    /** "S/ 12.5 k", como los indicadores del prototipo (y `kfmt` de la web). */
    private static function short(float $amount): string
    {
        return match (true) {
            abs($amount) >= 1e6 => 'S/ '.number_format($amount / 1e6, 1).' M',
            abs($amount) >= 1e3 => 'S/ '.number_format($amount / 1e3, 1).' k',
            default => 'S/ '.round($amount),
        };
    }
}
