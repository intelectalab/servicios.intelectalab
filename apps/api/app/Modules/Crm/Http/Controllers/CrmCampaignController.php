<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\CrmCampaign;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Crm\Support\CampaignMembership;
use App\Modules\Crm\Support\LeadScoring;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Campañas: sus miembros (leads) y lo que resultó de ellas. Quien ve campañas ve todas las
 * de la empresa, pero los miembros, las oportunidades y los ingresos se cuentan sobre los
 * leads y oportunidades que su rol alcanza: una campaña no abre lo que el alcance cierra.
 */
class CrmCampaignController extends Controller
{
    /** Leads que se ofrecen para agregar; con más, se acota con el buscador del desplegable. */
    private const AVAILABLE_LIMIT = 300;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = $this->withMetrics(CrmCampaign::query()->with('owner:id,name')->select('crm_campaigns.*'))
            ->when(isset(CrmCampaign::STATES[$request->query('status')]), fn ($q) => $q->where('crm_campaigns.status', $request->query('status')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('crm_campaigns.name', 'ILIKE', $like)
                    ->orWhere('crm_campaigns.code', 'ILIKE', $like)
                    ->orWhere('crm_campaigns.channel', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'campaign' => ColumnFilters::text("concat_ws(' ', crm_campaigns.name, crm_campaigns.code, crm_campaigns.channel)"),
            'start' => ColumnFilters::date('crm_campaigns.starts_at'),
            'budget' => ColumnFilters::number('crm_campaigns.budget'),
        ]);

        ListSort::apply($query, $request, [
            'campaign' => 'lower(crm_campaigns.name)',
            'start' => 'crm_campaigns.starts_at',
            'members' => 'members_count',
            'opportunities' => 'opportunities_count',
            'won' => 'won_count',
            'revenue' => 'revenue',
            'budget' => 'crm_campaigns.budget',
            'status' => ListSort::labels('crm_campaigns.status', CrmCampaign::STATES),
        ], fn ($q) => $q->orderByDesc('crm_campaigns.created_at')->orderByDesc('crm_campaigns.id'), 'crm_campaigns.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (CrmCampaign $campaign) => $this->row($campaign));

        return response()->json($page);
    }

    /** Indicadores del listado, sobre lo que la persona alcanza. */
    public function summary()
    {
        $leads = $this->memberLeads()->selectRaw('count(*) AS members')->toBase()->first();
        $opportunities = $this->memberOpportunities()->selectRaw("
            count(*) AS opportunities,
            COALESCE(sum(crm_opportunities.expected_value) FILTER (WHERE crm_opportunities.status = 'WON'), 0) AS revenue
        ")->toBase()->first();

        return response()->json([
            'active' => CrmCampaign::where('status', 'ACTIVE')->count(),
            'members' => (int) $leads->members,
            'opportunities' => (int) $opportunities->opportunities,
            'revenue' => (float) $opportunities->revenue,
        ]);
    }

    public function show(string $campaign)
    {
        return response()->json($this->detail($this->find($campaign)));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->campaignData($request);

        $campaign = DB::transaction(function () use ($data, $tenantId, $request) {
            $campaign = CrmCampaign::create([
                ...$data,
                'tenant_id' => $tenantId,
                'code' => SequentialNumberGenerator::next($tenantId, 'crm_campaigns', 'code', 'CMP'),
                'status' => 'DRAFT',
                'owner_user_id' => $request->user()->id,
            ]);
            $this->audit($request, $campaign, 'crm_campaign.create', ['to' => 'DRAFT', 'code' => $campaign->code, 'name' => $campaign->name]);

            return $campaign;
        });

        return response()->json($this->detail($campaign), 201);
    }

    /** Datos de la campaña; solo mientras está en borrador o activa. */
    public function update(string $campaign, Request $request)
    {
        $campaign = $this->find($campaign);
        $this->assertOpen($campaign, 'editar');
        $data = $this->campaignData($request);

        DB::transaction(function () use ($campaign, $data, $request) {
            $fields = ['name', 'channel', 'starts_at', 'ends_at', 'budget', 'consent_required'];
            $before = $campaign->only($fields);
            $campaign->update($data);

            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: 'crm_campaign.update',
                entityType: 'CrmCampaign',
                entityId: $campaign->id,
                beforeData: $before,
                afterData: $campaign->only($fields),
            );
        });

        return response()->json($this->detail($campaign));
    }

    public function launch(string $campaign, Request $request)
    {
        return $this->transition($request, $this->find($campaign), ['DRAFT'], 'ACTIVE', 'lanzar', 'crm_campaign.launch');
    }

    public function complete(string $campaign, Request $request)
    {
        return $this->transition($request, $this->find($campaign), ['ACTIVE'], 'COMPLETED', 'finalizar', 'crm_campaign.complete');
    }

    public function cancel(string $campaign, Request $request)
    {
        return $this->transition($request, $this->find($campaign), CrmCampaign::OPEN, 'CANCELLED', 'cancelar', 'crm_campaign.cancel', true);
    }

    /** Agrega un lead como miembro. El lead tiene que estar al alcance de quien lo agrega. */
    public function addMember(string $campaign, Request $request)
    {
        $campaign = $this->find($campaign);
        $data = $request->validate([
            'lead_id' => ['required', 'uuid'],
            'consent' => ['sometimes', 'boolean'],
        ], ['lead_id.required' => 'Elige el lead que quieres agregar.']);

        $lead = CrmLead::find($data['lead_id']);
        if (! $lead) {
            throw ValidationException::withMessages(['lead_id' => ['El lead indicado no existe.']]);
        }

        DB::transaction(fn () => CampaignMembership::add($campaign, $lead, (bool) ($data['consent'] ?? false), $request->user()->id));

        return response()->json($this->detail($campaign), 201);
    }

    /** Excluye a un miembro: deja de contar, pero su fila queda (no se borra). */
    public function excludeMember(string $campaign, string $member, Request $request)
    {
        $campaign = $this->find($campaign);
        abort_unless(Str::isUuid($member), 404);
        $this->assertOpen($campaign, 'cambiar los miembros de');

        $row = DB::table('crm_campaign_members')->where('campaign_id', $campaign->id)->where('id', $member)->first(['id', 'lead_id', 'status']);
        // El lead se busca por su modelo: un miembro fuera del alcance de la persona "no existe".
        $lead = $row?->lead_id ? CrmLead::find($row->lead_id) : null;
        abort_unless($row && $lead, 404);

        if ($row->status === 'EXCLUDED') {
            throw ValidationException::withMessages(['member' => ['El miembro ya estaba excluido.']]);
        }

        DB::transaction(function () use ($campaign, $row, $lead, $request) {
            DB::table('crm_campaign_members')->where('id', $row->id)->update([
                'status' => 'EXCLUDED', 'excluded_at' => now(), 'excluded_by' => $request->user()->id,
            ]);
            // Si era su campaña de origen, el lead deja de estar asociado a una campaña.
            if ($lead->campaign_id === $campaign->id) {
                DB::table('crm_leads')->where('id', $lead->id)->update(['campaign_id' => null]);
                $lead->setAttribute('campaign_id', null)->syncOriginalAttribute('campaign_id');
            }
            LeadScoring::apply($lead);

            $this->audit($request, $campaign, 'crm_campaign.member.exclude', ['lead_id' => $lead->id, 'lead_number' => $lead->lead_number]);
        });

        return response()->json($this->detail($campaign));
    }

    private function find(string $campaign): CrmCampaign
    {
        abort_unless(Str::isUuid($campaign), 404);

        return CrmCampaign::findOrFail($campaign);
    }

    /** Leads que son miembros vigentes de alguna campaña, dentro del alcance de la persona. */
    private function memberLeads()
    {
        return CrmLead::query()
            ->join('crm_campaign_members AS m', 'm.lead_id', '=', 'crm_leads.id')
            ->where('m.status', 'ADDED');
    }

    /** Oportunidades nacidas de leads que son miembros vigentes, dentro del alcance de la persona. */
    private function memberOpportunities()
    {
        return CrmOpportunity::query()
            ->join('crm_campaign_members AS m', 'm.lead_id', '=', 'crm_opportunities.lead_id')
            ->where('m.status', 'ADDED');
    }

    /** Añade a cada campaña sus resultados, contados por los modelos (heredan el alcance del rol). */
    private function withMetrics($query)
    {
        $ofCampaign = fn ($sub) => $sub->whereColumn('m.campaign_id', 'crm_campaigns.id');

        return $query->addSelect([
            'members_count' => $ofCampaign($this->memberLeads())->selectRaw('count(*)'),
            'qualified_count' => $ofCampaign($this->memberLeads())->whereIn('crm_leads.status', ['QUALIFIED', 'CONVERTED'])->selectRaw('count(*)'),
            'opportunities_count' => $ofCampaign($this->memberOpportunities())->selectRaw('count(*)'),
            'won_count' => $ofCampaign($this->memberOpportunities())->where('crm_opportunities.status', 'WON')->selectRaw('count(*)'),
            'revenue' => $ofCampaign($this->memberOpportunities())->where('crm_opportunities.status', 'WON')->selectRaw('COALESCE(sum(crm_opportunities.expected_value), 0)'),
        ]);
    }

    private function row(CrmCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'code' => $campaign->code,
            'name' => $campaign->name,
            'channel' => $campaign->channel,
            'status' => $campaign->status,
            'starts_at' => $campaign->starts_at?->toDateString(),
            'ends_at' => $campaign->ends_at?->toDateString(),
            'budget' => (float) $campaign->budget,
            'consent_required' => (bool) $campaign->consent_required,
            'cancel_reason' => $campaign->cancel_reason,
            'owner' => $campaign->owner ? ['id' => $campaign->owner->id, 'name' => $campaign->owner->name] : null,
            'metrics' => [
                'members' => (int) $campaign->members_count,
                'qualified' => (int) $campaign->qualified_count,
                'opportunities' => (int) $campaign->opportunities_count,
                'won' => (int) $campaign->won_count,
                'revenue' => (float) $campaign->revenue,
            ],
            'created_at' => $campaign->created_at?->toIso8601String(),
            'lock_version' => (int) $campaign->lock_version,
        ];
    }

