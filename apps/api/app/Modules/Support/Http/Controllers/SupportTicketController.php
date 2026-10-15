<?php

namespace App\Modules\Support\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Support\Billing;
use App\Modules\Operations\Models\Checklist;
use App\Modules\Operations\Models\ContractedService;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Operations\Support\Technicians;
use App\Modules\Operations\Support\WorkOrders;
use App\Modules\Services\Models\Contract;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketComment;
use App\Modules\Support\Models\WarrantyClaim;
use App\Modules\Support\Support\SlaCalendar;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tickets de soporte: Ticket → Triage (prioridad y SLA) → Asignación → Atención → Esperando
 * cliente (pausa el reloj) → Resolución → Cierre / Reapertura. El SLA se calcula al clasificar,
 * con la política de la prioridad, el calendario laboral de la empresa y el nivel del contrato
 * (Premium reduce los tiempos).
 */
class SupportTicketController extends Controller
{
    /** Clientes, contratos y servicios que se ofrecen en el formulario; con más, se acota con el buscador. */
    private const OPTIONS_LIMIT = 500;

    /** Quién puede llevar un ticket: quien tiene permiso de atenderlos. */
    private const AGENT_PERMISSION = 'tickets.manage';

    private const PRIORITY_ORDER = ['P4', 'P3', 'P2', 'P1'];

    private const ASSIGNEE_NAME = '(SELECT u.name FROM users u WHERE u.id = support_tickets.assigned_user_id)';

