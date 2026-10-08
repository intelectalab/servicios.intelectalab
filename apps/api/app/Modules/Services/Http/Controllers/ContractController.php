<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\ContractItem;
use App\Modules\Services\Models\Order;
use App\Modules\Services\Models\OrderItem;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\QuotationItem;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    public const SLA_LEVELS = ['ESTANDAR', 'PREMIUM'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Contract::with('customer.party.person', 'customer.party.organization', 'quotation:id,quotation_number')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por número de contrato o por nombre del cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($like) {
                    $w->where('contract_number', 'ILIKE', $like)
                        ->orWhereHas('customer.party.organization', fn ($o) => $o
                            ->where('legal_name', 'ILIKE', $like)
                            ->orWhere('trade_name', 'ILIKE', $like))
                        ->orWhereHas('customer.party.person', fn ($p) => $p
                            ->whereRaw("concat_ws(' ', given_names, family_name) ILIKE ?", [$like]));
                });
            });

        ColumnFilters::apply($query, $request, [
            'number' => ColumnFilters::text('contracts.contract_number'),
            'customer' => ColumnFilters::text(ListSort::customerName('contracts')),
            'start_date' => ColumnFilters::date('contracts.start_date'),
            'total' => ColumnFilters::number('contracts.total_value'),
        ]);

        ListSort::apply($query, $request, [
            'number' => 'contracts.contract_number',
            'customer' => ListSort::customerName('contracts'),
            'start_date' => 'contracts.start_date',
            'sla' => ListSort::labels('contracts.sla_level', ['ESTANDAR' => 'Estándar', 'PREMIUM' => 'Premium']),
            'total' => 'contracts.total_value',
            'status' => ListSort::labels('contracts.status', [
                'DRAFT' => 'Borrador', 'ACTIVE' => 'Vigente', 'SUSPENDED' => 'Suspendido', 'TERMINATED' => 'Terminado', 'EXPIRED' => 'Vencido',
            ]),
        ], fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $contract)
    {
        return response()->json($this->detail($this->find($contract)));
    }

    public function generateFromQuotation(string $quotation, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $quotation = Quotation::findOrFail($quotation);

        if ($quotation->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'status' => ["Solo se puede generar un contrato desde una cotización aprobada. Estado actual: {$quotation->status}."],
            ]);
        }

        $existing = Contract::where('quotation_id', $quotation->id)->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'quotation_id' => ['Esta cotización ya tiene un contrato generado.'],
            ]);
        }

        $contract = DB::transaction(function () use ($quotation, $tenantId, $request) {
            $contractNumber = SequentialNumberGenerator::next(
                $tenantId,
                'contracts',
                'contract_number',
                'CTR'
            );

            // Como el prototipo: nace vigente desde mañana y por un año, con las
            // condiciones de la cotización; todo se puede ajustar mientras sea borrador.
            $contract = Contract::create([
                'tenant_id' => $tenantId,
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'contract_number' => $contractNumber,
                'status' => 'DRAFT',
                'currency' => $quotation->currency,
                'start_date' => $request->start_date ?? now()->addDay()->toDateString(),
                'end_date' => $request->end_date ?? now()->addDays(366)->toDateString(),
                'total_value' => $quotation->total,
                'terms' => $request->terms ?? $quotation->notes,
                'sla_level' => 'ESTANDAR',
                'created_by' => $request->user()->id,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'contract.generate',
                entityType: 'Contract',
                entityId: $contract->id,
                metadata: [
                    'contract_number' => $contractNumber,
                    'quotation_id' => $quotation->id,
                    'to' => 'DRAFT',
                    'note' => "Desde {$quotation->quotation_number}",
                ],
            );

            // Outbox pendiente aquí también: la generación de un contrato
            // es otro punto donde, según la arquitectura, se espera un
            // evento (para crear la orden de trabajo / servicio contratado
            // automáticamente). No lo invento sin definición confirmada.

            $quotationItems = QuotationItem::where('quotation_id', $quotation->id)->get();

            foreach ($quotationItems as $item) {
                ContractItem::create([
                    'tenant_id' => $tenantId,
                    'contract_id' => $contract->id,
                    'service_id' => $item->service_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'service_snapshot' => $item->service_snapshot,
                ]);
            }

            return $contract;
        });

        return response()->json($contract, 201);
    }

    /** Vigencia, nivel de servicio y condiciones: solo mientras el contrato está en borrador. */
    public function update(string $contract, Request $request)
    {
        $contract = $this->find($contract);
        $this->assertStatus($contract, ['DRAFT'], 'editar');

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'sla_level' => ['required', 'in:'.implode(',', self::SLA_LEVELS)],
            'terms' => ['nullable', 'string', 'max:5000'],
        ], [
            'start_date.required' => 'Indica la fecha de inicio.',
            'end_date.required' => 'Indica la fecha de fin.',
            'end_date.after' => 'El fin debe ser posterior al inicio.',
        ]);

        $before = ['start_date' => $contract->start_date?->toDateString(), 'end_date' => $contract->end_date?->toDateString(), 'sla_level' => $contract->sla_level];
        $contract->update($data);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'contract.update',
            entityType: 'Contract',
            entityId: $contract->id,
            beforeData: $before,
            afterData: ['start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'sla_level' => $data['sla_level']],
        );

        return response()->json($this->detail($contract));
    }

    /**
     * Borrador → Vigente. Exige líneas y fecha de inicio; opcionalmente genera un
     * servicio contratado por cada línea, que Operaciones recibe en PENDIENTE.
     */
    public function activate(string $contract, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $contract = $this->find($contract);
        $this->assertStatus($contract, ['DRAFT'], 'activar');

        $data = $request->validate(['generate_services' => ['nullable', 'boolean']]);

        if (! $contract->items()->exists() || ! $contract->start_date) {
            throw ValidationException::withMessages([
                'status' => ['El contrato activo requiere líneas y vigencia.'],
            ]);
        }

        $generated = DB::transaction(function () use ($contract, $tenantId, $data) {
            $contract->update(['status' => 'ACTIVE']);

            if (! ($data['generate_services'] ?? true)) {
                return 0;
            }

            $created = 0;
            foreach ($contract->items()->whereNotNull('service_id')->get() as $item) {
                DB::table('contracted_services')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'customer_id' => $contract->customer_id,
                    'contract_id' => $contract->id,
                    'service_id' => $item->service_id,
                    'status' => 'PENDING',
                    'start_date' => $contract->start_date,
                    'end_date' => $contract->end_date,
                    'quantity' => $item->quantity,
                    'price' => $item->unit_price,
                    'recurring' => ($item->service_snapshot['billing_mode'] ?? null) === 'RECURRING',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }

            return $created;
        });

        $this->audit($request, $contract, 'contract.activate', array_filter([
            'from' => 'DRAFT',
            'to' => 'ACTIVE',
            'note' => $generated ? "Servicios contratados generados: {$generated}" : null,
        ]));

        return response()->json($this->detail($contract));
    }

    public function suspend(string $contract, Request $request)
    {
        return $this->transition($request, $this->find($contract), ['ACTIVE'], 'SUSPENDED', 'suspender', 'contract.suspend', true);
    }

    public function reactivate(string $contract, Request $request)
    {
        return $this->transition($request, $this->find($contract), ['SUSPENDED'], 'ACTIVE', 'reactivar', 'contract.reactivate');
    }

    public function terminate(string $contract, Request $request)
    {
        return $this->transition($request, $this->find($contract), ['ACTIVE', 'SUSPENDED'], 'TERMINATED', 'terminar', 'contract.terminate', true);
    }

    public function expire(string $contract, Request $request)
    {
        return $this->transition($request, $this->find($contract), ['ACTIVE'], 'EXPIRED', 'marcar vencido', 'contract.expire');
    }

    /** Orden desde un contrato vigente, con sus líneas. Una por contrato. */
    public function createOrder(string $contract, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $contract = $this->find($contract);

        if ($contract->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => ['El contrato debe estar vigente.']]);
        }
        if (Order::where('contract_id', $contract->id)->exists()) {
            throw ValidationException::withMessages(['contract_id' => ['El contrato ya tiene una orden.']]);
        }

        $order = DB::transaction(function () use ($contract, $tenantId, $request) {
            $number = SequentialNumberGenerator::next($tenantId, 'orders', 'order_number', 'ORD');

            $order = Order::create([
                'tenant_id' => $tenantId,
                'customer_id' => $contract->customer_id,
                'contract_id' => $contract->id,
                'quotation_id' => $contract->quotation_id,
                'order_number' => $number,
                'status' => 'DRAFT',
                'order_date' => now()->toDateString(),
                'currency' => $contract->currency,
                'total' => $contract->total_value,
                'notes' => "Orden desde {$contract->contract_number}",
                'created_by' => $request->user()->id,
            ]);

            foreach ($contract->items as $item) {
                OrderItem::create([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'service_id' => $item->service_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'service_snapshot' => $item->service_snapshot,
                ]);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'order.generate',
                entityType: 'Order',
                entityId: $order->id,
                metadata: ['order_number' => $number, 'contract_id' => $contract->id, 'to' => 'DRAFT', 'note' => "Orden desde {$contract->contract_number}"],
            );

            return $order;
        });

        return response()->json($order->load('items'), 201);
    }

    private function find(string $contract): Contract
    {
        abort_unless(Str::isUuid($contract), 404);

        return Contract::findOrFail($contract);
    }

    private function detail(Contract $contract): array
    {
        $contract->load(
            'items',
            'customer.party.person',
            'customer.party.organization',
            'quotation:id,quotation_number',
            'creator:id,name',
            'orders:id,contract_id,order_number,status',
        );

        // Dirección del cliente (su contacto), para el PDF del contrato.
        $address = $contract->customer
            ? DB::table('contacts')
                ->where('party_id', $contract->customer->party_id)
                ->where('contact_type', 'ADDRESS')
                ->orderByDesc('is_primary')
                ->orderBy('created_at')
                ->value('value')
            : null;

        return [
            ...$contract->toArray(),
            'customer_contact' => ['address' => $address],
            'contracted_services' => DB::table('contracted_services')
                ->join('services', 'services.id', '=', 'contracted_services.service_id')
                ->where('contracted_services.contract_id', $contract->id)
                ->orderBy('contracted_services.created_at')
                ->get([
                    'contracted_services.id', 'contracted_services.status', 'contracted_services.quantity',
                    'contracted_services.recurring', 'services.code as service_code', 'services.name as service_name',
                    DB::raw('(SELECT count(*) FROM work_orders WHERE work_orders.contracted_service_id = contracted_services.id) AS work_orders_count'),
                    // Los proyectos aún no se enlazan a un servicio contratado (solo al contrato).
                    DB::raw('0 AS projects_count'),
                ]),
            // Nacen cuando Operaciones activa un servicio contratado recurrente.
            'recurring_instances' => DB::table('recurring_service_instances as ri')
                ->join('contracted_services as cs', 'cs.id', '=', 'ri.contracted_service_id')
                ->join('services', 'services.id', '=', 'cs.service_id')
                ->where('cs.contract_id', $contract->id)
                ->orderBy('ri.created_at')
                ->get([
                    'ri.id', 'ri.status', 'ri.frequency', 'ri.next_cycle_date', 'services.name as service_name',
                    DB::raw("COALESCE(
                        (SELECT p.amount FROM recurring_service_prices p
                          WHERE p.recurring_instance_id = ri.id AND p.valid_from <= CURRENT_DATE
                            AND (p.valid_to IS NULL OR p.valid_to >= CURRENT_DATE)
                          ORDER BY p.valid_from DESC LIMIT 1),
                        round(cs.price * cs.quantity * 1.18, 2)
                    ) AS amount"),
                ]),
            'history' => AuditHistory::for('Contract', $contract->id),
        ];
    }

    private function transition(Request $request, Contract $contract, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        $this->assertStatus($contract, $from, $verb);

        $reason = null;
        if ($needsReason) {
            $reason = $request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Indica el motivo.'],
            )['reason'];
        }

        $previous = $contract->status;
        DB::transaction(function () use ($contract, $to) {
            $contract->update(['status' => $to]);

            // Las instancias recurrentes siguen al contrato: se suspenden con él,
            // vuelven al reactivarlo y se cancelan si termina o vence.
            $instances = DB::table('recurring_service_instances')
                ->whereIn('contracted_service_id', DB::table('contracted_services')->where('contract_id', $contract->id)->select('id'));
            if ($to === 'ACTIVE') {
                $instances->where('status', 'SUSPENDED')->update(['status' => 'ACTIVE', 'suspended_at' => null, 'updated_at' => now()]);
            } elseif ($to === 'SUSPENDED') {
                $instances->where('status', 'ACTIVE')->update(['status' => 'SUSPENDED', 'suspended_at' => now(), 'updated_at' => now()]);
            } else {
                $instances->where('status', 'ACTIVE')->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            }
        });
        $this->audit($request, $contract, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));

        return response()->json($this->detail($contract));
    }

    private function audit(Request $request, Contract $contract, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Contract',
            entityId: $contract->id,
            metadata: $metadata,
        );
    }

    private function assertStatus(Contract $contract, array $expected, string $verb): void
    {
        if (! in_array($contract->status, $expected, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un contrato en estado {$contract->status}."],
            ]);
        }
    }
}
