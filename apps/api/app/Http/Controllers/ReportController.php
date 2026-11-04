<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckPermission;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Finance\Http\Controllers\ReceivableController;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Models\RecurringInstance;
use App\Modules\Operations\Http\Controllers\ProjectController;
use App\Modules\Operations\Models\Project;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Quotation;
use App\Modules\Support\Models\SupportTicket;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Services\LocalTime;

/**
 * Reportes (Control → Reportes). Cada reporte lee de su módulo con los mismos modelos que sus
 * listados, así que respeta el alcance de quien lo mira (Propios, Equipo, Sede, Empresa) y solo
 * existe si la empresa tiene el módulo y el rol puede verlo. Se exportan como cualquier listado
 * (`?export=1` exige `reports.export` y queda en auditoría).
 */
class ReportController extends Controller
{
    /** Clave => [nombre, permiso del módulo de donde salen los datos]. */
    public const REPORTS = [
        'ventas' => ['Ventas y cotizaciones', 'quotations.view'],
        'pipeline' => ['Pipeline CRM', 'opportunities.view'],
        'cobranza' => ['Cobranza y antigüedad', 'receivables.view'],
        'rentabilidad' => ['Rentabilidad por proyecto', 'projects.view'],
        'sla' => ['Cumplimiento de SLA', 'tickets.view'],
        'renovaciones' => ['Renovaciones próximas', 'recurring.view'],
        'operaciones' => ['Productividad operativa', 'work_orders.view'],
    ];

    private const USER = '(SELECT u.name FROM users u WHERE u.id = %s)';

    /** Fecha de Lima de una columna con hora, para filtrar y mostrar por día. */
    private const LIMA_DAY = "(%s AT TIME ZONE 'America/Lima')::date";

    /**
     * Los reportes que la persona puede abrir (módulo activo y permiso del módulo) y sus últimas
     * exportaciones de reportes ("Mis exportaciones"), tomadas de la auditoría.
     */
    public function index(Request $request)
    {
        $exports = DB::table('audit_logs')
            ->where('user_id', $request->user()->id)->where('action', 'reports.export')
            ->orderByDesc('created_at')->limit(10)->get(['metadata', 'created_at'])
            ->map(function ($row) {
                $data = json_decode((string) $row->metadata, true) ?: [];
                parse_str((string) ($data['filters'] ?? ''), $filters);
                $key = is_string($filters['report'] ?? null) ? $filters['report'] : null;

                return [
                    'report' => $key,
                    'label' => self::REPORTS[$key][0] ?? 'Reporte',
                    'format' => $data['format'] ?? null,
                    'rows' => (int) ($data['rows'] ?? 0),
                    'selection' => (bool) ($data['selection'] ?? false),
                    'at' => Carbon::parse($row->created_at)->toIso8601String(),
                ];
            });

        return response()->json([
            'reports' => collect(self::REPORTS)
                ->filter(fn (array $report) => CheckPermission::granted($request, $report[1]))
                ->map(fn (array $report, string $key) => ['key' => $key, 'label' => $report[0]])
                ->values(),
            'exports' => $exports,
        ]);
    }

    public function show(Request $request, string $report)
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        $permission = self::REPORTS[$report][1];
        if (! CheckPermission::granted($request, $permission)) {
            return response()->json(['message' => "No tienes el permiso requerido: {$permission}", 'code' => 'PERMISSION_DENIED'], 403);
        }

        $spec = $this->{$report}();
        $query = $spec['query'];

        // Rango de fechas (en los reportes que tienen una fecha propia).
        if (isset($spec['date'])) {
            $from = $this->date($request->query('from'));
            $to = $this->date($request->query('to'));
            $query->when($from, fn ($q) => $q->whereRaw("{$spec['date']} >= ?", [$from]))
                ->when($to, fn ($q) => $q->whereRaw("{$spec['date']} <= ?", [$to]));
        }

        // Los resúmenes (gráficos) cuentan sobre el alcance y el rango, sin los filtros por columna.
        $base = clone $query;

        ColumnFilters::apply($query, $request, $spec['filters']);
        ListSort::apply($query, $request, $spec['sorts'], $spec['default'], $spec['tiebreak']);

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform($spec['row']);

