<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreCustomerRequest;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                ]);
            } else {
                Organization::create([
                    'party_id' => $party->id,
                    'legal_name' => $request->legal_name,
                    'trade_name' => $request->trade_name ?? null,
                    'tax_identifier' => $request->document_number, // el RUC va aquí
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
}