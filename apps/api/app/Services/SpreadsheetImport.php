<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lectura de un archivo de importación (.xlsx, .xls o .csv) y generación de su
 * plantilla, como en la importación de Clientes: la cabecera se reconoce por
 * sus nombres (no por ser la primera fila), se aceptan tildes, espacios y
 * guiones en los títulos, y las filas que empiezan con "#" son instrucciones.
 */
class SpreadsheetImport
{
    // Colores del logo, en formato ARGB de Excel.
    private const BRAND = 'FF1B8FC9';
    private const BRAND_DARK = 'FF0F5A82';
    private const INK = 'FF1D1D1B';
    private const SOFT = 'FFE3F2FA';
    private const BORDER = 'FFCFE0E8';

    /** Filas en blanco de la plantilla, con sus listas desplegables. */
    private const BLANK_ROWS = 200;

    /** "Razón Social" → "razon_social". */
    public static function normalize(string $header): string
    {
        $clean = trim(str_replace("\xEF\xBB\xBF", '', $header));
        // Str::ascii quita las tildes igual en cualquier sistema (iconv en Windows las vuelve "'o").
        $clean = preg_replace('/[^a-z0-9]+/', '_', strtolower(\Illuminate\Support\Str::ascii($clean))) ?? '';

        return trim($clean, '_');
    }

