<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreCustomerRequest;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Organization;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\Contract;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::with('party.person', 'party.organization')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $customer)
    {
        $customer = Customer::findOrFail($customer);

        return response()->json($customer->load('party.person', 'party.organization'));
    }

    public function store(StoreCustomerRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $customer = DB::transaction(function () use ($request, $tenantId) {
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
                    'tax_identifier' => $request->document_number, // el RUC va aquí
                    'metadata' => $request->metadata ?? [],
                ]);
            }

            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'status' => 'ACTIVE',
                'payment_terms_days' => $request->payment_terms_days ?? 30,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'customer.create',
                entityType: 'Customer',
                entityId: $customer->id,
                metadata: ['party_type' => $request->party_type],
            );

            // Outbox sigue pendiente: AuditLogger ya existe como servicio,
            // pero no vi ningún equivalente para outbox_events todavía.
            // No lo voy a improvisar — confírmalo con tu compañero/a antes
            // de que yo le invente una forma.

            return $customer;
        });

        return response()->json(
            $customer->load('party.person', 'party.organization'),
            201
        );
    }

    public function deactivate(string $customer, Request $request)
    {
        $customer = Customer::findOrFail($customer);

        if ($customer->status === 'INACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este cliente ya está inactivo.'],
            ]);
        }

        $customer->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'customer.deactivate',
            entityType: 'Customer',
            entityId: $customer->id,
        );

        return response()->json($customer);
    }

    public function activate(string $customer, Request $request)
    {
        $customer = Customer::findOrFail($customer);

        if ($customer->status === 'ACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este cliente ya está activo.'],
            ]);
        }

        $customer->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'customer.activate',
            entityType: 'Customer',
            entityId: $customer->id,
        );

        return response()->json($customer);
    }

    public function bulkDeactivate(Request $request)
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid']
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        // Filtramos para asegurar que sean del tenant y no estén ya inactivos
        $validIds = Customer::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'INACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay clientes válidos para desactivar.'],
            ]);
        }

        Customer::whereIn('id', $validIds)->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'customer.bulk_deactivate',
            entityType: 'Customer',
            entityId: null, 
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' cliente(s) desactivado(s) correctamente.',
            'affected_ids' => $validIds
        ]);
    }

    public function bulkActivate(Request $request)
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid']
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $validIds = Customer::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'ACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay clientes válidos para activar.'],
            ]);
        }

        Customer::whereIn('id', $validIds)->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'customer.bulk_activate',
            entityType: 'Customer',
            entityId: null,
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' cliente(s) activado(s) correctamente.',
            'affected_ids' => $validIds
        ]);
    }

    /**
     * Vista 360: el cliente junto con sus cotizaciones y contratos relacionados,
     * para tener una sola pantalla de resumen en vez de navegar por separado.
     */
    public function overview(string $customer)
    {
        $customer = Customer::with('party.person', 'party.organization')->findOrFail($customer);

        $quotations = Quotation::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get(['id', 'quotation_number', 'status', 'total', 'currency', 'created_at']);

        $contracts = Contract::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get(['id', 'contract_number', 'status', 'total_value', 'currency', 'start_date', 'end_date']);

        return response()->json([
            'customer' => $customer,
            'quotations' => $quotations,
            'contracts' => $contracts,
            'summary' => [
                'total_quotations' => $quotations->count(),
                'total_contracts' => $contracts->count(),
                'active_contracts' => $contracts->where('status', 'ACTIVE')->count(),
                'total_contracted_value' => $contracts->where('status', 'ACTIVE')->sum('total_value'),
            ],
        ]);
    }
}