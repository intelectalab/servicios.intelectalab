<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Http\Controllers\PartyController;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Party;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Crm\Support\CrmCatalog;
use App\Modules\Services\Models\Quotation;
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
 * Pipeline de oportunidades. Una oportunidad abierta avanza por las etapas del embudo;
 * ganarla exige evidencia y perderla, motivo. La ganada se convierte en cliente creando o
 * reutilizando su identidad en Clientes y actores: nunca un cliente paralelo.
 */
class CrmOpportunityController extends Controller
{
    private const STATUS_LABELS = ['OPEN' => 'Abierta', 'WON' => 'Ganada', 'LOST' => 'Perdida'];

    private const WEIGHTED = '(crm_opportunities.expected_value * crm_opportunities.probability / 100)';

    private const LIST_RELATIONS = [
        'stage:id,name,position,probability',
        'source:id,name',
        'owner:id,name',
        'customer.party.person',
        'customer.party.organization',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $uuid = fn (string $key) => Str::isUuid((string) $request->query($key)) ? $request->query($key) : null;

        $query = CrmOpportunity::query()
            ->with(self::LIST_RELATIONS)
            ->select('crm_opportunities.*')
            // Cuenta solo las cotizaciones que la persona alcanza.
            ->withCount('quotations')
            ->selectRaw('EXISTS (SELECT 1 FROM crm_conversions c WHERE c.opportunity_id = crm_opportunities.id) AS converted')
            ->when($request->filled('status'), fn ($q) => $q->where('crm_opportunities.status', $request->query('status')))
            ->when($uuid('stage_id'), fn ($q, $id) => $q->where('crm_opportunities.status', 'OPEN')->where('crm_opportunities.stage_id', $id))
            ->when($uuid('owner_id'), fn ($q, $id) => $q->where('crm_opportunities.owner_user_id', $id))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('crm_opportunities.opportunity_number', 'ILIKE', $like)
                    ->orWhere('crm_opportunities.name', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('crm_opportunities').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'opportunity' => ColumnFilters::text("concat_ws(' ', crm_opportunities.name, crm_opportunities.opportunity_number)"),
            'customer' => ColumnFilters::text(ListSort::customerName('crm_opportunities')),
            'amount' => ColumnFilters::number('crm_opportunities.expected_value'),
            'probability' => ColumnFilters::number('crm_opportunities.probability'),
            'weighted' => ColumnFilters::number(self::WEIGHTED),
            'close' => ColumnFilters::date('crm_opportunities.expected_close_date'),
        ]);

        ListSort::apply($query, $request, [
            'opportunity' => 'lower(crm_opportunities.name)',
            'customer' => ListSort::customerName('crm_opportunities'),
            // Las abiertas por el orden de su etapa; después las ganadas y las perdidas.
            'stage' => "CASE crm_opportunities.status WHEN 'WON' THEN 1000 WHEN 'LOST' THEN 1001
                ELSE (SELECT s.position FROM crm_pipeline_stages s WHERE s.id = crm_opportunities.stage_id) END",
            'amount' => 'crm_opportunities.expected_value',
            'probability' => 'crm_opportunities.probability',
            'weighted' => self::WEIGHTED,
            'close' => 'crm_opportunities.expected_close_date',
            'owner' => '(SELECT lower(u.name) FROM users u WHERE u.id = crm_opportunities.owner_user_id)',
        ], fn ($q) => $q->orderByDesc('crm_opportunities.created_at')->orderByDesc('crm_opportunities.id'));

