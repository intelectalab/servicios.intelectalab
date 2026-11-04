<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Models\RecurringCycle;
use App\Modules\Finance\Models\RecurringInstance;
use App\Modules\Finance\Support\Recurrence;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Services\Models\Contract;
use App\Services\AuditHistory;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\LocalTime;

/**
 * Servicios recurrentes: instancias que se cobran por ciclos. Nacen al activar un servicio
 * contratado recurrente y lo siguen (se suspenden y cancelan con él). Desde aquí se procesan sus
 * ciclos (cada uno deja su evento facturable), se renuevan y, si hace falta, se pausa su cobro.
 */
class RecurringController extends Controller
{
    private const WITH = ['customer.party.person', 'customer.party.organization'];

    private const SERVICE_NAME = '(SELECT s.name FROM contracted_services cs JOIN services s ON s.id = cs.service_id WHERE cs.id = recurring_service_instances.contracted_service_id)';

    private const CYCLES = '(SELECT count(*) FROM recurring_service_cycles c WHERE c.recurring_instance_id = recurring_service_instances.id)';

    /** Importe de un ciclo con IGV, como lo calcula `Recurrence::cycleAmount`. */
    private const AMOUNT = 'round(round(recurring_service_instances.unit_price * recurring_service_instances.quantity, 2) * 1.18, 2)';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = $this->listing()
            ->when(isset(RecurringInstance::STATES[$request->query('status')]), fn ($q) => $q->where('recurring_service_instances.status', $request->query('status')))
            ->when($search !== '', function ($q) use ($search) {
                // Por cliente o servicio.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw(self::SERVICE_NAME.' ILIKE ?', [$like])
                    ->orWhereRaw(ListSort::customerName('recurring_service_instances').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'service' => ColumnFilters::text("concat_ws(' ', ".self::SERVICE_NAME.', '.ListSort::customerName('recurring_service_instances').')'),
            'amount' => ColumnFilters::number(self::AMOUNT),
            'next' => ColumnFilters::date('recurring_service_instances.next_cycle_date'),
            'end' => ColumnFilters::date('recurring_service_instances.end_date'),
            'cycles' => ColumnFilters::number(self::CYCLES),
        ]);

        ListSort::apply($query, $request, [
            'service' => 'lower('.self::SERVICE_NAME.')',
            'frequency' => ListSort::labels('recurring_service_instances.frequency', RecurringInstance::FREQUENCIES),
            'amount' => self::AMOUNT,
            'next' => 'recurring_service_instances.next_cycle_date',
            'end' => 'recurring_service_instances.end_date',
            'cycles' => self::CYCLES,
            'status' => ListSort::labels('recurring_service_instances.status', RecurringInstance::STATES),
        ], fn ($q) => $q->orderByRaw('recurring_service_instances.next_cycle_date ASC NULLS LAST')->orderBy('recurring_service_instances.id'), 'recurring_service_instances.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (RecurringInstance $instance) => $this->row($instance));

        return response()->json($page);
    }

    /** Ciclos vencidos por procesar y servicios por renovar, sobre lo que la persona alcanza. */
    public function summary()
    {
        $days = RecurringInstance::RENEWAL_ALERT_DAYS;
        $alerts = $this->listing()->where('recurring_service_instances.status', 'ACTIVE')
            ->whereNotNull('recurring_service_instances.end_date')
            ->where('recurring_service_instances.end_date', '<', LocalTime::today()->addDays($days)->toDateString())
            ->orderBy('recurring_service_instances.end_date');

        return response()->json([
            'due' => RecurringInstance::query()->where('status', 'ACTIVE')->where('next_cycle_date', '<=', LocalTime::today()->toDateString())->count(),
            'alert_days' => $days,
            'alerts_total' => $alerts->clone()->count(),
            'alerts' => $alerts->limit(20)->get()->map(fn (RecurringInstance $instance) => [
                'id' => $instance->id, 'customer' => $instance->customer, 'service_name' => $instance->service_name,
                'end_date' => $instance->end_date?->toDateString(),
            ])->values(),
        ]);
    }

    public function show(string $recurring)
    {
        return response()->json($this->detail($this->find($recurring)));
    }

    /**
     * Procesa los ciclos vencidos de todas las instancias que la persona alcanza o, si se indica
     * una, el ciclo que le toca (hasta con `SINGLE_HORIZON_DAYS` de anticipación).
     */
    public function runCycles(Request $request)
    {
        $data = $request->validate(['instance_id' => ['sometimes', 'nullable', 'uuid']]);
        $single = ! empty($data['instance_id']);

        // Por su modelo: solo las instancias dentro del alcance. Las de otra empresa "no existen".
        $ids = RecurringInstance::query()
            ->when($single, fn ($q) => $q->whereKey($data['instance_id']))
            ->whereNotNull('next_cycle_date')
            ->orderBy('next_cycle_date')->orderBy('id')
            ->pluck('id')->all();
        abort_if($single && ! $ids, 404);

        $horizon = $single ? LocalTime::today()->addDays(Recurrence::SINGLE_HORIZON_DAYS) : LocalTime::today();
        $summary = Recurrence::run($ids, $horizon, $request->user()->id, $single);

        return response()->json($summary);
    }

    /**
     * Renueva: extiende la vigencia y, si se indica, cambia el precio desde el próximo ciclo. El
     * tramo anterior (fechas y precio) queda en el historial de vigencias; no se sobrescribe.
     */
    public function renew(string $recurring, Request $request)
    {
        $instance = $this->find($recurring);
        if ($instance->status === 'CANCELLED') {
            throw ValidationException::withMessages(['status' => ['Una instancia cancelada no se renueva.']]);
        }
        $data = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'price' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999'],
        ], [
            'months.required' => 'Indica los meses de renovación.',
            'months.min' => 'Indica al menos un mes.',
            'price.gt' => 'El precio debe ser mayor a cero.',
        ]);

