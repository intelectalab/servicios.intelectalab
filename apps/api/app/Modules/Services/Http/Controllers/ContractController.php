<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Models\Contract;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\ContractItem;
use App\Modules\Services\Models\QuotationItem;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $query = Contract::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $contract)
    {
        $contract = Contract::with('quotation')->findOrFail($contract);

        return response()->json($contract);
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

            $contract = Contract::create([
                'tenant_id' => $tenantId,
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'contract_number' => $contractNumber,
                'status' => 'DRAFT',
                'currency' => $quotation->currency,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'total_value' => $quotation->total,
                'terms' => $request->terms,
                'created_by' => $request->user()->id,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'contract.generate',
                entityType: 'Contract',
                entityId: $contract->id,
                metadata: ['contract_number' => $contractNumber, 'quotation_id' => $quotation->id],
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

    public function activate(string $contract, Request $request)
    {
        $contract = Contract::findOrFail($contract);

        if ($contract->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' => ["No se puede activar un contrato en estado {$contract->status}."],
            ]);
        }

        $contract->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'contract.activate',
            entityType: 'Contract',
            entityId: $contract->id,
        );

        return response()->json($contract);
    }
}