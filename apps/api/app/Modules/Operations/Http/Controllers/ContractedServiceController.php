<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Contract;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Servicios contratados: la instancia operativa de cada línea vendida. Nacen al activar el
 * contrato; aquí se activan, suspenden, completan y cancelan, y de los activos salen las
 * órdenes de trabajo.
 */
class ContractedServiceController extends Controller
{
    private const SERVICE_NAME = '(SELECT s.name FROM services s WHERE s.id = contracted_services.service_id)';

    private const CONTRACT_NUMBER = '(SELECT c.contract_number FROM contracts c WHERE c.id = contracted_services.contract_id)';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = $this->listing()
            ->when(isset(ContractedService::STATES[$request->query('status')]), fn ($q) => $q->where('contracted_services.status', $request->query('status')))
            ->when($search !== '', function ($q) use ($search) {
                // Por servicio, cliente o número de contrato.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw(self::SERVICE_NAME.' ILIKE ?', [$like])
                    ->orWhereRaw(ListSort::customerName('contracted_services').' ILIKE ?', [$like])
                    ->orWhereRaw(self::CONTRACT_NUMBER.' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'service' => ColumnFilters::text("concat_ws(' ', ".self::SERVICE_NAME.', '.ListSort::customerName('contracted_services').')'),
            'contract' => ColumnFilters::text(self::CONTRACT_NUMBER),
            'price' => ColumnFilters::number('contracted_services.price'),
            'start' => ColumnFilters::date('contracted_services.start_date'),
        ]);

        ListSort::apply($query, $request, [
            'service' => 'lower('.self::SERVICE_NAME.')',
            'contract' => self::CONTRACT_NUMBER,
            'price' => 'contracted_services.price',
            'start' => 'contracted_services.start_date',
            'status' => ListSort::labels('contracted_services.status', ContractedService::STATES),
        ], fn ($q) => $q->orderByDesc('contracted_services.created_at')->orderByDesc('contracted_services.id'), 'contracted_services.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (ContractedService $service) => $this->row($service));

        return response()->json($page);
    }

    public function show(string $contractedService)
    {
        return response()->json($this->detail($this->find($contractedService)));
    }

    public function activate(string $contractedService, Request $request)
    {
        return $this->transition($request, $this->find($contractedService), ['PENDING'], 'ACTIVE', 'activar', 'contracted_service.activate');
    }

    public function suspend(string $contractedService, Request $request)
    {
        return $this->transition($request, $this->find($contractedService), ['ACTIVE'], 'SUSPENDED', 'suspender', 'contracted_service.suspend', true);
    }

    public function reactivate(string $contractedService, Request $request)
    {
        return $this->transition($request, $this->find($contractedService), ['SUSPENDED'], 'ACTIVE', 'reactivar', 'contracted_service.reactivate');
    }

    public function complete(string $contractedService, Request $request)
    {
        return $this->transition($request, $this->find($contractedService), ['ACTIVE'], 'COMPLETED', 'completar', 'contracted_service.complete');
    }

    public function cancel(string $contractedService, Request $request)
    {
        return $this->transition($request, $this->find($contractedService), ['PENDING', 'ACTIVE', 'SUSPENDED'], 'CANCELLED', 'cancelar', 'contracted_service.cancel', true);
    }

    private function find(string $contractedService): ContractedService
    {
        abort_unless(Str::isUuid($contractedService), 404);

        return $this->listing()->findOrFail($contractedService);
    }

    /** Con lo que la tabla muestra en cada fila, para no pedir nada fila por fila. */
    private function listing()
    {
        return ContractedService::query()
            ->with(
                'customer.party.person',
                'customer.party.organization',
                'service:id,code,name,billing_mode',
                'service.recurrence',
                'contract:id,contract_number,status',
                'owner:id,name',
            )
            // Contadas por su modelo: solo las órdenes de trabajo que la persona alcanza.
            ->withCount('workOrders');
    }

    private function row(ContractedService $service): array
    {
        return [
            'id' => $service->id,
            'status' => $service->status,
            'status_reason' => $service->status_reason,
            'start_date' => $service->start_date?->toDateString(),
            'end_date' => $service->end_date?->toDateString(),
            'quantity' => (float) $service->quantity,
            'price' => (float) $service->price,
            'recurring' => (bool) $service->recurring,
            'customer' => $service->customer,
            'service' => $service->service ? [
                'id' => $service->service->id,
                'code' => $service->service->code,
                'name' => $service->service->name,
                'billing_mode' => $service->service->billing_mode,
                'frequency' => $service->service->recurrence?->frequency,
            ] : null,
            'contract' => $service->contract ? [
                'id' => $service->contract->id,
                'contract_number' => $service->contract->contract_number,
                'status' => $service->contract->status,
            ] : null,
            'owner' => $service->owner ? ['id' => $service->owner->id, 'name' => $service->owner->name] : null,
            'work_orders_count' => (int) $service->work_orders_count,
            'created_at' => $service->created_at?->toIso8601String(),
            'lock_version' => (int) $service->lock_version,
        ];
    }

