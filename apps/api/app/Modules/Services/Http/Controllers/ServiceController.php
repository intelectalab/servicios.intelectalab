<?php

namespace App\Modules\Services\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Services\Http\Requests\StoreServiceRequest;
use App\Modules\Services\Models\QuotationItem;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Models\ServiceCategory;
use App\Modules\Services\Models\ServicePrice;
use App\Modules\Services\Models\ServiceRecurringConfig;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SpreadsheetImport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceController extends Controller
{
    private const LIST_RELATIONS = ['category:id,name', 'currentPrice', 'recurrence'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Service::with(self::LIST_RELATIONS)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->query('category_id')))
            ->when($request->filled('billing_mode'), fn ($q) => $q->where('billing_mode', $request->query('billing_mode')))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('code', 'ILIKE', $like)->orWhere('name', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'name' => ColumnFilters::text("services.name || ' ' || services.code"),
            'price' => ColumnFilters::number('(SELECT sp.amount FROM service_prices sp WHERE sp.service_id = services.id AND sp.active ORDER BY sp.valid_from DESC LIMIT 1)'),
            'warranty' => ColumnFilters::number('services.warranty_months'),
        ]);

        ListSort::apply($query, $request, [
            'name' => 'services.name',
            'category' => '(SELECT sc.name FROM service_categories sc WHERE sc.id = services.category_id)',
            'billing_mode' => ListSort::labels('services.billing_mode', ['ONE_TIME' => 'Único', 'RECURRING' => 'Recurrente', 'PER_EVENT' => 'Por evento']),
            'price' => '(SELECT sp.amount FROM service_prices sp WHERE sp.service_id = services.id AND sp.active ORDER BY sp.valid_from DESC LIMIT 1)',
            'warranty' => 'services.warranty_months',
            'status' => ListSort::labels('services.status', ['DRAFT' => 'Borrador', 'ACTIVE' => 'Activo', 'INACTIVE' => 'Inactivo', 'ARCHIVED' => 'Archivado']),
        ], fn ($q) => $q->orderBy('name')->orderBy('id'));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $service)
    {
        $service = Service::findOrFail($service);
        $service->load([...self::LIST_RELATIONS, 'prices']);

        // Cotizaciones que usan el servicio, con el precio que guardó cada una.
        $usage = QuotationItem::where('service_id', $service->id)
            ->join('quotations', 'quotations.id', '=', 'quotation_items.quotation_id')
            ->orderByDesc('quotations.created_at')
            ->limit(50)
            ->get([
                'quotations.id as quotation_id', 'quotations.quotation_number', 'quotations.status',
                'quotations.currency', 'quotation_items.unit_price',
            ]);

        return response()->json([
            ...$service->toArray(),
            'quotations' => $usage,
            'history' => AuditHistory::for('Service', $service->id),
        ]);
    }

    public function store(StoreServiceRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $this->assertUniqueCode($request->code);
        $this->assertCategory($request->category_id);

        $service = DB::transaction(function () use ($request, $tenantId) {
            $service = Service::create([
                'tenant_id' => $tenantId,
                'status' => 'DRAFT',
                ...$this->attributes($request),
            ]);
            $this->syncRecurrence($service, $request->frequency);

            if ($request->filled('price') && (float) $request->price > 0) {
                ServicePrice::create([
                    'tenant_id' => $tenantId,
                    'service_id' => $service->id,
                    'currency' => 'PEN',
                    'amount' => $request->price,
                    'valid_from' => now()->toDateString(),
                    'active' => true,
                ]);
            }

            return $service;
        });

        $this->audit($request, $service, 'service.create');

        return response()->json($service->load(self::LIST_RELATIONS), 201);
    }

    public function update(string $service, StoreServiceRequest $request)
    {
        $service = Service::findOrFail($service);
        $this->assertUniqueCode($request->code, $service->id);
        $this->assertCategory($request->category_id);

        if ($service->status === 'ARCHIVED') {
            throw ValidationException::withMessages(['status' => ['Un servicio archivado no se puede editar.']]);
        }

        DB::transaction(function () use ($request, $service) {
            $service->update($this->attributes($request));
            $this->syncRecurrence($service, $request->frequency);
        });

        $this->audit($request, $service, 'service.update');

        return response()->json($service->load(self::LIST_RELATIONS));
    }

    /** DRAFT → ACTIVE (exige precio vigente) e INACTIVE → ACTIVE (reactivar). */
    public function activate(string $service, Request $request)
    {
        $service = Service::findOrFail($service);
        $this->assertStatus($service, ['DRAFT', 'INACTIVE'], 'activar');

        if ($service->status === 'DRAFT' && !$service->currentPrice()->exists()) {
            throw ValidationException::withMessages([
                'status' => ['El servicio necesita un precio vigente antes de activarse.'],
            ]);
        }

        return $this->transition($request, $service, 'ACTIVE', 'service.activate');
    }

    public function deactivate(string $service, Request $request)
    {
        $service = Service::findOrFail($service);
        $this->assertStatus($service, ['ACTIVE'], 'inactivar');

        return $this->transition($request, $service, 'INACTIVE', 'service.deactivate');
    }

    public function archive(string $service, Request $request)
    {
        $service = Service::findOrFail($service);
        $this->assertStatus($service, ['INACTIVE'], 'archivar');

        return $this->transition($request, $service, 'ARCHIVED', 'service.archive');
    }

    /**
     * Nueva versión de precio: la vigente se cierra con fecha de fin y no se
     * sobrescribe. Lo ya cotizado conserva su precio (snapshot en la línea).
     */
    public function storePrice(string $service, Request $request)
    {
        $service = Service::findOrFail($service);
        $tenantId = $request->attributes->get('tenant_id');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'valid_from' => ['nullable', 'date'],
        ], ['amount.gt' => 'Precio inválido.']);

        $validFrom = isset($data['valid_from'])
            ? Carbon::parse($data['valid_from'])->toDateString()
            : now()->toDateString();

        $price = DB::transaction(function () use ($service, $tenantId, $data, $validFrom) {
            foreach (ServicePrice::where('service_id', $service->id)->where('active', true)->get() as $current) {
                // valid_to no puede quedar antes de valid_from (check de la tabla).
                $from = $current->valid_from?->toDateString();
                $current->update([
                    'active' => false,
                    'valid_to' => $from !== null && $from > $validFrom ? $from : $validFrom,
                ]);
            }

            return ServicePrice::create([
                'tenant_id' => $tenantId,
                'service_id' => $service->id,
                'currency' => 'PEN',
                'amount' => $data['amount'],
                'valid_from' => $validFrom,
                'active' => true,
            ]);
        });

        $this->audit($request, $service, 'service.price.create', ['amount' => $price->amount, 'valid_from' => $validFrom]);

        return response()->json($price, 201);
    }

    // --- Importación ---------------------------------------------------------------

    private const IMPORT_HEADERS = ['codigo', 'nombre', 'categoria', 'unidad', 'modalidad', 'frecuencia', 'garantia_meses', 'precio', 'descripcion'];

    private const IMPORT_ALIASES = [
        'code' => 'codigo', 'codigo_servicio' => 'codigo', 'servicio' => 'nombre', 'name' => 'nombre', 'nombre_servicio' => 'nombre',
        'category' => 'categoria', 'unit' => 'unidad', 'modalidad_de_facturacion' => 'modalidad', 'billing_mode' => 'modalidad',
        'frequency' => 'frecuencia', 'garantia' => 'garantia_meses', 'garantia_en_meses' => 'garantia_meses', 'warranty_months' => 'garantia_meses',
        'precio_inicial' => 'precio', 'precio_sin_igv' => 'precio', 'price' => 'precio', 'description' => 'descripcion',
    ];

    private const IMPORT_MODES = [
        'unico' => 'ONE_TIME', 'unica' => 'ONE_TIME', 'one_time' => 'ONE_TIME',
        'recurrente' => 'RECURRING', 'recurring' => 'RECURRING',
        'por_evento' => 'PER_EVENT', 'evento' => 'PER_EVENT', 'per_event' => 'PER_EVENT',
    ];

    private const IMPORT_FREQUENCIES = [
        'mensual' => 'MONTHLY', 'monthly' => 'MONTHLY', 'trimestral' => 'QUARTERLY', 'quarterly' => 'QUARTERLY',
        'anual' => 'YEARLY', 'yearly' => 'YEARLY',
    ];

    public function downloadTemplate()
    {
        return SpreadsheetImport::template(
            'plantilla_importacion_servicios.xlsx',
            'Plantilla de importación de servicios',
            self::IMPORT_HEADERS,
            [
                ['SRV-101', 'Mantenimiento preventivo', 'Mantenimiento', 'visita', 'Único', '', '3', '380', 'Revisión general y limpieza de equipos.'],
                ['SRV-102', 'Soporte mensual', 'Soporte', 'mes', 'Recurrente', 'Mensual', '0', '1200', ''],
                ['SRV-103', 'Atención de emergencia', 'Soporte', 'hora', 'Por evento', '', '0', '150', ''],
            ],
            [
                'codigo, nombre y categoria son obligatorios. El código no puede repetirse: si ya existe, la fila se omite.',
                'categoria: si no existe, se crea con ese nombre.',
                'modalidad: Único, Recurrente o Por evento (sin dato, Único). frecuencia (solo si es Recurrente): Mensual, Trimestral o Anual.',
                'precio: en soles y sin IGV; crea la primera versión del precio. Sin precio el servicio no se puede activar.',
                'Los servicios entran en BORRADOR: se activan desde su ficha.',
                'Las columnas categoria, modalidad y frecuencia tienen lista desplegable; en categoria también puedes escribir una nueva.',
            ],
            [
                'categoria' => ServiceCategory::orderBy('name')->pluck('name')->all(),
                'modalidad' => ['Único', 'Recurrente', 'Por evento'],
                'frecuencia' => ['Mensual', 'Trimestral', 'Anual'],
            ],
            ['categoria'],
        );
    }

    /** Importa servicios en borrador; cada fila se guarda por separado y las que fallan vuelven como avisos. */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $tenantId = $request->attributes->get('tenant_id');

        [, $rows] = SpreadsheetImport::read($request->file('file'), self::IMPORT_ALIASES, ['codigo', 'nombre', 'categoria', 'precio', 'modalidad']);

        $created = 0;
        $warnings = [];
        $rules = (new StoreServiceRequest())->rules();
        $messages = (new StoreServiceRequest())->messages();

        foreach ($rows as $number => $row) {
            $get = fn (string $key) => trim((string) ($row[$key] ?? ''));

            try {
                DB::transaction(function () use ($get, $rules, $messages, $tenantId, $request, &$created) {
                    $mode = SpreadsheetImport::normalize($get('modalidad'));
                    $frequency = SpreadsheetImport::normalize($get('frecuencia'));
                    if ($mode !== '' && ! isset(self::IMPORT_MODES[$mode])) {
                        throw ValidationException::withMessages(['billing_mode' => ['modalidad debe ser Único, Recurrente o Por evento.']]);
                    }
                    if ($frequency !== '' && ! isset(self::IMPORT_FREQUENCIES[$frequency])) {
                        throw ValidationException::withMessages(['frequency' => ['frecuencia debe ser Mensual, Trimestral o Anual.']]);
                    }
                    if ($get('codigo') === '' || $get('nombre') === '' || $get('categoria') === '') {
                        throw ValidationException::withMessages(['category_id' => ['Código, nombre y categoría son obligatorios.']]);
                    }

                    $billingMode = self::IMPORT_MODES[$mode] ?? 'ONE_TIME';
                    $data = Validator::make([
                        'code' => $get('codigo'),
                        'name' => $get('nombre'),
                        'description' => $get('descripcion') ?: null,
                        'unit' => $get('unidad') ?: null,
                        'category_id' => $this->categoryFor($get('categoria'), $tenantId),
                        'billing_mode' => $billingMode,
                        'frequency' => $billingMode === 'RECURRING' ? (self::IMPORT_FREQUENCIES[$frequency] ?? null) : null,
                        'warranty_months' => $get('garantia_meses') === '' ? 0 : $get('garantia_meses'),
                        'price' => $get('precio') === '' ? null : $get('precio'),
                    ], $rules, $messages)->validate();
                    $this->assertUniqueCode($data['code']);

                    $service = Service::create([
                        'tenant_id' => $tenantId,
                        'status' => 'DRAFT',
                        'category_id' => $data['category_id'],
                        'code' => $data['code'],
                        'name' => $data['name'],
                        'description' => $data['description'],
                        'unit' => $data['unit'],
                        'billing_mode' => $data['billing_mode'],
                        'warranty_months' => (int) $data['warranty_months'],
                    ]);
                    $this->syncRecurrence($service, $data['frequency']);

                    if ((float) ($data['price'] ?? 0) > 0) {
                        ServicePrice::create([
                            'tenant_id' => $tenantId, 'service_id' => $service->id, 'currency' => 'PEN',
                            'amount' => $data['price'], 'valid_from' => now()->toDateString(), 'active' => true,
                        ]);
                    }

                    $this->audit($request, $service, 'service.create', ['note' => 'Importado']);
                    $created++;
                });
            } catch (ValidationException $e) {
                $warnings[] = "Fila {$number}: ".collect($e->errors())->flatten()->first();
            } catch (\Illuminate\Database\QueryException) {
                $warnings[] = "Fila {$number}: no se pudo guardar (revisa que los datos no sean demasiado largos).";
            }
        }

        if ($created === 0) {
            return response()->json(['message' => 'No se pudo importar ningún servicio.', 'errors' => ['file' => $warnings]], 422);
        }

        return response()->json([
            'imported' => $created,
            'message' => "Se importaron {$created} servicio(s) en borrador.",
            'warnings' => $warnings,
        ]);
    }

    /** La categoría con ese nombre; si no existe, se crea. */
    private function categoryFor(string $name, string $tenantId): string
    {
        $category = ServiceCategory::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? ServiceCategory::create(['tenant_id' => $tenantId, 'name' => $name, 'active' => true]);

        return $category->id;
    }

    private function attributes(StoreServiceRequest $request): array
    {
        return [
            'category_id' => $request->category_id,
            'code' => $request->code,
            'name' => $request->name,
            'description' => $request->description,
            'unit' => $request->unit,
            'billing_mode' => $request->billing_mode ?? 'ONE_TIME',
            'warranty_months' => (int) ($request->warranty_months ?? 0),
        ];
    }

    /** La recurrencia vive en service_recurring_configs: una fila activa si el servicio es recurrente. */
    private function syncRecurrence(Service $service, ?string $frequency): void
    {
        $current = ServiceRecurringConfig::where('service_id', $service->id)->where('active', true)->first();

        if ($service->billing_mode !== 'RECURRING') {
            $current?->update(['active' => false]);

            return;
        }

        if ($current && $current->frequency === $frequency) {
            return;
        }

        $current?->update(['active' => false]);
        ServiceRecurringConfig::create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'frequency' => $frequency,
            'interval_value' => 1,
            'active' => true,
        ]);
    }

    private function assertUniqueCode(string $code, ?string $exceptId = null): void
    {
        $taken = Service::where('code', $code)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => ['El código de servicio debe ser único en la empresa.'],
            ]);
        }
    }

    private function assertCategory(string $categoryId): void
    {
        if (!ServiceCategory::whereKey($categoryId)->exists()) {
            throw ValidationException::withMessages([
                'category_id' => ['La categoría seleccionada no existe.'],
            ]);
        }
    }

    private function assertStatus(Service $service, array $expected, string $action): void
    {
        if (!in_array($service->status, $expected, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$action} un servicio en estado {$service->status}."],
            ]);
        }
    }

    private function transition(Request $request, Service $service, string $to, string $action)
    {
        $from = $service->status;
        $service->update(['status' => $to]);
        $this->audit($request, $service, $action, ['from' => $from, 'to' => $to]);

        return response()->json($service);
    }

    private function audit(Request $request, Service $service, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Service',
            entityId: $service->id,
            metadata: $metadata,
        );
    }
}
