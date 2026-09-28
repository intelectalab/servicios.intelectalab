<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\StoreSupplierRequest;
use App\Modules\Core\Models\Organization;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\Supplier;
use App\Modules\Core\Models\CustomFieldDefinition;
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

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::with('party.person', 'party.organization')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $supplier)
    {
        $supplier = Supplier::findOrFail($supplier);

        return response()->json($supplier->load('party.person', 'party.organization'));
    }

    public function store(StoreSupplierRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $supplier = DB::transaction(function () use ($request, $tenantId) {
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
                    'tax_identifier' => $request->document_number,
                    'metadata' => $request->metadata ?? [],
                ]);
            }

            $supplier = Supplier::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'status' => 'ACTIVE',
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'supplier.create',
                entityType: 'Supplier',
                entityId: $supplier->id,
                metadata: ['party_type' => $request->party_type],
            );

            return $supplier;
        });

        return response()->json($supplier->load('party.person', 'party.organization'), 201);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($id);

        DB::transaction(function () use ($request, $supplier, $tenantId) {
            $party = $supplier->party;

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

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'supplier.update',
                entityType: 'Supplier',
                entityId: $supplier->id,
                metadata: [
                    'party_type' => $party->party_type,
                ],
            );
        });

        return response()->json([
            'message' => 'Proveedor actualizado correctamente',
            'supplier' => $supplier->load('party.person', 'party.organization')
        ]);
    }

    public function deactivate(string $supplier, Request $request)
    {
        $supplier = Supplier::findOrFail($supplier);

        if ($supplier->status === 'INACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este proveedor ya está inactivo.'],
            ]);
        }

        $supplier->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'supplier.deactivate',
            entityType: 'Supplier',
            entityId: $supplier->id,
        );

        return response()->json($supplier);
    }

    public function activate(string $supplier, Request $request)
    {
        $supplier = Supplier::findOrFail($supplier);

        if ($supplier->status === 'ACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este proveedor ya está activo.'],
            ]);
        }

        $supplier->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'supplier.activate',
            entityType: 'Supplier',
            entityId: $supplier->id,
        );

        return response()->json($supplier);
    }

    public function bulkDeactivate(Request $request)
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid']
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $validIds = Supplier::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'INACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay proveedores válidos para desactivar.'],
            ]);
        }

        Supplier::whereIn('id', $validIds)->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'supplier.bulk_deactivate',
            entityType: 'Supplier',
            entityId: null, 
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' proveedor(es) desactivado(s) correctamente.',
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

        $validIds = Supplier::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'ACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay proveedores válidos para activar.'],
            ]);
        }

        Supplier::whereIn('id', $validIds)->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'supplier.bulk_activate',
            entityType: 'Supplier',
            entityId: null,
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' proveedor(es) activado(s) correctamente.',
            'affected_ids' => $validIds
        ]);
    }

    /**
     * Vista 360 del proveedor.
     */
    public function overview(string $supplier)
    {
        $supplier = Supplier::with('party.person', 'party.organization')->findOrFail($supplier);

        return response()->json([
            'supplier' => $supplier,
            'summary' => [
                'total_cost_entries' => 0,
                'total_cost_value' => 0,
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
            'file' => ['required', 'file', 'max:5120'],
        ]);

        $tenantId = $request->attributes->get('tenant_id');
        $file = $request->file('file');
        
        $path = $file->getRealPath();
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: '');

        if (in_array($extension, ['xlsx', 'xls'], true)) {
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
            'tipo_proveedor' => 'tipo_persona',
            'nombre' => 'nombre_o_razon_social',
            'nombres' => 'nombre_o_razon_social',
            'razon_social' => 'nombre_o_razon_social',
            'apellido' => 'apellidos',
            'tipo_doc' => 'tipo_documento',
            'documento_tipo' => 'tipo_documento',
            'numero_doc' => 'numero_documento',
            'documento_numero' => 'numero_documento',
        ];

        $normalizeHeaders = static function (array $rawHeaders) use ($normalizeHeader, $headerAliases): array {
            return array_map(
                static fn ($header) => $headerAliases[$normalizeHeader((string) $header)] ?? $normalizeHeader((string) $header),
                $rawHeaders
            );
        };

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
            $hasSupplierColumn = (bool) array_intersect($candidateKeys, [
                'tipo_persona', 'nombre_o_razon_social', 'apellidos',
                'tipo_documento', 'estado',
            ]);

            if (!$hasDocumentColumn && !$hasSupplierColumn) {
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
                $data = array_map('trim', $data);

                if (count($data) < count($normalizedHeaders)) {
                    $data = array_pad($data, count($normalizedHeaders), '');
                }

                $row = array_combine($normalizedHeaders, array_slice($data, 0, count($normalizedHeaders)));

                $partyType = strtoupper($row['tipo_persona'] ?? 'PERSON');
                if (!in_array($partyType, ['PERSON', 'ORGANIZATION'], true)) {
                    $partyType = 'PERSON';
                }

                $documentNumber = '';
                foreach (['numero_documento', 'ruc', 'dni', 'documento'] as $k) {
                    if (!empty($row[$k])) {
                        $documentNumber = $row[$k];
                        break;
                    }
                }
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

                $givenNames = '';
                foreach (['nombre_o_razon_social', 'nombre', 'nombres', 'razon_social'] as $k) {
                    if (!empty($row[$k])) {
                        $givenNames = $row[$k];
                        break;
                    }
                }
                if (empty($givenNames) && isset($data[1])) {
                    $givenNames = $data[1];
                }

                $familyNames = $row['apellidos'] ?? $row['apellido'] ?? '';
                if (empty($familyNames) && isset($data[2])) {
                    $familyNames = $data[2];
                }

                $documentType = strtoupper($row['tipo_documento'] ?? 'DNI');
                if ($documentType === 'PASAPORTE') {
                    $documentType = 'PASSPORT';
                }

                $estado = strtoupper($row['estado'] ?? 'ACTIVE');

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

                $supplier = Supplier::create([
                    'tenant_id' => $tenantId,
                    'party_id' => $party->id,
                    'status' => $estado,
                ]);

                $standardKeys = [
                    'tipo_persona', 'nombre_o_razon_social', 'apellidos',
                    'tipo_documento', 'numero_documento', 'estado', 'trade_name', 'ruc', 'dni', 'documento'
                ];

                foreach ($normalizedHeaders as $index => $key) {
                    if (in_array($key, $standardKeys, true)) continue;
                    
                    $val = $data[$index] ?? '';
                    if ($val === '') continue;

                    $definition = CustomFieldDefinition::where('tenant_id', $tenantId)
                        ->where('entity_type', 'Supplier')
                        ->get()
                        ->first(function ($candidate) use ($key, $normalizeHeader) {
                            return $normalizeHeader($candidate->field_key) === $key
                                || $normalizeHeader($candidate->label) === $key;
                        });

                    if (!$definition) {
                        $definition = CustomFieldDefinition::create([
                            'tenant_id' => $tenantId,
                            'entity_type' => 'Supplier',
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
                            'entity_id' => $supplier->id,
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'entity_type' => 'Supplier',
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
                action: 'supplier.import',
                entityType: 'Supplier',
                entityId: null,
                metadata: ['imported_count' => $importedCount]
            );

            return response()->json([
                'imported' => $importedCount,
                'message' => "Se importaron {$importedCount} proveedores exitosamente.",
                'warnings' => $errors
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            throw ValidationException::withMessages([
                'file' => ['Error al procesar el archivo: ' . $e->getMessage()],
            ]);
        }
    }

    private function excelToLines(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

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

    public function downloadTemplate(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $customFields = CustomFieldDefinition::where('tenant_id', $tenantId)
            ->where('entity_type', 'Supplier')
            ->get(['field_key', 'field_type']);

        $headers = [
            'tipo_persona',
            'nombre_o_razon_social',
            'apellidos',
            'tipo_documento',
            'numero_documento',
            'estado'
        ];

        $fieldKeys = $customFields->pluck('field_key')->toArray();
        $allHeaders = array_merge($headers, $fieldKeys);

        if ($customFields->isEmpty()) {
            $allHeaders[] = 'fecha_registro';
            $allHeaders[] = 'activo_certificado';
            $customFields = collect([
                (object)['field_key' => 'fecha_registro', 'field_type' => 'date'],
                (object)['field_key' => 'activo_certificado', 'field_type' => 'boolean'],
            ]);
        }

        $row1 = ['PERSON', 'Carlos', 'Ramírez Soto', 'DNI', '87654321', 'ACTIVE'];
        foreach ($customFields as $field) {
            $row1[] = match ($field->field_type) {
                'number' => 100,
                'date' => '2026-09-15',
                'boolean' => 'true',
                default => 'Ejemplo Texto'
            };
        }

        // Fila de ejemplo 2 (Organización / Empresa)
        $row2 = ['ORGANIZATION', 'Proveedores Globales S.A.C.', '', 'RUC', '20987654321', 'ACTIVE'];
        foreach ($customFields as $field) {
            $row2[] = match ($field->field_type) {
                'number' => 250,
                'date' => '2026-09-16',
                'boolean' => 'false',
                default => 'Otro Ejemplo'
            };
        }

        $instructions = [
            '1. tipo_persona: Usa exactamente PERSON (Persona Natural) u ORGANIZATION (Empresa).',
            '2. tipo_documento: Usa DNI, RUC, CE o PASAPORTE.',
            '3. estado: Usa ACTIVE o INACTIVE.',
            '4. Puedes agregar columnas adicionales al final con espacios o guiones.',
        ];

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

        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'Plantilla de importación de proveedores');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 15, 'bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $BRAND_DARK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A2', 'Completa la información respetando el formato de cada columna.');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 9, 'italic' => true, 'color' => ['argb' => $SUBTITLE]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF5F6FA']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(18);

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
                } elseif (in_array($headerName, ['tipo_persona', 'tipo_documento'], true)) {
                    $style['alignment']['horizontal'] = Alignment::HORIZONTAL_CENTER;
                }

                $sheet->getStyle($cellRef)->applyFromArray($style + $thinBorder);
            }
            $sheet->getRowDimension($r)->setRowHeight(22);
        }

        $blankRowsCount = 30;
        $lastBlankRow = $firstDataRow + count($exampleRows) + $blankRowsCount - 1;
        for ($r = $firstDataRow + count($exampleRows); $r <= $lastBlankRow; $r++) {
            $sheet->getStyle("A{$r}:{$lastColLetter}{$r}")->applyFromArray($thinBorder);
            $sheet->getRowDimension($r)->setRowHeight(20);
        }

        $dropdowns = [
            'tipo_persona' => '"PERSON,ORGANIZATION"',
            'tipo_documento' => '"DNI,RUC,CE,PASAPORTE"',
            'estado' => '"ACTIVE,INACTIVE"',
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

        $widths = [
            'tipo_persona' => 16,
            'nombre_o_razon_social' => 28,
            'apellidos' => 16,
            'tipo_documento' => 15,
            'numero_documento' => 18,
            'estado' => 12,
        ];
        foreach ($allHeaders as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($col)->setWidth($widths[$h] ?? 18);
        }

        $sheet->freezePane('A' . $firstDataRow);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_importacion_proveedores.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}