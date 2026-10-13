<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Support\Billing;
use App\Modules\Services\Models\Contract;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Eventos facturables: lo que ya se puede cobrar. Los deja Operaciones al completar una orden de
 * trabajo que se cobra por evento, o Finanzas a mano. De los pendientes sale el comprobante.
 */
class BillableEventController extends Controller
{
    private const OPTIONS_LIMIT = 500;

    private const WITH = ['customer.party.person', 'customer.party.organization', 'invoice:id,invoice_number,status', 'contract:id,contract_number'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = BillableEvent::query()->with(self::WITH)
            ->when(isset(BillableEvent::STATES[$request->query('status')]), fn ($q) => $q->where('billable_events.status', $request->query('status')))
            ->when(isset(BillableEvent::SOURCES[$request->query('source')]), fn ($q) => $q->where('billable_events.source_type', $request->query('source')))
            ->when($search !== '', function ($q) use ($search) {
                // Por descripción o cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('billable_events.description', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('billable_events').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'event' => ColumnFilters::text('billable_events.description'),
            'customer' => ColumnFilters::text(ListSort::customerName('billable_events')),
            'date' => ColumnFilters::date('billable_events.created_at'),
            'base' => ColumnFilters::number('billable_events.base'),
            'total' => ColumnFilters::number('billable_events.amount'),
        ]);

        ListSort::apply($query, $request, [
            'event' => 'lower(billable_events.description)',
            'customer' => 'lower('.ListSort::customerName('billable_events').')',
            'date' => 'billable_events.created_at',
            'base' => 'billable_events.base',
            'total' => 'billable_events.amount',
            'status' => ListSort::labels('billable_events.status', BillableEvent::STATES),
        ], fn ($q) => $q->orderByDesc('billable_events.created_at')->orderByDesc('billable_events.id'), 'billable_events.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (BillableEvent $event) => $this->row($event));

        return response()->json($page);
    }

    /** Listas del formulario "Nuevo evento": clientes y, de ellos, sus contratos vigentes. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'customers' => Customer::query()->with('party.person', 'party.organization')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                ->map(function (Customer $customer) {
                    $identity = Billing::identity($customer);

                    return ['id' => $customer->id, 'name' => $identity['name'], 'tax_id' => $identity['tax_id'], 'currency' => $customer->preferred_currency];
                })
                ->filter(fn (array $customer) => $customer['name'] !== '')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            // Por su modelo: solo los contratos que la persona alcanza.
            'contracts' => Contract::query()->where('status', 'ACTIVE')->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'contract_number', 'customer_id', 'currency'])
                ->map(fn (Contract $contract) => [
                    'id' => $contract->id, 'contract_number' => $contract->contract_number,
                    'customer_id' => $contract->customer_id, 'currency' => $contract->currency,
                ])->values(),
        ]);
    }

    /** Evento registrado a mano por Finanzas (lo que no nace de una orden de trabajo). */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'contract_id' => ['nullable', 'uuid'],
            'description' => ['required', 'string', 'max:500'],
            'base' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', 'in:PEN,USD'],
        ], [
            'customer_id.required' => 'Elige el cliente.',
            'description.required' => 'Describe qué se va a facturar.',
            'base.required' => 'Indica el importe sin IGV.',
            'base.gt' => 'El importe debe ser mayor a cero.',
        ]);

        $customer = Customer::where('tenant_id', $tenantId)->find($data['customer_id']);
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => ['El cliente indicado no existe.']]);
        }

        // Por su modelo: un contrato fuera del alcance de la persona "no existe".
        $contract = ! empty($data['contract_id']) ? Contract::find($data['contract_id']) : null;
        if (! empty($data['contract_id']) && (! $contract || $contract->customer_id !== $customer->id)) {
            throw ValidationException::withMessages(['contract_id' => ['El contrato indicado no existe o no es de ese cliente.']]);
        }

        $event = DB::transaction(function () use ($request, $tenantId, $data, $customer, $contract) {
            $base = round((float) $data['base'], 2);
            $event = BillableEvent::create([
                'tenant_id' => $tenantId,
                'source_type' => 'MANUAL',
                'customer_id' => $customer->id,
                'contract_id' => $contract?->id,
                'description' => trim($data['description']),
                'base' => $base,
                'amount' => Billing::withTax($base),
                'currency' => $data['currency'],
                'status' => 'PENDING',
                'idempotency_key' => 'manual:'.Str::uuid(),
                'owner_user_id' => $contract?->created_by ?? $request->user()->id,
                'created_by' => $request->user()->id,
                // Del contrato, la sede; sin contrato, la de quien lo registra.
                ...($contract?->branch_id ? ['branch_id' => $contract->branch_id] : []),
            ]);
            $this->audit($request, $event, 'billable_event.create', ['to' => 'PENDING', 'source' => 'MANUAL', 'amount' => (float) $event->amount]);

            return $event;
        });

        return response()->json($this->row($event->load(self::WITH)), 201);
    }

    /** Anula un evento pendiente (se registró por error, no se va a cobrar). Uno facturado no se anula. */
    public function cancel(string $billableEvent, Request $request)
    {
        abort_unless(Str::isUuid($billableEvent), 404);
        $event = BillableEvent::findOrFail($billableEvent);

        if ($event->status !== 'PENDING') {
            throw ValidationException::withMessages([
                'status' => ['No se puede anular un evento '.mb_strtolower(BillableEvent::STATES[$event->status] ?? $event->status).'.'],
            ]);
        }
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Debes indicar un motivo.'],
        )['reason']);

        DB::transaction(function () use ($request, $event, $reason) {
            $event->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);
            $this->audit($request, $event, 'billable_event.cancel', ['from' => 'PENDING', 'to' => 'CANCELLED', 'note' => $reason]);
        });

        return response()->json($this->row($event->load(self::WITH)));
    }

    private function row(BillableEvent $event): array
    {
        return [
            'id' => $event->id,
            'description' => $event->description,
            'source_type' => $event->source_type,
            'source_id' => $event->source_id,
            'idempotency_key' => $event->idempotency_key,
            'customer' => $event->customer,
            'base' => (float) $event->base,
            'amount' => (float) $event->amount,
            'currency' => $event->currency,
            'status' => $event->status,
            'cancel_reason' => $event->cancel_reason,
            'invoice' => $event->invoice ? ['id' => $event->invoice->id, 'invoice_number' => $event->invoice->invoice_number, 'status' => $event->invoice->status] : null,
            // null si el contrato queda fuera del alcance de quien mira.
            'contract' => $event->contract ? ['id' => $event->contract->id, 'contract_number' => $event->contract->contract_number] : null,
            'created_at' => $event->created_at?->toIso8601String(),
            'lock_version' => (int) $event->lock_version,
        ];
    }

    private function audit(Request $request, BillableEvent $event, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'BillableEvent',
            entityId: $event->id,
            metadata: $metadata,
        );
    }
}