    /**
     * Filas del archivo, cada una como [columna => valor], con su número de fila.
     *
     * @param  array<string, string>  $aliases  otros títulos aceptados para cada columna
     * @param  string[]  $anchors  columnas que identifican la fila de cabecera
     * @return array{0: string[], 1: array<int, array<string, string>>}  cabeceras y filas por número
     */
    public static function read(UploadedFile $file, array $aliases, array $anchors): array
    {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: '');
        $raw = in_array($extension, ['xlsx', 'xls'], true)
            ? IOFactory::load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false)
            : self::csvRows($file->getRealPath());

        $headers = [];
        $rows = [];
        foreach ($raw as $index => $cells) {
            $cells = array_map(static fn ($cell) => trim((string) ($cell ?? '')), $cells);
            $first = $cells[0] ?? '';
            if (implode('', $cells) === '' || str_starts_with($first, '#')) {
                continue;
            }

            if (! $headers) {
                $keys = array_map(static fn ($cell) => $aliases[self::normalize($cell)] ?? self::normalize($cell), $cells);
                if (count(array_intersect($keys, $anchors)) >= 2) {
                    $headers = $keys;
                }

                continue;
            }

            $row = [];
            foreach ($headers as $position => $key) {
                if ($key !== '') {
                    $row[$key] = $cells[$position] ?? '';
                }
            }
            $rows[$index + 1] = $row;
        }

        if (! $headers) {
            throw ValidationException::withMessages(['file' => ['No se encontraron cabeceras válidas en el archivo. Usa la plantilla.']]);
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => ['El archivo no tiene filas para importar.']]);
        }

        return [array_values(array_filter($headers, static fn ($key) => $key !== '')), $rows];
    }

    private static function csvRows(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $sample = implode("\n", array_slice($lines, 0, 5));
        $delimiter = substr_count($sample, ';') >= substr_count($sample, ',') ? ';' : ',';

        return array_map(static fn ($line) => str_getcsv(str_replace("\xEF\xBB\xBF", '', $line), $delimiter), $lines);
    }

    /** Fecha de una celda: número de serie de Excel o texto ("15/09/2026", "2026-09-15"). */
    public static function date(string $value): string
    {
        if (is_numeric($value) && (float) $value > 20000) {
            return gmdate('Y-m-d', (int) round(((float) $value - 25569) * 86400));
        }
        $parsed = strtotime(str_replace('/', '-', $value));

        return $parsed ? date('Y-m-d', $parsed) : trim($value);
    }

    /** "Sí", "si", "x", "1", "true" → true. */
    public static function truthy(string $value): bool
    {
        return in_array(self::normalize($value), ['si', 's', 'x', '1', 'true', 'verdadero', 'yes'], true);
    }

    /**
     * Plantilla .xlsx: título, instrucciones (filas con "#"), cabecera y filas de ejemplo.
     *
     * @param  string[]  $headers
     * @param  array<int, array<int, string|int|float>>  $examples
     * @param  string[]  $notes
     * @param  array<string, string[]>  $lists  columnas con lista desplegable: cabecera => opciones
     * @param  string[]  $openLists  columnas cuya lista solo sugiere (aceptan otro valor)
     */
    public static function template(string $filename, string $title, array $headers, array $examples, array $notes, array $lists = [], array $openLists = []): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Plantilla');
        $sheet->setShowGridlines(false);

        $last = Coordinate::stringFromColumnIndex(count($headers));
        $border = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::BORDER]]]];

        $sheet->mergeCells("A1:{$last}1");
        $sheet->setCellValue('A1', "# {$title}");
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BRAND_DARK]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $line = 2;
        foreach ($notes as $note) {
            $sheet->mergeCells("A{$line}:{$last}{$line}");
            $sheet->setCellValue("A{$line}", "# {$note}");
            $sheet->getStyle("A{$line}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9, 'color' => ['argb' => self::BRAND_DARK]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::SOFT]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
            ]);
            $line++;
        }

        foreach ($headers as $index => $header) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).$line;
            $sheet->setCellValue($cell, $header);
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth(max(16, strlen($header) + 6));
        }
        $sheet->getStyle("A{$line}:{$last}{$line}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BRAND]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ] + $border);
        $sheet->getRowDimension($line)->setRowHeight(24);
        $sheet->freezePane('A'.($line + 1));

        foreach ($examples as $example) {
            $line++;
            foreach (array_values($example) as $index => $value) {
                // Como texto: los RUC, DNI y teléfonos no deben perder ceros ni volverse 2.0E+10.
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($index + 1).$line,
                    (string) $value,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
            }
            $sheet->getStyle("A{$line}:{$last}{$line}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 10, 'color' => ['argb' => self::INK]],
            ] + $border);
        }

        // Filas en blanco listas para llenar, con el mismo borde y formato de texto.
        $firstData = $line - count($examples) + 1;
        $lastData = $line + self::BLANK_ROWS;
        $sheet->getStyle('A'.($line + 1).":{$last}{$lastData}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 10, 'color' => ['argb' => self::INK]],
            'numberFormat' => ['formatCode' => '@'],
        ] + $border);

        // Listas desplegables. Las opciones van en una hoja oculta: así pueden llevar comas y ser largas.
        $lists = array_filter($lists);
        if ($lists) {
            $options = $spreadsheet->createSheet();
            $options->setTitle('Listas');
            $position = 0;
            foreach ($lists as $header => $values) {
                $index = array_search($header, $headers, true);
                if ($index === false) {
                    continue;
                }
                $position++;
                $source = Coordinate::stringFromColumnIndex($position);
                foreach (array_values($values) as $row => $value) {
                    $options->setCellValueExplicit($source.($row + 1), (string) $value, DataType::TYPE_STRING);
                }
                $open = in_array($header, $openLists, true);
                $column = Coordinate::stringFromColumnIndex($index + 1);

                $validation = new DataValidation();
                $validation->setType(DataValidation::TYPE_LIST);
                $validation->setErrorStyle(DataValidation::STYLE_STOP);
                $validation->setAllowBlank(true);
                $validation->setShowDropDown(true);
                $validation->setShowErrorMessage(! $open);
                $validation->setErrorTitle('Valor no válido');
                $validation->setError('Elige una opción de la lista.');
                $validation->setFormula1("Listas!\${$source}\$1:\${$source}\$".count($values));
                $sheet->setDataValidation("{$column}{$firstData}:{$column}{$lastData}", $validation);
            }
            $options->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
            $spreadsheet->setActiveSheetIndex(0);
        }

        return response()->streamDownload(
            static fn () => (new Xlsx($spreadsheet))->save('php://output'),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
