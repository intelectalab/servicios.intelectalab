<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Services\Models\Order;
use App\Modules\Services\Models\OrderItem;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\QuotationItem;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
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
                metadata: ['order_number' => $number, 'quotation_id' => $quotation->id, 'note' => "Orden directa desde {$quotation->quotation_number}"],
            );

            return $order;
        });

        return response()->json($order->load('items'), 201);
    }
}