    private function detail(ContractedService $service): array
    {
        $service = $this->listing()->findOrFail($service->id);

        $instance = DB::table('recurring_service_instances')->where('contracted_service_id', $service->id)
            ->first(['id', 'status', 'frequency', 'next_cycle_date']);

        return [
            ...$this->row($service),
            // Por su modelo: cada quien ve las órdenes de trabajo que su rol alcanza.
            'work_orders' => WorkOrder::query()->with('assignee:id,name')
                ->where('contracted_service_id', $service->id)
                ->orderByDesc('created_at')->limit(100)
                ->get(['id', 'code', 'title', 'status', 'scheduled_start', 'assigned_user_id'])
                ->map(fn (WorkOrder $order) => [
                    'id' => $order->id, 'code' => $order->code, 'title' => $order->title, 'status' => $order->status,
                    'scheduled_start' => $order->scheduled_start?->toIso8601String(),
                    'assignee' => $order->assignee?->name,
                ])->values(),
            'recurring_instance' => $instance ? [
                'id' => $instance->id, 'status' => $instance->status, 'frequency' => $instance->frequency, 'next_cycle_date' => $instance->next_cycle_date,
            ] : null,
            'history' => AuditHistory::for('ContractedService', $service->id),
        ];
    }

    private function transition(Request $request, ContractedService $service, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        if (! in_array($service->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un servicio contratado ".mb_strtolower(ContractedService::STATES[$service->status] ?? $service->status).'.'],
            ]);
        }

        $reason = null;
        if ($needsReason) {
            $reason = trim($request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason']);
        }

        // El servicio se presta al amparo de su contrato: solo se pone en marcha si está vigente.
        if ($to === 'ACTIVE' && Contract::ignoringAccessScope()->whereKey($service->contract_id)->value('status') !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} el servicio: su contrato no está vigente."],
            ]);
        }

        // No se cierra un servicio con trabajo en curso, lo vea o no quien lo cierra.
        if (in_array($to, ['COMPLETED', 'CANCELLED'], true)) {
            $open = WorkOrder::ignoringAccessScope()->where('contracted_service_id', $service->id)->whereIn('status', WorkOrder::OPEN)->count();
            if ($open > 0) {
                throw ValidationException::withMessages([
                    'status' => ["No se puede {$verb} el servicio: tiene {$open} ".($open === 1 ? 'orden de trabajo abierta' : 'órdenes de trabajo abiertas').'.'],
                ]);
            }
        }

        DB::transaction(function () use ($request, $service, $to, $action, $reason) {
            $previous = $service->status;
            $service->update(['status' => $to, 'status_reason' => $reason]);
            $note = $this->syncRecurringInstance($service, $to) ?? $reason;

            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: $action,
                entityType: 'ContractedService',
                entityId: $service->id,
                metadata: array_filter(['from' => $previous, 'to' => $to, 'note' => $note]),
            );
        });

        return response()->json($this->detail($service));
    }

    /**
     * La instancia recurrente sigue al servicio: nace (una sola vez) al activarlo, se suspende
     * con él y se cancela cuando termina. Devuelve la nota para el historial si la creó.
     */
    private function syncRecurringInstance(ContractedService $service, string $to): ?string
    {
        $instances = DB::table('recurring_service_instances')->where('contracted_service_id', $service->id);

        if ($to === 'SUSPENDED') {
            $instances->where('status', 'ACTIVE')->update(['status' => 'SUSPENDED', 'suspended_at' => now(), 'updated_at' => now()]);

            return null;
        }
        if ($to !== 'ACTIVE') {
            $instances->whereIn('status', ['ACTIVE', 'SUSPENDED'])->update(['status' => 'CANCELLED', 'updated_at' => now()]);

            return null;
        }

        $instances->clone()->where('status', 'SUSPENDED')->update(['status' => 'ACTIVE', 'suspended_at' => null, 'updated_at' => now()]);

        $recurrence = $service->recurring
            ? DB::table('service_recurring_configs')->where('service_id', $service->service_id)->where('active', true)->orderByDesc('created_at')->first(['frequency', 'interval_value'])
            : null;
        if (! $recurrence || $instances->clone()->exists()) {
            return null;
        }

        DB::table('recurring_service_instances')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $service->tenant_id,
            'contracted_service_id' => $service->id,
            'status' => 'ACTIVE',
            'frequency' => $recurrence->frequency,
            'interval_value' => $recurrence->interval_value,
            'next_cycle_date' => max($service->start_date?->toDateString() ?? '', now()->toDateString()),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'Instancia recurrente creada';
    }
}
