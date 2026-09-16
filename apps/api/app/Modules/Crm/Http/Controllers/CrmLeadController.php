<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Crm\Http\Requests\StoreLeadRequest;
use App\Modules\Crm\Models\CrmLead;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CrmLeadController extends Controller
{
    public function index(Request $request)
    {
        $query = CrmLead::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->orderByDesc('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(string $lead)
    {
        return response()->json(CrmLead::findOrFail($lead));
    }

    public function store(StoreLeadRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $lead = CrmLead::create([
            'tenant_id' => $tenantId,
            'person_name' => $request->person_name,
            'organization_name' => $request->organization_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'status' => 'NEW',
            'owner_user_id' => $request->user()->id,
        ]);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'crm_lead.create',
            entityType: 'CrmLead',
            entityId: $lead->id,
        );

        return response()->json($lead, 201);
    }

    public function qualify(string $lead, Request $request)
    {
        $lead = CrmLead::findOrFail($lead);

        if ($lead->status !== 'NEW') {
            throw ValidationException::withMessages([
                'status' => ["No se puede calificar un lead en estado {$lead->status}."],
            ]);
        }

        $lead->update(['status' => 'QUALIFIED']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'crm_lead.qualify',
            entityType: 'CrmLead',
            entityId: $lead->id,
        );

        return response()->json($lead);
    }

    /**
     * PIEZA CLAVE: convierte un lead en un Customer REAL del CORE.
     * Nunca crea un "cliente" propio del CRM — reutiliza exactamente
     * el mismo patrón Party -> Person/Organization -> Customer que
     * ya usa el módulo de Clientes.
     */
    public function convert(string $lead, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $leadModel = CrmLead::findOrFail($lead);

        if ($leadModel->status === 'CONVERTED') {
            throw ValidationException::withMessages([
                'status' => ['Este lead ya fue convertido anteriormente.'],
            ]);
        }

        $customer = DB::transaction(function () use ($leadModel, $tenantId, $request) {
            $isOrganization = ! empty($leadModel->organization_name);

            $party = Party::create([
                'tenant_id' => $tenantId,
                'party_type' => $isOrganization ? 'ORGANIZATION' : 'PERSON',
                'status' => 'ACTIVE',
            ]);

            if ($isOrganization) {
                Organization::create([
                    'party_id' => $party->id,
                    'legal_name' => $leadModel->organization_name,
                ]);
            } else {
                $nameParts = explode(' ', trim($leadModel->person_name ?? 'Sin nombre'), 2);
                Person::create([
                    'party_id' => $party->id,
                    'given_names' => $nameParts[0],
                    'family_name' => $nameParts[1] ?? '-',
                ]);
            }

            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'status' => 'ACTIVE',
                'payment_terms_days' => 30,
            ]);

            $leadModel->update([
                'status' => 'CONVERTED',
                'converted_at' => now(),
                'converted_customer_id' => $customer->id,
                'party_id' => $party->id,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'crm_lead.convert',
                entityType: 'CrmLead',
                entityId: $leadModel->id,
                metadata: ['converted_customer_id' => $customer->id],
            );

            return $customer;
        });

        return response()->json([
            'lead' => $leadModel->fresh(),
            'customer' => $customer->load('party.person', 'party.organization'),
        ]);
    }
}