        if ($request->boolean('export')) {
            return response()->json([...$page->toArray(), 'summary' => null, 'kpis' => null, 'period' => null]);
        }

        $period = $this->period($request);

        return response()->json([
            ...$page->toArray(),
            'summary' => ($spec['summary'] ?? fn () => null)($base),
            'kpis' => $this->{'kpis'.ucfirst($report)}($base, $period),
            'period' => $period,
        ]);
    }

    /**
     * Periodo de los indicadores que se comparan: el rango elegido (sin "hasta", hasta hoy; sin
     * "desde", los 30 días previos) o, sin rango, lo que va del mes. El anterior es un periodo de la
     * misma cantidad de días justo antes (sin rango: los mismos días del mes anterior).
     *
     * @return array{from: string, to: string, prev_from: string, prev_to: string, custom: bool}
     */
    private function period(Request $request): array
    {
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $today = now('America/Lima')->startOfDay();

        if (! $from && ! $to) {
            $start = $today->copy()->startOfMonth();
            $prevStart = $start->copy()->subMonthNoOverflow();
            $prevEnd = $prevStart->copy()->addDays(min($today->day, $prevStart->daysInMonth) - 1);

            return ['from' => $start->toDateString(), 'to' => $today->toDateString(), 'prev_from' => $prevStart->toDateString(), 'prev_to' => $prevEnd->toDateString(), 'custom' => false];
        }

        $end = $to ? Carbon::parse($to, 'America/Lima') : $today;
        $start = $from ? Carbon::parse($from, 'America/Lima') : $end->copy()->subDays(29);

        // Meses completos (p. ej. octubre): se comparan con los mismos meses justo antes (septiembre).
        if ($start->day === 1 && $end->isSameDay($end->copy()->endOfMonth()) && $start->lte($end)) {
            $months = ($end->year - $start->year) * 12 + $end->month - $start->month + 1;

            return [
                'from' => $start->toDateString(), 'to' => $end->toDateString(),
                'prev_from' => $start->copy()->subMonthsNoOverflow($months)->toDateString(), 'prev_to' => $start->copy()->subDay()->toDateString(),
                'custom' => true,
            ];
        }

        $days = (int) $start->diffInDays($end) + 1;

        return [
            'from' => $start->toDateString(), 'to' => $end->toDateString(),
            'prev_from' => $start->copy()->subDays($days)->toDateString(), 'prev_to' => $start->copy()->subDay()->toDateString(),
            'custom' => true,
        ];
    }

    /** Un indicador: cifra, formato y, si se compara, la del periodo anterior. */
    private static function kpi(string $label, float|int|null $value, string $format, float|int|null $previous = null, ?string $hint = null): array
    {
        return ['label' => $label, 'value' => $value, 'format' => $format, 'previous' => $previous, 'hint' => $hint];
    }

    private static function pct(float|int $part, float|int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }

    // --- Indicadores de cada reporte (sobre el alcance de quien mira) ---------------------------

    private function kpisVentas($base, array $period): array
    {
        $day = sprintf(self::LIMA_DAY, 'quotations.created_at');
        $pen = $this->inPen('quotations.total', 'quotations.currency', 'quotations.exchange_rate');
        $stats = function (string $from, string $to) use ($day, $pen) {
            $row = Quotation::query()->whereRaw("{$day} BETWEEN ? AND ?", [$from, $to])->where('quotations.status', '<>', 'CANCELLED')
                ->toBase()->selectRaw("count(*) AS n, coalesce(sum({$pen}), 0) AS amount,
                    count(*) FILTER (WHERE quotations.status = 'APPROVED') AS approved,
                    count(*) FILTER (WHERE quotations.status IN ('SUBMITTED', 'APPROVED', 'REJECTED', 'EXPIRED')) AS sent")->first();

            return [(float) $row->amount, (int) $row->n, self::pct((int) $row->approved, (int) $row->sent)];
        };
        [$amount, $count, $rate] = $stats($period['from'], $period['to']);
        [$prevAmount, $prevCount, $prevRate] = $stats($period['prev_from'], $period['prev_to']);

        return [
            self::kpi('Total cotizado', round($amount, 2), 'money', round($prevAmount, 2), 'En soles, sin las canceladas'),
            self::kpi('Cotizaciones', $count, 'count', $prevCount),
            self::kpi('Aprobación', $rate, 'percent', $prevRate, 'Aprobadas sobre las enviadas'),
        ];
    }

    private function kpisPipeline($base, array $period): array
    {
        $rate = (float) (ExchangeRate::current('USD')?->sell ?? 0);
        $pen = "CASE WHEN crm_opportunities.currency = 'USD' THEN crm_opportunities.expected_value * {$rate} ELSE crm_opportunities.expected_value END";
        $row = $base->toBase()->select(DB::raw("
            count(*) FILTER (WHERE crm_opportunities.status = 'OPEN') AS open_count,
            coalesce(sum({$pen}) FILTER (WHERE crm_opportunities.status = 'OPEN'), 0) AS open_amount,
            coalesce(sum({$pen} * crm_opportunities.probability / 100) FILTER (WHERE crm_opportunities.status = 'OPEN'), 0) AS weighted,
            count(*) FILTER (WHERE crm_opportunities.status = 'WON') AS won,
            count(*) FILTER (WHERE crm_opportunities.status = 'LOST') AS lost"))->first();

        return [
            self::kpi('Pipeline abierto', round((float) $row->open_amount, 2), 'money', null, "{$row->open_count} oportunidades abiertas"),
            self::kpi('Ponderado', round((float) $row->weighted, 2), 'money', null, 'Monto por probabilidad de cierre'),
            self::kpi('Tasa de cierre', self::pct((int) $row->won, (int) $row->won + (int) $row->lost), 'percent', null, 'Ganadas sobre las cerradas'),
        ];
    }

    private function kpisCobranza($base, array $period): array
    {
        $summary = app(ReceivableController::class)->summary()->getData(true);

        return [
            self::kpi('Saldo por cobrar', $summary['outstanding'], 'money', null, "{$summary['open_count']} cuentas con saldo"),
            self::kpi('Vencido', $summary['overdue'], 'money', null, "{$summary['overdue_customers']} clientes con deuda vencida"),
            self::kpi('Vencido sobre el saldo', self::pct($summary['overdue'], $summary['outstanding']), 'percent'),
        ];
    }

    private function kpisRentabilidad($base, array $period): array
    {
        $t = ProjectController::totals();
        $row = $base->toBase()->where('projects.status', '<>', 'CANCELLED')->select(DB::raw("
            coalesce(sum(projects.budget_revenue), 0) AS revenue,
            coalesce(sum(({$t['labor_cost']}) + ({$t['other_cost']})), 0) AS cost"))->first();
        $revenue = (float) $row->revenue;
        $cost = (float) $row->cost;
        $pct = self::pct($revenue - $cost, $revenue);

        return [
            self::kpi('Ingreso presupuestado', round($revenue, 2), 'money', null, 'Proyectos no cancelados'),
            self::kpi('Costo real', round($cost, 2), 'money', null, 'Horas y costos aprobados'),
            self::kpi('Margen', round($revenue - $cost, 2), 'money', null, $pct !== null ? "{$pct} % del ingreso" : null),
        ];
    }

    private function kpisSla($base, array $period): array
    {
        $sla = SupportTicket::SLA_SQL;
        $counts = (clone $base)->toBase()->select(DB::raw("({$sla}) AS state"), DB::raw('count(*) AS total'))->groupByRaw('1')->pluck('total', 'state');
        $with = $counts->except('none')->sum();
        $onTime = (int) ($counts['met'] ?? 0) + (int) ($counts['ok'] ?? 0);

        return [
            self::kpi('Cumplimiento', self::pct($onTime, $with), 'percent', null, 'A tiempo o cumplidos sobre los que tienen SLA'),
            self::kpi('Vencidos', (int) ($counts['breached'] ?? 0), 'count'),
            self::kpi('En riesgo', (int) ($counts['risk'] ?? 0), 'count', null, '75 % del plazo consumido'),
        ];
    }

    private function kpisRenovaciones($base, array $period): array
    {
        $amount = 'recurring_service_instances.unit_price * recurring_service_instances.quantity';
        $row = $base->toBase()->select(DB::raw("
            count(*) FILTER (WHERE recurring_service_instances.end_date BETWEEN (now() AT TIME ZONE 'America/Lima')::date AND (now() AT TIME ZONE 'America/Lima')::date + 30) AS n30,
            coalesce(sum({$amount}) FILTER (WHERE recurring_service_instances.end_date BETWEEN (now() AT TIME ZONE 'America/Lima')::date AND (now() AT TIME ZONE 'America/Lima')::date + 30), 0) AS a30,
            count(*) FILTER (WHERE recurring_service_instances.end_date BETWEEN (now() AT TIME ZONE 'America/Lima')::date AND (now() AT TIME ZONE 'America/Lima')::date + 90) AS n90,
            count(*) FILTER (WHERE recurring_service_instances.end_date < (now() AT TIME ZONE 'America/Lima')::date) AS overdue"))->first();

        return [
            self::kpi('Vencen en 30 días', (int) $row->n30, 'count', null, 'S/ '.number_format((float) $row->a30, 2).' por ciclo'),
            self::kpi('Vencen en 90 días', (int) $row->n90, 'count'),
            self::kpi('Vigencia pasada sin renovar', (int) $row->overdue, 'count', null, 'Siguen activas con la fecha de fin ya pasada'),
        ];
    }

    private function kpisOperaciones($base, array $period): array
    {
        $day = sprintf(self::LIMA_DAY, 'work_orders.completed_at');
        $stats = function (string $from, string $to) use ($day) {
            $row = WorkOrder::query()->where('work_orders.status', 'COMPLETED')->whereRaw("{$day} BETWEEN ? AND ?", [$from, $to])
                ->toBase()->selectRaw('count(*) AS n, avg(extract(epoch FROM work_orders.completed_at - work_orders.started_at)) / 3600 AS hours')->first();

            return [(int) $row->n, $row->hours !== null ? round((float) $row->hours, 1) : null];
        };
        [$done, $hours] = $stats($period['from'], $period['to']);
        [$prevDone, $prevHours] = $stats($period['prev_from'], $period['prev_to']);
        $open = WorkOrder::query()->whereIn('work_orders.status', WorkOrder::OPEN)->count();

        return [
            self::kpi('Completadas', $done, 'count', $prevDone),
            self::kpi('Tiempo promedio de atención', $hours, 'hours', $prevHours, 'Del inicio al cierre'),
            self::kpi('Abiertas hoy', $open, 'count', null, 'Planificadas, asignadas, en ejecución o bloqueadas'),
        ];
    }

    // ------------------------------------------------------------------------------------------

    private function ventas(): array
    {
        $customer = ListSort::customerName('quotations');
        $day = sprintf(self::LIMA_DAY, 'quotations.created_at');
        $pen = $this->inPen('quotations.total', 'quotations.currency', 'quotations.exchange_rate');

        return [
            'query' => Quotation::query()->select('quotations.*')->selectRaw("{$customer} AS customer_name"),
            'date' => $day,
            'filters' => [
                'number' => ColumnFilters::text('quotations.quotation_number'),
                'customer' => ColumnFilters::text($customer),
                'total' => ColumnFilters::number('quotations.total'),
                'date' => ColumnFilters::date($day),
            ],
            'sorts' => [
                'number' => 'quotations.quotation_number',
                'customer' => $customer,
                'status' => 'quotations.status',
                'total' => 'quotations.total',
                'date' => 'quotations.created_at',
            ],
            'default' => fn ($q) => $q->orderByDesc('quotations.created_at'),
            'tiebreak' => 'quotations.id',
            'row' => fn (Quotation $q) => [
                'id' => $q->id,
                'number' => $q->quotation_number,
                'customer' => $q->customer_name,
                'source' => $q->source_type,
                'status' => $q->status,
                'currency' => $q->currency,
                'total' => (float) $q->total,
                'date' => $q->created_at?->timezone('America/Lima')->toDateString(),
            ],
            // Total cotizado por mes (en soles), los últimos 6 meses, dentro del alcance.
            'summary' => function () use ($day, $pen) {
                $start = now('America/Lima')->startOfMonth()->subMonths(5);
                $sums = Quotation::query()->whereRaw("{$day} >= ?", [$start->toDateString()])
                    ->selectRaw("to_char({$day}, 'YYYY-MM') AS month, coalesce(sum({$pen}), 0) AS amount")
                    ->groupByRaw('1')->toBase()->pluck('amount', 'month');

                return ['months' => collect(range(0, 5))->map(function (int $i) use ($start, $sums) {
                    $month = $start->copy()->addMonths($i);

                    return ['key' => $month->format('Y-m'), 'amount' => round((float) ($sums[$month->format('Y-m')] ?? 0), 2)];
                })->all()];
            },
        ];
    }

    private function pipeline(): array
    {
        $customer = ListSort::customerName('crm_opportunities');
        $stage = '(SELECT s.name FROM crm_pipeline_stages s WHERE s.id = crm_opportunities.stage_id)';
        $owner = sprintf(self::USER, 'crm_opportunities.owner_user_id');

        return [
            'query' => CrmOpportunity::query()->select('crm_opportunities.*')
                ->selectRaw("{$customer} AS customer_name, {$stage} AS stage_name, {$owner} AS owner_name"),
            'filters' => [
                'opportunity' => ColumnFilters::text("concat_ws(' ', crm_opportunities.opportunity_number, crm_opportunities.name, {$customer})"),
                'stage' => ColumnFilters::text($stage),
                'amount' => ColumnFilters::number('crm_opportunities.expected_value'),
                'owner' => ColumnFilters::text($owner),
            ],
            'sorts' => [
                'opportunity' => 'lower(crm_opportunities.name)',
                'stage' => $stage,
                'status' => 'crm_opportunities.status',
                'amount' => 'crm_opportunities.expected_value',
                'probability' => 'crm_opportunities.probability',
                'owner' => $owner,
            ],
            'default' => fn ($q) => $q->orderByDesc('crm_opportunities.created_at'),
            'tiebreak' => 'crm_opportunities.id',
            'row' => fn (CrmOpportunity $o) => [
                'id' => $o->id,
                'number' => $o->opportunity_number,
                'name' => $o->name,
                'customer' => $o->customer_name,
                'stage' => $o->stage_name,
                'status' => $o->status,
                'currency' => $o->currency,
                'amount' => (float) $o->expected_value,
                'probability' => (int) round((float) $o->probability),
                'owner' => $o->owner_name,
            ],
        ];
    }

    private function cobranza(): array
    {
        $customer = ListSort::customerName('receivables');
        $invoice = ReceivableController::INVOICE_NUMBER;

        return [
            'query' => Receivable::query()->whereIn('receivables.status', Receivable::OPEN)->select('receivables.*')
                ->selectRaw("{$customer} AS customer_name, {$invoice} AS invoice_number, ".Receivable::DAYS_OVERDUE.' AS days_overdue'),
            'filters' => [
                'invoice' => ColumnFilters::text($invoice),
                'customer' => ColumnFilters::text($customer),
                'due' => ColumnFilters::date('receivables.due_date'),
                'balance' => ColumnFilters::number('receivables.balance_amount'),
            ],
            'sorts' => [
                'invoice' => $invoice,
                'customer' => $customer,
                'due' => 'receivables.due_date',
                'balance' => 'receivables.balance_amount',
            ],
            'default' => fn ($q) => $q->orderBy('receivables.due_date'),
            'tiebreak' => 'receivables.id',
            'row' => function (Receivable $r) {
                $days = $r->days_overdue !== null ? (int) $r->days_overdue : null;

                return [
                    'id' => $r->id,
                    'invoice' => $r->invoice_number,
                    'customer' => $r->customer_name,
                    'due_date' => $r->due_date?->toDateString(),
                    'days_overdue' => max($days ?? 0, 0),
                    'bucket' => Receivable::bucketOf($days),
                    'status' => $r->status,
                    'currency' => $r->currency,
                    'balance' => (float) $r->balance_amount,
                ];
            },
            // Saldo por tramo de antigüedad (en soles): el mismo resumen de Cuentas por cobrar.
            'summary' => fn () => collect(app(ReceivableController::class)->summary()->getData(true))->only(['aging', 'missing_rate'])->all(),
        ];
    }

    private function rentabilidad(): array
    {
        $t = ProjectController::totals();
        $customer = ListSort::customerName('projects');
        $cost = "({$t['labor_cost']}) + ({$t['other_cost']})";
        $margin = "projects.budget_revenue - ({$t['labor_cost']}) - ({$t['other_cost']})";

        return [
            'query' => Project::query()->select('projects.*')->selectRaw("{$customer} AS customer_name")
                ->selectRaw(collect($t)->map(fn (string $sql, string $as) => "({$sql}) AS {$as}")->implode(', ')),
            'filters' => [
                'project' => ColumnFilters::text("concat_ws(' ', projects.code, projects.name, {$customer})"),
                'revenue' => ColumnFilters::number('projects.budget_revenue'),
                'margin' => ColumnFilters::number($margin),
            ],
            'sorts' => [
                'project' => 'lower(projects.name)',
                'revenue' => 'projects.budget_revenue',
                'cost' => $cost,
                'margin' => $margin,
                'margin_pct' => "CASE WHEN projects.budget_revenue > 0 THEN ({$margin}) / projects.budget_revenue END",
                'status' => 'projects.status',
            ],
            'default' => fn ($q) => $q->orderByDesc('projects.created_at'),
            'tiebreak' => 'projects.id',
            'row' => function (Project $p) {
                $estimate = (float) $p->estimate_total;
                $tasks = (int) $p->tasks_total;
                $progress = $estimate > 0 ? (float) $p->estimate_done / $estimate : ($tasks > 0 ? (int) $p->tasks_done / $tasks : 0);
                $revenue = (float) $p->budget_revenue;
                $cost = round((float) $p->labor_cost + (float) $p->other_cost, 2);
                $margin = round($revenue - $cost, 2);

                return [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'customer' => $p->customer_name,
                    'status' => $p->status,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'margin' => $margin,
                    'margin_pct' => $revenue > 0 ? (int) round($margin / $revenue * 100) : 0,
                    'progress' => (int) round($progress * 100),
                ];
            },
        ];
    }

    private function sla(): array
    {
        $customer = ListSort::customerName('support_tickets');
        $sla = SupportTicket::SLA_SQL;

        return [
            'query' => SupportTicket::query()->select('support_tickets.*')->selectRaw("{$customer} AS customer_name, ({$sla}) AS sla_status"),
            'date' => sprintf(self::LIMA_DAY, 'support_tickets.opened_at'),
            'filters' => [
                'ticket' => ColumnFilters::text("concat_ws(' ', support_tickets.ticket_number, support_tickets.subject)"),
                'customer' => ColumnFilters::text($customer),
                'opened' => ColumnFilters::date(sprintf(self::LIMA_DAY, 'support_tickets.opened_at')),
            ],
            'sorts' => [
                'ticket' => 'support_tickets.ticket_number',
                'customer' => $customer,
                'priority' => 'support_tickets.priority',
                'status' => 'support_tickets.status',
                'sla' => ListSort::labels("({$sla})", SupportTicket::SLA_STATES),
                'opened' => 'support_tickets.opened_at',
            ],
            'default' => fn ($q) => $q->orderByDesc('support_tickets.opened_at'),
            'tiebreak' => 'support_tickets.id',
            'row' => fn (SupportTicket $t) => [
                'id' => $t->id,
                'number' => $t->ticket_number,
                'subject' => $t->subject,
                'customer' => $t->customer_name,
                'priority' => $t->priority,
                'status' => $t->status,
                'sla' => $t->sla_status,
                'opened_at' => $t->opened_at?->toIso8601String(),
            ],
            // Cuántos tickets hay en cada estado del SLA, dentro del alcance y del rango de fechas.
            'summary' => fn ($base) => ['sla' => $base->toBase()->select(DB::raw("({$sla}) AS state"), DB::raw('count(*) AS total'))
                ->groupByRaw('1')->pluck('total', 'state')->map(fn ($n) => (int) $n)],
        ];
    }

    private function renovaciones(): array
    {
        $customer = ListSort::customerName('recurring_service_instances');
        $service = '(SELECT s.name FROM contracted_services cs JOIN services s ON s.id = cs.service_id WHERE cs.id = recurring_service_instances.contracted_service_id)';
        $amount = 'recurring_service_instances.unit_price * recurring_service_instances.quantity';

        return [
            'query' => RecurringInstance::query()->where('recurring_service_instances.status', 'ACTIVE')
                ->whereNotNull('recurring_service_instances.end_date')
                ->select('recurring_service_instances.*')->selectRaw("{$customer} AS customer_name, {$service} AS service_name"),
            'date' => 'recurring_service_instances.end_date',
            'filters' => [
                'customer' => ColumnFilters::text($customer),
                'service' => ColumnFilters::text($service),
                'end' => ColumnFilters::date('recurring_service_instances.end_date'),
                'amount' => ColumnFilters::number($amount),
            ],
            'sorts' => [
                'customer' => $customer,
                'service' => $service,
                'end' => 'recurring_service_instances.end_date',
                'amount' => $amount,
            ],
            'default' => fn ($q) => $q->orderBy('recurring_service_instances.end_date'),
            'tiebreak' => 'recurring_service_instances.id',
            'row' => fn (RecurringInstance $r) => [
                'id' => $r->id,
                'customer' => $r->customer_name,
                'service' => $r->service_name,
                'frequency' => $r->frequency,
                'end_date' => $r->end_date?->toDateString(),
                'days_left' => $r->end_date ? (int) LocalTime::today()->diffInDays($r->end_date, false) : null,
                'currency' => $r->currency,
                'amount' => round((float) $r->unit_price * (float) $r->quantity, 2),
            ],
        ];
    }

    private function operaciones(): array
    {
        $customer = ListSort::customerName('work_orders');
        $tech = sprintf(self::USER, 'work_orders.assigned_user_id');
        $day = sprintf(self::LIMA_DAY, 'work_orders.scheduled_start');

        return [
            'query' => WorkOrder::query()->select('work_orders.*')->selectRaw("{$customer} AS customer_name, {$tech} AS technician"),
            'date' => $day,
            'filters' => [
                'order' => ColumnFilters::text("concat_ws(' ', work_orders.code, work_orders.title, {$customer})"),
                'technician' => ColumnFilters::text($tech),
                'scheduled' => ColumnFilters::date($day),
            ],
            'sorts' => [
                'order' => 'work_orders.code',
                'technician' => $tech,
                'status' => 'work_orders.status',
                'priority' => "CASE work_orders.priority WHEN 'HIGH' THEN 1 WHEN 'NORMAL' THEN 2 ELSE 3 END",
                'scheduled' => 'work_orders.scheduled_start',
            ],
            'default' => fn ($q) => $q->orderByDesc('work_orders.scheduled_start'),
            'tiebreak' => 'work_orders.id',
            'row' => fn (WorkOrder $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'title' => $w->title,
                'customer' => $w->customer_name,
                'technician' => $w->technician,
                'status' => $w->status,
                'priority' => $w->priority,
                'scheduled_at' => $w->scheduled_start ? Carbon::parse($w->scheduled_start)->toIso8601String() : null,
            ],
            // Órdenes por estado, dentro del alcance y del rango de fechas.
            'summary' => fn ($base) => ['states' => $base->toBase()->select('work_orders.status', DB::raw('count(*) AS total'))
                ->groupBy('work_orders.status')->pluck('total', 'status')->map(fn ($n) => (int) $n)],
        ];
    }

    // ------------------------------------------------------------------------------------------

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** Importe en soles: lo que está en dólares, con el tipo de cambio del documento o el vigente. */
    private function inPen(string $amount, string $currency, string $rate): string
    {
        $current = (float) (ExchangeRate::current('USD')?->sell ?? 0);

        return "CASE WHEN {$currency} = 'USD' THEN {$amount} * coalesce({$rate}, {$current}) ELSE {$amount} END";
    }
}