    private function detail(CrmCampaign $campaign): array
    {
        $campaign = $this->withMetrics(CrmCampaign::query()->with('owner:id,name')->select('crm_campaigns.*'))->findOrFail($campaign->id);

        // Los miembros se listan por el modelo de leads: cada quien ve los que su rol alcanza.
        $members = CrmLead::query()
            ->join('crm_campaign_members AS m', 'm.lead_id', '=', 'crm_leads.id')
            ->where('m.campaign_id', $campaign->id)
            ->orderByRaw("(m.status = 'EXCLUDED')")->orderByDesc('m.created_at')
            ->limit(500)
            ->get([
                'm.id AS member_id', 'm.status AS member_status', 'm.consent', 'm.created_at AS added_at',
                'crm_leads.id', 'crm_leads.lead_number', 'crm_leads.organization_name', 'crm_leads.person_name', 'crm_leads.status',
            ]);
        $totalRows = DB::table('crm_campaign_members')->where('campaign_id', $campaign->id)->count();

        $open = in_array($campaign->status, CrmCampaign::OPEN, true);

        return [
            ...$this->row($campaign),
            'members' => $members->map(fn ($member) => [
                'id' => $member->member_id,
                'status' => $member->member_status,
                'consent' => (bool) $member->consent,
                'added_at' => Carbon::parse($member->added_at)->toIso8601String(),
                'lead' => [
                    'id' => $member->id, 'lead_number' => $member->lead_number,
                    'name' => $member->organization_name ?: $member->person_name, 'person_name' => $member->organization_name ? $member->person_name : null,
                    'status' => $member->status,
                ],
            ])->values(),
            // Miembros que existen pero quedan fuera del alcance de quien mira: solo cuántos son.
            'hidden_members' => max(0, $totalRows - $members->count()),
            // Leads que la persona alcanza y aún no son miembros vigentes.
            'available_leads' => $open ? CrmLead::query()
                ->where('crm_leads.status', '<>', 'DISQUALIFIED')
                ->whereNotIn('crm_leads.id', fn ($sub) => $sub->select('lead_id')->from('crm_campaign_members')
                    ->where('campaign_id', $campaign->id)->where('status', 'ADDED')->whereNotNull('lead_id'))
                ->orderByDesc('crm_leads.created_at')->limit(self::AVAILABLE_LIMIT)
                ->get(['id', 'lead_number', 'organization_name', 'person_name'])
                ->map(fn ($lead) => [
                    'id' => $lead->id, 'number' => $lead->lead_number,
                    'name' => $lead->organization_name ?: $lead->person_name, 'person_name' => $lead->organization_name ? $lead->person_name : null,
                ]) : [],
            'history' => AuditHistory::for('CrmCampaign', $campaign->id),
        ];
    }

