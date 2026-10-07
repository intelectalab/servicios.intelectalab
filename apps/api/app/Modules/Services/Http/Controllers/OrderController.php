<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Services\Models\Order;
use App\Modules\Services\Models\OrderItem;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\QuotationItem;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Order::with(
            'customer.party.person',
            'customer.party.organization',
            'quotation:id,quotation_number',
            'contract:id,contract_number',
        )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por número de orden o por nombre del cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(function ($w) use ($like) {
                    $w->where('order_number', 'ILIKE', $like)
                        ->orWhereHas('customer.party.organization', fn ($o) => $o
                            ->where('legal_name', 'ILIKE', $like)
                            ->orWhere('trade_name', 'ILIKE', $like))
                        ->orWhereHas('customer.party.person', fn ($p) => $p
                            ->whereRaw("concat_ws(' ', given_names, family_name) ILIKE ?", [$like]));
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $order)
    {
        return response()->json($this->detail($this->find($order)));
    }

    // Secuencia lineal del prototipo: Borrador → Confirmada → En ejecución →
    // Completada. No se completa sin ejecutar ni se cancela una orden en ejecución.
    public function confirm(string $order, Request $request)
    {
        return $this->transition($request, $this->find($order), ['DRAFT'], 'CONFIRMED', 'confirmar', 'order.confirm');
    }

    public function start(string $order, Request $request)
    {
        return $this->transition($request, $this->find($order), ['CONFIRMED'], 'IN_PROGRESS', 'iniciar', 'order.start');
    }

    public function complete(string $order, Request $request)
    {
        return $this->transition($request, $this->find($order), ['IN_PROGRESS'], 'COMPLETED', 'completar', 'order.complete');
    }

    public function cancel(string $order, Request $request)
    {
        return $this->transition($request, $this->find($order), ['DRAFT', 'CONFIRMED'], 'CANCELLED', 'cancelar', 'order.cancel', true);
    }

    /**
     * Orden directa: una cotización aprobada se convierte en una orden en
     * borrador, sin pasar por contrato. Una sola vez por cotización y solo si
     * la empresa lo permite.
     */
    public function generateFromQuotation(string $quotation, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $quotation = Quotation::findOrFail($quotation);

        if ($quotation->status !== 'APPROVED') {
            throw ValidationException::withMessages([
                'status' => ['Solo una cotización APROBADA se puede convertir.'],
            ]);
        }
        if (! TenantSettingsController::settings($tenantId)['direct_order']) {
            throw ValidationException::withMessages([
                'status' => ['La configuración de la empresa no permite órdenes directas.'],
            ]);
        }
        if (Order::where('quotation_id', $quotation->id)->exists()) {
            throw ValidationException::withMessages([
                'quotation_id' => ['La cotización ya tiene una orden.'],
            ]);
        }

        $order = DB::transaction(function () use ($quotation, $tenantId, $request) {
            $number = SequentialNumberGenerator::next($tenantId, 'orders', 'order_number', 'ORD');

            $order = Order::create([
                'tenant_id' => $tenantId,
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'order_number' => $number,
                'status' => 'DRAFT',
                'order_date' => now()->toDateString(),
                'currency' => $quotation->currency,
                'total' => $quotation->total,
                'notes' => "Orden directa desde {$quotation->quotation_number}",
                'created_by' => $request->user()->id,
            ]);

            foreach (QuotationItem::where('quotation_id', $quotation->id)->get() as $item) {
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
                metadata: ['order_number' => $number, 'quotation_id' => $quotation->id, 'to' => 'DRAFT', 'note' => "Orden directa desde {$quotation->quotation_number}"],
            );

            return $order;
        });

        return response()->json($order->load('items'), 201);
    }

    private function find(string $order): Order
    {
        abort_unless(Str::isUuid($order), 404);

        return Order::findOrFail($order);
    }

    private function detail(Order $order): array
    {
        // La unidad sale del servicio cuando la línea se copió sin ella.
        $order->load(
            'items.service:id,unit',
            'customer.party.person',
            'customer.party.organization',
            'quotation:id,quotation_number',
            'contract:id,contract_number',
            'creator:id,name',
        );

        // Dirección del cliente (su contacto), para el PDF de la orden.
        $address = $order->customer
            ? DB::table('contacts')
                ->where('party_id', $order->customer->party_id)
                ->where('contact_type', 'ADDRESS')
                ->orderByDesc('is_primary')
                ->orderBy('created_at')
                ->value('value')
            : null;

        return [
            ...$order->toArray(),
            'customer_contact' => ['address' => $address],
            'history' => AuditHistory::for('Order', $order->id),
        ];
    }

    private function transition(Request $request, Order $order, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        if (! in_array($order->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una orden en estado {$order->status}."],
            ]);
        }

        $reason = null;
        if ($needsReason) {
            $reason = $request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason'];
        }

        $previous = $order->status;
        $order->update(['status' => $to]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Order',
            entityId: $order->id,
            metadata: array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]),
        );

        return response()->json($this->detail($order));
    }
}