    public function index(Request $request)
    {
        $query = $this->filtered($request);

        ColumnFilters::apply($query, $request, [
            'ticket' => ColumnFilters::text("concat_ws(' ', support_tickets.ticket_number, support_tickets.subject)"),
            'customer' => ColumnFilters::text(ListSort::customerName('support_tickets')),
            'opened' => ColumnFilters::date('support_tickets.opened_at'),
        ]);

        ListSort::apply($query, $request, [
            'ticket' => 'support_tickets.ticket_number',
            'customer' => ListSort::customerName('support_tickets'),
            'priority' => "COALESCE(support_tickets.priority, 'P9')",
            'sla' => 'support_tickets.resolution_due_at',
            'assignee' => 'lower('.self::ASSIGNEE_NAME.')',
            'status' => ListSort::labels('support_tickets.status', SupportTicket::STATES),
            'opened' => 'support_tickets.opened_at',
        ], fn ($q) => $q->orderByRaw("COALESCE(support_tickets.priority, 'P9')")->orderByDesc('support_tickets.opened_at'), 'support_tickets.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (SupportTicket $ticket) => $this->row($ticket));

        return response()->json($page);
    }

    /** Indicadores del prototipo, sobre los tickets que la persona alcanza (sin los cerrados). */
    public function summary()
    {
        $row = SupportTicket::query()
            ->where('support_tickets.status', '<>', 'CLOSED')
            ->toBase()
            ->selectRaw("count(*) FILTER (WHERE status <> 'RESOLVED') AS open")
            ->selectRaw("count(*) FILTER (WHERE status <> 'RESOLVED' AND (".SupportTicket::SLA_SQL.") = 'breached') AS breached")
            ->selectRaw("count(*) FILTER (WHERE (".SupportTicket::SLA_SQL.") = 'risk') AS risk")
            ->selectRaw("count(*) FILTER (WHERE status = 'RESOLVED') AS resolved")
            ->first();

        return response()->json([
            'open' => (int) $row->open, 'breached' => (int) $row->breached, 'risk' => (int) $row->risk, 'resolved' => (int) $row->resolved,
        ]);
    }

    /** Listas del formulario y de las acciones: clientes, contratos vigentes, servicios, responsables y políticas. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'customers' => Customer::query()->with('party.person', 'party.organization')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => Billing::identity($customer)['name']])
                ->filter(fn (array $customer) => $customer['name'] !== '')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            // Por su modelo: solo los contratos que la persona alcanza.
            'contracts' => Contract::query()->where('status', 'ACTIVE')->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'contract_number', 'customer_id', 'sla_level'])
                ->map(fn (Contract $contract) => [
                    'id' => $contract->id, 'number' => $contract->contract_number, 'customer_id' => $contract->customer_id, 'sla_level' => $contract->sla_level,
                ]),
            'services' => DB::table('services')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('name')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'code', 'name']),
            'agents' => Technicians::list($tenantId, self::AGENT_PERMISSION),
            'policies' => $this->policies($tenantId),
        ]);
    }

    public function show(string $ticket)
    {
        return response()->json($this->detail($this->find($ticket)));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'service_id' => ['nullable', 'uuid'],
            'channel' => ['nullable', 'in:PHONE,EMAIL,BACKOFFICE'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ], [
            'customer_id.required' => 'Elige el cliente.',
            'subject.required' => 'El asunto es obligatorio.',
        ]);

        // S9-R-001: el ticket es de un cliente válido de la empresa.
        $customer = Customer::where('tenant_id', $tenantId)->whereKey($data['customer_id'])->where('status', 'ACTIVE')->first();
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => ['El ticket debe asociarse a un cliente activo de la empresa.']]);
        }

        // El contrato: el indicado (del mismo cliente) o el vigente más reciente del cliente.
        $contract = ! empty($data['contract_id'])
            ? DB::table('contracts')->where('tenant_id', $tenantId)->where('id', $data['contract_id'])->first(['id', 'customer_id', 'branch_id'])
            : DB::table('contracts')->where('tenant_id', $tenantId)->where('customer_id', $customer->id)->where('status', 'ACTIVE')
                ->orderByDesc('created_at')->first(['id', 'customer_id', 'branch_id']);
        if (! empty($data['contract_id']) && (! $contract || $contract->customer_id !== $customer->id)) {
            throw ValidationException::withMessages(['contract_id' => ['El contrato indicado no es de ese cliente.']]);
        }
        if (! empty($data['service_id']) && ! DB::table('services')->where('tenant_id', $tenantId)->where('id', $data['service_id'])->exists()) {
            throw ValidationException::withMessages(['service_id' => ['El servicio indicado no existe.']]);
        }
        // Sin servicio elegido: el primero del contrato.
        $serviceId = $data['service_id'] ?? ($contract
            ? DB::table('contracted_services')->where('contract_id', $contract->id)->orderBy('created_at')->value('service_id')
            : null);

        $ticket = DB::transaction(function () use ($request, $tenantId, $data, $customer, $contract, $serviceId) {
            $user = $request->user();
            $ticket = SupportTicket::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'contract_id' => $contract?->id,
                'service_id' => $serviceId,
                'ticket_number' => SequentialNumberGenerator::next($tenantId, 'support_tickets', 'ticket_number', 'TKT'),
                'subject' => trim($data['subject']),
                'description' => $this->text($data['description'] ?? null),
                'channel' => $data['channel'] ?? 'BACKOFFICE',
                'category' => $data['category'] ?? 'INCIDENT',
                'status' => 'OPEN',
                'opened_at' => now(),
                'created_by' => $user->id,
                'team_id' => $this->teamOf($tenantId, $user->id),
                // El ticket nace en la sede del contrato; si no tiene, en la de quien lo registra.
                ...($contract?->branch_id ? ['branch_id' => $contract->branch_id] : []),
            ]);

            // Como en el prototipo, la descripción abre la conversación.
            if ($ticket->description) {
                SupportTicketComment::create([
                    'tenant_id' => $tenantId, 'ticket_id' => $ticket->id, 'author_user_id' => $user->id,
                    'author_type' => 'STAFF', 'body' => $ticket->description, 'internal' => false,
                ]);
            }

            $this->audit($request, $ticket, 'ticket.create', ['to' => 'OPEN', 'number' => $ticket->ticket_number, 'channel' => $ticket->channel]);

            return $ticket;
        });

        return response()->json($this->detail($ticket), 201);
    }

    /** Triage: prioridad → SLA según la política, el calendario y el nivel del contrato (S9-R-004). */
    public function triage(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket);
        $data = $request->validate([
            'priority' => ['required', 'in:'.implode(',', array_keys(SlaCalendar::PRIORITIES))],
            'category' => ['nullable', 'in:'.implode(',', array_keys(SupportTicket::CATEGORIES))],
        ], ['priority.required' => 'Selecciona la prioridad.']);

        if (! in_array($ticket->status, ['OPEN', 'TRIAGED', 'REOPENED'], true)) {
            throw ValidationException::withMessages(['status' => ['El ticket ya fue clasificado y está en atención.']]);
        }

        DB::transaction(function () use ($request, $ticket, $data) {
            $from = $ticket->status;
            $before = $ticket->priority;
            $ticket->fill([
                'priority' => $data['priority'],
                'category' => $data['category'] ?? $ticket->category,
                'status' => 'TRIAGED',
                'triaged_at' => now(),
                ...$this->sla($request, $ticket, $data['priority'], $ticket->sla_started_at ?? $ticket->opened_at),
            ])->save();

            $note = "Prioridad {$data['priority']}".($ticket->sla_factor < 1 ? ' · SLA Premium' : '');
            $this->audit($request, $ticket, 'ticket.triage', array_filter([
                'from' => $from, 'to' => 'TRIAGED', 'priority' => $data['priority'], 'previous_priority' => $before,
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(), 'note' => $note,
            ]));
        });

        return response()->json($this->detail($ticket));
    }

