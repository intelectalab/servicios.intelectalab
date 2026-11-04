<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Chart;
use App\Modules\Accounting\Support\Ledger;
use App\Services\AuditLogger;
use App\Services\ImportReport;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SpreadsheetImport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\LocalTime;

/**
 * Libro diario: los asientos que generan solos los documentos (ventas, cobros, compras, pagos,
 * caja, extractos) y los manuales. Un asiento contabilizado no se edita: se extorna.
 */
class JournalController extends Controller
{
    /** Cuentas del asiento en una línea: "1212 D · 40111 H · 7041 H". */
    private const ACCOUNTS = "(SELECT string_agg(l.account_code || CASE WHEN l.debit > 0 THEN ' D' ELSE ' H' END, ' · ' ORDER BY l.position)
        FROM acc_journal_lines l WHERE l.entry_id = acc_journal_entries.id)";

    private const AMOUNT = '(SELECT coalesce(sum(l.debit), 0) FROM acc_journal_lines l WHERE l.entry_id = acc_journal_entries.id)';

    private const REVERSED_NUMBER = '(SELECT r.entry_number FROM acc_journal_entries r WHERE r.id = acc_journal_entries.reversed_by)';

    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $query = $this->listing($tenantId);
        $this->filter($query, $request);

        ListSort::apply($query, $request, [
            'number' => 'acc_journal_entries.entry_number',
            'description' => 'lower(acc_journal_entries.description)',
            'accounts' => self::ACCOUNTS,
            'amount' => self::AMOUNT,
            'status' => 'acc_journal_entries.status',
        ], fn ($q) => $q->orderByDesc('acc_journal_entries.entry_date')->orderByDesc('acc_journal_entries.entry_number'), 'acc_journal_entries.id');

        $page = $query->paginate(min(max($request->integer('per_page', 15), 1), 200));
        $page->getCollection()->transform(fn ($entry) => $this->row($entry));

        return response()->json($page);
    }

    /** Indicadores del libro diario (del periodo elegido o del último con asientos). */
    public function summary(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $periods = $this->periods($tenantId);
        $period = in_array($request->query('period'), $periods, true) ? $request->query('period') : ($periods[0] ?? now()->format('Y-m'));
        $totals = DB::table('acc_journal_entries')->where('tenant_id', $tenantId)
            ->selectRaw('count(*) AS total')
            ->selectRaw('count(*) FILTER (WHERE period = ?) AS in_period', [$period])
            ->selectRaw("count(*) FILTER (WHERE source_type NOT IN ('MANUAL', 'IMPORT')) AS automatic")
            ->first();
        $sums = DB::table('acc_journal_lines')->where('tenant_id', $tenantId)->selectRaw('coalesce(sum(debit), 0) AS debit, coalesce(sum(credit), 0) AS credit')->first();

        return response()->json([
            'total' => (int) $totals->total,
            'period' => $period,
            'in_period' => (int) $totals->in_period,
            'automatic' => (int) $totals->automatic,
            'balanced' => abs((float) $sums->debit - (float) $sums->credit) < 0.05,
        ]);
    }

    /** Listas de los filtros y del asiento manual. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'periods' => $this->periods($tenantId),
            'sources' => collect(Ledger::SOURCE_FILTERS)->map(fn (string $label, string $key) => ['value' => $key, 'label' => $label])->values(),
            'accounts' => Chart::options($tenantId),
            'cost_centers' => DB::table('cost_centers')->where('tenant_id', $tenantId)->where('active', true)->orderBy('code')->limit(500)
                ->get(['id', 'code', 'name'])->map(fn ($center) => ['id' => $center->id, 'code' => $center->code, 'name' => $center->name])->values(),
        ]);
    }

    public function show(string $entry, Request $request)
    {
        return response()->json($this->detail($request->attributes->get('tenant_id'), $this->find($request, $entry)->id));
    }

    /** Asiento manual: cuadrado, con al menos dos líneas, cuentas imputables y en un periodo abierto. */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2', 'max:40'],
            'lines.*.account' => ['nullable', 'string', 'max:20'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'lines.*.cost_center_id' => ['nullable', 'uuid'],
            'lines.*.note' => ['nullable', 'string', 'max:500'],
        ], [
            'date.required' => 'Indica la fecha del asiento.',
            'description.required' => 'Indica la glosa del asiento.',
            'lines.required' => 'Un asiento necesita al menos dos líneas.',
            'lines.min' => 'Un asiento necesita al menos dos líneas.',
        ]);

        $lines = collect($data['lines'])->filter(fn (array $line) => ! empty($line['account']) && ((float) ($line['debit'] ?? 0) > 0 || (float) ($line['credit'] ?? 0) > 0))->values();
        if ($lines->count() < 2) {
            throw ValidationException::withMessages(['lines' => ['Un asiento necesita al menos dos líneas.']]);
        }
        if ($lines->contains(fn (array $line) => (float) ($line['debit'] ?? 0) > 0 && (float) ($line['credit'] ?? 0) > 0)) {
            throw ValidationException::withMessages(['lines' => ['Cada línea va al debe o al haber, no a los dos.']]);
        }
        foreach ($lines as $line) {
            if (! Chart::postable($tenantId, $line['account'])) {
                throw ValidationException::withMessages(['lines' => ['Usa cuentas de detalle (imputables) del plan de cuentas.']]);
            }
        }
        $debit = round($lines->sum(fn (array $line) => (float) ($line['debit'] ?? 0)), 2);
        $credit = round($lines->sum(fn (array $line) => (float) ($line['credit'] ?? 0)), 2);
        if (abs($debit - $credit) >= 0.005) {
            throw ValidationException::withMessages(['lines' => ['El asiento no cuadra: el debe debe ser igual al haber.']]);
        }
        $centers = $lines->pluck('cost_center_id')->filter()->unique();
        if ($centers->isNotEmpty() && DB::table('cost_centers')->where('tenant_id', $tenantId)->whereIn('id', $centers)->count() !== $centers->count()) {
            throw ValidationException::withMessages(['lines' => ['Alguno de los centros de costo no existe.']]);
        }
        $date = Carbon::parse($data['date'])->toDateString();
        if (Ledger::isClosed($tenantId, substr($date, 0, 7))) {
            throw ValidationException::withMessages(['date' => ['El periodo '.substr($date, 0, 7).' está cerrado.']]);
        }

        $id = DB::transaction(function () use ($request, $tenantId, $data, $lines, $date, $debit) {
            $id = Ledger::post($tenantId, $date, trim($data['description']), ['type' => 'MANUAL'], $lines->map(fn (array $line) => [
                'account' => $line['account'], 'debit' => (float) ($line['debit'] ?? 0), 'credit' => (float) ($line['credit'] ?? 0),
                'cost_center_id' => $line['cost_center_id'] ?? null, 'note' => isset($line['note']) ? trim($line['note']) : null,
            ])->all(), $request->user()->id);
            $number = DB::table('acc_journal_entries')->where('id', $id)->value('entry_number');
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'journal.create', entityType: 'JournalEntry', entityId: $id,
                metadata: ['note' => "{$number} · ".trim($data['description']), 'amount' => $debit],
            );

            return $id;
        });

        return response()->json($this->detail($tenantId, $id), 201);
    }

    /** Columnas de la plantilla de importación: una fila por línea; las filas del mismo "asiento" forman uno. */
    public const IMPORT_HEADERS = ['asiento', 'fecha', 'glosa', 'cuenta', 'debe', 'haber', 'centro_costo', 'detalle'];

    private const IMPORT_ALIASES = [
        'n_asiento' => 'asiento', 'nro_asiento' => 'asiento', 'numero' => 'asiento', 'grupo' => 'asiento',
        'fecha_contable' => 'fecha', 'descripcion' => 'glosa', 'concepto' => 'glosa',
        'codigo_de_cuenta' => 'cuenta', 'cuenta_contable' => 'cuenta', 'codigo' => 'cuenta',
        'cargo' => 'debe', 'debito' => 'debe', 'abono' => 'haber', 'credito' => 'haber',
        'centro' => 'centro_costo', 'centro_de_costo' => 'centro_costo', 'nota' => 'detalle',
    ];

    private const IMPORT_MAX_ROWS = 2000;

    public function importTemplate(Request $request)
    {
        return SpreadsheetImport::template('plantilla_asientos.xlsx', ...self::importTemplateParts($request->attributes->get('tenant_id')));
    }

    /** Título, columnas, ejemplos, instrucciones, listas y anchos de la plantilla de asientos. */
    public static function importTemplateParts(string $tenantId): array
    {
        Chart::ensure($tenantId);
        $accounts = DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('postable', true)->where('active', true)->orderBy('code')->pluck('code')->all();
        $centers = DB::table('cost_centers')->where('tenant_id', $tenantId)->where('active', true)->orderBy('code')->limit(500)->pluck('code')->all();
        $day = LocalTime::today()->startOfMonth()->addDays(min(4, LocalTime::today()->day - 1))->format('d/m/Y');
        $center = $centers[0] ?? '';

        return [
            'Plantilla de asientos contables',
            self::IMPORT_HEADERS,
            [
                ['1', $day, 'Aporte de capital de los socios', '1041', '10000.00', '', '', 'Depósito en cuenta corriente'],
                ['1', $day, '', '5011', '', '10000.00', '', ''],
                ['2', $day, 'Alquiler de oficina del mes', '6352', '1500.00', '', $center, ''],
                ['2', $day, '', '1041', '', '1500.00', '', 'Transferencia al arrendador'],
            ],
            [
                'Una fila por cada línea del asiento. Las filas con el mismo número en "asiento" forman un asiento, en el orden del archivo.',
                'fecha y glosa basta con ponerlas en la primera fila de cada asiento. fecha: día/mes/año; su periodo debe estar abierto.',
                'cuenta: el código de una cuenta de detalle (imputable) y activa de tu plan de cuentas.',
                'Cada línea lleva un monto en debe o en haber (no en los dos), sin símbolo de moneda. El asiento debe cuadrar.',
                'centro_costo: el código del centro de costo, si aplica. detalle: una nota para la línea.',
                'Un asiento con algún error no entra; los demás sí. Reemplaza las filas de ejemplo por las tuyas antes de importar.',
            ],
            ['cuenta' => $accounts, 'centro_costo' => $centers],
            [],
            ['asiento' => 12, 'fecha' => 14, 'glosa' => 40, 'cuenta' => 14, 'debe' => 16, 'haber' => 16, 'centro_costo' => 16, 'detalle' => 36],
        ];
    }

    /**
     * Importa asientos desde Excel o CSV. Cada asiento se valida entero (cuadra, cuentas imputables,
     * periodo abierto, centro que exista): el que tiene un error no entra y se avisa; los demás quedan
     * contabilizados como importados, cada uno con su registro en auditoría.
     */
    public function import(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Chart::ensure($tenantId);
        $request->validate(['file' => ['required', 'file', 'max:5120']], [
            'file.required' => 'Selecciona el archivo con los asientos.',
            'file.max' => 'El archivo supera los 5 MB: impórtalo por partes.',
        ]);
        [, $rows] = SpreadsheetImport::read($request->file('file'), self::IMPORT_ALIASES, ['asiento', 'cuenta']);
        if (count($rows) > self::IMPORT_MAX_ROWS) {
            throw ValidationException::withMessages(['file' => ['Un archivo admite como mucho '.self::IMPORT_MAX_ROWS.' líneas: impórtalo por partes.']]);
        }

        $number = fn (string $value) => $value === '' ? 0.0 : (is_numeric($clean = str_replace([' ', ','], ['', ''], $value)) ? round((float) $clean, 2) : null);
        $groups = [];
        $warnings = [];
        foreach ($rows as $line => $row) {
            $key = trim($row['asiento'] ?? '');
            if ($key === '') {
                $warnings[] = "Fila {$line}: falta el número de asiento.";

                continue;
            }
            $groups[$key] ??= ['first' => $line, 'date' => '', 'description' => '', 'lines' => [], 'errors' => []];
            $group = &$groups[$key];
            if ($group['date'] === '' && trim($row['fecha'] ?? '') !== '') {
                $group['date'] = SpreadsheetImport::date(trim($row['fecha']));
            }
            if ($group['description'] === '' && trim($row['glosa'] ?? '') !== '') {
                $group['description'] = trim($row['glosa']);
            }
            $debit = $number(trim($row['debe'] ?? ''));
            $credit = $number(trim($row['haber'] ?? ''));
            $account = trim($row['cuenta'] ?? '');
            if ($debit === null || $credit === null || $debit < 0 || $credit < 0) {
                $group['errors'][] = "fila {$line}: el debe y el haber son montos positivos";
            } elseif (($debit > 0) === ($credit > 0)) {
                $group['errors'][] = "fila {$line}: la línea va al debe o al haber (uno de los dos)";
            } elseif (! Chart::postable($tenantId, $account)) {
                $group['errors'][] = "fila {$line}: la cuenta {$account} no es de detalle o no está activa";
            }
            $centerCode = trim($row['centro_costo'] ?? '');
            $centerId = $centerCode === '' ? null
                : DB::table('cost_centers')->where('tenant_id', $tenantId)->whereRaw('upper(code) = ?', [mb_strtoupper($centerCode)])->value('id');
            if ($centerCode !== '' && ! $centerId) {
                $group['errors'][] = "fila {$line}: no existe el centro de costo {$centerCode}";
            }
            $group['lines'][] = ['account' => $account, 'debit' => $debit ?? 0, 'credit' => $credit ?? 0, 'cost_center_id' => $centerId, 'note' => trim($row['detalle'] ?? '') ?: null];
            unset($group);
        }

        $ready = [];
        foreach ($groups as $key => $group) {
            $label = "Asiento {$key} (fila {$group['first']})";
            $errors = $group['errors'];
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $group['date'])) {
                $errors[] = 'falta la fecha o no se entiende (usa día/mes/año)';
            } elseif (Ledger::isClosed($tenantId, substr($group['date'], 0, 7))) {
                $errors[] = 'el periodo '.substr($group['date'], 0, 7).' está cerrado';
            } elseif ($group['date'] > LocalTime::today()->toDateString()) {
                $errors[] = 'la fecha no puede ser futura';
            }
            if ($group['description'] === '') {
                $errors[] = 'falta la glosa';
            }
            if (count($group['lines']) < 2) {
                $errors[] = 'necesita al menos dos líneas';
            }
            $debit = round(array_sum(array_column($group['lines'], 'debit')), 2);
            $credit = round(array_sum(array_column($group['lines'], 'credit')), 2);
            if (abs($debit - $credit) >= 0.005) {
                $errors[] = 'no cuadra (debe '.number_format($debit, 2).' · haber '.number_format($credit, 2).')';
            }
            if ($errors) {
                $warnings[] = "{$label}: ".implode('; ', array_unique($errors)).'. No se importó.';

                continue;
            }
            $ready[] = [...$group, 'key' => $key, 'amount' => $debit];
        }

        if (! $ready) {
            ImportReport::recordGrouped($request, 'journal', 0, $warnings, count($rows));
            throw ValidationException::withMessages(['file' => ['No se importó ningún asiento.', ...$warnings]]);
        }

        $file = Str::limit((string) $request->file('file')->getClientOriginalName(), 120);
        $numbers = DB::transaction(function () use ($request, $tenantId, $ready, $file, $warnings, $rows) {
            ImportReport::recordGrouped($request, 'journal', count($ready), $warnings, count($rows));
            $numbers = [];
            foreach ($ready as $group) {
                $id = Ledger::post($tenantId, $group['date'], Str::limit($group['description'], 1000, ''), ['type' => 'IMPORT', 'number' => Str::limit($file, 100, '')], $group['lines'], $request->user()->id);
                $number = DB::table('acc_journal_entries')->where('id', $id)->value('entry_number');
                AuditLogger::log(
                    tenantId: $tenantId, userId: $request->user()->id, action: 'journal.import', entityType: 'JournalEntry', entityId: $id,
                    metadata: ['note' => "{$number} · {$group['description']} · desde {$file} (asiento {$group['key']})", 'amount' => $group['amount']],
                );
                $numbers[] = $number;
            }

            return $numbers;
        });

        $count = count($numbers);

        return response()->json([
            'imported' => $count,
            'message' => ($count === 1 ? '1 asiento importado' : "{$count} asientos importados").': '.implode(', ', array_slice($numbers, 0, 5)).($count > 5 ? '…' : '').'.',
            'warnings' => $warnings,
        ]);
    }

    /** Extorno: un asiento inverso en el periodo abierto; el original queda extornado. */
    public function reverse(string $entry, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $entry = $this->find($request, $entry);
        $reason = trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo del extorno.'])['reason']);
        // El asiento de cierre se deshace reabriendo el ejercicio, que también reabre diciembre.
        if ($entry->source_type === 'YEAR_CLOSE') {
            throw ValidationException::withMessages(['status' => ['El asiento de cierre no se extorna aquí: reabre el ejercicio en Plan de cuentas y periodos.']]);
        }

        DB::transaction(function () use ($request, $tenantId, $entry, $reason) {
            $reversal = Ledger::reverse($tenantId, $entry->id, $reason, $request->user()->id);
            AuditLogger::log(
                tenantId: $tenantId, userId: $request->user()->id, action: 'journal.reverse', entityType: 'JournalEntry', entityId: $entry->id,
                metadata: ['from' => 'POSTED', 'to' => 'REVERSED', 'note' => $entry->entry_number.' · '.DB::table('acc_journal_entries')->where('id', $reversal)->value('entry_number').": {$reason}"],
            );
        });

        return response()->json($this->detail($tenantId, $entry->id));
    }

    private function listing(string $tenantId)
    {
        return DB::table('acc_journal_entries')->where('acc_journal_entries.tenant_id', $tenantId)
            ->select('acc_journal_entries.*')
            ->selectRaw(self::ACCOUNTS.' AS accounts')
            ->selectRaw(self::AMOUNT.' AS amount')
            ->selectRaw(self::REVERSED_NUMBER.' AS reversed_number');
    }

    private function filter($query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        $source = (string) $request->query('source', '');

        $query
            ->when(preg_match('/^\d{4}-\d{2}$/', (string) $request->query('period')), fn ($q) => $q->where('acc_journal_entries.period', $request->query('period')))
            ->when($source === 'BANK', fn ($q) => $q->whereIn('acc_journal_entries.source_type', ['BANK_FEE', 'UNIDENTIFIED']))
            ->when($source !== 'BANK' && isset(Ledger::SOURCES[$source]), fn ($q) => $q->where('acc_journal_entries.source_type', $source))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('acc_journal_entries.entry_number', 'ILIKE', $like)
                    ->orWhere('acc_journal_entries.description', 'ILIKE', $like)
                    ->orWhere('acc_journal_entries.source_number', 'ILIKE', $like)
                    ->orWhereExists(fn ($lines) => $lines->selectRaw('1')->from('acc_journal_lines as l')
                        ->whereColumn('l.entry_id', 'acc_journal_entries.id')->where('l.account_code', 'ILIKE', addcslashes($search, '%_\\').'%')));
            });

        ColumnFilters::apply($query, $request, [
            'number' => ColumnFilters::text("concat_ws(' ', acc_journal_entries.entry_number, to_char(acc_journal_entries.entry_date, 'DD/MM/YYYY'))"),
            'date' => ColumnFilters::date('acc_journal_entries.entry_date'),
            'description' => ColumnFilters::text("concat_ws(' ', acc_journal_entries.description, acc_journal_entries.source_number)"),
            'accounts' => ColumnFilters::text(self::ACCOUNTS),
            'amount' => ColumnFilters::number(self::AMOUNT),
        ]);
    }

    private function row(object $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => Carbon::parse($entry->entry_date)->toDateString(),
            'period' => $entry->period,
            'description' => $entry->description,
            'source_type' => $entry->source_type,
            'source_label' => Ledger::SOURCES[$entry->source_type] ?? 'Otro',
            'source_number' => $entry->source_number,
            'accounts' => $entry->accounts,
            'amount' => round((float) $entry->amount, 2),
            'status' => $entry->status,
            'reversed_number' => $entry->reversed_number,
        ];
    }

    private function find(Request $request, string $id): object
    {
        abort_unless(Str::isUuid($id), 404);
        $entry = DB::table('acc_journal_entries')->where('tenant_id', $request->attributes->get('tenant_id'))->where('id', $id)->first();
        abort_unless((bool) $entry, 404);

        return $entry;
    }

    private function detail(string $tenantId, string $id): array
    {
        $entry = $this->listing($tenantId)->where('acc_journal_entries.id', $id)->first();
        $partyName = "COALESCE(o.trade_name, o.legal_name, nullif(concat_ws(' ', pe.given_names, pe.family_name), ''))";
        $lines = DB::table('acc_journal_lines as l')
            ->leftJoin('acc_accounts as a', fn ($join) => $join->on('a.tenant_id', '=', 'l.tenant_id')->on('a.code', '=', 'l.account_code'))
            ->leftJoin('cost_centers as c', 'c.id', '=', 'l.cost_center_id')
            ->leftJoin('organizations as o', 'o.party_id', '=', 'l.party_id')
            ->leftJoin('persons as pe', 'pe.party_id', '=', 'l.party_id')
            ->where('l.entry_id', $id)->orderBy('l.position')
            ->get(['l.id', 'l.account_code', 'a.name as account_name', 'l.debit', 'l.credit', 'l.note', 'c.code as center_code', 'c.name as center_name', DB::raw("{$partyName} AS party_name")]);
        $related = fn (?string $relatedId) => $relatedId
            ? DB::table('acc_journal_entries')->where('id', $relatedId)->first(['id', 'entry_number'])
            : null;
        $reversal = $related($entry->reversed_by);
        $original = $related($entry->reversal_of);

        return [
            ...$this->row($entry),
            'reason' => $entry->reason,
            'created_by' => $entry->created_by ? DB::table('users')->where('id', $entry->created_by)->value('name') : null,
            'automatic' => $entry->created_by === null || ! in_array($entry->source_type, Ledger::BY_HAND, true),
            'reversed_by' => $reversal ? ['id' => $reversal->id, 'number' => $reversal->entry_number] : null,
            'reversal_of' => $original ? ['id' => $original->id, 'number' => $original->entry_number] : null,
            'lines' => $lines->map(fn ($line) => [
                'id' => $line->id, 'account_code' => $line->account_code, 'account_name' => $line->account_name ?? $line->account_code,
                'debit' => (float) $line->debit, 'credit' => (float) $line->credit, 'note' => $line->note,
                'cost_center' => $line->center_code ? "{$line->center_code} · {$line->center_name}" : null, 'party' => $line->party_name,
            ])->values(),
        ];
    }

    /** Periodos con asientos o abiertos, del más reciente al más antiguo (siempre incluye el actual). */
    private function periods(string $tenantId): array
    {
        return collect(DB::table('acc_periods')->where('tenant_id', $tenantId)->pluck('period'))
            ->merge(DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->distinct()->pluck('period'))
            ->push(now()->format('Y-m'))->map(fn ($period) => trim($period))->unique()->sortDesc()->values()->all();
    }
}