        return response()->json($query->paginate(min(max($request->integer('per_page', 20), 1), 200)));
    }

    /** Indicadores del pipeline: cuentan solo lo que la persona alcanza. */
    public function summary()
    {
        $rows = CrmOpportunity::query()
            ->selectRaw('status, count(*) AS total, COALESCE(sum(expected_value), 0) AS amount, COALESCE(sum('.self::WEIGHTED.'), 0) AS weighted')
            ->groupBy('status')->get()->keyBy('status');

        $count = fn (string $status) => (int) ($rows[$status]->total ?? 0);
        $closed = $count('WON') + $count('LOST');

        return response()->json([
            'open' => ['count' => $count('OPEN'), 'amount' => (float) ($rows['OPEN']->amount ?? 0), 'weighted' => (float) ($rows['OPEN']->weighted ?? 0)],
            'won' => ['count' => $count('WON'), 'amount' => (float) ($rows['WON']->amount ?? 0)],
            'lost' => ['count' => $count('LOST')],
            'close_rate' => $closed ? (int) round($count('WON') / $closed * 100) : null,
        ]);
    }

    /** Listas de los filtros, del tablero y del formulario. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'stages' => CrmCatalog::stages($tenantId),
            'sources' => CrmCatalog::sources($tenantId),
            // Responsables de las oportunidades que la persona alcanza.
            'owners' => DB::table('users')
                ->whereIn('id', CrmOpportunity::query()->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id'))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(string $opportunity, Request $request)
    {
        return response()->json($this->detail($this->find($opportunity), $request));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->opportunityData($request, $tenantId);

        $opportunity = DB::transaction(function () use ($data, $tenantId, $request) {
            $stage = CrmCatalog::stages($tenantId)->first();
            $number = SequentialNumberGenerator::next($tenantId, 'crm_opportunities', 'opportunity_number', 'OP');

            $opportunity = CrmOpportunity::create([
                ...$data,
                'tenant_id' => $tenantId,
                'opportunity_number' => $number,
                'pipeline_id' => $stage->pipeline_id,
                'stage_id' => $stage->id,
                'status' => 'OPEN',
                'currency' => 'PEN',
                'probability' => $stage->probability,
                'expected_close_date' => $data['expected_close_date'] ?? now()->addDays(30)->toDateString(),
                'owner_user_id' => $request->user()->id,
            ]);

            $this->audit($request, $opportunity, 'crm_opportunity.create', ['to' => 'OPEN', 'opportunity_number' => $number, 'amount' => (float) $data['expected_value']]);

            return $opportunity;
        });

        return response()->json($this->detail($opportunity, $request), 201);
    }

    /** Nombre, monto, cierre esperado, fuente y cliente; solo mientras sigue abierta. */
    public function update(string $opportunity, Request $request)
    {
        $opportunity = $this->find($opportunity);
        $this->assertOpen($opportunity, 'editar');
        $data = $this->opportunityData($request, $request->attributes->get('tenant_id'), $opportunity);

        DB::transaction(function () use ($opportunity, $data, $request) {
            $before = ['name' => $opportunity->name, 'amount' => (float) $opportunity->expected_value];
            $opportunity->update($data);
            AuditLogger::log(
                tenantId: $opportunity->tenant_id,
                userId: $request->user()->id,
                action: 'crm_opportunity.update',
                entityType: 'CrmOpportunity',
                entityId: $opportunity->id,
                beforeData: $before,
                afterData: ['name' => $opportunity->name, 'amount' => (float) $opportunity->expected_value],
            );
        });

        return response()->json($this->detail($opportunity, $request));
    }

    /** Mueve una oportunidad abierta a otra etapa del embudo; toma la probabilidad de la etapa. */
    public function moveStage(string $opportunity, Request $request)
    {
        $opportunity = $this->find($opportunity);
        if ($opportunity->status !== 'OPEN') {
            throw ValidationException::withMessages(['status' => ['Solo las oportunidades abiertas cambian de etapa.']]);
        }

        $stageId = $request->validate(['stage_id' => ['required', 'uuid']], ['stage_id.required' => 'Indica la etapa.'])['stage_id'];
        $stage = CrmCatalog::stages($opportunity->tenant_id)->firstWhere('id', $stageId);
        if (! $stage || $stage->pipeline_id !== $opportunity->pipeline_id) {
            throw ValidationException::withMessages(['stage_id' => ['Etapa inválida.']]);
        }

        if ($stage->id !== $opportunity->stage_id) {
            $opportunity->update(['stage_id' => $stage->id, 'probability' => $stage->probability]);
            $this->audit($request, $opportunity, 'crm_opportunity.stage', ['note' => "Etapa → {$stage->name}"]);
        }

        return response()->json($this->detail($opportunity, $request));
    }

    public function win(string $opportunity, Request $request)
    {
        $opportunity = $this->find($opportunity);
        $this->assertOpen($opportunity, 'cerrar');

        $evidence = trim((string) $request->validate(
            ['evidence' => ['required', 'string', 'max:2000']],
            ['evidence.required' => 'Registra la evidencia de cierre (orden de compra, correo, acta).'],
        )['evidence']);

        $opportunity->update([
            'status' => 'WON', 'won_at' => now(), 'won_by' => $request->user()->id, 'won_evidence' => $evidence, 'probability' => 100,
        ]);
        $this->audit($request, $opportunity, 'crm_opportunity.win', ['from' => 'OPEN', 'to' => 'WON', 'note' => $evidence]);

        return response()->json($this->detail($opportunity, $request));
    }

    public function lose(string $opportunity, Request $request)
    {
        $opportunity = $this->find($opportunity);
        $this->assertOpen($opportunity, 'cerrar');

        $reason = trim((string) $request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'El motivo de pérdida es obligatorio.'],
        )['reason']);

        $opportunity->update(['status' => 'LOST', 'lost_at' => now(), 'loss_reason' => $reason, 'probability' => 0]);
        $this->audit($request, $opportunity, 'crm_opportunity.lose', ['from' => 'OPEN', 'to' => 'LOST', 'note' => $reason]);

        return response()->json($this->detail($opportunity, $request));
    }

    /**
     * Convierte la oportunidad ganada en cliente. REUSE toma una identidad que ya existe y,
     * si hace falta, le agrega el rol Cliente; CREATE da de alta la organización con el mismo
     * control de duplicados de Clientes y actores. Una sola conversión por oportunidad (lo
     * garantiza también la base).
     *
     * Dar de alta una identidad o darle un rol pide además `core.manage`, como en su pantalla.
     */
    public function convert(string $opportunity, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $opportunity = $this->find($opportunity);

        if ($opportunity->status !== 'WON') {
            throw ValidationException::withMessages(['status' => ['Solo se convierten oportunidades ganadas.']]);
        }
        if (DB::table('crm_conversions')->where('opportunity_id', $opportunity->id)->exists()) {
            throw ValidationException::withMessages(['status' => ['La oportunidad ya fue convertida.']]);
        }

        $mode = $request->validate(['mode' => ['required', 'in:CREATE,REUSE']])['mode'];
        $parties = app(PartyController::class);
        $canManageIdentities = CheckPermission::granted($request, 'core.manage');
        $denied = fn () => abort(response()->json([
            'message' => 'No tienes el permiso requerido: core.manage',
            'code' => 'PERMISSION_DENIED',
        ], 403));
        $lead = $opportunity->lead_id ? CrmLead::ignoringAccessScope()->find($opportunity->lead_id) : null;

        try {
            $result = DB::transaction(function () use ($mode, $request, $opportunity, $lead, $tenantId, $parties, $canManageIdentities, $denied) {
                if ($mode === 'REUSE') {
                    $partyId = $request->validate(
                        ['party_id' => ['required', 'uuid']],
                        ['party_id.required' => 'Selecciona la identidad existente a reutilizar.'],
                    )['party_id'];
                    $party = Party::where('tenant_id', $tenantId)->with('person', 'organization')->find($partyId);
                    if (! $party) {
                        throw ValidationException::withMessages(['party_id' => ['La identidad indicada no existe.']]);
                    }

                    $customer = $parties->customerOf($party, $request, false);
                    // La oportunidad ya era de un cliente: se convierte con ese, no con otro.
                    if ($opportunity->customer_id && $opportunity->customer_id !== $customer?->id) {
                        throw ValidationException::withMessages(['party_id' => ['La oportunidad ya está enlazada a otro cliente.']]);
                    }
                    if (! $customer) {
                        $canManageIdentities || $denied();
                        $customer = $parties->customerOf($party, $request);
                    }
                } else {
                    $canManageIdentities || $denied();
                    if ($opportunity->customer_id) {
                        throw ValidationException::withMessages(['mode' => ['La oportunidad ya está enlazada a un cliente: reutilízalo.']]);
                    }

                    $created = $parties->createFromModule($request, [
                        'kind' => 'ORGANIZATION',
                        'roles' => ['CUSTOMER'],
                        'legal_name' => $request->input('legal_name'),
                        'tax_identifier' => $request->input('tax_identifier'),
                        'sector' => $request->input('sector'),
                    ]);
                    if (! $created['party']) {
                        return ['duplicates' => $created['duplicates']];
                    }
                    $party = $created['party']->load('organization');
                    $customer = $parties->customerOf($party, $request, false);

                    // El contacto del lead queda como persona de contacto de la organización.
                    $names = preg_split('/\s+/', trim((string) $lead?->person_name), 2);
                    if (count($names) === 2) {
                        $parties->createFromModule($request, [
                            'kind' => 'PERSON',
                            'roles' => ['CONTACT'],
                            'given_names' => $names[0],
                            'family_name' => $names[1],
                            'contact_of' => $party->id,
                            'position' => $lead->position,
                            'email' => $lead->email,
                            'phone' => $lead->phone,
                        ]);
                    }
                }

                DB::table('crm_conversions')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $tenantId,
                    'lead_id' => $lead?->id,
                    'opportunity_id' => $opportunity->id,
                    'customer_id' => $customer->id,
                    'party_id' => $party->id,
                    'mode' => $mode,
                    'converted_by' => $request->user()->id,
                    'converted_at' => now(),
                ]);

                $opportunity->update(['customer_id' => $customer->id]);
                $lead?->update(['party_id' => $party->id, 'converted_customer_id' => $customer->id]);

                $this->audit($request, $opportunity, 'crm_opportunity.convert', [
                    'party_id' => $party->id,
                    'mode' => $mode,
                    'note' => ($mode === 'REUSE' ? 'Se reutilizó ' : 'Se creó ').$party->display_name,
                ]);

                return ['duplicates' => []];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['status' => ['La oportunidad ya fue convertida.']]);
        }

        if ($result['duplicates']) {
            return response()->json([
                'message' => 'Esa organización ya existe en Clientes y actores. Usa «Reutilizar existente».',
                'duplicates' => $result['duplicates'],
            ], 409);
        }

        return response()->json($this->detail($opportunity, $request));
    }

    private function find(string $opportunity): CrmOpportunity
    {
        abort_unless(Str::isUuid($opportunity), 404);

        return CrmOpportunity::findOrFail($opportunity);
    }

    private function detail(CrmOpportunity $opportunity, Request $request): array
    {
        $opportunity = CrmOpportunity::with([...self::LIST_RELATIONS, 'lead:id,lead_number,organization_name,person_name'])->findOrFail($opportunity->id);

        $conversion = DB::table('crm_conversions')->where('opportunity_id', $opportunity->id)->first(['id', 'party_id', 'mode', 'converted_at']);
        $partyName = fn (?string $id) => $id ? Party::with('person', 'organization')->find($id)?->display_name : null;

        return [
            ...$opportunity->toArray(),
            'conversion' => $conversion ? [
                'id' => $conversion->id,
                'party_id' => $conversion->party_id ?? $opportunity->customer?->party_id,
                'party_name' => $partyName($conversion->party_id ?? $opportunity->customer?->party_id),
                'mode' => $conversion->mode,
                'converted_at' => \Illuminate\Support\Carbon::parse($conversion->converted_at)->toIso8601String(),
            ] : null,
            // Identidades que ya existen y con las que se puede convertir sin duplicar.
            'conversion_candidates' => $opportunity->status === 'WON' && ! $conversion ? $this->candidates($opportunity) : [],
            // Las cotizaciones, solo con su permiso de ver y dentro del alcance del rol.
            'quotations' => CheckPermission::granted($request, 'quotations.view')
                ? $opportunity->quotations()->orderByDesc('created_at')->limit(100)->get(['id', 'quotation_number', 'total', 'currency', 'status'])
                : [],
            'history' => AuditHistory::for('CrmOpportunity', $opportunity->id),
        ];
    }

    /** El cliente de la oportunidad, la identidad enlazada al lead y las organizaciones con su mismo nombre. */
    private function candidates(CrmOpportunity $opportunity): array
    {
        $lead = $opportunity->lead_id ? CrmLead::ignoringAccessScope()->find($opportunity->lead_id) : null;
        $ids = collect([$opportunity->customer?->party_id, $lead?->party_id])->filter();

        if ($lead?->organization_name) {
            $ids = $ids->merge(DB::table('organizations')
                ->join('parties', 'parties.id', '=', 'organizations.party_id')
                ->where('parties.tenant_id', $opportunity->tenant_id)
                ->where(fn ($q) => $q
                    ->whereRaw('lower(organizations.legal_name) = lower(?)', [$lead->organization_name])
                    ->orWhereRaw('lower(organizations.trade_name) = lower(?)', [$lead->organization_name]))
                ->limit(5)->pluck('parties.id'));
        }
        if ($ids->isEmpty()) {
            return [];
        }

        return Party::whereIn('id', $ids->unique())->with('person', 'organization', 'customer:id,party_id')->get()
            ->map(fn (Party $party) => [
                'id' => $party->id,
                'name' => $party->display_name,
                'document' => $party->party_type === 'ORGANIZATION'
                    ? 'RUC '.($party->organization?->tax_identifier ?: '—')
                    : trim(($party->person?->document_type ?? '').' '.($party->person?->document_number ?? '')),
                'is_customer' => (bool) $party->customer,
            ])->values()->all();
    }

    /** Valida los datos del formulario y los devuelve con los nombres de las columnas. */
    private function opportunityData(Request $request, string $tenantId, ?CrmOpportunity $opportunity = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'expected_close_date' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'uuid'],
            'source_id' => ['nullable', 'uuid'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'amount.required' => 'Indica el monto.',
            'amount.numeric' => 'Monto inválido.',
            'amount.min' => 'Monto inválido.',
        ]);

        $sources = CrmCatalog::sources($tenantId);
        $sourceId = $data['source_id'] ?? $opportunity?->source_id ?? $sources->firstWhere('code', CrmCatalog::EXISTING_CUSTOMER)?->id;
        if ($sourceId && ! $sources->contains('id', $sourceId)) {
            throw ValidationException::withMessages(['source_id' => ['La fuente indicada no existe.']]);
        }

        $customerId = $data['customer_id'] ?? null;
        if ($customerId && ! Customer::where('tenant_id', $tenantId)->whereKey($customerId)->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['El cliente indicado no existe.']]);
        }
        // El cliente de una oportunidad ya cotizada no se cambia: sus cotizaciones son de ese cliente.
        if ($opportunity && $opportunity->customer_id && $customerId !== $opportunity->customer_id
            && Quotation::ignoringAccessScope()->where('source_type', 'OPPORTUNITY')->where('source_id', $opportunity->id)->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['La oportunidad ya tiene cotizaciones de su cliente: no se puede cambiar.']]);
        }

        return [
            'name' => trim($data['name']),
            'expected_value' => $data['amount'],
            'customer_id' => $customerId,
            'source_id' => $sourceId,
            ...(array_key_exists('expected_close_date', $data) && $data['expected_close_date']
                ? ['expected_close_date' => $data['expected_close_date']] : []),
        ];
    }

    private function assertOpen(CrmOpportunity $opportunity, string $verb): void
    {
        if ($opportunity->status !== 'OPEN') {
            throw ValidationException::withMessages(['status' => ["No se puede {$verb}: la oportunidad ya está cerrada."]]);
        }
    }

    private function audit(Request $request, CrmOpportunity $opportunity, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'CrmOpportunity',
            entityId: $opportunity->id,
            metadata: $metadata,
        );
    }
}
