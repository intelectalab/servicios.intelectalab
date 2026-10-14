<?php

namespace App\Modules\Operations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operations\Models\Checklist;
use App\Modules\Operations\Models\ChecklistItem;
use App\Modules\Operations\Models\WorkOrder;
use App\Modules\Services\Models\Service;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SpreadsheetImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de checklist: lo que se copia a una orden de trabajo al crearla. Cambiar una plantilla
 * no toca las órdenes que ya la usan. No se borran: se desactivan; sus ítems no se editan, se
 * retiran y se agregan otros (lo garantiza la base). Un ítem puede pedir evidencia obligatoria.
 */
class ChecklistController extends Controller
{
    public const MAX_ITEMS = 100;

    /** Columnas de la plantilla de importación (una fila por ítem). */
    public const IMPORT_HEADERS = ['plantilla', 'descripcion', 'servicio', 'item', 'obligatorio', 'evidencia'];

    private const IMPORT_ALIASES = [
        'nombre' => 'plantilla', 'nombre_de_la_plantilla' => 'plantilla', 'checklist' => 'plantilla',
        'codigo_de_servicio' => 'servicio', 'codigo_servicio' => 'servicio',
        'actividad' => 'item', 'texto' => 'item', 'item_del_checklist' => 'item',
        'obligatoria' => 'obligatorio', 'requerido' => 'obligatorio',
        'evidencia_obligatoria' => 'evidencia', 'foto' => 'evidencia', 'requiere_evidencia' => 'evidencia',
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = $this->listing()
            ->when(in_array($request->query('status'), ['ACTIVE', 'INACTIVE'], true), fn ($q) => $q->where('checklists.active', $request->query('status') === 'ACTIVE'))
            ->when(Str::isUuid((string) $request->query('service_id')), fn ($q) => $q->where('checklists.service_id', $request->query('service_id')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('checklists.name', 'ILIKE', $like)
                    ->orWhere('checklists.description', 'ILIKE', $like)
                    ->orWhereHas('service', fn ($s) => $s->where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like))
                    ->orWhereHas('items', fn ($i) => $i->where('label', 'ILIKE', $like)));
            });

        ColumnFilters::apply($query, $request, [
            'name' => ColumnFilters::text("concat_ws(' ', checklists.name, checklists.description)"),
            'service' => ColumnFilters::text(self::SERVICE_NAME),
            'items' => ColumnFilters::number('('.self::ITEMS_COUNT.')'),
            'updated' => ColumnFilters::date('checklists.updated_at'),
        ]);

        ListSort::apply($query, $request, [
            'name' => 'lower(checklists.name)',
            'service' => 'lower('.self::SERVICE_NAME.')',
            'items' => 'items_count',
            'required' => 'required_count',
            'work_orders' => 'work_orders_count',
            'updated' => 'checklists.updated_at',
            'status' => 'checklists.active',
        ], fn ($q) => $q->orderBy('checklists.active', 'desc')->orderBy('checklists.name')->orderBy('checklists.id'), 'checklists.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (Checklist $checklist) => $this->row($checklist));

        return response()->json($page);
    }

    /** Indicadores: plantillas activas, ítems obligatorios, con evidencia y OT abiertas que usan una. */
    public function summary()
    {
        $active = Checklist::query()->where('active', true);

        return response()->json([
            'active' => (clone $active)->count(),
            'inactive' => Checklist::query()->where('active', false)->count(),
            'required_items' => ChecklistItem::query()->whereNull('removed_at')->where('required', true)
                ->whereIn('checklist_id', (clone $active)->select('id'))->count(),
            'with_evidence' => (clone $active)->whereHas('items', fn ($q) => $q->where('evidence_required', true))->count(),
            'with_service' => (clone $active)->whereNotNull('service_id')->count(),
            // Por su modelo: las órdenes que la persona alcanza.
            'open_work_orders' => WorkOrder::query()->whereNotNull('checklist_id')->whereIn('status', WorkOrder::OPEN)->count(),
        ]);
    }

    /** Servicios del catálogo que se pueden asociar. */
    public function options()
    {
        return response()->json([
            'services' => Service::query()->where('status', '<>', 'ARCHIVED')->orderBy('name')->limit(500)
                ->get(['id', 'code', 'name', 'status'])
                ->map(fn (Service $service) => ['id' => $service->id, 'code' => $service->code, 'name' => $service->name, 'active' => $service->status === 'ACTIVE']),
        ]);
    }

    public function show(string $checklist)
    {
        return response()->json($this->detail($this->find($checklist)));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->validated($request);

        $checklist = DB::transaction(function () use ($request, $tenantId, $data) {
            $checklist = $this->create($tenantId, $request->user()->id, $data);
            $this->audit($request, $checklist, 'checklist.create', ['note' => $this->itemsNote(count($data['items']))]);

            return $checklist;
        });

        return response()->json($this->detail($checklist), 201);
    }

    /** Edita la plantilla. Si cambian los ítems, los anteriores quedan retirados y entran los nuevos. */
    public function update(string $checklist, Request $request)
    {
        $checklist = $this->find($checklist);
        $data = $this->validated($request, $checklist);

        DB::transaction(function () use ($request, $checklist, $data) {
            $before = $checklist->items->map(fn (ChecklistItem $item) => [$item->label, $item->required, $item->evidence_required])->all();
            $after = array_map(fn (array $item) => [$item['label'], $item['required'], $item['evidence_required']], $data['items']);
            $changes = [];

            if ($checklist->name !== $data['name']) {
                $changes[] = "Nombre: {$checklist->name} → {$data['name']}";
            }
            if ($checklist->service_id !== $data['service_id']) {
                $changes[] = 'Servicio: '.($data['service_id'] ? Service::whereKey($data['service_id'])->value('name') : 'sin servicio');
            }
            $checklist->update(['name' => $data['name'], 'description' => $data['description'], 'service_id' => $data['service_id']]);

            if ($before !== $after) {
                ChecklistItem::query()->where('checklist_id', $checklist->id)->whereNull('removed_at')->update(['removed_at' => now()]);
                $this->insertItems($checklist, $data['items']);
                $changes[] = 'Ítems: '.count($before).' → '.count($after);
            }

            $this->audit($request, $checklist, 'checklist.update', ['note' => $changes ? implode(' · ', $changes) : 'Sin cambios en los ítems']);
        });

        return response()->json($this->detail($checklist->fresh()));
    }

    /** Copia la plantilla con otro nombre: "(copia)", "(copia 2)"… */
    public function duplicate(string $checklist, Request $request)
    {
        $source = $this->find($checklist);
        $name = $this->freeName($source->name);

        $copy = DB::transaction(function () use ($request, $source, $name) {
            $copy = $this->create($request->attributes->get('tenant_id'), $request->user()->id, [
                'name' => $name, 'description' => $source->description, 'service_id' => $source->service_id,
                'items' => $source->items->map(fn (ChecklistItem $item) => [
                    'label' => $item->label, 'required' => $item->required, 'evidence_required' => $item->evidence_required,
                ])->all(),
            ]);
            $this->audit($request, $copy, 'checklist.duplicate', ['note' => "Copia de {$source->name}"]);

            return $copy;
        });

        return response()->json($this->detail($copy), 201);
    }

    public function deactivate(string $checklist, Request $request)
    {
        return $this->toggle($request, $this->find($checklist), false);
    }

    public function activate(string $checklist, Request $request)
    {
        return $this->toggle($request, $this->find($checklist), true);
    }

    public function importTemplate()
    {
        $services = Service::query()->where('status', 'ACTIVE')->orderBy('code')->limit(500)->pluck('code')->all();

        return SpreadsheetImport::template('plantilla_checklists.xlsx', ...self::importTemplateParts($services));
    }

    /** Título, columnas, ejemplos, instrucciones, listas y anchos de la plantilla de importación. */
    public static function importTemplateParts(array $services): array
    {
        return [
            'Plantillas de checklist',
            self::IMPORT_HEADERS,
            [
                ['Mantenimiento preventivo', 'Visita mensual a equipos', '', 'Inspección visual del equipo', 'Sí', 'No'],
                ['Mantenimiento preventivo', '', '', 'Medición de parámetros', 'Sí', 'Sí'],
                ['Mantenimiento preventivo', '', '', 'Limpieza y ajuste', 'No', 'No'],
                ['Cierre de proyecto', 'Antes de cerrar el proyecto', '', 'Acta de conformidad firmada', 'Sí', 'Sí'],
                ['Cierre de proyecto', '', '', 'Entregables aprobados', 'Sí', 'No'],
                ['Cierre de proyecto', '', '', 'Encuesta de satisfacción', 'No', 'No'],
            ],
            [
                'Una fila por cada ítem. Las filas con el mismo nombre de plantilla forman una plantilla, en el orden del archivo.',
                'plantilla e item son obligatorios. descripcion y servicio basta con ponerlos en la primera fila de cada plantilla.',
                'servicio: el código del servicio del catálogo (se propone al crear la OT de ese servicio). Déjalo vacío si no aplica.',
                'obligatorio: Sí si la OT no se puede completar sin ese ítem. evidencia: Sí si el ítem no se marca sin su foto o archivo.',
                'Una plantilla con un nombre que ya existe no se importa: edítala en la pantalla. Máximo '.self::MAX_ITEMS.' ítems por plantilla.',
                'Reemplaza las filas de ejemplo por las tuyas antes de importar.',
            ],
            ['servicio' => $services, 'obligatorio' => ['Sí', 'No'], 'evidencia' => ['Sí', 'No']],
            [],
            ['plantilla' => 32, 'descripcion' => 34, 'servicio' => 18, 'item' => 46, 'obligatorio' => 14, 'evidencia' => 14],
        ];
    }

    /** Importa plantillas nuevas desde Excel o CSV. Lo que no se puede importar se avisa por fila. */
    public function import(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $request->validate(['file' => ['required', 'file', 'max:5120']], [
            'file.required' => 'Selecciona el archivo con las plantillas.',
            'file.max' => 'El archivo supera los 5 MB: impórtalo por partes.',
        ]);
        [, $rows] = SpreadsheetImport::read($request->file('file'), self::IMPORT_ALIASES, ['plantilla', 'item']);

        $warnings = [];
        $groups = [];
        foreach ($rows as $line => $row) {
            $name = $this->clean($row['plantilla'] ?? '');
            $label = $this->clean($row['item'] ?? '');
            if ($name === '' || $label === '') {
                $warnings[] = "Fila {$line}: falta ".($name === '' ? 'el nombre de la plantilla' : 'el texto del ítem').'.';

                continue;
            }
            $key = mb_strtolower($name);
            $groups[$key] ??= ['name' => Str::limit($name, 255, ''), 'description' => null, 'service' => '', 'line' => $line, 'items' => [], 'labels' => []];
            $groups[$key]['description'] ??= $this->clean($row['descripcion'] ?? '') ?: null;
            if ($groups[$key]['service'] === '') {
                $groups[$key]['service'] = $this->clean($row['servicio'] ?? '');
            }
            if (isset($groups[$key]['labels'][mb_strtolower($label)])) {
                $warnings[] = "Fila {$line}: el ítem \"{$label}\" se repite en {$name}; se tomó una vez.";

                continue;
            }
            $groups[$key]['labels'][mb_strtolower($label)] = true;
            $groups[$key]['items'][] = [
                'label' => Str::limit($label, 500, ''),
                'required' => SpreadsheetImport::truthy($row['obligatorio'] ?? ''),
                'evidence_required' => SpreadsheetImport::truthy($row['evidencia'] ?? ''),
            ];
        }

        $services = Service::query()->where('status', '<>', 'ARCHIVED')->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id]);
        $existing = Checklist::query()->pluck('name')->map(fn ($name) => mb_strtolower(trim($name)))->flip();
        $ready = [];
        foreach ($groups as $key => $group) {
            if (isset($existing[$key])) {
                $warnings[] = "Fila {$group['line']}: ya existe una plantilla llamada {$group['name']}; no se importó.";

                continue;
            }
            if (count($group['items']) > self::MAX_ITEMS) {
                $warnings[] = "Fila {$group['line']}: {$group['name']} tiene más de ".self::MAX_ITEMS.' ítems; no se importó.';

                continue;
            }
            $serviceId = null;
            if ($group['service'] !== '') {
                $serviceId = $services[mb_strtolower($group['service'])] ?? null;
                if (! $serviceId) {
                    $warnings[] = "Fila {$group['line']}: no existe el servicio {$group['service']}; {$group['name']} no se importó.";

                    continue;
                }
            }
            $ready[] = ['name' => $group['name'], 'description' => $group['description'], 'service_id' => $serviceId, 'items' => $group['items']];
        }

        if (! $ready) {
            throw ValidationException::withMessages(['file' => ['No se importó ninguna plantilla.', ...$warnings]]);
        }

        $items = DB::transaction(function () use ($request, $tenantId, $ready) {
            $total = 0;
            foreach ($ready as $data) {
                $checklist = $this->create($tenantId, $request->user()->id, $data);
                $this->audit($request, $checklist, 'checklist.import', ['note' => $this->itemsNote(count($data['items'])).' · desde '.Str::limit((string) $request->file('file')->getClientOriginalName(), 120)]);
                $total += count($data['items']);
            }

            return $total;
        });

        $count = count($ready);

        return response()->json([
            'imported' => $count,
            'message' => ($count === 1 ? '1 plantilla importada' : "{$count} plantillas importadas")." con {$items} ".($items === 1 ? 'ítem' : 'ítems').'.',
            'warnings' => $warnings,
        ]);
    }

    // ------------------------------------------------------------------------------------------

    private const SERVICE_NAME = "COALESCE((SELECT s.name FROM services s WHERE s.id = checklists.service_id), '')";

    private const ITEMS_COUNT = 'SELECT count(*) FROM checklist_items ci WHERE ci.checklist_id = checklists.id AND ci.removed_at IS NULL';

    /** Con lo que la tabla muestra en cada fila, para no pedir nada fila por fila. */
    private function listing(): Builder
    {
        return Checklist::query()
            ->select('checklists.*')
            ->with('service:id,code,name', 'creator:id,name')
            ->withCount([
                'items',
                'items as required_count' => fn ($q) => $q->where('required', true),
                'items as evidence_count' => fn ($q) => $q->where('evidence_required', true),
                // Por su modelo: solo las órdenes de trabajo que la persona alcanza.
                'workOrders',
            ]);
    }

    private function find(string $checklist): Checklist
    {
        abort_unless(Str::isUuid($checklist), 404);

        return $this->listing()->findOrFail($checklist);
    }

    private function row(Checklist $checklist): array
    {
        return [
            'id' => $checklist->id,
            'name' => $checklist->name,
            'description' => $checklist->description,
            'active' => (bool) $checklist->active,
            'service' => $checklist->service ? ['id' => $checklist->service->id, 'code' => $checklist->service->code, 'name' => $checklist->service->name] : null,
            'items_count' => (int) $checklist->items_count,
            'required_count' => (int) $checklist->required_count,
            'evidence_count' => (int) $checklist->evidence_count,
            'work_orders_count' => (int) $checklist->work_orders_count,
            'creator' => $checklist->creator?->name,
            'created_at' => $checklist->created_at?->toIso8601String(),
            'updated_at' => $checklist->updated_at?->toIso8601String(),
            'lock_version' => (int) $checklist->lock_version,
        ];
    }

    private function detail(Checklist $checklist): array
    {
        $checklist = $this->find($checklist->id);

        return [
            ...$this->row($checklist),
            'items' => $checklist->items->map(fn (ChecklistItem $item) => [
                'id' => $item->id, 'position' => $item->position, 'label' => $item->label,
                'required' => (bool) $item->required, 'evidence_required' => (bool) $item->evidence_required,
            ])->values(),
            'open_work_orders' => WorkOrder::query()->where('checklist_id', $checklist->id)->whereIn('status', WorkOrder::OPEN)->count(),
            'history' => AuditHistory::for('Checklist', $checklist->id),
        ];
    }

    /** Datos validados y limpios. El nombre no se repite en la empresa (sin distinguir mayúsculas). */
    private function validated(Request $request, ?Checklist $current = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'service_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.label' => ['required', 'string', 'max:500'],
            'items.*.required' => ['nullable', 'boolean'],
            'items.*.evidence_required' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Indica el nombre de la plantilla.',
            'items.required' => 'Agrega al menos un ítem.',
            'items.min' => 'Agrega al menos un ítem.',
            'items.max' => 'Una plantilla admite como mucho '.self::MAX_ITEMS.' ítems.',
            'items.*.label.required' => 'Escribe el texto del ítem.',
        ]);

        $name = $this->clean($data['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Indica el nombre de la plantilla.']]);
        }
        $taken = Checklist::query()->whereRaw('lower(btrim(name)) = ?', [mb_strtolower($name)])
            ->when($current, fn ($q) => $q->whereKeyNot($current->id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => ['Ya existe una plantilla con ese nombre.']]);
        }

        $serviceId = $data['service_id'] ?? null;
        if ($serviceId && ! Service::query()->whereKey($serviceId)->where('status', '<>', 'ARCHIVED')->exists()) {
            throw ValidationException::withMessages(['service_id' => ['El servicio indicado no existe.']]);
        }

        $items = [];
        $seen = [];
        foreach ($data['items'] as $index => $item) {
            $label = $this->clean($item['label']);
            if ($label === '') {
                throw ValidationException::withMessages(["items.{$index}.label" => ['Escribe el texto del ítem.']]);
            }
            if (isset($seen[mb_strtolower($label)])) {
                throw ValidationException::withMessages(["items.{$index}.label" => ['Este ítem ya está en la plantilla.']]);
            }
            $seen[mb_strtolower($label)] = true;
            $items[] = ['label' => $label, 'required' => (bool) ($item['required'] ?? false), 'evidence_required' => (bool) ($item['evidence_required'] ?? false)];
        }

        return ['name' => $name, 'description' => $this->clean($data['description'] ?? '') ?: null, 'service_id' => $serviceId, 'items' => $items];
    }

    private function create(string $tenantId, string $userId, array $data): Checklist
    {
        $checklist = Checklist::create([
            'tenant_id' => $tenantId, 'name' => $data['name'], 'description' => $data['description'],
            'service_id' => $data['service_id'], 'active' => true, 'created_by' => $userId,
        ]);
        $this->insertItems($checklist, $data['items']);

        return $checklist;
    }

    private function insertItems(Checklist $checklist, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            ChecklistItem::create([
                'tenant_id' => $checklist->tenant_id, 'checklist_id' => $checklist->id, 'position' => $position + 1,
                'label' => $item['label'], 'required' => $item['required'], 'evidence_required' => $item['evidence_required'],
            ]);
        }
    }

    private function toggle(Request $request, Checklist $checklist, bool $active)
    {
        if ((bool) $checklist->active === $active) {
            throw ValidationException::withMessages(['status' => [$active ? 'La plantilla ya está activa.' : 'La plantilla ya está inactiva.']]);
        }
        DB::transaction(function () use ($request, $checklist, $active) {
            $checklist->update(['active' => $active]);
            $this->audit($request, $checklist, $active ? 'checklist.activate' : 'checklist.deactivate', [
                'note' => $active ? 'Vuelve a ofrecerse al crear órdenes de trabajo' : 'Deja de ofrecerse al crear órdenes de trabajo; las que ya la usan no cambian',
            ]);
        });

        return response()->json($this->detail($checklist));
    }

    private function freeName(string $name): string
    {
        $base = Str::limit("{$name} (copia", 240, '');
        $taken = Checklist::query()->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip();
        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? "{$base})" : "{$base} {$n})";
            if (! isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }
    }

    private function itemsNote(int $count): string
    {
        return $count === 1 ? '1 ítem' : "{$count} ítems";
    }

    private function clean(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    }

    private function audit(Request $request, Checklist $checklist, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Checklist',
            entityId: $checklist->id,
            metadata: array_filter($metadata),
        );
    }
}
