<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Operations\Models\CostEntry;
use App\Modules\Operations\Models\Project;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Costos directos de un proyecto (proveedor, licencias, materiales…). Se registran, y otra persona
 * los aprueba o los rechaza. Solo los aprobados cuentan para el margen del proyecto.
 */
class CostEntryController extends Controller
{
    private const PROJECT = "(SELECT concat_ws(' ', p.code, p.name) FROM projects p WHERE p.id = cost_entries.project_id)";

    private const WITH = ['project:id,code,name', 'creator:id,name'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = CostEntry::query()->with(self::WITH)
            ->when(isset(CostEntry::STATES[$request->query('status')]), fn ($q) => $q->where('cost_entries.status', $request->query('status')))
            ->when(Str::isUuid((string) $request->query('project_id')), fn ($q) => $q->where('cost_entries.project_id', $request->query('project_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por descripción, categoría o proyecto.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('cost_entries.description', 'ILIKE', $like)
                    ->orWhere('cost_entries.category', 'ILIKE', $like)
                    ->orWhereRaw(self::PROJECT.' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'cost' => ColumnFilters::text("concat_ws(' ', cost_entries.description, cost_entries.category)"),
            'project' => ColumnFilters::text(self::PROJECT),
            'total' => ColumnFilters::number('cost_entries.amount'),
            'date' => ColumnFilters::date('cost_entries.incurred_at'),
        ]);

        ListSort::apply($query, $request, [
            'cost' => 'lower(cost_entries.description)',
            'project' => 'lower('.self::PROJECT.')',
            'total' => 'cost_entries.amount',
            'date' => 'cost_entries.incurred_at',
            'status' => ListSort::labels('cost_entries.status', CostEntry::STATES),
        ], fn ($q) => $q->orderByDesc('cost_entries.created_at')->orderByDesc('cost_entries.id'), 'cost_entries.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $userId = $request->user()->id;
        $page->getCollection()->transform(fn (CostEntry $cost) => $this->row($cost, $userId));

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'project_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:1000'],
            'category' => ['required', 'in:'.implode(',', CostEntry::CATEGORIES)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'unit_cost' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'incurred_at' => ['nullable', 'date'],
        ], [
            'project_id.required' => 'Elige el proyecto.',
            'description.required' => 'Describe el costo.',
            'quantity.gt' => 'La cantidad debe ser mayor a cero.',
            'unit_cost.gt' => 'El costo unitario debe ser mayor a cero.',
        ]);

        // Por su modelo: un proyecto fuera del alcance de la persona "no existe".
        $project = Project::find($data['project_id']);
        if (! $project || ! in_array($project->status, Project::OPEN, true)) {
            throw ValidationException::withMessages(['project_id' => [$project ? 'El proyecto ya está cerrado.' : 'El proyecto indicado no existe.']]);
        }

        $cost = DB::transaction(function () use ($request, $tenantId, $data, $project) {
            $cost = CostEntry::create([
                'tenant_id' => $tenantId,
                'project_id' => $project->id,
                'category' => $data['category'],
                'description' => trim($data['description']),
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
                'amount' => round((float) $data['quantity'] * (float) $data['unit_cost'], 2),
                'currency' => 'PEN',
                'incurred_at' => $data['incurred_at'] ?? now()->toDateString(),
                'approved' => false,
                'status' => 'SUBMITTED',
                'created_by' => $request->user()->id,
                // El costo es de la sede del proyecto; si no tiene, de la de quien lo registra.
                ...($project->branch_id ? ['branch_id' => $project->branch_id] : []),
            ]);
            $this->audit($request, $cost, 'cost_entry.create', ['to' => 'SUBMITTED', 'amount' => (float) $cost->amount]);

            return $cost;
        });

        return response()->json($this->row($cost->load(self::WITH), $request->user()->id), 201);
    }

    /** Aprueba un costo enviado. Quien lo registró no lo aprueba, salvo que la empresa no segregue funciones. */
    public function approve(string $costEntry, Request $request)
    {
        $cost = $this->find($costEntry);
        $this->assertSubmitted($cost, 'aprobar');

        if ($cost->created_by === $request->user()->id && TenantSettingsController::settings($request->attributes->get('tenant_id'))['segregation']) {
            throw ValidationException::withMessages(['status' => ['No puedes aprobar un costo que registraste tú: lo aprueba otra persona.']]);
        }

        DB::transaction(function () use ($request, $cost) {
            $cost->update(['status' => 'APPROVED', 'approved' => true, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'reject_reason' => null]);
            $this->audit($request, $cost, 'cost_entry.approve', ['from' => 'SUBMITTED', 'to' => 'APPROVED']);
        });

        return response()->json($this->row($cost->load(self::WITH), $request->user()->id));
    }

    public function reject(string $costEntry, Request $request)
    {
        $cost = $this->find($costEntry);
        $this->assertSubmitted($cost, 'rechazar');
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Debes indicar un motivo.'],
        )['reason']);

        DB::transaction(function () use ($request, $cost, $reason) {
            $cost->update(['status' => 'REJECTED', 'approved' => false, 'approved_by' => $request->user()->id, 'approved_at' => now(), 'reject_reason' => $reason]);
            $this->audit($request, $cost, 'cost_entry.reject', ['from' => 'SUBMITTED', 'to' => 'REJECTED', 'note' => $reason]);
        });

        return response()->json($this->row($cost->load(self::WITH), $request->user()->id));
    }

    private function find(string $costEntry): CostEntry
    {
        abort_unless(Str::isUuid($costEntry), 404);

        return CostEntry::findOrFail($costEntry);
    }

    private function assertSubmitted(CostEntry $cost, string $verb): void
    {
        if ($cost->status !== 'SUBMITTED') {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un costo ".mb_strtolower(CostEntry::STATES[$cost->status] ?? $cost->status).'.'],
            ]);
        }
    }

    private function row(CostEntry $cost, string $viewerId): array
    {
        return [
            'id' => $cost->id,
            'description' => $cost->description,
            'category' => $cost->category,
            'quantity' => (float) $cost->quantity,
            'unit_cost' => (float) $cost->unit_cost,
            'total' => (float) $cost->amount,
            'incurred_at' => $cost->incurred_at?->toDateString(),
            'status' => $cost->status,
            'reject_reason' => $cost->reject_reason,
            // null si el proyecto queda fuera del alcance de quien mira.
            'project' => $cost->project ? ['id' => $cost->project->id, 'code' => $cost->project->code, 'name' => $cost->project->name] : null,
            'creator' => $cost->creator ? ['id' => $cost->creator->id, 'name' => $cost->creator->name] : null,
            'own' => $cost->created_by === $viewerId,
            'lock_version' => (int) $cost->lock_version,
        ];
    }

    private function audit(Request $request, CostEntry $cost, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'CostEntry',
            entityId: $cost->id,
            metadata: $metadata,
        );
    }
}