    public function assign(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket);
        $data = $request->validate(['assigned_user_id' => ['required', 'uuid']], ['assigned_user_id.required' => 'Elige el responsable.']);
        $tenantId = $request->attributes->get('tenant_id');

        if ($ticket->status === 'OPEN') {
            throw ValidationException::withMessages(['status' => ['Clasifica el ticket (triage) antes de asignarlo.']]);
        }
        if ($ticket->status === 'CLOSED') {
            throw ValidationException::withMessages(['status' => ['Un ticket cerrado no se reasigna.']]);
        }
        if (! Technicians::includes($tenantId, $data['assigned_user_id'], self::AGENT_PERMISSION)) {
            throw ValidationException::withMessages(['assigned_user_id' => ['La persona indicada no puede atender tickets en esta empresa.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $ticket, $data) {
            $from = $ticket->status;
            $reassigned = $ticket->assigned_user_id && $ticket->assigned_user_id !== $data['assigned_user_id'];
            $to = $from === 'TRIAGED' ? 'ASSIGNED' : $from;
            $ticket->update([
                'assigned_user_id' => $data['assigned_user_id'],
                'team_id' => $this->teamOf($tenantId, $data['assigned_user_id']) ?? $ticket->team_id,
                'status' => $to,
            ]);
            $this->audit($request, $ticket, 'ticket.assign', array_filter([
                'from' => $from === $to ? null : $from, 'to' => $from === $to ? null : $to,
                'assigned_user_id' => $data['assigned_user_id'],
                'note' => ($reassigned ? 'Reasignado a ' : 'Asignado a ').$this->userName($data['assigned_user_id']),
            ]));
        });

        return response()->json($this->detail($ticket));
    }

    /** Respuesta al cliente o nota interna (las notas internas nunca salen al portal). */
    public function comment(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
        ], ['body.required' => 'Escribe un comentario.']);

        if ($ticket->status === 'CLOSED') {
            throw ValidationException::withMessages(['status' => ['Un ticket cerrado no recibe mensajes: reábrelo si hace falta.']]);
        }
        $internal = (bool) ($data['internal'] ?? false);
        if (trim($data['body']) === '') {
            throw ValidationException::withMessages(['body' => ['Escribe un comentario.']]);
        }

        DB::transaction(function () use ($request, $ticket, $data, $internal) {
            SupportTicketComment::create([
                'tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id, 'author_user_id' => $request->user()->id,
                'author_type' => 'STAFF', 'body' => trim($data['body']), 'internal' => $internal,
            ]);
            // La primera respuesta pública cumple el SLA de respuesta.
            if (! $internal && $ticket->resolution_due_at && ! $ticket->first_response_at) {
                $ticket->update(['first_response_at' => now()]);
            }
            $this->audit($request, $ticket, $internal ? 'ticket.comment.internal' : 'ticket.comment.public', ['length' => mb_strlen(trim($data['body']))]);
        });

