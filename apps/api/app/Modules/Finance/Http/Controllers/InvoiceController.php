<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\StoreInvoiceRequest;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Services\Models\Contract;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $invoice)
    {
        $invoice = Invoice::with('items')->findOrFail($invoice);

        return response()->json($invoice);
    }

    /**
     * Emite una factura interna a partir de un contrato activo, y genera
     * automáticamente su cuenta por cobrar en la misma transacción.
     *
     * NOTA: no incluye integración real con SUNAT (fiscal_document_id
     * queda null). Eso es un pendiente a definir con el equipo, igual
     * que Outbox y el escaneo de antivirus.
     */
    public function store(StoreInvoiceRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $contract = Contract::findOrFail($request->contract_id);

        if ($contract->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'contract_id' => ["Solo se puede facturar un contrato activo. Estado actual: {$contract->status}."],
            ]);
        }

        $invoice = DB::transaction(function () use ($request, $tenantId, $contract) {
            $invoiceNumber = SequentialNumberGenerator::next($tenantId, 'invoices', 'invoice_number', 'FAC');

            $subtotal = 0;
            $taxTotal = 0;

            $itemsData = collect($request->items)->map(function ($item) use (&$subtotal, &$taxTotal) {
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $taxRate = (float) ($item['tax_rate'] ?? 0);

                $lineBase = $quantity * $unitPrice;
                $taxAmount = round($lineBase * ($taxRate / 100), 4);
                $lineTotal = round($lineBase + $taxAmount, 4);

                $subtotal += $lineBase;
                $taxTotal += $taxAmount;

                return [
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                ];
            });

            $total = round($subtotal + $taxTotal, 4);

            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $contract->customer_id,
                'contract_id' => $contract->id,
                'invoice_number' => $invoiceNumber,
                'invoice_type' => 'INVOICE',
                'status' => 'ISSUED',
                'currency' => $contract->currency,
                'issue_date' => now()->toDateString(),
                'due_date' => $request->due_date,
                'subtotal' => round($subtotal, 4),
                'tax_total' => round($taxTotal, 4),
                'total' => $total,
                'balance_due' => $total,
            ]);

            foreach ($itemsData as $itemData) {
                $itemData['tenant_id'] = $tenantId;
                $itemData['invoice_id'] = $invoice->id;
                InvoiceItem::create($itemData);
            }

            // Generar automáticamente la cuenta por cobrar.
            Receivable::create([
                'tenant_id' => $tenantId,
                'customer_id' => $contract->customer_id,
                'invoice_id' => $invoice->id,
                'original_amount' => $total,
                'balance_amount' => $total,
                'due_date' => $request->due_date,
                'status' => 'OPEN',
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'invoice.issue',
                entityType: 'Invoice',
                entityId: $invoice->id,
                metadata: ['invoice_number' => $invoiceNumber, 'total' => (string) $total],
            );

            return $invoice;
        });

        return response()->json($invoice->load('items'), 201);
    }
}