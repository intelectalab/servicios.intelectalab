<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Employee;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\CustomFieldDefinition;
use App\Modules\Core\Http\Requests\StoreEmployeeRequest;
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

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::with('party.person', 'user')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    public function show(string $employee)
    {
        $employee = Employee::findOrFail($employee);

        return response()->json($employee->load('party.person', 'user'));
    }

    public function store(StoreEmployeeRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $employee = DB::transaction(function () use ($request, $tenantId) {
            // Generación automática del código de empleado si no viene especificado
            $employeeCode = $request->employee_code;
            if (empty($employeeCode)) {
                $count = Employee::where('tenant_id', $tenantId)->count();
                do {
                    $count++;
                    $employeeCode = 'EMP-' . str_pad($count, 3, '0', STR_PAD_LEFT);
                } while (Employee::where('tenant_id', $tenantId)->where('employee_code', $employeeCode)->exists());
            }

            $party = Party::create([
                'tenant_id' => $tenantId,
                'party_type' => 'PERSON',
                'status' => 'ACTIVE',
            ]);

            Person::create([
                'party_id' => $party->id,
                'given_names' => $request->given_names,
                'family_name' => $request->family_name,
                'document_type' => $request->document_type,
                'document_number' => $request->document_number,
                'metadata' => $request->metadata ?? [],
            ]);

            $employee = Employee::create([
                'tenant_id' => $tenantId,
                'person_party_id' => $party->id,
                'user_id' => $request->user_id,
                'employee_code' => $employeeCode,
                'position' => $request->position,
                'status' => 'ACTIVE',
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'employee.create',
                entityType: 'Employee',
                entityId: $employee->id,
                metadata: ['employee_code' => $employeeCode],
            );

            return $employee;
        });

        return response()->json($employee->load('party.person', 'user'), 201);
    }

    public function update(Request $request, string $id)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $employee = Employee::where('tenant_id', $tenantId)->findOrFail($id);

        DB::transaction(function () use ($request, $employee, $tenantId) {
            $employee->update([
                'employee_code' => $request->input('employee_code', $employee->employee_code),
                'position' => $request->input('position', $employee->position),
                'user_id' => $request->has('user_id') ? $request->input('user_id') : $employee->user_id,
            ]);

            $party = $employee->party;
            if ($party && $party->person) {
                $party->person->update([
                    'given_names' => $request->input('given_names', $party->person->given_names),
                    'family_name' => $request->input('family_name', $party->person->family_name),
                    'document_type' => $request->input('document_type', $party->person->document_type),
                    'document_number' => $request->input('document_number', $party->person->document_number),
                ]);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'employee.update',
                entityType: 'Employee',
                entityId: $employee->id,
            );
        });

        return response()->json([
            'message' => 'Empleado actualizado correctamente',
            'employee' => $employee->load('party.person', 'user')
        ]);
    }

    public function deactivate(string $employee, Request $request)
    {
        $employee = Employee::findOrFail($employee);

        if ($employee->status === 'INACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este empleado ya está inactivo.'],
            ]);
        }

        $employee->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'employee.deactivate',
            entityType: 'Employee',
            entityId: $employee->id,
        );

        return response()->json($employee);
    }

    public function activate(string $employee, Request $request)
    {
        $employee = Employee::findOrFail($employee);

        if ($employee->status === 'ACTIVE') {
            throw ValidationException::withMessages([
                'status' => ['Este empleado ya está activo.'],
            ]);
        }

        $employee->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'employee.activate',
            entityType: 'Employee',
            entityId: $employee->id,
        );

        return response()->json($employee);
    }

    public function bulkDeactivate(Request $request)
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['uuid']
        ]);

        $tenantId = $request->attributes->get('tenant_id');

        $validIds = Employee::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'INACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay empleados válidos para desactivar.'],
            ]);
        }

        Employee::whereIn('id', $validIds)->update(['status' => 'INACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'employee.bulk_deactivate',
            entityType: 'Employee',
            entityId: null, 
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' empleado(s) desactivado(s) correctamente.',
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

        $validIds = Employee::where('tenant_id', $tenantId)
            ->whereIn('id', $request->ids)
            ->where('status', '!=', 'ACTIVE')
            ->pluck('id')
            ->toArray();

        if (empty($validIds)) {
            throw ValidationException::withMessages([
                'ids' => ['No hay empleados válidos para activar.'],
            ]);
        }

        Employee::whereIn('id', $validIds)->update(['status' => 'ACTIVE']);

        AuditLogger::log(
            tenantId: $tenantId,
            userId: $request->user()->id,
            action: 'employee.bulk_activate',
            entityType: 'Employee',
            entityId: null,
            metadata: ['affected_ids' => $validIds],
        );

        return response()->json([
            'message' => count($validIds) . ' empleado(s) activado(s) correctamente.',
            'affected_ids' => $validIds
        ]);
    }

    public function overview(string $employee)
    {
        $employee = Employee::with('party.person', 'user')->findOrFail($employee);

        return response()->json([
            'employee' => $employee,
            'summary' => [
                'total_time_entries' => 0,
            ],
        ]);
    }

    private function parseExcelDate($value): string
    {
        if (empty($value)) {
            return '';
        }
        if (is_numeric($value) && (float)$value > 20000) {
            $unixDate = (\PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$value));
            return date('Y-m-d', $unixDate);
        }
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

        $lines = in_array($extension, ['xlsx', 'xls'], true) 
            ? $this->excelToLines($path) 
            : file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

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
            'codigo' => 'employee_code',
            'codigo_empleado' => 'employee_code',
            'nombre' => 'nombres',
            'nombre_empleado' => 'nombres',
            'apellido' => 'apellidos',
            'tipo_doc' => 'tipo_documento',
            'documento_tipo' => 'tipo_documento',
            'numero_doc' => 'numero_documento',
            'documento_numero' => 'numero_documento',
            'cargo' => 'position',
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
            
            $hasEmployeeColumn = (bool) array_intersect($candidateKeys, [
                'employee_code', 'codigo_empleado', 'nombres', 'apellidos', 'numero_documento'
            ]);

            if (!$hasEmployeeColumn) {
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

                // Autogenerar o asegurar un código de empleado único y secuencial
                $employeeCode = $row['employee_code'] ?? '';
                if (empty($employeeCode) || Employee::where('tenant_id', $tenantId)->where('employee_code', $employeeCode)->exists()) {
                    // Obtenemos el número más alto actual de los códigos tipo EMP-XXX
                    $maxNumber = Employee::where('tenant_id', $tenantId)
                        ->where('employee_code', 'LIKE', 'EMP-%')
                        ->get()
                        ->map(function ($emp) {
                            return (int) str_replace('EMP-', '', $emp->employee_code);
                        })
                        ->max() ?? 0;

                    $nextNum = max($maxNumber, Employee::where('tenant_id', $tenantId)->count()) + 1;
                    
                    do {
                        $employeeCode = 'EMP-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                        $nextNum++;
                    } while (Employee::where('tenant_id', $tenantId)->where('employee_code', $employeeCode)->exists());
                }

                $party = Party::create([
                    'tenant_id' => $tenantId,
                    'party_type' => 'PERSON',
                    'status' => 'ACTIVE',
                ]);

                Person::create([
                    'party_id' => $party->id,
                    'given_names' => $row['nombres'] ?? $row['nombre'] ?? 'Sin Nombre',
                    'family_name' => $row['apellidos'] ?? $row['apellido'] ?? 'Sin Apellido',
                    'document_type' => strtoupper($row['tipo_documento'] ?? 'DNI'),
                    'document_number' => $row['numero_documento'] ?? $row['documento'] ?? '00000000',
                ]);

                $employee = Employee::create([
                    'tenant_id' => $tenantId,
                    'person_party_id' => $party->id,
                    'employee_code' => $employeeCode,
                    'position' => $row['position'] ?? $row['cargo'] ?? null,
                    'status' => strtoupper($row['estado'] ?? 'ACTIVE'),
                ]);

                // Procesamiento de campos personalizados (EAV)
                $standardKeys = [
                    'employee_code', 'nombres', 'nombre', 'apellidos', 'apellido',
                    'tipo_documento', 'numero_documento', 'documento', 'position', 'cargo', 'estado'
                ];

                foreach ($normalizedHeaders as $index => $key) {
                    if (in_array($key, $standardKeys, true)) continue;
                    
                    $val = $data[$index] ?? '';
                    if ($val === '') continue;

                    $definition = CustomFieldDefinition::where('tenant_id', $tenantId)
                        ->where('entity_type', 'Employee')
                        ->get()
                        ->first(function ($candidate) use ($key, $normalizeHeader) {
                            return $normalizeHeader($candidate->field_key) === $key
                                || $normalizeHeader($candidate->label) === $key;
                        });

                    if (!$definition) {
                        $definition = CustomFieldDefinition::create([
                            'tenant_id' => $tenantId,
                            'entity_type' => 'Employee',
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
                            'entity_id' => $employee->id,
                        ],
                        [
                            'tenant_id' => $tenantId,
                            'entity_type' => 'Employee',
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
                action: 'employee.import',
                entityType: 'Employee',
                entityId: null,
                metadata: ['imported_count' => $importedCount]
            );

            return response()->json([
                'imported' => $importedCount,
                'message' => "Se importaron {$importedCount} empleados exitosamente.",
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
            if ($isBlank) continue;

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
            ->where('entity_type', 'Employee')
            ->get(['field_key', 'field_type']);

        $headers = [
            'employee_code',
            'nombres',
            'apellidos',
            'tipo_documento',
            'numero_documento',
            'position',
            'estado'
        ];

        $fieldKeys = $customFields->pluck('field_key')->toArray();
        $allHeaders = array_merge($headers, $fieldKeys);

        if ($customFields->isEmpty()) {
            $allHeaders[] = 'fecha_ingreso';
            $allHeaders[] = 'es_remoto';
            $customFields = collect([
                (object)['field_key' => 'fecha_ingreso', 'field_type' => 'date'],
                (object)['field_key' => 'es_remoto', 'field_type' => 'boolean'],
            ]);
        }

        $row1 = ['EMP-001', 'Ana', 'Torres Silva', 'DNI', '45678912', 'Desarrolladora', 'ACTIVE'];
        foreach ($customFields as $field) {
            $row1[] = match ($field->field_type) {
                'number' => 2000,
                'date' => '2026-09-15',
                'boolean' => 'true',
                default => 'Ejemplo Texto'
            };
        }

        $row2 = ['EMP-002', 'Luis', 'Mendoza Rios', 'DNI', '78912345', 'Supervisor', 'ACTIVE'];
        foreach ($customFields as $field) {
            $row2[] = match ($field->field_type) {
                'number' => 2500,
                'date' => '2026-09-16',
                'boolean' => 'false',
                default => 'Otro Ejemplo'
            };
        }

        $instructions = [
            '1. employee_code: Código único del empleado (ej. EMP-001).',
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

        // Título
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'Plantilla de importación de empleados');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 15, 'bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $BRAND_DARK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Subtítulo
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

        // Instrucciones
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

        // Cabeceras
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

        // Filas de ejemplo
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
                } elseif (in_array($headerName, ['employee_code', 'tipo_documento'], true)) {
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

        // Listas desplegables (Dropdowns) para tipo_documento y estado
        $dropdowns = [
            'tipo_documento' => '"DNI,RUC,CE,PASAPORTE"',
            'estado' => '"ACTIVE,INACTIVE"',
        ];
        foreach ($dropdowns as $key => $formula) {
            $colIndex = array_search($key, $allHeaders, true);
            if ($colIndex === false) continue;
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

        // Anchos de columna amplios para que nunca se corten los textos
        $widths = [
            'employee_code' => 18,
            'nombres' => 22,
            'apellidos' => 22,
            'tipo_documento' => 16,
            'numero_documento' => 20,
            'position' => 22,
            'estado' => 14,
        ];
        foreach ($allHeaders as $i => $h) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
            $sheet->getColumnDimension($col)->setWidth($widths[$h] ?? 20);
        }

        $sheet->freezePane('A' . $firstDataRow);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_importacion_empleados.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}