    /** Valida el formulario y lo devuelve con los nombres de las columnas. */
    private function campaignData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'consent_required' => ['sometimes', 'boolean'],
        ], [
            'name.required' => 'El nombre es obligatorio.',
            'ends_at.after_or_equal' => 'El fin no puede ser anterior al inicio.',
            'budget.min' => 'El presupuesto no puede ser negativo.',
        ]);

        return [
            'name' => trim($data['name']),
            'channel' => trim((string) ($data['channel'] ?? '')) ?: null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'budget' => $data['budget'] ?? 0,
            'consent_required' => (bool) ($data['consent_required'] ?? true),
        ];
    }

    private function assertOpen(CrmCampaign $campaign, string $verb): void
    {
        if (! in_array($campaign->status, CrmCampaign::OPEN, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una campaña ".mb_strtolower(CrmCampaign::STATES[$campaign->status] ?? $campaign->status).'.'],
            ]);
        }
    }

    private function transition(Request $request, CrmCampaign $campaign, array $from, string $to, string $verb, string $action, bool $needsReason = false)
    {
        if (! in_array($campaign->status, $from, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} una campaña ".mb_strtolower(CrmCampaign::STATES[$campaign->status] ?? $campaign->status).'.'],
            ]);
        }

        $reason = null;
        if ($needsReason) {
            $reason = trim($request->validate(
                ['reason' => ['required', 'string', 'max:1000']],
                ['reason.required' => 'Debes indicar un motivo.'],
            )['reason']);
        }

        $previous = $campaign->status;
        $campaign->update(['status' => $to, ...($needsReason ? ['cancel_reason' => $reason] : [])]);
        $this->audit($request, $campaign, $action, array_filter(['from' => $previous, 'to' => $to, 'note' => $reason]));

        return response()->json($this->detail($campaign));
    }

    private function audit(Request $request, CrmCampaign $campaign, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'CrmCampaign',
            entityId: $campaign->id,
            metadata: $metadata,
        );
    }
}
