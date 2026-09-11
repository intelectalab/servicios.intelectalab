<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Http\Requests\StoreQuotationRequest;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\QuotationItem;
use App\Modules\Services\Models\Service;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    public function index(Request $request)
    {
        $query = Quotation::with('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $quotation)
    {
        $quotation = Quotation::findOrFail($quotation);

        return response()->json($quotation->load('items.service'));
    }

    public function store(StoreQuotationRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $quotation = DB::transaction(function () use ($request, $tenantId) {
            $quotationNumber = SequentialNumberGenerator::next(
                $tenantId,
                'quotations',
                'quotation_number',
                'COT'
            );

            $subtotal = 0;
            $taxTotal = 0;
            $discountTotal = 0;

            $itemsData = collect($request->items)->map(function ($item) use (&$subtotal, &$taxTotal, &$discountTotal, $tenantId) {
                $quantity = (float) $item['quantity'];
                $unitPrice = (float) $item['unit_price'];
                $discount = (float) ($item['discount'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? 0);

                $lineBase = ($quantity * $unitPrice) - $discount;
                $taxAmount = round($lineBase * ($taxRate / 100), 4);
                $lineTotal = round($lineBase + $taxAmount, 4);

                $subtotal += $lineBase;
                $taxTotal += $taxAmount;
                $discountTotal += $discount;

                $serviceSnapshot = null;
                if (!empty($item['service_id'])) {
                    $service = Service::find($item['service_id']);
                    if ($service) {
                        $serviceSnapshot = ['code' => $service->code, 'name' => $service->name];
                    }
                }

                return [
                    'tenant_id' => $tenantId,
                    'service_id' => $item['service_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'service_snapshot' => $serviceSnapshot,
                ];
            });

            $quotation = Quotation::create([
                'tenant_id' => $tenantId,
                'customer_id' => $request->customer_id,
                'quotation_number' => $quotationNumber,
                'status' => 'DRAFT',
                'currency' => $request->currency ?? 'PEN',
                'valid_until' => $request->valid_until,
                'subtotal' => round($subtotal, 4),
                'tax_total' => round($taxTotal, 4),
                'discount_total' => round($discountTotal, 4),
                'total' => round($subtotal + $taxTotal, 4),
                'notes' => $request->notes,
                'created_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $itemData) {
                $itemData['quotation_id'] = $quotation->id;
                QuotationItem::create($itemData);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'quotation.create',
                entityType: 'Quotation',
                entityId: $quotation->id,
                metadata: ['quotation_number' => $quotationNumber, 'total' => $quotation->total],
            );

            return $quotation;
        });

        return response()->json($quotation->load('items.service'), 201);
    }

    public function submit(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, 'DRAFT', 'enviar');

        $quotation->update(['status' => 'SUBMITTED']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'quotation.submit',
            entityType: 'Quotation',
            entityId: $quotation->id,
        );

        return response()->json($quotation);
    }

    public function approve(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, 'SUBMITTED', 'aprobar');

        $quotation->update(['status' => 'APPROVED', 'approved_at' => now()]);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'quotation.approve',
            entityType: 'Quotation',
            entityId: $quotation->id,
        );

        // Outbox pendiente aquí también: este es el punto exacto donde,
        // según la arquitectura, debería dispararse un evento
        // "quotation.approved". No lo invento sin definición confirmada.

        return response()->json($quotation);
    }

    public function reject(string $quotation, Request $request)
    {
        $quotation = Quotation::findOrFail($quotation);
        $this->assertStatus($quotation, 'SUBMITTED', 'rechazar');

        $quotation->update(['status' => 'REJECTED']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'quotation.reject',
            entityType: 'Quotation',
            entityId: $quotation->id,
        );

        return response()->json($quotation);
    }

    private function assertStatus(Quotation $quotation, string $expected, string $action): void
    {
        if ($quotation->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$action} una cotización en estado {$quotation->status}."],
            ]);
        }
    }
}