<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Una importación desde Excel o CSV, fila por fila. Cada fila va en su propia transacción
 * (savepoint): la que falla no arrastra a las demás. Con `dry_run` (Validar) todo se deshace al
 * final y se devuelve qué pasaría con cada fila; sin él, se guarda y queda en el historial
 * (import_jobs). La respuesta conserva la forma de siempre (imported, updated, message,
 * warnings) para el ImportModal de cada listado, y suma `rows` con el resultado de cada fila.
 */
class ImportReport
{
    /** Filas con su resultado: created, updated, skipped o error. */
    private array $rows = [];

    private ?string $mark = null;

    private ?string $note = null;

    /** Etiquetas del historial y de la pantalla Importación masiva. */
    public const ENTITIES = [
        'parties' => 'Clientes y actores',
        'services' => 'Catálogo de servicios',
        'leads' => 'Leads (CRM)',
        'checklists' => 'Plantillas de checklist',
        'journal' => 'Asientos contables',
    ];

    /** Detalle guardado en el historial: las filas que no entraron, hasta este número. */
    private const DETAIL_LIMIT = 500;

    private function __construct(private readonly string $entity) {}

    /**
     * @param  array<int, array<string, string>>  $rows  número de fila => valores (como los da SpreadsheetImport::read)
     * @param  callable(array $row, int $number, self $report): void  $each  crea la fila; llama a updated() o skipped() si no la crea
     * @param  string[]  $preview  columnas que se muestran en la vista previa
     */
    public static function run(Request $request, string $entity, array $rows, array $preview, callable $each, string $noun = 'registro(s)'): JsonResponse
    {
        $report = new self($entity);
        $dryRun = $request->boolean('dry_run');

        DB::beginTransaction();
        try {
            foreach ($rows as $number => $row) {
                $report->mark = null;
                $report->note = null;
                try {
                    DB::transaction(fn () => $each($row, $number, $report));
                    $report->add($number, $row, $preview, $report->mark ?? 'created', $report->note);
                } catch (ValidationException $e) {
                    $report->add($number, $row, $preview, 'error', (string) collect($e->errors())->flatten()->first());
                } catch (QueryException) {
                    $report->add($number, $row, $preview, 'error', 'No se pudo guardar (revisa que los datos no sean demasiado largos).');
                }
            }

            if ($dryRun) {
                DB::rollBack();

                return response()->json($report->summary() + ['dry_run' => true, 'rows' => $report->rows]);
            }

            $report->record($request, (string) ($request->file('file')?->getClientOriginalName() ?: 'archivo'));
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $summary = $report->summary();
        if ($summary['imported'] + $summary['updated'] === 0) {
            return response()->json([
                'message' => 'No se pudo importar ningún registro.',
                'errors' => ['file' => $report->warnings() ?: ['El archivo no tiene filas.']],
                'rows' => $report->rows,
            ], 422);
        }

        return response()->json($summary + [
            'message' => "Se importaron {$summary['imported']} {$noun}".($summary['updated'] ? " y se actualizaron {$summary['updated']}" : '').'.',
            'warnings' => $report->warnings(),
            'rows' => $report->rows,
        ]);
    }

    /** La fila no se creó pero cambió un registro que ya existía. */
    public function updated(string $note): void
    {
        $this->mark = 'updated';
        $this->note = $note;
    }

    /** La fila no se importa y no cambia nada (p. ej. ya existía igual). */
    public function skipped(string $note): void
    {
        $this->mark = 'skipped';
        $this->note = $note;
    }

    /**
     * Historial de una importación que agrupa filas (plantillas de checklist, asientos) y no pasa
     * por run(): se guarda junto con lo importado.
     */
    public static function recordGrouped(Request $request, string $entity, int $created, array $warnings, int $totalRows): void
    {
        $report = new self($entity);
        foreach (array_values($warnings) as $index => $warning) {
            $report->rows[] = ['row' => $index + 1, 'status' => 'error', 'message' => $warning, 'values' => []];
        }
        $report->record($request, (string) ($request->file('file')?->getClientOriginalName() ?: 'archivo'), $created, $totalRows);
    }

    private function add(int $number, array $row, array $preview, string $status, ?string $message): void
    {
        $this->rows[] = [
            'row' => $number,
            'status' => $status,
            'message' => $message,
            'values' => array_combine($preview, array_map(fn (string $key) => trim((string) ($row[$key] ?? '')), $preview)),
        ];
    }

    private function count(string $status): int
    {
        return count(array_filter($this->rows, fn (array $row) => $row['status'] === $status));
    }

    private function summary(): array
    {
        return [
            'imported' => $this->count('created'),
            'updated' => $this->count('updated'),
            'skipped' => $this->count('skipped'),
            'failed' => $this->count('error'),
            'total' => count($this->rows),
        ];
    }

    /** Lo que no entró tal cual, con el texto de siempre ("Fila N: …"). */
    private function warnings(): array
    {
        return array_values(array_map(
            fn (array $row) => "Fila {$row['row']}: {$row['message']}",
            array_filter($this->rows, fn (array $row) => $row['status'] !== 'created' && $row['message'])
        ));
    }

    private function record(Request $request, string $fileName, ?int $created = null, ?int $total = null): void
    {
        $created ??= $this->count('created');
        $updated = $this->count('updated');
        $failed = $this->count('error');
        $status = $created + $updated === 0 ? 'FAILED' : ($failed > 0 ? 'PARTIAL' : 'COMPLETED');
        $details = array_slice(array_values(array_filter($this->rows, fn (array $row) => $row['status'] !== 'created')), 0, self::DETAIL_LIMIT);

        DB::table('import_jobs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $request->attributes->get('tenant_id'),
            'entity' => $this->entity,
            'file_name' => mb_substr($fileName, 0, 255),
            'total_rows' => $total ?? count($this->rows),
            'created_count' => $created,
            'updated_count' => $updated,
            'skipped_count' => $this->count('skipped'),
            'error_count' => $failed,
            'status' => $status,
            'details' => json_encode($details),
            'created_by' => $request->user()?->id,
            'branch_id' => AccessScope::branchId(),
            'created_at' => now(),
        ]);
    }
}
