<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreCustomerRequest;
use App\Modules\Core\Models\Customer;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\CustomFieldDefinition;
use App\Modules\Services\Models\Quotation;
use App\Modules\Services\Models\Contract;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::with('party.person', 'party.organization')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $customer)
    {
        $customer = Customer::findOrFail($customer);

        return response()->json($customer->load('party.person', 'party.organization'));
    }

    public function store(StoreCustomerRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $customer = DB::transaction(function () use ($request, $tenantId) {
            $party = Party::create([
                'tenant_id' => $tenantId,
                'party_type' => $request->party_type,
                'status' => 'ACTIVE',
            ]);

            if ($request->party_type === 'PERSON') {
                Person::create([
                    'party_id' => $party->id,
                    'given_names' => $request->given_names,
                    'family_name' => $request->family_name,
                    'document_type' => $request->document_type,
                    'document_number' => $request->document_number,
                    'metadata' => $request->metadata ?? [],
                ]);
            } else {
                Organization::create([
                    'party_id' => $party->id,
                    'legal_name' => $request->legal_name,
                    'trade_name' => $request->trade_name ?? null,
                    'tax_identifier' => $request->document_number, // el RUC va aquí
                    'metadata' => $request->metadata ?? [],
                ]);
            }

            $customer = Customer::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'status' => 'ACTIVE',
                'payment_terms_days' => $request->payment_terms_days ?? 30,
                'credit_limit' => $request->credit_limit,
                'preferred_currency' => $request->preferred_currency ?? 'PEN',
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'customer.create',
                entityType: 'Customer',
                entityId: $customer->id,
                metadata: ['party_type' => $request->party_type],
            );

            // Outbox sigue pendiente: AuditLogger ya existe como servicio,
            // pero no vi ningún equivalente para outbox_events todavía.
            // No lo voy a improvisar — confírmalo con tu compañero/a antes
            // de que yo le invente una forma.

            return $customer;
        });

        return response()->json(
            $customer->load('party.person', 'party.organization'),
            201
        );
    }

    public function update(Request $request, string $id)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $customer = Customer::where('tenant_id', $tenantId)->findOrFail($id);

        DB::transaction(function () use ($request, $customer, $tenantId) {
            $party = $customer->party;

            // Actualizar condiciones comerciales del cliente
            $customer->update([
                'payment_terms_days' => $request->input('payment_terms_days', $customer->payment_terms_days),
                'retention_agent' => $request->has('retention_agent') ? $request->boolean('retention_agent') : $customer->retention_agent,
                'credit_limit' => $request->has('credit_limit') ? $request->input('credit_limit') : $customer->credit_limit,
                'preferred_currency' => $request->input('preferred_currency', $customer->preferred_currency),
            ]);

            // Actualizar datos de la Party según su tipo
            if ($party->party_type === 'PERSON') {
                $person = $party->person;
                if ($person) {
                    $person->update([
                        'given_names' => $request->input('given_names', $person->given_names),
                        'family_name' => $request->input('family_name', $person->family_name),
                        'document_type' => $request->input('document_type', $person->document_type),
                        'document_number' => $request->input('document_number', $person->document_number),
                    ]);
                }
            } else {
                $organization = $party->organization;
                if ($organization) {
                    $organization->update([
                        'legal_name' => $request->input('legal_name', $organization->legal_name),
                        'trade_name' => $request->input('trade_name', $organization->trade_name),
                        'tax_identifier' => $request->input('document_number', $organization->tax_identifier),
                    ]);
                }
            }

            // Registrar en la bitácora de auditoría
            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'customer.update',
                entityType: 'Customer',
                entityId: $customer->id,
                metadata: [
                    'party_type' => $party->party_type,
                    'payment_terms_days' => $customer->payment_terms_days,
                    'credit_limit' => $customer->credit_limit,
                ],
            );
        });

        return response()->json([
            'message' => 'Cliente actualizado correctamente',
            'customer' => $customer->load('party.person', 'party.organization')
        ]);
    }

    public function deactivate(string $customer, Request $request)
    {
        $customer = Customer::findOrFail($customer);

        if ($customer->status === 'INACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este cliente ya está inactivo.'],
            ]);
        }

        $customer->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'customer.deactivate',
            entityType: 'Customer',
            entityId: $customer->id,
        );

        return response()->json($customer);
    }

    public function activate(string $customer, Request $request)
    {
        $customer = Customer::findOrFail($customer);

        if ($customer->status === 'ACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este cliente ya está activo.'],
            ]);
        }

        $customer->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'customer.activate',
            entityType: 'Customer',
            entityId: $customer->id,
        );

        return response()->json($customer);
    }

    public function bulkDeactivate(Request $request)
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid']
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        // Filtramos para asegurar que sean del tenant y no estén ya inactivos
        $validIds = Customer::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'INACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay clientes válidos para desactivar.'],
            ]);
        }

        Customer::whereIn('id', $validIds)->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'customer.bulk_deactivate',
            entityType: 'Customer',
            entityId: null, 
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' cliente(s) desactivado(s) correctamente.',
            'affected_ids' => $validIds
        ]);
    }

    public function bulkActivate(Request $request)
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid']
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $validIds = Customer::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'ACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay clientes válidos para activar.'],
            ]);
        }

        Customer::whereIn('id', $validIds)->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'customer.bulk_activate',
            entityType: 'Customer',
            entityId: null,
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' cliente(s) activado(s) correctamente.',
            'affected_ids' => $validIds
        ]);
    }

    /**
     * Vista 360: el cliente junto con sus cotizaciones y contratos relacionados,
     * para tener una sola pantalla de resumen en vez de navegar por separado.
     */
    public function overview(string $customer)
    {
        $customer = Customer::with('party.person', 'party.organization')->findOrFail($customer);

        $quotations = Quotation::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get(['id', 'quotation_number', 'status', 'total', 'currency', 'created_at']);

        $contracts = Contract::where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->get(['id', 'contract_number', 'status', 'total_value', 'currency', 'start_date', 'end_date']);

        return response()->json([
            'customer' => $customer,
            'quotations' => $quotations,
            'contracts' => $contracts,
            'summary' => [
                'total_quotations' => $quotations->count(),
                'total_contracts' => $contracts->count(),
                'active_contracts' => $contracts->where('status', 'ACTIVE')->count(),
                'total_contracted_value' => $contracts->where('status', 'ACTIVE')->sum('total_value'),
            ],
        ]);
    }

    private function parseExcelDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        // Si Excel lo leyó como un número serial (ej. 46097)
        if (is_numeric($value) && (float)$value > 20000) {
            $unixDate = (\PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$value));
            return date('Y-m-d', $unixDate); // <-- Corregido de 'YYYY-MM-DD' a 'Y-m-d'
        }
        // Si viene como string normal (ej. 15/09/2026 o 2026-09-15)
        $parsed = strtotime(str_replace('/', '-', $value));
        return $parsed ? date('Y-m-d', $parsed) : trim((string)$value);
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'], // Máximo 5MB
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $file = $request->file('file');
        
        $path = $file->getRealPath();
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: '');

        if (in_array($extension, ['xlsx', 'xls'], true)) {
            // Mismo array de "líneas" que produce file() para un CSV, pero
            // armado a partir de las celdas del Excel. Todo lo que sigue
            // (delimitador, detección de cabecera, alias, parsing de filas)
            // es exactamente el mismo código de siempre — no se toca.
            $lines = $this->excelToLines($path);
        } else {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        }

        if (empty($lines)) {
            throw ValidationException::withMessages([
                'file' => ['El archivo está vacío.'],
            ]);
        }

        $delimiter = ';';
        $headerIndex = -1;
        $headers = [];

        $normalizeHeader = static function (string $header): string {
            $clean = trim(str_replace("\xEF\xBB\xBF", '', $header));
            $clean = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $clean) ?: $clean;
            $clean = strtolower($clean);
            $clean = preg_replace('/[^a-z0-9]+/', '_', $clean) ?? '';

            return trim($clean, '_');
        };

        $headerAliases = [
            'tipo_cliente' => 'tipo_persona',
            'tipo_persona_cliente' => 'tipo_persona',
            'nombre' => 'nombre_o_razon_social',
            'nombres' => 'nombre_o_razon_social',
            'razon_social' => 'nombre_o_razon_social',
            'apellido' => 'apellidos',
            'tipo_doc' => 'tipo_documento',
            'documento_tipo' => 'tipo_documento',
            'numero_doc' => 'numero_documento',
            'documento_numero' => 'numero_documento',
            'dias_de_credito' => 'dias_credito',
            'plazo_credito' => 'dias_credito',
            'limite_de_credito' => 'limite_credito',
            'credito_limite' => 'limite_credito',
            'moneda' => 'preferred_currency',
            'currency' => 'preferred_currency',
            'moneda_preferida' => 'preferred_currency',
        ];

        $normalizeHeaders = static function (array $rawHeaders) use ($normalizeHeader, $headerAliases): array {
            return array_map(
                static fn ($header) => $headerAliases[$normalizeHeader((string) $header)] ?? $normalizeHeader((string) $header),
                $rawHeaders
            );
        };

        // La cabecera se identifica por sus nombres, no por ser la primera línea no comentada.
        // Así las instrucciones de Excel no se convierten en una columna ni desplazan los datos.
        foreach ($lines as $index => $lineContent) {
            $trimmed = trim($lineContent);
            if (empty($trimmed) || str_starts_with($trimmed, '#') || str_contains($trimmed, '---')) {
                continue;
            }

            $candidateDelimiter = strpos($lineContent, ';') !== false ? ';' : ',';
            $candidateHeaders = str_getcsv($lineContent, $candidateDelimiter);
            $candidateKeys = $normalizeHeaders($candidateHeaders);
            $hasDocumentColumn = (bool) array_intersect($candidateKeys, [
                'numero_documento', 'ruc', 'dni', 'documento',
            ]);
            $hasCustomerColumn = (bool) array_intersect($candidateKeys, [
                'tipo_persona', 'nombre_o_razon_social', 'apellidos',
                'tipo_documento', 'dias_credito', 'limite_credito', 'estado',
                'preferred_currency',
            ]);

            if (!$hasDocumentColumn && !$hasCustomerColumn) {
                continue;
            }

            $delimiter = $candidateDelimiter;
            $headers = $candidateHeaders;
            $headerIndex = $index;
            break;
        }

        if (empty($headers)) {
            throw ValidationException::withMessages([
                'file' => ['No se encontraron cabeceras válidas en el archivo.'],
            ]);
        }

        $normalizedHeaders = $normalizeHeaders($headers);

        $importedCount = 0;
        $errors = [];
        $rowNumber = $headerIndex + 1;

        DB::beginTransaction();

        try {
            for ($i = $headerIndex + 1; $i < count($lines); $i++) {
                $rowNumber++;
                $lineContent = trim($lines[$i]);

                if (empty($lineContent) || str_starts_with($lineContent, '#') || str_contains($lineContent, '---')) {
                    continue;
                }

                $data = str_getcsv($lineContent, $delimiter);
                
                // Limpiar espacios vacíos en todas las celdas de la fila
                $data = array_map('trim', $data);

                // Si la fila tiene menos elementos que las cabeceras, rellenamos con vacíos
                if (count($data) < count($normalizedHeaders)) {
                    $data = array_pad($data, count($normalizedHeaders), '');
                }

                $row = array_combine($normalizedHeaders, array_slice($data, 0, count($normalizedHeaders)));

                // 2. Extracción ultra-robusta de campos clave (buscando por nombre o barriendo la fila si hay desfase)
                $partyType = strtoupper($row['tipo_persona'] ?? 'PERSON');
                if (!in_array($partyType, ['PERSON', 'ORGANIZATION'])) {
                    $partyType = 'PERSON';
                }

                // Buscar número de documento (por llave o barriendo celdas que parezcan documentos)
                $documentNumber = '';
                foreach (['numero_documento', 'ruc', 'dni', 'documento'] as $k) {
                    if (!empty($row[$k])) {
                        $documentNumber = $row[$k];
                        break;
                    }
                }
                // Si aún está vacío, buscamos cualquier celda con formato numérico de documento en la fila
                if (empty($documentNumber)) {
                    foreach ($data as $cell) {
                        if (is_numeric($cell) && strlen($cell) >= 6) {
                            $documentNumber = $cell;
                            break;
                        }
                    }
                }

                if (empty($documentNumber)) {
                    $errors[] = "Fila {$rowNumber}: El número de documento es obligatorio.";
                    continue;
                }

                // Nombres y Apellidos
                $givenNames = '';
                foreach (['nombre_o_razon_social', 'nombre', 'nombres', 'razon_social'] as $k) {
                    if (!empty($row[$k])) {
                        $givenNames = $row[$k];
                        break;
                    }
                }
                if (empty($givenNames) && isset($data[1])) {
                    $givenNames = $data[1]; // Respaldo columna 2
                }

                $familyNames = $row['apellidos'] ?? $row['apellido'] ?? '';
                if (empty($familyNames) && isset($data[2])) {
                    $familyNames = $data[2]; // Respaldo columna 3
                }

                $documentType = strtoupper($row['tipo_documento'] ?? 'DNI');
                if ($documentType === 'PASAPORTE') {
                    $documentType = 'PASSPORT';
                }

                $diasCredito = ($row['dias_credito'] ?? '') === ''
                    ? null
                    : (is_numeric($row['dias_credito']) ? (int) $row['dias_credito'] : null);
                $limiteCredito = ($row['limite_credito'] ?? '') === ''
                    ? null
                    : (is_numeric($row['limite_credito']) ? (float) $row['limite_credito'] : null);
                $preferredCurrency = strtoupper(trim($row['preferred_currency'] ?? 'PEN'));
                if (!in_array($preferredCurrency, ['PEN', 'USD'], true)) {
                    $preferredCurrency = 'PEN';
                }
                $estado = strtoupper($row['estado'] ?? 'ACTIVE');

                // Crear Party
                $party = Party::create([
                    'tenant_id' => $tenantId,
                    'party_type' => $partyType,
                    'status' => 'ACTIVE',
                ]);

                if ($partyType === 'PERSON') {
                    Person::create([
                        'party_id' => $party->id,
                        'given_names' => $givenNames,
                        'family_name' => $familyNames,
                        'document_type' => $documentType,
                        'document_number' => $documentNumber,
                    ]);
                } else {
                    Organization::create([
                        'party_id' => $party->id,
                        'legal_name' => $givenNames,
                        'trade_name' => $row['trade_name'] ?? null,
                        'tax_identifier' => $documentNumber,
                    ]);
                }

                // Crear Customer
                $customer = Customer::create([
                    'tenant_id' => $tenantId,
                    'party_id' => $party->id,
                    'status' => $estado,
                    'payment_terms_days' => $diasCredito,
                    'credit_limit' => $limiteCredito,
                    'preferred_currency' => $preferredCurrency,
                ]);

                // Procesar columnas dinámicas / extras (EAV)
                $standardKeys = [
                    'tipo_persona', 'nombre_o_razon_social', 'apellidos',
                    'tipo_documento', 'numero_documento', 'dias_credito', 
                    'limite_credito', 'preferred_currency', 'estado', 'trade_name', 'ruc', 'dni', 'documento'
                ];

                foreach ($normalizedHeaders as $index => $key) {
                    if (in_array($key, $standardKeys)) continue;
                    
                    $val = $data[$index] ?? '';
                    if ($val === '') continue;

                    $definition = CustomFieldDefinition::where('tenant_id', $tenantId)
                        ->where('entity_type', 'Customer')
                        ->get()
                        ->first(function ($candidate) use ($key, $normalizeHeader) {
                            return $normalizeHeader($candidate->field_key) === $key
                                || $normalizeHeader($candidate->label) === $key;
                        });

                    if (!$definition) {
                        $definition = CustomFieldDefinition::create([
                            'tenant_id' => $tenantId,
                            'entity_type' => 'Customer',
                            'field_key' => $key,
                            'label' => ucwords(str_replace('_', ' ', $key)),
                            'field_type' => 'text',
                        ]);
                    }

                    if ($definition->field_type === 'date' || str_contains(strtolower($key), 'fecha')) {
                        $val = $this->parseExcelDate($val);
                    }

                    \App\Modules\Core\Models\CustomFieldValue::updateOrCreate(
                        [
                            'field_id' => $definition->id,
                            'entity_id' => $customer->id,
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'entity_type' => 'Customer',
                            'value' => $val,
                        ]
                    );
                }

                $importedCount++;
            }

            if (!empty($errors) && $importedCount === 0) {
                DB::rollBack();
                return response()->json([
                    'message' => 'No se pudo importar ningún registro.',
                    'errors' => $errors
                ], 422);
            }

            DB::commit();

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'customer.import',
                entityType: 'Customer',
                entityId: null,
                metadata: ['imported_count' => $importedCount]
            );

            return response()->json([
                'imported' => $importedCount,
                'message' => "Se importaron {$importedCount} clientes exitosamente.",
                'warnings' => $errors
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw ValidationException::withMessages([
                'file' => ['Error al procesar el archivo: ' . $e->getMessage()],
            ]);
        }
    }

    /**
     * Convierte una hoja de Excel en el mismo formato de "líneas de texto"
     * que produce file() al leer un CSV, para que import() pueda seguir
     * usando su parsing de siempre (str_getcsv con delimitador ';',
     * detección de cabecera, alias, etc.) sin ningún cambio.
     *
     * - Cada celda se envuelve entre comillas y se unen con ';', igual que
     *   un CSV bien formado — así str_getcsv() las separa correctamente
     *   aunque el texto de alguna celda contenga comas, puntos y comas o
     *   comillas.
     * - Las filas completamente vacías (como las 30 filas en blanco de la
     *   plantilla) se descartan aquí, igual que FILE_SKIP_EMPTY_LINES hace
     *   con un CSV.
     * - Se leen los valores "crudos" (sin el formato visual de la celda,
     *   ej. sin el símbolo "S/" de limite_credito), para que
     *   is_numeric() siga funcionando igual que con el CSV original.
     */
    private function excelToLines(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        // null: valor para celdas vacías | true: calcular fórmulas |
        // false: valores crudos, sin formato visual | false: sin referencias de celda
        $rawRows = $worksheet->toArray(null, true, false, false);

        $lines = [];
        foreach ($rawRows as $rawRow) {
            $isBlank = true;
            foreach ($rawRow as $cell) {
                if ($cell !== null && trim((string) $cell) !== '') {
                    $isBlank = false;
                    break;
                }
            }
            if ($isBlank) {
                continue;
            }

            $quotedCells = array_map(
                static fn ($cell) => '"' . str_replace('"', '""', (string) ($cell ?? '')) . '"',
                $rawRow
            );
            $lines[] = implode(';', $quotedCells);
        }

        return $lines;
    }

    /**
     * Plantilla de importación de clientes, ahora como .xlsx con diseño propio
     * (antes era un .csv plano). La lógica de columnas, alias y datos de ejemplo
     * es exactamente la misma que ya existía; solo cambia cómo se ve el archivo.
     *
     * import() ya sabe leer este .xlsx (ver excelToLines()) además del CSV
     * de siempre, así que el ciclo completo descargar → llenar → importar
     * funciona con cualquiera de los dos formatos.
     */
    public function downloadTemplate(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        // Obtener definiciones completas de campos personalizados (para saber su tipo: date, number, text, etc.)
        $customFields = CustomFieldDefinition::where('tenant_id', $tenantId)
            ->where('entity_type', 'Customer')
            ->get(['field_key', 'field_type']);

        // Cabeceras base estándar
        $headers = [
            'tipo_persona',
            'nombre_o_razon_social',
            'apellidos',
            'tipo_documento',
            'numero_documento',
            'dias_credito',
            'limite_credito',
            'preferred_currency',
            'estado'
        ];

        $fieldKeys = $customFields->pluck('field_key')->toArray();
        $allHeaders = array_merge($headers, $fieldKeys);

        // Si no hay ninguno creado aún, dejamos uno de texto y uno de fecha de ejemplo
        if ($customFields->isEmpty()) {
            $allHeaders[] = 'zona_asignada';
            $allHeaders[] = 'fecha_registro';
            $customFields = collect([
                (object)['field_key' => 'zona_asignada', 'field_type' => 'text'],
                (object)['field_key' => 'fecha_registro', 'field_type' => 'date'],
            ]);
        }

        // Fila de ejemplo 1 (Persona Natural con DNI/Pasaporte)
        $row1 = ['PERSON', 'Juan Carlos', 'Pérez Gómez', 'DNI', '12345678', 30, 1500.00, 'PEN', 'ACTIVE'];
        foreach ($customFields as $field) {
            $row1[] = match ($field->field_type) {
                'number' => 100,
                'date' => '2026-09-16',
                'boolean' => 'true',
                default => 'Norte'
            };
        }

        // Fila de ejemplo 2 (Organización / Empresa con RUC)
        $row2 = ['ORGANIZATION', 'Corporación Ejemplo S.A.C.', '', 'RUC', '20123456789', 45, 5000.00, 'USD', 'ACTIVE'];
        foreach ($customFields as $field) {
            $row2[] = match ($field->field_type) {
                'number' => 250,
                'date' => '2026-09-16',
                'boolean' => 'false',
                default => 'Sur'
            };
        }

        $instructions = [
            '1. tipo_persona: Usa exactamente PERSON (Persona Natural) u ORGANIZATION (Empresa).',
            '2. tipo_documento: Usa DNI, RUC, CE o PASAPORTE.',
            '3. estado: Usa ACTIVE o INACTIVE.',
            '4. Si el cliente no trabaja a crédito, deja vacíos dias_credito, limite_credito y preferred_currency.',
            '5. Puedes agregar columnas adicionales al final con espacios o guiones (ej: Zona Asignada).',
        ];

        // ---- Paleta de marca (Creativas OS) ----
        $BRAND = 'FF2A4FE0';
        $BRAND_DARK = 'FF12225E';
        $INK = 'FF12162A';
        $SUBTITLE = 'FF5B6178';
        $BORDER_COLOR = 'FFD8DCE9';
        $STRIPE = 'FFF7F8FC';
        $INFO_TINT = 'FFEDF1FE';
        $INFO_TEXT = 'FF2140BE';
        $SUCCESS_TINT = 'FFE7F7F0';
        $SUCCESS_TEXT = 'FF1E9E6B';

        $nCols = count($allHeaders);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nCols);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plantilla');
        $sheet->setShowGridlines(false);

        $thinBorder = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => $BORDER_COLOR],
                ],
            ],
        ];

        // ---- Fila 1: título ----
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'Plantilla de importación de clientes');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 15, 'bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $BRAND_DARK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // ---- Fila 2: subtítulo ----
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', 'Completa la información respetando el formato de cada columna. No elimines columnas obligatorias.');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => ['argb' => $SUBTITLE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF5F6FA']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

        // ---- Bloque de instrucciones (callout) ----
        $instructionsStartRow = 4;
        $sheet->mergeCells("A3:{$lastColLetter}3");
        $sheet->getRowDimension(3)->setRowHeight(6);

        foreach ($instructions as $offset => $text) {
            $row = $instructionsStartRow + $offset;
            $sheet->mergeCells("A{$row}:{$lastColLetter}{$row}");
            $sheet->setCellValue("A{$row}", $text);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9.5, 'color' => ['argb' => $INFO_TEXT]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $INFO_TINT]],
                'borders' => [
                    'left' => ['borderStyle' => Border::BORDER_THICK, 'color' => ['argb' => $BRAND]],
                ],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(18);
        }

        $spacerRow = $instructionsStartRow + count($instructions);
        $sheet->getRowDimension($spacerRow)->setRowHeight(8);

        $headerRow = $spacerRow + 1;
        $firstDataRow = $headerRow + 1;

        // ---- Fila de encabezado ----
        foreach ($allHeaders as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->setCellValue("{$col}{$headerRow}", $h);
            $sheet->getStyle("{$col}{$headerRow}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10.5, 'bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $BRAND]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            ] + $thinBorder);
        }
        $sheet->getRowDimension($headerRow)->setRowHeight(30);

        // ---- Filas de ejemplo ----
        $exampleRows = [$row1, $row2];
        foreach ($exampleRows as $rIdx => $values) {
            $r = $firstDataRow + $rIdx;
            $stripe = $rIdx % 2 === 0 ? 'FFFFFFFF' : $STRIPE;

            foreach ($values as $cIdx => $val) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($cIdx + 1);
                $cellRef = "{$col}{$r}";
                $sheet->setCellValue($cellRef, $val);
                $headerName = $allHeaders[$cIdx];

                $style = [
                    'font' => ['name' => 'Arial', 'size' => 10.5, 'color' => ['argb' => $INK]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $stripe]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
                ];

                if ($headerName === 'estado') {
                    $style['font']['bold'] = true;
                    $style['font']['color'] = ['argb' => $SUCCESS_TEXT];
                    $style['fill']['startColor'] = ['argb' => $SUCCESS_TINT];
                    $style['alignment']['horizontal'] = Alignment::HORIZONTAL_CENTER;
                } elseif (in_array($headerName, ['dias_credito', 'limite_credito'], true)) {
                    $style['alignment']['horizontal'] = Alignment::HORIZONTAL_RIGHT;
                } elseif (in_array($headerName, ['tipo_persona', 'tipo_documento'], true)) {
                    $style['alignment']['horizontal'] = Alignment::HORIZONTAL_CENTER;
                }

                $sheet->getStyle($cellRef)->applyFromArray($style + $thinBorder);
            }
            $sheet->getRowDimension($r)->setRowHeight(22);
        }

        // ---- Filas en blanco listas para completar (borde suave, sin datos) ----
        $blankRowsCount = 30;
        $lastBlankRow = $firstDataRow + count($exampleRows) + $blankRowsCount - 1;
        for ($r = $firstDataRow + count($exampleRows); $r <= $lastBlankRow; $r++) {
            $sheet->getStyle("A{$r}:{$lastColLetter}{$r}")->applyFromArray($thinBorder);
            $sheet->getRowDimension($r)->setRowHeight(20);
        }

        // ---- Listas desplegables (validación de datos) para las columnas clave ----
        $dropdowns = [
            'tipo_persona' => '"PERSON,ORGANIZATION"',
            'tipo_documento' => '"DNI,RUC,CE,PASAPORTE"',
            'dias_credito' => '"15,30,45,60"',
            'estado' => '"ACTIVE,INACTIVE"',
            'preferred_currency' => '"PEN,USD"',
        ];
        foreach ($dropdowns as $key => $formula) {
            $colIndex = array_search($key, $allHeaders, true);
            if ($colIndex === false) {
                continue;
            }
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            for ($r = $firstDataRow; $r <= $lastBlankRow; $r++) {
                $validation = $sheet->getCell("{$col}{$r}")->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowErrorMessage(true);
                $validation->setErrorTitle('Valor no válido');
                $validation->setError('Elige una opción de la lista.');
                $validation->setFormula1($formula);
            }
        }

        // ---- Anchos de columna ----
        $widths = [
            'tipo_persona' => 16,
            'nombre_o_razon_social' => 28,
            'apellidos' => 16,
            'tipo_documento' => 15,
            'numero_documento' => 18,
            'dias_credito' => 13,
            'limite_credito' => 16,
            'preferred_currency' => 18,
            'estado' => 12,
        ];
        foreach ($allHeaders as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($col)->setWidth($widths[$h] ?? 18);
        }

        // ---- Encabezado congelado ----
        $sheet->freezePane('A' . $firstDataRow);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_importacion_clientes.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}