        return response()->json($this->detail($ticket), 201);
    }

    /** Sube una prioridad y recalcula el vencimiento de la resolución desde ahora. */
    public function escalate(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket);
        if (! $ticket->priority) {
            throw ValidationException::withMessages(['priority' => ['Clasifica primero el ticket.']]);
        }
        if ($ticket->priority === 'P1') {
            throw ValidationException::withMessages(['priority' => ['El ticket ya tiene la prioridad máxima.']]);
        }
        if (in_array($ticket->status, ['RESOLVED', 'CLOSED'], true)) {
            throw ValidationException::withMessages(['status' => ['Un ticket resuelto o cerrado no se escala.']]);
        }

        DB::transaction(function () use ($request, $ticket) {
            $before = $ticket->priority;
            $priority = self::PRIORITY_ORDER[array_search($before, self::PRIORITY_ORDER, true) + 1];
            $sla = $this->sla($request, $ticket, $priority, now());
            $ticket->update([
                'priority' => $priority,
                'sla_calendar_mode' => $sla['sla_calendar_mode'],
                'sla_resolution_minutes' => $sla['sla_resolution_minutes'],
                'resolution_due_at' => $sla['resolution_due_at'],
            ]);
            $this->audit($request, $ticket, 'ticket.escalate', [
                'priority' => $priority, 'previous_priority' => $before,
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(), 'note' => "Escalado {$before} → {$priority}",
            ]);
        });

        return response()->json($this->detail($ticket));
    }

    public function start(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket);
        if (! $ticket->assigned_user_id) {
            throw ValidationException::withMessages(['assigned_user_id' => ['Asigna el ticket antes de atenderlo.']]);
        }
        if ($ticket->status === 'REOPENED' && ! $ticket->resolution_due_at) {
            throw ValidationException::withMessages(['priority' => ['Clasifica el ticket (triage) para calcular su SLA antes de atenderlo.']]);
        }

        return $this->transition($request, $ticket, ['ASSIGNED', 'REOPENED'], 'IN_PROGRESS', 'atender', 'ticket.start');
    }

    /** "Esperando cliente" pausa el reloj (RN-SUP-03). */
    public function wait(string $ticket, Request $request)
    {
        return $this->transition($request, $this->find($ticket), ['IN_PROGRESS'], 'WAITING_CUSTOMER', 'pasar a esperando cliente', 'ticket.wait');
    }

    /** Retomar: el tiempo en pausa se suma a los vencimientos. */
    public function resume(string $ticket, Request $request)
    {
        return $this->transition($request, $this->find($ticket), ['WAITING_CUSTOMER'], 'IN_PROGRESS', 'retomar', 'ticket.resume');
    }

    public function resolve(string $ticket, Request $request)
    {
        return $this->transition($request, $this->find($ticket), ['IN_PROGRESS'], 'RESOLVED', 'resolver', 'ticket.resolve', 'resolution');
    }

    public function close(string $ticket, Request $request)
    {
        return $this->transition($request, $this->find($ticket), ['RESOLVED'], 'CLOSED', 'cerrar', 'ticket.close');
    }

    /** Reabrir pide motivo y recalcula el vencimiento desde ahora (RN-SUP-04). */
    public function reopen(string $ticket, Request $request)
    {
        return $this->transition($request, $this->find($ticket), ['RESOLVED', 'CLOSED'], 'REOPENED', 'reabrir', 'ticket.reopen', 'reopen_reason');
    }

    /** Crear OT desde el ticket: sobre un servicio contratado activo del cliente, una por ticket. */
    public function createWorkOrder(string $ticket, Request $request)
    {
        $ticket = $this->find($ticket);
        $data = $request->validate(['contracted_service_id' => ['nullable', 'uuid']]);

        if ($ticket->work_order_id) {
            throw ValidationException::withMessages(['work_order' => ['El ticket ya tiene una orden de trabajo.']]);
        }
        if (in_array($ticket->status, ['OPEN', 'CLOSED'], true)) {
            throw ValidationException::withMessages(['status' => [$ticket->status === 'OPEN' ? 'Clasifica el ticket antes de crear su orden de trabajo.' : 'Un ticket cerrado no genera órdenes de trabajo.']]);
        }

        // Por su modelo: solo un servicio contratado que la persona alcanza.
        $service = ! empty($data['contracted_service_id'])
            ? ContractedService::where('customer_id', $ticket->customer_id)->where('status', 'ACTIVE')->find($data['contracted_service_id'])
            : $this->contractedServicesFor($ticket)->first();
        if (! $service) {
            throw ValidationException::withMessages(['contracted_service_id' => ['El cliente no tiene un servicio contratado activo para atender el ticket.']]);
        }

        // La plantilla del servicio, si la tiene (como al crear la OT a mano).
        $template = Checklist::with('items')->where('active', true)->where('service_id', $service->service_id)->orderBy('name')->first();

        $order = DB::transaction(function () use ($request, $ticket, $service, $template) {
            $order = WorkOrders::create($request, $service, $template, [
                'title' => Str::limit("Atención ticket {$ticket->ticket_number}: {$ticket->subject}", 250, ''),
                'priority' => in_array($ticket->priority, ['P1', 'P2'], true) ? 'HIGH' : 'NORMAL',
                'description' => $ticket->description,
            ], ['note' => "Desde el ticket {$ticket->ticket_number}"]);

            $ticket->update(['work_order_id' => $order->id]);
            $this->audit($request, $ticket, 'ticket.work_order', ['work_order_id' => $order->id, 'note' => "Orden de trabajo {$order->code}"]);

            return $order;
        });

        return response()->json(['work_order' => ['id' => $order->id, 'code' => $order->code], 'ticket' => $this->detail($ticket)], 201);
    }

    // ------------------------------------------------------------------ piezas

    private function find(string $ticket): SupportTicket
    {
        abort_unless(Str::isUuid($ticket), 404);

        return SupportTicket::findOrFail($ticket);
    }

    /** El listado con los filtros de arriba (también lo usa exportar). */
    private function filtered(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $priority = (string) $request->query('priority', '');
        $sla = (string) $request->query('sla', '');

        return $this->listing()
            ->when($status === 'OPEN_ALL', fn ($q) => $q->whereIn('support_tickets.status', SupportTicket::UNRESOLVED))
            ->when(isset(SupportTicket::STATES[$status]), fn ($q) => $q->where('support_tickets.status', $status))
            ->when(isset(SlaCalendar::PRIORITIES[$priority]), fn ($q) => $q->where('support_tickets.priority', $priority))
            ->when($priority === 'none', fn ($q) => $q->whereNull('support_tickets.priority'))
            ->when(isset(SupportTicket::SLA_STATES[$sla]), fn ($q) => $q->whereRaw('('.SupportTicket::SLA_SQL.') = ?', [$sla]))
            ->when($search !== '', function ($q) use ($search) {
                // Por número, asunto o nombre del cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('support_tickets.ticket_number', 'ILIKE', $like)
                    ->orWhere('support_tickets.subject', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('support_tickets').' ILIKE ?', [$like]));
            });
    }

    /** Con lo que la tabla y el tablero muestran en cada fila, para no pedir nada fila por fila. */
    private function listing()
    {
        return SupportTicket::query()
            ->select('support_tickets.*')
            ->selectRaw('('.SupportTicket::SLA_SQL.') AS sla_status')
            ->with('customer.party.person', 'customer.party.organization', 'assignee:id,name');
    }

    private function row(SupportTicket $ticket): array
    {
        $sla = $ticket->sla_status ?? DB::table('support_tickets')->where('id', $ticket->id)->selectRaw('('.SupportTicket::SLA_SQL.') AS s')->value('s');

        return [
            'id' => $ticket->id,
            'number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'channel' => $ticket->channel,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'customer' => $ticket->customer,
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => $ticket->assignee->name] : null,
            'sla' => [
                'status' => $sla,
                'started_at' => ($ticket->sla_started_at ?? $ticket->opened_at)?->toIso8601String(),
                'response_due_at' => $ticket->response_due_at?->toIso8601String(),
                'resolution_due_at' => $ticket->resolution_due_at?->toIso8601String(),
                'first_response_at' => $ticket->first_response_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                'paused_at' => $ticket->paused_at?->toIso8601String(),
                'paused_seconds' => (int) $ticket->paused_seconds,
            ],
            'opened_at' => $ticket->opened_at?->toIso8601String(),
            'lock_version' => (int) $ticket->lock_version,
        ];
    }

    private function detail(SupportTicket $ticket): array
    {
        $ticket = $this->listing()->with('service:id,code,name', 'creator:id,name', 'comments.author:id,name')->findOrFail($ticket->id);
        $tenantId = $ticket->tenant_id;

        // El contrato se lee aunque la persona no alcance contratos: su número y su nivel de SLA son datos del ticket.
        $contract = $ticket->contract_id ? DB::table('contracts')->where('id', $ticket->contract_id)->first(['id', 'contract_number', 'sla_level']) : null;
        $policy = $ticket->priority ? ($this->policies($tenantId)[$ticket->priority] ?? null) : null;
        $order = $ticket->work_order_id ? DB::table('work_orders')->where('id', $ticket->work_order_id)->first(['id', 'code', 'status']) : null;
        $claim = WarrantyClaim::ignoringAccessScope()->where('ticket_id', $ticket->id)->first(['id', 'claim_number', 'status']);

        return [
            ...$this->row($ticket),
            'description' => $ticket->description,
            'resolution' => $ticket->resolution,
            'reopen_reason' => $ticket->reopen_reason,
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'triaged_at' => $ticket->triaged_at?->toIso8601String(),
            'contract' => $contract ? [
                'id' => $contract->id, 'number' => $contract->contract_number, 'sla_level' => $contract->sla_level,
                // Enlace a la ficha solo si la persona alcanza el contrato.
                'visible' => Contract::whereKey($contract->id)->exists(),
            ] : null,
            'service' => $ticket->service ? ['id' => $ticket->service->id, 'code' => $ticket->service->code, 'name' => $ticket->service->name] : null,
            'team' => $ticket->team_id ? DB::table('teams')->where('id', $ticket->team_id)->value('name') : null,
            'creator' => $ticket->creator?->name,
            'policy' => $policy ? [
                ...$policy,
                'factor' => $ticket->sla_factor !== null ? (float) $ticket->sla_factor : 1.0,
                'mode' => $ticket->sla_calendar_mode ?? $policy['mode'],
                'applied_response_minutes' => $ticket->sla_response_minutes,
                'applied_resolution_minutes' => $ticket->sla_resolution_minutes,
            ] : null,
            'work_order' => $order ? ['id' => $order->id, 'code' => $order->code, 'status' => $order->status, 'visible' => WorkOrder::whereKey($order->id)->exists()] : null,
            'claim' => $claim ? ['id' => $claim->id, 'number' => $claim->claim_number, 'status' => $claim->status] : null,
            // Servicios contratados activos del cliente, para "Crear OT".
            'contracted_services' => $this->contractedServicesFor($ticket)->map(fn (ContractedService $service) => [
                'id' => $service->id, 'service' => $service->service?->name, 'contract' => $service->contract?->contract_number,
            ])->values(),
            'comments' => $ticket->comments->map(fn (SupportTicketComment $comment) => [
                'id' => $comment->id,
                'author' => $comment->author?->name,
                'author_type' => $comment->author_type,
                'internal' => (bool) $comment->internal,
                'body' => $comment->body,
                'at' => $comment->created_at?->toIso8601String(),
            ])->values(),
            'history' => AuditHistory::for('SupportTicket', $ticket->id),
        ];
    }

    /** Servicios contratados activos del cliente del ticket; primero los de su contrato y su servicio. */
    private function contractedServicesFor(SupportTicket $ticket)
    {
        return ContractedService::query()
            ->with('service:id,name', 'contract:id,contract_number')
            ->where('customer_id', $ticket->customer_id)
            ->where('status', 'ACTIVE')
            ->orderByRaw('CASE WHEN contract_id = ? THEN 0 ELSE 1 END', [$ticket->contract_id ?? '00000000-0000-0000-0000-000000000000'])
            ->orderByRaw('CASE WHEN service_id = ? THEN 0 ELSE 1 END', [$ticket->service_id ?? '00000000-0000-0000-0000-000000000000'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /** @return array<string, array{priority: string, label: string, response_minutes: int, resolution_minutes: int, mode: string}> */
    private function policies(string $tenantId): array
    {
        return SlaCalendar::policies($tenantId)->map(fn ($policy) => [
            'priority' => $policy->priority,
            'label' => $policy->name,
            'response_minutes' => (int) $policy->response_minutes,
            'resolution_minutes' => (int) $policy->resolution_minutes,
            'mode' => $policy->calendar_mode,
        ])->all();
    }

    /** Vencimientos del SLA desde `$start` para una prioridad, con el factor del contrato. */
    private function sla(Request $request, SupportTicket $ticket, string $priority, Carbon $start): array
    {
        $tenantId = $request->attributes->get('tenant_id');
        $clock = SlaCalendar::for($tenantId);
        $policy = SlaCalendar::policies($tenantId)[$priority];
        $level = $ticket->contract_id ? DB::table('contracts')->where('id', $ticket->contract_id)->value('sla_level') : null;
        $factor = $clock->factorFor($level);
        $response = max(1, (int) round($policy->response_minutes * $factor));
        $resolution = max(1, (int) round($policy->resolution_minutes * $factor));

        return [
            'sla_started_at' => $start,
            'sla_calendar_mode' => $policy->calendar_mode,
            'sla_factor' => $factor,
            'sla_response_minutes' => $response,
            'sla_resolution_minutes' => $resolution,
            'response_due_at' => $clock->add($start, $response, $policy->calendar_mode),
            'resolution_due_at' => $clock->add($start, $resolution, $policy->calendar_mode),
        ];
    }

    private function transition(Request $request, SupportTicket $ticket, array $from, string $to, string $verb, string $action, ?string $reasonColumn = null)
    {
        if (! in_array($ticket->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un ticket ".mb_strtolower(SupportTicket::STATES[$ticket->status] ?? $ticket->status).'.'],
            ]);
        }

        $reason = null;
        if ($reasonColumn) {
            $label = $reasonColumn === 'resolution' ? 'Escribe la resolución.' : 'Debes indicar un motivo.';
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:2000']], ['reason.required' => $label])['reason']);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => [$label]]);
            }
        }

        DB::transaction(function () use ($request, $ticket, $to, $action, $reason, $reasonColumn) {
            $previous = $ticket->status;
            $now = now();
            $changes = ['status' => $to, ...($reasonColumn ? [$reasonColumn => $reason] : [])];

            if ($to === 'IN_PROGRESS' && $ticket->resolution_due_at && ! $ticket->first_response_at) {
                $changes['first_response_at'] = $now;
            }
            if ($to === 'WAITING_CUSTOMER') {
                $changes['paused_at'] = $now;
            }
            if ($previous === 'WAITING_CUSTOMER' && $ticket->paused_at) {
                // El tiempo en pausa se suma a los vencimientos, en el mismo calendario del SLA.
                $mode = $ticket->sla_calendar_mode ?? '24X7';
                $clock = SlaCalendar::for($ticket->tenant_id);
                $paused = $clock->between($ticket->paused_at, $now, $mode);
                $changes['paused_at'] = null;
                $changes['paused_seconds'] = (int) $ticket->paused_seconds + max(0, (int) $ticket->paused_at->diffInSeconds($now));
                if ($ticket->resolution_due_at) {
                    $changes['resolution_due_at'] = $clock->add($ticket->resolution_due_at, $paused, $mode);
                }
                if ($ticket->response_due_at && ! $ticket->first_response_at) {
                    $changes['response_due_at'] = $clock->add($ticket->response_due_at, $paused, $mode);
                }
            }
            if ($to === 'RESOLVED') {
                $changes['resolved_at'] = $now;
            }
            if ($to === 'CLOSED') {
                $changes['closed_at'] = $now;
            }
            if ($to === 'REOPENED') {
                $changes += ['resolved_at' => null, 'closed_at' => null];
                // Reabrir recalcula el vencimiento desde ahora, con el tiempo de resolución que ya tenía.
                if ($ticket->sla_resolution_minutes && $ticket->sla_calendar_mode) {
                    $changes['sla_started_at'] = $now;
                    $changes['resolution_due_at'] = SlaCalendar::for($ticket->tenant_id)->add($now, $ticket->sla_resolution_minutes, $ticket->sla_calendar_mode);
                }
            }

            $ticket->update($changes);
            $this->audit($request, $ticket, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));
        });

        return response()->json($this->detail($ticket));
    }

    private function teamOf(string $tenantId, string $userId): ?string
    {
        return DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $userId)->value('team_id');
    }

    private function text(?string $value): ?string
    {
        return trim((string) $value) ?: null;
    }

    private function userName(?string $userId): ?string
    {
        return $userId ? DB::table('users')->where('id', $userId)->value('name') : null;
    }

    private function audit(Request $request, SupportTicket $ticket, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'SupportTicket',
            entityId: $ticket->id,
            metadata: $metadata,
        );
    }
}
