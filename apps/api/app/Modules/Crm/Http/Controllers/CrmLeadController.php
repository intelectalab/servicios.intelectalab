<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Crm\Http\Requests\StoreLeadRequest;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Crm\Support\CrmCatalog;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Leads: prospectos iniciales. Al calificar se crea una oportunidad; la identidad en
 * Clientes y actores solo se crea o se reutiliza al convertir una oportunidad ganada.
 */
class CrmLeadController extends Controller
{
    private const STATUS_LABELS = [
        'NEW' => 'Nuevo', 'CONTACTED' => 'Contactado', 'QUALIFIED' => 'Calificado',
        'CONVERTED' => 'Convertido', 'DISQUALIFIED' => 'Descartado', 'NURTURING' => 'En seguimiento',
    ];

    /** Empresa o, si el lead es una persona, su nombre. */
    private const NAME = 'COALESCE(crm_leads.organization_name, crm_leads.person_name)';

    /** Identidad de Clientes y actores con la que el lead está enlazado o coincide por nombre. */
    private const CORE_MATCH = "COALESCE(crm_leads.party_id, (
            SELECT o.party_id FROM organizations o JOIN parties p ON p.id = o.party_id
            WHERE p.tenant_id = crm_leads.tenant_id AND crm_leads.organization_name IS NOT NULL
              AND lower(crm_leads.organization_name) IN (lower(o.legal_name), lower(o.trade_name))
            LIMIT 1))";

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $uuid = fn (string $key) => Str::isUuid((string) $request->query($key)) ? $request->query($key) : null;

        $query = CrmLead::query()
            ->with('source:id,name', 'owner:id,name')
            ->select('crm_leads.*')
            ->selectRaw(self::CORE_MATCH.' AS core_party_id')
            ->when($request->filled('status'), fn ($q) => $q->where('crm_leads.status', $request->query('status')))
            ->when($uuid('source_id'), fn ($q, $id) => $q->where('crm_leads.source_id', $id))
            ->when($uuid('owner_id'), fn ($q, $id) => $q->where('crm_leads.owner_user_id', $id))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('crm_leads.lead_number', 'ILIKE', $like)
                    ->orWhere('crm_leads.organization_name', 'ILIKE', $like)
                    ->orWhere('crm_leads.person_name', 'ILIKE', $like)
                    ->orWhere('crm_leads.email', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'lead' => ColumnFilters::text("concat_ws(' ', crm_leads.organization_name, crm_leads.lead_number, crm_leads.person_name, crm_leads.position)"),
            'contact' => ColumnFilters::text("concat_ws(' ', crm_leads.email, crm_leads.phone)"),
            'interest' => ColumnFilters::text('crm_leads.interest'),
            'created' => ColumnFilters::date('crm_leads.created_at'),
        ]);

        ListSort::apply($query, $request, [
            'lead' => 'lower('.self::NAME.')',
            'contact' => 'lower(crm_leads.email::text)',
            'source' => '(SELECT lower(s.name) FROM crm_sources s WHERE s.id = crm_leads.source_id)',
            'interest' => 'lower(crm_leads.interest)',
            'owner' => '(SELECT lower(u.name) FROM users u WHERE u.id = crm_leads.owner_user_id)',
            'created' => 'crm_leads.created_at',
            'status' => ListSort::labels('crm_leads.status', self::STATUS_LABELS),
        ], fn ($q) => $q->orderByDesc('crm_leads.created_at')->orderByDesc('crm_leads.id'));

        return response()->json($query->paginate(min(max($request->integer('per_page', 20), 1), 200)));
    }

    /** Indicadores del listado: cuentan solo lo que la persona alcanza. */
    public function summary()
    {
        $byStatus = CrmLead::query()->selectRaw('status, count(*) AS total')->groupBy('status')->pluck('total', 'status');

        return response()->json([
            'total' => (int) $byStatus->sum(),
            'by_status' => (object) $byStatus->map(fn ($total) => (int) $total)->all(),
        ]);
    }

    /** Listas de los filtros y del formulario. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'sources' => CrmCatalog::sources($tenantId)->where('code', '<>', CrmCatalog::EXISTING_CUSTOMER)->values(),
            // Responsables de los leads que la persona alcanza.
            'owners' => DB::table('users')
                ->whereIn('id', CrmLead::query()->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id'))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(string $lead)
    {
        return response()->json($this->detail($this->find($lead)));
    }

    public function store(StoreLeadRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->leadData($request, $tenantId);

        $lead = DB::transaction(function () use ($data, $tenantId, $request) {
            $number = SequentialNumberGenerator::next($tenantId, 'crm_leads', 'lead_number', 'LD');

            $lead = CrmLead::create([
                ...$data,
                'tenant_id' => $tenantId,
                'lead_number' => $number,
                'status' => 'NEW',
                'owner_user_id' => $request->user()->id,
                // Si la empresa ya está en Clientes y actores, el lead nace enlazado a ella.
                'party_id' => $this->matchingPartyId($tenantId, $data['organization_name'] ?? null),
            ]);

            $this->audit($request, $lead, 'crm_lead.create', ['to' => 'NEW', 'lead_number' => $number]);

            return $lead;
        });

        return response()->json($this->detail($lead), 201);
    }

    /** Datos del prospecto; solo mientras el lead sigue abierto. */
    public function update(string $lead, StoreLeadRequest $request)
    {
        $lead = $this->find($lead);
        $this->assertOpen($lead, 'editar');
        $data = $this->leadData($request, $request->attributes->get('tenant_id'), $lead);

        DB::transaction(function () use ($lead, $data, $request) {
            $lead->update([
                ...$data,
                'party_id' => $lead->party_id ?? $this->matchingPartyId($lead->tenant_id, $data['organization_name'] ?? null),
            ]);
            $this->audit($request, $lead, 'crm_lead.update');
        });

        return response()->json($this->detail($lead));
    }

    public function contact(string $lead, Request $request)
    {
        return $this->transition($request, $this->find($lead), ['NEW'], 'CONTACTED', 'marcar como contactado', 'crm_lead.contact');
    }

    public function qualify(string $lead, Request $request)
    {
        return $this->transition($request, $this->find($lead), ['NEW', 'CONTACTED'], 'QUALIFIED', 'calificar', 'crm_lead.qualify');
    }

    public function disqualify(string $lead, Request $request)
    {
        return $this->transition($request, $this->find($lead), CrmLead::OPEN, 'DISQUALIFIED', 'descartar', 'crm_lead.disqualify', true);
    }

    /**
     * Calificar y crear la oportunidad: el lead queda Convertido y enlazado a ella.
     * Una sola oportunidad por lead (lo garantiza también la base).
     */
    public function createOpportunity(string $lead, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $lead = $this->find($lead);

        if (! in_array($lead->status, CrmLead::OPEN, true) || CrmOpportunity::ignoringAccessScope()->where('lead_id', $lead->id)->exists()) {
            throw ValidationException::withMessages(['status' => ['El lead ya fue cerrado.']]);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'expected_close_date' => ['nullable', 'date'],
        ], [
            'amount.required' => 'Indica el monto estimado.',
            'amount.gt' => 'Indica el monto estimado.',
        ]);

        try {
            $opportunity = DB::transaction(function () use ($lead, $data, $tenantId, $request) {
                $stage = CrmCatalog::stages($tenantId)->first();
                $number = SequentialNumberGenerator::next($tenantId, 'crm_opportunities', 'opportunity_number', 'OP');
                $name = trim((string) ($data['name'] ?? '')) ?: ($lead->interest ?: 'Oportunidad').' — '.($lead->organization_name ?: $lead->person_name);

                $opportunity = CrmOpportunity::create([
                    'tenant_id' => $tenantId,
                    'opportunity_number' => $number,
                    'name' => Str::limit($name, 255, ''),
                    'lead_id' => $lead->id,
                    // Si el lead ya está enlazado a un cliente, la oportunidad nace con él.
                    'customer_id' => $lead->party_id ? Customer::where('party_id', $lead->party_id)->value('id') : null,
                    'pipeline_id' => $stage->pipeline_id,
                    'stage_id' => $stage->id,
                    'source_id' => $lead->source_id,
                    'status' => 'OPEN',
                    'expected_value' => $data['amount'],
                    'currency' => 'PEN',
                    'probability' => $stage->probability,
                    'expected_close_date' => $data['expected_close_date'] ?? now()->addDays(30)->toDateString(),
                    // La oportunidad sigue siendo de quien llevaba el lead, la cree quien la cree.
                    'owner_user_id' => $lead->owner_user_id ?? $request->user()->id,
                    'branch_id' => $lead->branch_id,
                ]);

                $previous = $lead->status;
                $lead->update(['status' => 'CONVERTED', 'converted_at' => now()]);

                $this->audit($request, $lead, 'crm_lead.convert', ['from' => $previous, 'to' => 'CONVERTED', 'note' => "Oportunidad {$number}"]);
                AuditLogger::log(
                    tenantId: $tenantId,
                    userId: $request->user()->id,
                    action: 'crm_opportunity.create',
                    entityType: 'CrmOpportunity',
                    entityId: $opportunity->id,
                    metadata: ['to' => 'OPEN', 'opportunity_number' => $number, 'amount' => (float) $data['amount'], 'note' => "Desde {$lead->lead_number}"],
                );

                return $opportunity;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['status' => ['El lead ya tiene una oportunidad.']]);
        }

        return response()->json(['id' => $opportunity->id, 'opportunity_number' => $opportunity->opportunity_number, 'lead' => $this->detail($lead)], 201);
    }

    /**
     * DESCONECTADO (2026-10-08): su ruta ya no existe. Convertía el lead en cliente sin buscar
     * si la identidad ya estaba registrada. El cliente se crea o se reutiliza ahora al convertir
     * la oportunidad ganada (CrmOpportunityController::convert), que no duplica identidades.
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

    private function find(string $lead): CrmLead
    {
        abort_unless(Str::isUuid($lead), 404);

        return CrmLead::findOrFail($lead);
    }

    private function detail(CrmLead $lead): array
    {
        $lead = CrmLead::query()
            ->with('source:id,name', 'owner:id,name', 'opportunity:id,lead_id,opportunity_number,name,status')
            ->select('crm_leads.*')
            ->selectRaw(self::CORE_MATCH.' AS core_party_id')
            ->findOrFail($lead->id);

        $party = $lead->core_party_id ? Party::with('person', 'organization')->find($lead->core_party_id) : null;

        return [
            ...$lead->toArray(),
            // La identidad que ya existe en Clientes y actores: al convertir se reutiliza, no se duplica.
            'core_party' => $party ? ['id' => $party->id, 'name' => $party->display_name] : null,
            'history' => AuditHistory::for('CrmLead', $lead->id),
        ];
    }

    /** Valida los datos del formulario y los devuelve con los nombres de las columnas. */
    private function leadData(StoreLeadRequest $request, string $tenantId, ?CrmLead $lead = null): array
    {
        $data = $request->validated();
        $text = fn (string $key) => trim((string) ($data[$key] ?? '')) ?: null;

        if (! $text('organization_name') && ! $text('person_name')) {
            throw ValidationException::withMessages(['organization_name' => ['Indica empresa o nombre de contacto.']]);
        }
        if (! CrmCatalog::sources($tenantId)->contains('id', $data['source_id'])) {
            throw ValidationException::withMessages(['source_id' => ['La fuente indicada no existe.']]);
        }

        // Un mismo correo no puede tener dos leads abiertos, sean de quien sean.
        $email = $text('email');
        $repeated = $email && CrmLead::ignoringAccessScope()
            ->where('email', $email)
            ->whereIn('status', CrmLead::OPEN)
            ->when($lead, fn ($q) => $q->where('id', '<>', $lead->id))
            ->exists();
        if ($repeated) {
            throw ValidationException::withMessages(['email' => ['Ya existe un lead abierto con ese correo.']]);
        }

        return [
            'organization_name' => $text('organization_name'),
            'person_name' => $text('person_name'),
            'position' => $text('position'),
            'email' => $email,
            'phone' => $text('phone'),
            'interest' => $text('interest'),
            'source_id' => $data['source_id'],
        ];
    }

    /** Organización de la empresa cuya razón social o nombre comercial coincide con el del lead. */
    private function matchingPartyId(string $tenantId, ?string $organizationName): ?string
    {
        if (! $organizationName) {
            return null;
        }

        return DB::table('organizations')
            ->join('parties', 'parties.id', '=', 'organizations.party_id')
            ->where('parties.tenant_id', $tenantId)
            ->where(fn ($q) => $q
                ->whereRaw('lower(organizations.legal_name) = lower(?)', [$organizationName])
                ->orWhereRaw('lower(organizations.trade_name) = lower(?)', [$organizationName]))
            ->orderBy('parties.created_at')
            ->value('parties.id');
    }

    private function assertOpen(CrmLead $lead, string $verb): void
    {
        if (! in_array($lead->status, CrmLead::OPEN, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un lead en estado ".(self::STATUS_LABELS[$lead->status] ?? $lead->status).'.'],
            ]);
        }
    }

    private function transition(Request $request, CrmLead $lead, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        if (! in_array($lead->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un lead en estado ".(self::STATUS_LABELS[$lead->status] ?? $lead->status).'.'],
            ]);
        }

        $reason = null;
        if ($needsReason) {
            $reason = trim($request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason']);
        }

        $previous = $lead->status;
        $lead->update(['status' => $to, ...($needsReason ? ['discard_reason' => $reason] : [])]);

        $this->audit($request, $lead, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));

        return response()->json($this->detail($lead));
    }

    private function audit(Request $request, CrmLead $lead, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'CrmLead',
            entityId: $lead->id,
            metadata: $metadata,
        );
    }
}
