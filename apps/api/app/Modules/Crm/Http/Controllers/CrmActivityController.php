<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\CrmActivity;
use App\Modules\Crm\Models\CrmLead;
use App\Modules\Crm\Models\CrmOpportunity;
use App\Modules\Crm\Support\LeadScoring;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Actividades comerciales: llamadas, reuniones, correos, demos y visitas ligadas a un lead o
 * a una oportunidad. Respetan el alcance del rol: cada quien ve las que registró y las de los
 * leads y oportunidades que lleva.
 */
class CrmActivityController extends Controller
{
    private const RELATIONS = [
        'owner:id,name',
        'lead:id,lead_number,organization_name,person_name',
        'opportunity:id,opportunity_number,name',
    ];

    /** Nombre de la oportunidad o del lead al que pertenece la actividad. */
    private const RELATED = '(COALESCE(
            (SELECT o.name FROM crm_opportunities o WHERE o.id = crm_activities.opportunity_id),
            (SELECT COALESCE(l.organization_name, l.person_name) FROM crm_leads l WHERE l.id = crm_activities.lead_id)))';

    /** Tope de opciones que se ofrecen en el formulario; con más, se busca desde la ficha. */
    private const OPTIONS_LIMIT = 300;

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $uuid = fn (string $key) => Str::isUuid((string) $request->query($key)) ? $request->query($key) : null;
        $status = (string) $request->query('status', '');

        $query = CrmActivity::query()
            ->with(self::RELATIONS)
            ->select('crm_activities.*')
            ->when($status === 'pending', fn ($q) => $q->whereNull('crm_activities.completed_at'))
            ->when($status === 'done', fn ($q) => $q->whereNotNull('crm_activities.completed_at'))
            ->when(isset(CrmActivity::TYPES[$request->query('type')]), fn ($q) => $q->where('crm_activities.activity_type', $request->query('type')))
            ->when($uuid('lead_id'), fn ($q, $id) => $q->where('crm_activities.lead_id', $id))
            ->when($uuid('opportunity_id'), fn ($q, $id) => $q->where('crm_activities.opportunity_id', $id))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('crm_activities.subject', 'ILIKE', $like)
                    ->orWhere('crm_activities.description', 'ILIKE', $like)
                    ->orWhereRaw(self::RELATED.' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'activity' => ColumnFilters::text("concat_ws(' ', crm_activities.subject, crm_activities.description)"),
            'related' => ColumnFilters::text(self::RELATED),
            'due' => ColumnFilters::date('crm_activities.due_at'),
            'owner' => ColumnFilters::text('(SELECT u.name FROM users u WHERE u.id = crm_activities.owner_user_id)'),
        ]);

        ListSort::apply($query, $request, [
            'activity' => 'lower(crm_activities.subject)',
            'type' => ListSort::labels('crm_activities.activity_type', CrmActivity::TYPES),
            'related' => 'lower('.self::RELATED.')',
            'due' => 'crm_activities.due_at',
            'owner' => '(SELECT lower(u.name) FROM users u WHERE u.id = crm_activities.owner_user_id)',
            'status' => '(crm_activities.completed_at IS NOT NULL)::int',
        ], fn ($q) => $q->orderByRaw('crm_activities.due_at ASC NULLS LAST')->orderBy('crm_activities.id'), 'crm_activities.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (CrmActivity $activity) => $this->row($activity));

        return response()->json($page);
    }

    /**
     * Indicadores: atrasadas, para hoy, próximas y realizadas, dentro de lo que la persona
     * alcanza. "Hoy" es el día de quien mira: la pantalla envía dónde empieza y dónde termina.
     */
    public function summary(Request $request)
    {
        $data = $request->validate(['day_start' => ['nullable', 'date'], 'day_end' => ['nullable', 'date']]);
        $start = isset($data['day_start']) ? Carbon::parse($data['day_start']) : now()->startOfDay();
        $end = isset($data['day_end']) ? Carbon::parse($data['day_end']) : $start->copy()->addDay();

        $row = CrmActivity::query()->selectRaw('
            count(*) FILTER (WHERE completed_at IS NULL AND due_at < ?) AS late,
            count(*) FILTER (WHERE completed_at IS NULL AND due_at >= ? AND due_at < ?) AS today,
            count(*) FILTER (WHERE completed_at IS NULL AND (due_at >= ? OR due_at IS NULL)) AS upcoming,
            count(*) FILTER (WHERE completed_at IS NOT NULL) AS done
        ', [$start, $start, $end, $end])->toBase()->first();

        return response()->json([
            'late' => (int) $row->late, 'today' => (int) $row->today, 'upcoming' => (int) $row->upcoming, 'done' => (int) $row->done,
        ]);
    }

    /** Lo que el formulario ofrece para enlazar la actividad: oportunidades y leads abiertos que la persona alcanza. */
    public function options()
    {
        return response()->json([
            'opportunities' => CrmOpportunity::query()->where('status', 'OPEN')->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'opportunity_number', 'name'])
                ->map(fn ($opportunity) => ['id' => $opportunity->id, 'number' => $opportunity->opportunity_number, 'name' => $opportunity->name]),
            'leads' => CrmLead::query()->whereIn('status', CrmLead::OPEN)->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)
                ->get(['id', 'lead_number', 'organization_name', 'person_name'])
                ->map(fn ($lead) => ['id' => $lead->id, 'number' => $lead->lead_number, 'name' => $lead->organization_name ?: $lead->person_name]),
            'types' => collect(CrmActivity::TYPES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->activityData($request);

        $activity = DB::transaction(function () use ($data, $tenantId, $request) {
            $activity = CrmActivity::create([...$data, 'tenant_id' => $tenantId, 'owner_user_id' => $request->user()->id]);

            $this->audit($request, $activity, 'crm_activity.create', [
                'subject' => $activity->subject, 'type' => $activity->activity_type,
                ...array_filter(['lead_id' => $activity->lead_id, 'opportunity_id' => $activity->opportunity_id]),
            ]);
            // Las interacciones registradas cuentan para el puntaje del lead.
            LeadScoring::applyById($activity->lead_id);

            return $activity;
        });

        return response()->json($this->row($activity->load(self::RELATIONS)), 201);
    }

    /** Solo mientras la actividad está pendiente: una realizada se reabre antes de corregirla. */
    public function update(string $activity, Request $request)
    {
        $activity = $this->find($activity);
        if ($activity->completed_at) {
            throw ValidationException::withMessages(['status' => ['La actividad ya está realizada. Reábrela para editarla.']]);
        }
        $data = $this->activityData($request);

        DB::transaction(function () use ($activity, $data, $request) {
            $before = $activity->only(['subject', 'activity_type', 'due_at', 'description', 'lead_id', 'opportunity_id']);
            $previousLead = $activity->lead_id;
            $activity->update([...$data, 'updated_at' => now()]);

            AuditLogger::log(
                tenantId: $request->attributes->get('tenant_id'),
                userId: $request->user()->id,
                action: 'crm_activity.update',
                entityType: 'CrmActivity',
                entityId: $activity->id,
                beforeData: $before,
                afterData: $activity->only(['subject', 'activity_type', 'due_at', 'description', 'lead_id', 'opportunity_id']),
            );
            foreach (array_unique(array_filter([$previousLead, $activity->lead_id])) as $leadId) {
                LeadScoring::applyById($leadId);
            }
        });

        return response()->json($this->row($activity->load(self::RELATIONS)));
    }

    /** Marca la actividad como realizada o, si ya lo estaba, la reabre. */
    public function toggle(string $activity, Request $request)
    {
        $activity = $this->find($activity);
        $done = $activity->completed_at === null;

        $activity->update([
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $request->user()->id : null,
            'updated_at' => now(),
        ]);
        $this->audit($request, $activity, $done ? 'crm_activity.complete' : 'crm_activity.reopen', ['subject' => $activity->subject]);

        return response()->json($this->row($activity->load(self::RELATIONS)));
    }

    private function find(string $activity): CrmActivity
    {
        abort_unless(Str::isUuid($activity), 404);

        return CrmActivity::findOrFail($activity);
    }

    /** Valida el formulario y comprueba que el lead o la oportunidad indicados estén al alcance de quien registra. */
    private function activityData(Request $request): array
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'activity_type' => ['required', Rule::in(array_keys(CrmActivity::TYPES))],
            'due_at' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'lead_id' => ['nullable', 'uuid'],
            'opportunity_id' => ['nullable', 'uuid'],
        ], [
            'subject.required' => 'El asunto es obligatorio.',
            'activity_type.required' => 'Indica el tipo de actividad.',
            'activity_type.in' => 'El tipo de actividad no existe.',
            'due_at.required' => 'Indica la fecha y la hora.',
            'due_at.date' => 'La fecha no es válida.',
        ]);

        $leadId = $data['lead_id'] ?? null;
        $opportunityId = $data['opportunity_id'] ?? null;
        if ($leadId && $opportunityId) {
            throw ValidationException::withMessages(['opportunity_id' => ['La actividad se enlaza a un lead o a una oportunidad, no a los dos.']]);
        }
        // Se consultan por sus modelos: lo que queda fuera del alcance de la persona "no existe".
        if ($leadId && ! CrmLead::whereKey($leadId)->exists()) {
            throw ValidationException::withMessages(['lead_id' => ['El lead indicado no existe.']]);
        }
        if ($opportunityId && ! CrmOpportunity::whereKey($opportunityId)->exists()) {
            throw ValidationException::withMessages(['opportunity_id' => ['La oportunidad indicada no existe.']]);
        }

        return [
            'subject' => trim($data['subject']),
            'activity_type' => $data['activity_type'],
            'due_at' => Carbon::parse($data['due_at']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'lead_id' => $leadId,
            'opportunity_id' => $opportunityId,
        ];
    }

    private function row(CrmActivity $activity): array
    {
        $related = match (true) {
            $activity->opportunity_id !== null => [
                'type' => 'opportunity', 'id' => $activity->opportunity_id,
                'number' => $activity->opportunity?->opportunity_number, 'name' => $activity->opportunity?->name,
            ],
            $activity->lead_id !== null => [
                'type' => 'lead', 'id' => $activity->lead_id,
                'number' => $activity->lead?->lead_number, 'name' => $activity->lead ? ($activity->lead->organization_name ?: $activity->lead->person_name) : null,
            ],
            default => null,
        };

        return [
            'id' => $activity->id,
            'subject' => $activity->subject,
            'description' => $activity->description,
            'activity_type' => $activity->activity_type,
            'due_at' => $activity->due_at?->toIso8601String(),
            'completed_at' => $activity->completed_at?->toIso8601String(),
            'done' => $activity->completed_at !== null,
            'lead_id' => $activity->lead_id,
            'opportunity_id' => $activity->opportunity_id,
            'related' => $related,
            'owner' => $activity->owner ? ['id' => $activity->owner->id, 'name' => $activity->owner->name] : null,
            'lock_version' => (int) $activity->lock_version,
        ];
    }

    private function audit(Request $request, CrmActivity $activity, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'CrmActivity',
            entityId: $activity->id,
            metadata: $metadata,
        );
    }
}
