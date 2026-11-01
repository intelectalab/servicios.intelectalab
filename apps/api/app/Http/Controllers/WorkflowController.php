<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckPermission;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\Workflows;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Workflows (Control → Workflows): las reglas de la empresa y sus ejecuciones. El motor está en
 * App\Services\Workflows.
 */
class WorkflowController extends Controller
{
    /** Las reglas, el catálogo para armarlas (disparadores, campos, acciones) y los roles de la empresa. */
    public function index(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $rules = DB::table('workflow_definitions as w')->leftJoin('users as u', 'u.id', '=', 'w.created_by')
            ->where('w.tenant_id', $tenantId)
            ->orderByDesc('w.active')->orderBy('w.name')
            ->get(['w.*', 'u.name as creator',
                DB::raw('(SELECT count(*) FROM workflow_runs r WHERE r.workflow_definition_id = w.id) AS runs'),
                DB::raw('(SELECT max(r.started_at) FROM workflow_runs r WHERE r.workflow_definition_id = w.id) AS last_run_at')]);
        $roles = DB::table('roles')->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'code']);

        return response()->json([
            'rules' => $rules->map(fn ($rule) => $this->row($rule, $tenantId, $roles->pluck('name', 'id'))),
            'roles' => $roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'code' => strtoupper((string) $role->code)]),
            'catalog' => [
                'triggers' => collect(Workflows::TRIGGERS)->map(fn (array $t, string $key) => [
                    'key' => $key, 'label' => $t[0], 'available' => CheckPermission::moduleAvailable($tenantId, $t[3]),
                    'customer' => ! in_array($key, Workflows::WITHOUT_CUSTOMER, true),
                    'fields' => collect(Workflows::FIELDS[$key] ?? [])->map(fn (array $f, string $field) => [
                        'key' => $field, 'label' => $f[0], 'type' => $f[1],
                        'options' => collect($f[2])->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values(),
                    ])->values(),
                ])->values(),
                'operators' => Workflows::OPERATORS,
                'actions' => Workflows::ACTIONS,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $data = $this->validated($request, $tenantId);

        $id = DB::transaction(function () use ($request, $tenantId, $data) {
            $id = (string) Str::uuid();
            DB::table('workflow_definitions')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'code' => Str::limit(Str::slug($data['name']), 80, '').'-'.Str::lower(Str::random(6)),
                'name' => $data['name'],
                'entity_type' => $data['trigger'],
                'version' => 1,
                'active' => $data['active'] ?? true,
                'configuration' => json_encode(['condition' => $data['condition'], 'actions' => $data['actions']]),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'workflow.create', entityType: 'WorkflowDefinition', entityId: $id,
                afterData: ['name' => $data['name'], 'trigger' => $data['trigger'], 'condition' => $data['condition'], 'actions' => $data['actions']]);

            return $id;
        });

        return response()->json($this->find($id, $tenantId), 201);
    }

    public function update(Request $request, string $workflow)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $before = $this->rule($workflow, $tenantId);
        $data = $this->validated($request, $tenantId);

        DB::transaction(function () use ($request, $tenantId, $data, $before) {
            DB::table('workflow_definitions')->where('id', $before->id)->update([
                'name' => $data['name'],
                'entity_type' => $data['trigger'],
                'configuration' => json_encode(['condition' => $data['condition'], 'actions' => $data['actions']]),
                'updated_by' => $request->user()->id,
                'updated_at' => now(),
            ]);
            $old = json_decode((string) $before->configuration, true) ?: [];
            AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: 'workflow.update', entityType: 'WorkflowDefinition', entityId: $before->id,
                beforeData: ['name' => $before->name, 'trigger' => $before->entity_type, 'condition' => $old['condition'] ?? null, 'actions' => $old['actions'] ?? []],
                afterData: ['name' => $data['name'], 'trigger' => $data['trigger'], 'condition' => $data['condition'], 'actions' => $data['actions']]);
        });

        return response()->json($this->find($before->id, $tenantId));
    }

    /** Activar o desactivar (las reglas no se borran). */
    public function toggle(Request $request, string $workflow)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];
        $rule = $this->rule($workflow, $tenantId);

        if ((bool) $rule->active !== (bool) $active) {
            DB::transaction(function () use ($request, $tenantId, $rule, $active) {
                DB::table('workflow_definitions')->where('id', $rule->id)->update(['active' => $active, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                AuditLogger::log(tenantId: $tenantId, userId: $request->user()->id, action: $active ? 'workflow.activate' : 'workflow.deactivate',
                    entityType: 'WorkflowDefinition', entityId: $rule->id, beforeData: ['active' => (bool) $rule->active], afterData: ['active' => (bool) $active]);
            });
        }

        return response()->json($this->find($rule->id, $tenantId));
    }

    /** Historial de ejecuciones (lo más reciente primero), con filtros y exportación. */
    public function runs(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $query = DB::table('workflow_runs as r')->join('workflow_definitions as w', 'w.id', '=', 'r.workflow_definition_id')
            ->where('r.tenant_id', $tenantId)
            ->when(Str::isUuid((string) $request->query('workflow_id')), fn ($q) => $q->where('r.workflow_definition_id', $request->query('workflow_id')))
            ->when(in_array($request->query('status'), ['DONE', 'SKIPPED', 'FAILED'], true), fn ($q) => $q->where('r.status', $request->query('status')))
            ->select(['r.id', 'r.workflow_definition_id', 'w.name as workflow', 'r.trigger', 'r.entity_label', 'r.matched', 'r.status', 'r.result', 'r.started_at']);

        ColumnFilters::apply($query, $request, [
            'workflow' => ColumnFilters::text('w.name'),
            'record' => ColumnFilters::text('r.entity_label'),
            'result' => ColumnFilters::text('r.result'),
            'date' => ColumnFilters::date("(r.started_at AT TIME ZONE 'America/Lima')"),
        ]);
        ListSort::apply($query, $request, ['workflow' => 'lower(w.name)', 'record' => 'lower(r.entity_label)', 'result' => 'lower(r.result)', 'date' => 'r.started_at', 'status' => 'r.status'],
            fn ($q) => $q->orderByDesc('r.started_at'), 'r.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($run) => [
            'id' => $run->id,
            'workflow_id' => $run->workflow_definition_id,
            'workflow' => $run->workflow,
            'trigger' => $run->trigger,
            'trigger_label' => Workflows::TRIGGERS[$run->trigger][0] ?? $run->trigger,
            'record' => $run->entity_label,
            'matched' => $run->matched,
            'status' => $run->status,
            'result' => $run->result,
            'at' => Carbon::parse($run->started_at)->toIso8601String(),
        ]);

        return response()->json($page);
    }

    // ------------------------------------------------------------------------------------------

    /** Nombre, disparador, condición opcional (campo del disparador) y 1 a 3 acciones. */
    private function validated(Request $request, string $tenantId): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'trigger' => ['required', Rule::in(array_keys(Workflows::TRIGGERS))],
            'condition' => ['nullable', 'array'],
            'condition.field' => ['nullable', 'string'],
            'condition.op' => ['nullable', 'string'],
            'condition.value' => ['nullable'],
            'actions' => ['required', 'array', 'min:1', 'max:3'],
            'actions.*.type' => ['required', Rule::in(array_keys(Workflows::ACTIONS))],
            'actions.*.role_id' => ['nullable', 'uuid'],
            'actions.*.text' => ['required', 'string', 'max:300'],
            'active' => ['sometimes', 'boolean'],
        ], [
            'actions.required' => 'Agrega al menos una acción.',
            'actions.*.text.required' => 'Escribe el texto del aviso.',
        ], ['name' => 'nombre', 'trigger' => 'disparador', 'actions' => 'acciones']);

        $data['name'] = trim($data['name']);
        $trigger = $data['trigger'];

        // Condición: un campo de ese disparador, un operador de su tipo y un valor válido.
        $condition = null;
        if (! empty($data['condition']['field'])) {
            $field = Workflows::FIELDS[$trigger][$data['condition']['field']] ?? null;
            if (! $field) {
                throw ValidationException::withMessages(['condition.field' => ['Ese campo no existe para este disparador.']]);
            }
            [, $type, $options] = $field;
            $op = (string) ($data['condition']['op'] ?? '');
            if (! isset(Workflows::OPERATORS[$type][$op])) {
                throw ValidationException::withMessages(['condition.op' => ['Elige un operador válido.']]);
            }
            $value = $data['condition']['value'] ?? null;
            $value = match ($type) {
                'number' => is_numeric($value) ? (float) $value : throw ValidationException::withMessages(['condition.value' => ['Escribe un número.']]),
                'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? throw ValidationException::withMessages(['condition.value' => ['Elige Sí o No.']]),
                default => isset($options[(string) $value]) ? (string) $value : throw ValidationException::withMessages(['condition.value' => ['Elige un valor de la lista.']]),
            };
            $condition = ['field' => $data['condition']['field'], 'op' => $op, 'value' => $value];
        }

        $roles = DB::table('roles')->where('tenant_id', $tenantId)->pluck('id')->all();
        $actions = [];
        foreach ($data['actions'] as $index => $action) {
            if ($action['type'] === 'notify_role' && ! in_array($action['role_id'] ?? null, $roles, true)) {
                throw ValidationException::withMessages(["actions.{$index}.role_id" => ['Elige un rol de la empresa.']]);
            }
            if ($action['type'] === 'portal_notice' && in_array($trigger, Workflows::WITHOUT_CUSTOMER, true)) {
                throw ValidationException::withMessages(["actions.{$index}.type" => ['Este disparador no tiene cliente: no puede publicar en el portal.']]);
            }
            $actions[] = array_filter([
                'type' => $action['type'],
                'role_id' => $action['type'] === 'notify_role' ? $action['role_id'] : null,
                'text' => trim($action['text']),
            ], fn ($value) => $value !== null);
        }

        return ['name' => $data['name'], 'trigger' => $trigger, 'condition' => $condition, 'actions' => $actions, 'active' => $data['active'] ?? true];
    }

    private function rule(string $id, string $tenantId): object
    {
        abort_unless(Str::isUuid($id), 404);

        return DB::table('workflow_definitions')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404);
    }

    private function find(string $id, string $tenantId): array
    {
        $rule = DB::table('workflow_definitions as w')->leftJoin('users as u', 'u.id', '=', 'w.created_by')
            ->where('w.id', $id)->first(['w.*', 'u.name as creator',
                DB::raw('(SELECT count(*) FROM workflow_runs r WHERE r.workflow_definition_id = w.id) AS runs'),
                DB::raw('(SELECT max(r.started_at) FROM workflow_runs r WHERE r.workflow_definition_id = w.id) AS last_run_at')]);

        return $this->row($rule, $tenantId, DB::table('roles')->where('tenant_id', $tenantId)->pluck('name', 'id'));
    }

    private function row(object $rule, string $tenantId, $roleNames): array
    {
        $config = json_decode((string) $rule->configuration, true) ?: [];
        $trigger = Workflows::TRIGGERS[$rule->entity_type] ?? null;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'trigger' => $rule->entity_type,
            'trigger_label' => $trigger[0] ?? $rule->entity_type,
            'module_on' => $trigger ? CheckPermission::moduleAvailable($tenantId, $trigger[3]) : false,
            'condition' => $config['condition'] ?? null,
            'actions' => collect($config['actions'] ?? [])->map(fn (array $action) => [
                ...$action, 'role' => isset($action['role_id']) ? ($roleNames[$action['role_id']] ?? 'Rol eliminado') : null,
            ])->values(),
            'active' => (bool) $rule->active,
            'runs' => (int) $rule->runs,
            'last_run_at' => $rule->last_run_at ? Carbon::parse($rule->last_run_at)->toIso8601String() : null,
            'creator' => $rule->creator,
            'updated_at' => Carbon::parse($rule->updated_at)->toIso8601String(),
        ];
    }
}
