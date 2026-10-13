<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Models\Customer;
use App\Modules\Operations\Models\AgendaEvent;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Operations\Support\AgendaClash;
use App\Modules\Operations\Support\Technicians;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Agenda: eventos (reuniones, visitas, mantenimientos, sesiones) y las órdenes de trabajo
 * programadas, por semana. Al agendar se avisa si la persona ya tiene algo en ese horario.
 */
class AgendaController extends Controller
{
    /** Rango máximo que se entrega de una vez (la pantalla pide una semana). */
    private const MAX_DAYS = 45;

    private const OPTIONS_LIMIT = 500;

    /** Eventos y órdenes de trabajo programadas entre `from` y `to`; con `user`, solo los de esa persona. */
    public function index(Request $request)
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'], 'user' => ['nullable', 'uuid']]);
        // Agenda de una sola persona, dentro de lo que quien mira alcanza.
        $userId = $data['user'] ?? null;
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => ['El rango pedido es demasiado amplio.']]);
        }

        $events = AgendaEvent::query()
            ->with('assignee:id,name', 'customer.party.person', 'customer.party.organization')
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($userId, fn ($q) => $q->where('assigned_user_id', $userId))
            ->orderBy('starts_at')->limit(1000)->get();

        return response()->json([
            'data' => $events->map(fn (AgendaEvent $event) => $this->row($event, $request)),
            // Por su modelo: solo las órdenes que la persona alcanza; sin permiso de verlas, no se entregan.
            'work_orders' => CheckPermission::granted($request, 'work_orders.view')
                ? WorkOrder::query()
                    ->with('assignee:id,name', 'customer.party.person', 'customer.party.organization')
                    ->where('status', '<>', 'CANCELLED')
                    ->whereNotNull('scheduled_start')
                    ->where('scheduled_start', '>=', $from)->where('scheduled_start', '<', $to)
                    ->when($userId, fn ($q) => $q->where('assigned_user_id', $userId))
                    ->orderBy('scheduled_start')->limit(1000)->get()
                    ->map(fn (WorkOrder $order) => [
                        'id' => $order->id,
                        'code' => $order->code,
                        'title' => $order->title,
                        'status' => $order->status,
                        'starts_at' => $order->scheduled_start->toIso8601String(),
                        'ends_at' => ($order->scheduled_end ?? $order->scheduled_start->copy()->addHours(AgendaClash::WORK_ORDER_HOURS))->toIso8601String(),
                        'assignee' => $order->assignee ? ['id' => $order->assignee->id, 'name' => $order->assignee->name] : null,
                        'customer' => $this->customerName($order->customer),
                    ])
                : null,
        ]);
    }

    /** Listas del formulario: a quién se le puede agendar y, con permiso de verlos, los clientes. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'people' => Technicians::list($tenantId, 'agenda.view'),
            'customers' => CheckPermission::granted($request, 'core.view')
                ? Customer::query()->with('party.person', 'party.organization')->where('tenant_id', $tenantId)
                    ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                    ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => $this->customerName($customer)])
                    ->filter(fn (array $customer) => $customer['name'])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()
                : null,
        ]);
    }

    public function show(string $agendaEvent, Request $request)
    {
        return response()->json($this->row($this->find($agendaEvent), $request));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->eventData($request, $tenantId);
        $forced = $this->assertFree($request, $data, null);

        $event = DB::transaction(function () use ($request, $tenantId, $data, $forced) {
            $event = AgendaEvent::create([...$data, 'tenant_id' => $tenantId, 'status' => 'SCHEDULED', 'created_by' => $request->user()->id]);
            $this->audit($request, $event, 'agenda.create', ['note' => $event->title, ...($forced ? ['forced' => true] : [])]);

            return $event;
        });

        return response()->json($this->row($event->fresh(), $request), 201);
    }

    /** Solo un evento programado se edita. */
    public function update(string $agendaEvent, Request $request)
    {
        $event = $this->find($agendaEvent);
        $this->assertScheduled($event, 'editar');
        $data = $this->eventData($request, $event->tenant_id);
        $forced = $this->assertFree($request, $data, $event);

        DB::transaction(function () use ($request, $event, $data, $forced) {
            $event->update($data);
            $this->audit($request, $event, 'agenda.update', ['note' => $event->title, ...($forced ? ['forced' => true] : [])]);
        });

        return response()->json($this->row($event->fresh(), $request));
    }

    public function done(string $agendaEvent, Request $request)
    {
        $event = $this->find($agendaEvent);
        $this->assertScheduled($event, 'marcar como realizado');

        DB::transaction(function () use ($request, $event) {
            $event->update(['status' => 'DONE']);
            $this->audit($request, $event, 'agenda.done', ['from' => 'SCHEDULED', 'to' => 'DONE', 'note' => $event->title]);
        });

        return response()->json($this->row($event->fresh(), $request));
    }

    public function cancel(string $agendaEvent, Request $request)
    {
        $event = $this->find($agendaEvent);
        $this->assertScheduled($event, 'cancelar');
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Debes indicar un motivo.'],
        )['reason']);

        DB::transaction(function () use ($request, $event, $reason) {
            $event->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);
            $this->audit($request, $event, 'agenda.cancel', ['from' => 'SCHEDULED', 'to' => 'CANCELLED', 'note' => "{$event->title} ({$reason})"]);
        });

        return response()->json($this->row($event->fresh(), $request));
    }

    // ------------------------------------------------------------------ Apoyo

    private function find(string $event): AgendaEvent
    {
        abort_unless(Str::isUuid($event), 404);

        return AgendaEvent::with('assignee:id,name', 'customer.party.person', 'customer.party.organization')->findOrFail($event);
    }

    private function eventData(Request $request, string $tenantId): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'event_type' => ['nullable', Rule::in(array_keys(AgendaEvent::TYPES))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'assigned_user_id' => ['nullable', 'uuid'],
            'customer_id' => ['nullable', 'uuid'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => 'El título es obligatorio.',
            'starts_at.required' => 'Indica cuándo empieza.',
            'ends_at.after' => 'El fin debe ser posterior al inicio.',
        ]);

        $start = Carbon::parse($data['starts_at']);
        $end = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : $start->copy()->addMinutes(AgendaEvent::DEFAULT_MINUTES);
        if ($end->greaterThan($start->copy()->addDay())) {
            throw ValidationException::withMessages(['ends_at' => ['Un evento dura como mucho 24 horas.']]);
        }

        $assignee = $data['assigned_user_id'] ?? $request->user()->id;
        if ($assignee !== $request->user()->id && ! Technicians::includes($tenantId, $assignee, 'agenda.view')) {
            throw ValidationException::withMessages(['assigned_user_id' => ['Esa persona no tiene acceso a la agenda de la empresa.']]);
        }

        $customerId = $data['customer_id'] ?? null;
        if ($customerId) {
            // Elegir un cliente pide poder verlos; uno de otra empresa "no existe".
            if (! CheckPermission::granted($request, 'core.view') || ! Customer::query()->where('tenant_id', $tenantId)->whereKey($customerId)->exists()) {
                throw ValidationException::withMessages(['customer_id' => ['El cliente indicado no existe.']]);
            }
        }

        return [
            'title' => trim($data['title']),
            'event_type' => $data['event_type'] ?? 'MEETING',
            'starts_at' => $start,
            'ends_at' => $end,
            'assigned_user_id' => $assignee,
            'customer_id' => $customerId,
            'location' => trim((string) ($data['location'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    /**
     * Avisa si la persona ya tiene otro evento o una orden de trabajo en ese horario. Con
     * `force`, quien agenda lo confirma y se guarda igual (queda anotado en auditoría).
     * Devuelve true si se guardó pese al cruce.
     */
    private function assertFree(Request $request, array $data, ?AgendaEvent $event): bool
    {
        $clash = AgendaClash::find($data['assigned_user_id'], $data['starts_at'], $data['ends_at'], exceptEvent: $event?->id);
        if (! $clash) {
            return false;
        }
        if ($request->boolean('force')) {
            return true;
        }

        throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
            'message' => "Conflicto de agenda con {$clash}.",
            'code' => 'AGENDA_CONFLICT',
            'errors' => ['starts_at' => ["Conflicto de agenda con {$clash}."]],
        ], 422));
    }

    private function assertScheduled(AgendaEvent $event, string $verb): void
    {
        if ($event->status !== 'SCHEDULED') {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un evento ".mb_strtolower(AgendaEvent::STATES[$event->status] ?? $event->status).'.'],
            ]);
        }
    }

    private function row(AgendaEvent $event, Request $request): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'event_type' => $event->event_type,
            'status' => $event->status,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'assignee' => $event->assignee ? ['id' => $event->assignee->id, 'name' => $event->assignee->name] : null,
            'customer' => $event->customer_id ? ['id' => $event->customer_id, 'name' => $this->customerName($event->customer)] : null,
            'location' => $event->location,
            'notes' => $event->notes,
            'cancel_reason' => $event->cancel_reason,
            'own' => $event->assigned_user_id === $request->user()->id,
            'lock_version' => (int) $event->lock_version,
        ];
    }

    private function customerName(?object $customer): ?string
    {
        $party = $customer?->party;

        return $party?->organization?->trade_name ?: $party?->organization?->legal_name
            ?: (trim(($party?->person?->given_names ?? '').' '.($party?->person?->family_name ?? '')) ?: null);
    }

    private function audit(Request $request, AgendaEvent $event, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'AgendaEvent',
            entityId: $event->id,
            metadata: $metadata,
        );
    }
}
