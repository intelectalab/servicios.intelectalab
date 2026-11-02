<?php

namespace App\Http\Controllers;

use App\Http\Middleware\CheckPermission;
use App\Models\ImportJob;
use App\Modules\Subscription\Support\TenantModules;
use App\Services\ColumnFilters;
use App\Services\ImportReport;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Importación masiva (Administración): qué puede importar la persona, el historial de
 * importaciones (con su alcance) y el detalle de cada una. Validar e importar van a la ruta de
 * cada entidad (/parties/import, /services/import, /crm/leads/import), con `dry_run` para validar:
 * las mismas reglas y los mismos permisos que desde su listado.
 */
class ImportController extends Controller
{
    /**
     * Entidades de la pantalla: ruta, permiso de crear, módulo del plan (null = obligatorio),
     * columnas de la plantilla y cuáles son obligatorias.
     */
    public const ENTITIES = [
        'parties' => [
            'endpoint' => '/v1/parties', 'permission' => 'core.manage', 'module' => null,
            'template' => 'plantilla_importacion_clientes_y_actores.xlsx',
            'columns' => ['tipo', 'razon_social', 'nombre_comercial', 'nombres', 'apellidos', 'tipo_documento', 'numero_documento', 'roles', 'correo', 'prefijo', 'telefono', 'direccion', 'sector', 'nivel_sla', 'cargo', 'contacto_de', 'dias_credito', 'limite_credito', 'moneda'],
            'required' => [],
            'hint' => 'Una fila por organización (razon_social) o persona (nombres), con sus roles. Si el documento ya existe no se duplica: se le agregan los roles que falten. Las columnas que agregues al final se vuelven columnas personalizadas.',
        ],
        'services' => [
            'endpoint' => '/v1/services', 'permission' => 'services.manage', 'module' => 'SERVICIOS',
            'template' => 'plantilla_importacion_servicios.xlsx',
            'columns' => ['codigo', 'nombre', 'categoria', 'unidad', 'modalidad', 'frecuencia', 'garantia_meses', 'precio', 'descripcion'],
            'required' => ['codigo', 'nombre', 'categoria'],
            'hint' => 'Los servicios entran en borrador y se activan desde su ficha. Un código que ya existe no se repite.',
        ],
        'leads' => [
            'endpoint' => '/v1/crm/leads', 'permission' => 'leads.manage', 'module' => 'CRM',
            'template' => 'plantilla_importacion_leads.xlsx',
            'columns' => ['empresa', 'contacto', 'cargo', 'correo', 'telefono', 'fuente', 'interes'],
            'required' => ['fuente'],
            'hint' => 'Empresa o contacto, y la fuente. Los leads entran como Nuevos, a tu nombre. Un correo con un lead abierto no se repite.',
        ],
    ];

    public function index(Request $request)
    {
        $tenantId = (string) $request->attributes->get('tenant_id');
        $modules = TenantModules::enabledCodes($tenantId);
        $canImport = CheckPermission::granted($request, 'imports.create');

        $entities = [];
        foreach (self::ENTITIES as $key => $entity) {
            // Fuera del plan o sin permiso de crear esa entidad: no se ofrece.
            if ($entity['module'] && ! in_array($entity['module'], $modules, true)) {
                continue;
            }
            if (! CheckPermission::granted($request, $entity['permission'])) {
                continue;
            }
            $entities[] = ['key' => $key, 'label' => ImportReport::ENTITIES[$key], 'can_import' => $canImport] + $entity;
        }

        return response()->json([
            'entities' => $entities,
            'labels' => ImportReport::ENTITIES,
            'states' => ImportJob::STATES,
        ]);
    }

    public function jobs(Request $request)
    {
        $query = ImportJob::query()->from('import_jobs')
            ->leftJoin('users as u', 'u.id', '=', 'import_jobs.created_by')
            ->when(array_key_exists((string) $request->query('entity'), ImportReport::ENTITIES), fn ($q) => $q->where('import_jobs.entity', $request->query('entity')))
            ->when(array_key_exists((string) $request->query('status'), ImportJob::STATES), fn ($q) => $q->where('import_jobs.status', $request->query('status')))
            ->select(['import_jobs.*', 'u.name as user_name']);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('import_jobs.file_name', 'ilike', "%{$search}%")->orWhere('u.name', 'ilike', "%{$search}%"));
        }

        ColumnFilters::apply($query, $request, [
            'file' => ColumnFilters::text('import_jobs.file_name'),
            'user' => ColumnFilters::text('u.name'),
            'created' => ColumnFilters::number('import_jobs.created_count'),
            'skipped' => ColumnFilters::number('(import_jobs.skipped_count + import_jobs.error_count)'),
            'date' => ColumnFilters::date("(import_jobs.created_at AT TIME ZONE 'America/Lima')"),
        ]);
        ListSort::apply($query, $request, [
            'file' => 'lower(import_jobs.file_name)', 'entity' => 'import_jobs.entity', 'user' => 'lower(u.name)',
            'created' => 'import_jobs.created_count', 'skipped' => '(import_jobs.skipped_count + import_jobs.error_count)',
            'status' => 'import_jobs.status', 'date' => 'import_jobs.created_at',
        ], fn ($q) => $q->orderByDesc('import_jobs.created_at'), 'import_jobs.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (ImportJob $job) => $this->row($job));

        return response()->json($page);
    }

    public function show(Request $request, string $job)
    {
        abort_unless(Str::isUuid($job), 404);
        $row = ImportJob::query()->from('import_jobs')
            ->leftJoin('users as u', 'u.id', '=', 'import_jobs.created_by')
            ->where('import_jobs.id', $job)
            ->select(['import_jobs.*', 'u.name as user_name'])
            ->firstOrFail();

        return response()->json($this->row($row) + ['details' => $row->details ?? []]);
    }

    private function row(ImportJob $job): array
    {
        return [
            'id' => $job->id,
            'entity' => $job->entity,
            'entity_label' => ImportReport::ENTITIES[$job->entity] ?? $job->entity,
            'file' => $job->file_name,
            'total' => $job->total_rows,
            'created' => $job->created_count,
            'updated' => $job->updated_count,
            'skipped' => $job->skipped_count,
            'failed' => $job->error_count,
            'status' => $job->status,
            'status_label' => ImportJob::STATES[$job->status] ?? $job->status,
            'user_name' => $job->getAttribute('user_name'),
            'at' => $job->created_at?->toIso8601String(),
        ];
    }
}