        // Una instancia vencida vuelve a cobrar al renovarse: su servicio debe seguir activo.
        if ($instance->status === 'EXPIRED') {
            $this->assertServiceActive($instance, 'renovar');
        }

        DB::transaction(function () use ($request, $instance, $data) {
            $previous = $instance->status;
            $today = LocalTime::today();
            // El tramo que se cierra empieza donde terminó el anterior (o al inicio de la instancia).
            $lastEnd = DB::table('recurring_service_prices')->where('recurring_instance_id', $instance->id)->max('valid_to');
            $from = $lastEnd ? \Illuminate\Support\Carbon::parse($lastEnd)->addDay() : ($instance->start_date ?? $instance->created_at->copy()->startOfDay());
            $until = $instance->end_date ?? $today->copy();

            DB::table('recurring_service_prices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $instance->tenant_id, 'recurring_instance_id' => $instance->id,
                'amount' => $instance->unit_price, 'currency' => $instance->currency,
                'valid_from' => $from->toDateString(), 'valid_to' => $until->lt($from) ? $from->toDateString() : $until->toDateString(),
                'created_at' => now(),
            ]);

            // La vigencia se extiende desde donde terminaba; si ya había vencido, desde hoy.
            $running = $instance->end_date && $instance->end_date->gte($today);
            $instance->start_date ??= $from->copy();
            $instance->end_date = ($running ? $instance->end_date->copy() : $today->copy())->addMonthsNoOverflow((int) $data['months']);
            if (! empty($data['price'])) {
                $instance->unit_price = round((float) $data['price'], 4);
            }
            if ($instance->status === 'EXPIRED') {
                $instance->status = 'ACTIVE';
                if (! $instance->next_cycle_date || $instance->next_cycle_date->lt($today)) {
                    $instance->next_cycle_date = $today->copy();
                }
            }
            $instance->save();

            $months = (int) $data['months'];
            Recurrence::audit($instance, $request->user()->id, 'recurring.renew', array_filter([
                'from' => $previous !== $instance->status ? $previous : null, 'to' => $previous !== $instance->status ? $instance->status : null,
                'note' => 'Renovado '.$months.($months === 1 ? ' mes' : ' meses')
                    .(! empty($data['price']) ? ' · nuevo precio '.($instance->currency === 'USD' ? 'US$ ' : 'S/ ').number_format((float) $data['price'], 2) : ''),
            ]));
        });

        return response()->json($this->detail($instance));
    }

    public function suspend(string $recurring, Request $request)
    {
        return $this->transition($request, $this->find($recurring), ['ACTIVE'], 'SUSPENDED', 'suspender', 'recurring.suspend', true);
    }

    public function reactivate(string $recurring, Request $request)
    {
        $instance = $this->find($recurring);
        if ($instance->status === 'SUSPENDED') {
            $this->assertServiceActive($instance, 'reactivar');
        }

        return $this->transition($request, $instance, ['SUSPENDED'], 'ACTIVE', 'reactivar', 'recurring.reactivate');
    }

    public function cancel(string $recurring, Request $request)
    {
        return $this->transition($request, $this->find($recurring), ['ACTIVE', 'SUSPENDED'], 'CANCELLED', 'cancelar', 'recurring.cancel', true);
    }

    private function transition(Request $request, RecurringInstance $instance, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        if (! in_array($instance->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una instancia ".mb_strtolower(RecurringInstance::STATES[$instance->status] ?? $instance->status).'.'],
            ]);
        }
        $reason = $needsReason
            ? trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Debes indicar un motivo.'])['reason'])
            : null;

        DB::transaction(function () use ($request, $instance, $to, $action, $reason) {
            $previous = $instance->status;
            $instance->update([
                'status' => $to, 'status_reason' => $reason,
                'suspended_at' => $to === 'SUSPENDED' ? now() : null,
            ]);
            Recurrence::audit($instance, $request->user()->id, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));
        });

        return response()->json($this->detail($instance));
    }

    /** La instancia cobra al amparo de su servicio contratado: solo vuelve a cobrar si sigue activo. */
    private function assertServiceActive(RecurringInstance $instance, string $verb): void
    {
        if (ContractedService::ignoringAccessScope()->whereKey($instance->contracted_service_id)->value('status') !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => ["No se puede {$verb}: su servicio contratado no está activo."]]);
        }
    }

    private function find(string $recurring): RecurringInstance
    {
        abort_unless(Str::isUuid($recurring), 404);

        return $this->listing()->findOrFail($recurring);
    }

    /** Con lo que la tabla muestra en cada fila, para no pedir nada fila por fila. */
    private function listing()
    {
        return RecurringInstance::query()->with(self::WITH)
            ->select('recurring_service_instances.*')
            ->selectRaw(self::SERVICE_NAME.' AS service_name')
            ->selectRaw(self::CYCLES.' AS cycles_count');
    }

    private function row(RecurringInstance $instance): array
    {
        $due = $instance->status === 'ACTIVE' && $instance->next_cycle_date && $instance->next_cycle_date->lte(LocalTime::today());

        return [
            'id' => $instance->id,
            'service_name' => $instance->service_name,
            'customer' => $instance->customer,
            'frequency' => $instance->frequency,
            'interval_value' => (int) $instance->interval_value,
            'unit_price' => (float) $instance->unit_price,
            'quantity' => (float) $instance->quantity,
            'currency' => $instance->currency,
            'amount' => Recurrence::cycleAmount($instance),
            'next_cycle_date' => $instance->next_cycle_date?->toDateString(),
            'due' => $due,
            // Días que faltan para el ciclo que toca (negativo si ya venció).
            'days_to_next' => $instance->next_cycle_date ? (int) LocalTime::today()->diffInDays($instance->next_cycle_date, false) : null,
            'start_date' => $instance->start_date?->toDateString(),
            'end_date' => $instance->end_date?->toDateString(),
            'renewal_due' => $instance->status === 'ACTIVE' && $instance->end_date && $instance->end_date->lt(LocalTime::today()->addDays(RecurringInstance::RENEWAL_ALERT_DAYS)),
            'cycles_count' => (int) $instance->cycles_count,
            'status' => $instance->status,
            'status_reason' => $instance->status_reason,
            'lock_version' => (int) $instance->lock_version,
        ];
    }

    private function detail(RecurringInstance $instance): array
    {
        $instance = $this->listing()->findOrFail($instance->id);

        $contracted = DB::table('contracted_services')->where('id', $instance->contracted_service_id)->first(['id', 'contract_id', 'status']);
        // Por su modelo: el contrato y el servicio fuera del alcance de quien mira no se enlazan.
        $contract = $contracted ? Contract::query()->find($contracted->contract_id, ['id', 'contract_number']) : null;
        $cycles = $instance->cycles()->limit(200)->get();
        // El estado del evento de cada ciclo es un dato del ciclo, se alcance o no el evento.
        $events = BillableEvent::ignoringAccessScope()->whereIn('id', $cycles->pluck('billable_event_id')->filter())->pluck('status', 'id');

        return [
            ...$this->row($instance),
            'contract' => $contract ? ['id' => $contract->id, 'contract_number' => $contract->contract_number] : null,
            'contracted_service' => $contracted ? [
                'id' => $contracted->id, 'status' => $contracted->status,
                'visible' => ContractedService::query()->whereKey($contracted->id)->exists(),
            ] : null,
            'single_horizon_days' => Recurrence::SINGLE_HORIZON_DAYS,
            'terms' => DB::table('recurring_service_prices')->where('recurring_instance_id', $instance->id)->orderBy('valid_from')->orderBy('created_at')
                ->get(['id', 'amount', 'currency', 'valid_from', 'valid_to'])
                ->map(fn ($term) => [
                    'id' => $term->id, 'unit_price' => (float) $term->amount, 'currency' => $term->currency, 'start' => $term->valid_from, 'end' => $term->valid_to,
                ])->values(),
            'cycles' => $cycles->map(fn (RecurringCycle $cycle) => [
                'id' => $cycle->id,
                'period' => $cycle->period,
                'idempotency_key' => $cycle->idempotency_key,
                'amount' => (float) $cycle->amount,
                'status' => $cycle->status,
                'attempts' => (int) $cycle->attempts,
                'error' => $cycle->error,
                'billable_event_id' => $cycle->billable_event_id,
                'billable_event_status' => $cycle->billable_event_id ? ($events[$cycle->billable_event_id] ?? null) : null,
                'processed_at' => $cycle->processed_at?->toIso8601String(),
            ])->values(),
            'history' => AuditHistory::for('RecurringInstance', $instance->id),
        ];
    }
}
