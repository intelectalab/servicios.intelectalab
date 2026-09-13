<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreSupplierRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::with('party.person', 'party.organization')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $supplier)
    {
        $supplier = Supplier::findOrFail($supplier);

        return response()->json($supplier->load('party.person', 'party.organization'));
    }

    public function store(StoreSupplierRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $supplier = DB::transaction(function () use ($request, $tenantId) {
            $party = Party::create([
                'tenant_id' => $tenantId,
                'party_type' => $request->party_type,
                'status' => 'ACTIVE',
            ]);

            if ($request->party_type === 'PERSON') {
                Person::create([
                    'party_id' => $party->id,
                    'given_names' => $request->given_names,
                    'family_name' => $request->family_name,
                    'document_type' => $request->document_type,
                    'document_number' => $request->document_number,
                    'metadata' => $request->metadata ?? [],
                ]);
            } else {
                Organization::create([
                    'party_id' => $party->id,
                    'legal_name' => $request->legal_name,
                    'trade_name' => $request->trade_name ?? null,
                    'tax_identifier' => $request->document_number,
                    'metadata' => $request->metadata ?? [],
                ]);
            }

            $supplier = Supplier::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'status' => 'ACTIVE',
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'supplier.create',
                entityType: 'Supplier',
                entityId: $supplier->id,
                metadata: ['party_type' => $request->party_type],
            );

            return $supplier;
        });

        return response()->json($supplier->load('party.person', 'party.organization'), 201);
    }

    public function deactivate(string $supplier, Request $request)
    {
        $supplier = Supplier::findOrFail($supplier);

        if ($supplier->status === 'INACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este proveedor ya está inactivo.'],
            ]);
        }

        $supplier->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'supplier.deactivate',
            entityType: 'Supplier',
            entityId: $supplier->id,
        );

        return response()->json($supplier);
    }
}