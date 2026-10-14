<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Posting;
use App\Modules\Accounting\Support\Chart;
use App\Modules\Accounting\Support\Ledger;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentStore;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Purchasing\Support\Purchasing;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Support\StatementRows;
use App\Modules\Finance\Support\Treasury;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use App\Services\SpreadsheetImport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bancos y caja: cuentas y sus saldos, movimientos, transferencias, conciliación contra el
 * extracto del banco y caja chica con arqueo. El saldo de una cuenta es la suma de sus
 * movimientos; nada lo escribe a mano.
 */
class TreasuryController extends Controller
{
    /** Movimientos en libros sin conciliar de la cuenta (la apertura no se concilia). */
    private const PENDING = "(SELECT count(*) FROM bank_movements m WHERE m.account_id = financial_accounts.id AND NOT m.reconciled AND m.ref_type <> 'OPENING')";

    private const MAX_STATEMENT_LINES = 2000;

    /** Días de margen entre la fecha en libros y la del banco para emparejar solos. */
    private const MATCH_DAYS = 3;

    // ------------------------------------------------------------------ Cuentas

    public function accounts(Request $request)
    {
        $rate = (float) (ExchangeRate::current('USD')?->sell ?? 0);

        $accounts = $this->accountQuery($request)
            ->orderByRaw("CASE financial_accounts.account_type WHEN 'BANK' THEN 1 WHEN 'DETRACTION' THEN 2 ELSE 3 END")
            ->orderBy('financial_accounts.name')->limit(200)->get();

        return response()->json([
            'data' => $accounts->map(fn (FinancialAccount $account) => $this->accountRow($account, $rate))->values(),
            'custodians' => DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')
                ->where('tenant_users.tenant_id', $request->attributes->get('tenant_id'))->where('tenant_users.status', 'ACTIVE')
                ->orderBy('users.name')->limit(500)->get(['users.id', 'users.name']),
        ]);
    }

    /** Indicadores de la pantalla, sobre todas las cuentas activas. */
    public function summary(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $rate = (float) (ExchangeRate::current('USD')?->sell ?? 0);
        $accounts = $this->accountQuery($request)->where('financial_accounts.status', 'ACTIVE')->get();

        $pending = DB::table('bank_movements')->where('tenant_id', $tenantId)->where('reconciled', false)->where('ref_type', '<>', 'OPENING')
            ->selectRaw('count(*) AS total, coalesce(sum(abs(amount)), 0) AS amount')->first();
        $cash = $accounts->where('account_type', 'CASH');

        return response()->json([
            'total_pen' => round($accounts->sum(fn ($account) => $account->currency === 'USD' ? (float) $account->balance * $rate : (float) $account->balance), 2),
            // Hay saldo en dólares y ningún tipo de cambio registrado: ese saldo no entra en el total.
            'missing_rate' => $rate <= 0 && $accounts->contains(fn ($account) => $account->currency === 'USD' && abs((float) $account->balance) >= 0.01),
            'unreconciled' => (int) $pending->total,
            'unreconciled_amount' => round((float) $pending->amount, 2),
            'statements' => DB::table('bank_statements')->where('tenant_id', $tenantId)->count(),
            'cash_count' => $cash->count(),
            'cash_balance' => round((float) $cash->where('currency', 'PEN')->sum('balance'), 2),
            'cash_fund' => round((float) $cash->where('currency', 'PEN')->sum('fund_amount'), 2),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->validateAccount($request, null);

        $account = DB::transaction(function () use ($request, $tenantId, $data) {
            $account = FinancialAccount::create([
                'tenant_id' => $tenantId,
                'code' => SequentialNumberGenerator::next($tenantId, 'financial_accounts', 'code', 'CTA'),
                'account_type' => $data['account_type'],
                'currency' => $data['currency'],
                'status' => 'ACTIVE',
                'created_by' => $request->user()->id,
                ...$this->accountFields($data),
            ]);

            $opening = round((float) ($data['opening_balance'] ?? 0), 2);
            if ($opening > 0) {
                $movementId = Treasury::move($account, $opening, 'OPENING', 'Saldo de apertura', ['date' => $data['opening_date'] ?? now(), 'user_id' => $request->user()->id]);
                Posting::bankMovement($tenantId, $movementId, $request->user()->id);
            }
            $this->audit($request, 'treasury.account_create', 'FinancialAccount', $account->id, ['note' => $account->name, 'opening' => $opening]);

            return $account;
        });

        return response()->json($this->accountDetail($request, $account->id), 201);
    }

    public function updateAccount(string $bankAccount, Request $request)
    {
        $account = $this->findAccount($request, $bankAccount);
        $data = $this->validateAccount($request, $account);

        DB::transaction(function () use ($request, $account, $data) {
            $status = $data['status'] ?? $account->status;
            if ($status === 'INACTIVE' && $account->status === 'ACTIVE' && abs(Treasury::balance($account->id)) >= 0.01) {
                throw ValidationException::withMessages(['status' => ['No se desactiva una cuenta con saldo: transfiérelo antes.']]);
            }
            $account->update([...$this->accountFields($data), 'status' => $status]);
            $this->audit($request, 'treasury.account_update', 'FinancialAccount', $account->id, ['note' => $account->name]);
        });

        return response()->json($this->accountDetail($request, $account->id));
    }

    // ------------------------------------------------------------------ Movimientos

    public function movements(Request $request)
    {
        $account = $this->findAccount($request, (string) $request->query('account_id'));
        $search = trim((string) $request->query('search', ''));
        $reconciled = $request->query('reconciled');

        // El saldo corrido se calcula sobre toda la cuenta, antes de buscar o filtrar.
        $inner = DB::table('bank_movements')->where('account_id', $account->id)
            ->select('bank_movements.*')
            ->selectRaw('sum(amount) OVER (ORDER BY movement_date, created_at, id) AS running');
        $query = DB::query()->fromSub($inner, 'bank_movements')
            ->when(isset(Treasury::REF_TYPES[$request->query('ref_type')]), fn ($q) => $q->where('ref_type', $request->query('ref_type')))
            ->when(in_array($reconciled, ['true', 'false'], true), fn ($q) => $q->where('reconciled', $reconciled === 'true'))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('description', 'ILIKE', $like)->orWhere('op_number', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'date' => ColumnFilters::date('movement_date'),
            'description' => ColumnFilters::text("concat_ws(' ', description, op_number)"),
            'income' => ColumnFilters::number('GREATEST(amount, 0)'),
            'expense' => ColumnFilters::number('GREATEST(-amount, 0)'),
            'running' => ColumnFilters::number('running'),
        ]);

        ListSort::apply($query, $request, [
            'date' => 'movement_date',
            'description' => 'lower(description)',
            'origin' => ListSort::labels('ref_type', Treasury::REF_TYPES),
            'income' => 'GREATEST(amount, 0)',
            'expense' => 'GREATEST(-amount, 0)',
            'running' => 'running',
            'reconciled' => 'reconciled',
        ], fn ($q) => $q->orderByDesc('movement_date')->orderByDesc('created_at')->orderByDesc('id'), 'id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn ($movement) => $this->movementRow($movement));

        return response()->json($page);
    }

    /** Transferencia entre dos cuentas de la misma moneda: un egreso y un ingreso con la misma referencia. */
    public function transfer(Request $request)
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'uuid'],
            'to_account_id' => ['required', 'uuid', 'different:from_account_id'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'reference' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'from_account_id.required' => 'Selecciona la cuenta de origen.',
            'to_account_id.required' => 'Selecciona la cuenta de destino.',
            'to_account_id.different' => 'Selecciona cuentas de origen y destino distintas.',
            'amount.required' => 'Indica el monto.',
            'amount.gt' => 'El monto debe ser mayor a cero.',
            'date.before_or_equal' => 'La fecha no puede ser futura.',
        ]);

        $result = DB::transaction(fn () => $this->moveBetween(
            $request, $data['from_account_id'], $data['to_account_id'], round((float) $data['amount'], 2),
            trim((string) ($data['reference'] ?? '')) ?: 'TRF-'.random_int(100000, 999999), $data['date'] ?? null, 'treasury.transfer',
        ));

        return response()->json($result, 201);
    }

    /** Repone el fondo fijo de una caja desde una cuenta bancaria, por lo que le falta. */
    public function refill(string $bankAccount, Request $request)
    {
        $cash = $this->findAccount($request, $bankAccount);
        $data = $request->validate(['from_account_id' => ['required', 'uuid']], ['from_account_id.required' => 'Selecciona la cuenta de origen.']);
        if ($cash->account_type !== 'CASH' || ! $cash->fund_amount) {
            throw ValidationException::withMessages(['from_account_id' => ['Solo se repone una caja con fondo fijo.']]);
        }

        $result = DB::transaction(function () use ($request, $cash, $data) {
            $missing = round((float) $cash->fund_amount - Treasury::balance($cash->id), 2);
            if ($missing < 1) {
                throw ValidationException::withMessages(['from_account_id' => ['El fondo está completo.']]);
            }

            return $this->moveBetween($request, $data['from_account_id'], $cash->id, $missing, 'REPOSICION-'.now()->toDateString(), null, 'treasury.refill');
        });

        return response()->json($result, 201);
    }

    // ------------------------------------------------------------------ Conciliación

    /** Lo que muestra la pestaña Conciliación de una cuenta: resumen, último extracto y lo pendiente en libros. */
    public function reconciliation(Request $request)
    {
        $account = $this->findAccount($request, (string) $request->query('account_id'));

        $pending = DB::table('bank_movements')->where('account_id', $account->id)->where('reconciled', false)->where('ref_type', '<>', 'OPENING');
        $pendingRows = $pending->clone()->orderBy('movement_date')->orderBy('created_at')->limit(300)->get();
        // El extracto elegido o, si no se elige, el último importado.
        $statements = DB::table('bank_statements')->where('account_id', $account->id);
        $statement = Str::isUuid((string) $request->query('statement_id'))
            ? $statements->clone()->where('id', $request->query('statement_id'))->first()
            : $statements->clone()->orderByDesc('created_at')->first();
        $lines = $statement
            ? DB::table('bank_statement_lines as l')->leftJoin('bank_movements as m', 'm.id', '=', 'l.movement_id')
                ->where('l.statement_id', $statement->id)->orderBy('l.position')->limit(self::MAX_STATEMENT_LINES)
                ->get(['l.id', 'l.line_date', 'l.description', 'l.amount', 'l.op_number', 'l.status', 'l.rule', 'l.movement_id', 'm.description as movement_description'])
            : collect();
        $resolved = $lines->where('status', '<>', 'UNMATCHED')->count();

        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name, 'currency' => $account->currency, 'account_type' => $account->account_type],
            'book_balance' => Treasury::balance($account->id),
            'pending_count' => $pending->clone()->count(),
            'pending_amount' => round((float) $pending->clone()->sum('amount'), 2),
            'pending' => $pendingRows->map(fn ($movement) => $this->movementRow($movement))->values(),
            'statements_count' => $statements->clone()->count(),
            // Historial de extractos de la cuenta, del más reciente al más antiguo.
            'statements' => $statements->clone()->orderByDesc('created_at')->limit(200)->get(['id', 'file_name', 'lines_count', 'created_at'])
                ->map(fn ($row) => ['id' => $row->id, 'file_name' => $row->file_name, 'lines_count' => (int) $row->lines_count, 'imported_at' => Carbon::parse($row->created_at)->toIso8601String()])->values(),
            'statement' => $statement ? [
                'id' => $statement->id,
                'file_name' => $statement->file_name,
                'imported_at' => Carbon::parse($statement->created_at)->toIso8601String(),
                'imported_by' => $statement->imported_by ? DB::table('users')->where('id', $statement->imported_by)->value('name') : null,
                'has_file' => (bool) $statement->document_id,
                'duplicates_count' => (int) $statement->duplicates_count,
                'resolved_pct' => $lines->count() ? (int) round($resolved / $lines->count() * 100) : 0,
                'unmatched' => $lines->count() - $resolved,
                'lines' => $lines->map(fn ($line) => [
                    'id' => $line->id,
                    'date' => $line->line_date,
                    'description' => $line->description,
                    'amount' => (float) $line->amount,
                    'op_number' => $line->op_number,
                    'status' => $line->status,
                    'rule' => $line->rule,
                    'movement_description' => $line->movement_description,
                    // Movimientos en libros por el mismo importe con los que se puede emparejar a mano.
                    'candidates' => $line->status === 'UNMATCHED'
                        ? $pendingRows->filter(fn ($movement) => abs((float) $movement->amount - (float) $line->amount) < 0.01)
                            ->map(fn ($movement) => ['id' => $movement->id, 'date' => $movement->movement_date, 'description' => $movement->description])->values()
                        : [],
                ])->values(),
            ] : null,
        ]);
    }

    /** Plantilla de Excel del extracto: columnas, instrucciones y ejemplos, como las demás importaciones. */
    public function statementTemplate()
    {
        return SpreadsheetImport::template('plantilla_extracto_bancario.xlsx', ...self::statementTemplateParts());
    }

    /** Título, columnas, ejemplos, instrucciones y anchos de la plantilla del extracto. */
    public static function statementTemplateParts(): array
    {
        $day = fn (int $ago) => now()->subDays($ago)->format('d/m/Y');

        return [
            'Plantilla de extracto bancario',
            StatementRows::HEADERS,
            [
                [$day(3), 'ABONO TRANSFERENCIA RECIBIDA', '1180.00', '000123'],
                [$day(2), 'COMISION MANTENIMIENTO DE CUENTA', '-25.00', '000124'],
                [$day(1), 'ITF', '-0.05', ''],
            ],
            [
                'Una fila por cada línea del extracto de tu banco. Reemplaza las filas de ejemplo por las tuyas antes de importar.',
                'fecha y monto son obligatorios. fecha: día/mes/año (15/10/2026); no puede ser futura.',
                'monto: los abonos en positivo y los cargos en negativo (-25.00), sin símbolo de moneda.',
                'descripcion: el texto del banco. operacion: el número de operación, si el banco lo da.',
                'Cada línea se empareja con un movimiento en libros del mismo monto y fecha (±3 días); primero por operación.',
                'Las líneas que ya importaste en la misma cuenta se omiten: puedes subir un extracto que se solapa con el anterior.',
            ],
            [],
            [],
            // Cuatro columnas: sin estos anchos las instrucciones y la descripción del banco se cortarían.
            ['fecha' => 16, 'descripcion' => 62, 'monto' => 18, 'operacion' => 24],
        ];
    }

    /**
     * Importa el extracto del banco de una cuenta (Excel o CSV con fecha, descripción, monto y
     * operación) y empareja solo lo que coincide en importe exacto y fecha (±3 días), dando
     * prioridad al n.º de operación. Las líneas que ya se habían importado en esa cuenta se omiten.
     */
    public function importStatement(string $bankAccount, Request $request)
    {
        $account = $this->findAccount($request, $bankAccount);
        $request->validate(['file' => ['required', 'file', 'max:5120']], [
            'file.required' => 'Selecciona el archivo del extracto.',
            'file.max' => 'El archivo supera los 5 MB: impórtalo por partes.',
        ]);
        if ($account->account_type === 'CASH' || $account->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['file' => ['Los extractos son de una cuenta bancaria activa.']]);
        }

        $file = $request->file('file');
        [, $rows] = SpreadsheetImport::read($file, StatementRows::ALIASES, ['fecha', 'monto']);
        $parsed = StatementRows::parse($rows);
        if (! $parsed['lines']) {
            throw ValidationException::withMessages(['file' => ['El extracto no tiene líneas que se puedan leer.', ...$parsed['warnings']]]);
        }
        if (count($parsed['lines']) > self::MAX_STATEMENT_LINES) {
            throw ValidationException::withMessages(['file' => ['Un extracto admite como mucho '.self::MAX_STATEMENT_LINES.' líneas: impórtalo por partes.']]);
        }
        $fileName = Str::limit(trim((string) $file->getClientOriginalName()) ?: 'extracto', 255, '');
        $sha = hash_file('sha256', $file->getRealPath());
        // Si todas sus líneas ya se importaron, ni siquiera se guarda el archivo.
        $fingerprints = array_values(array_unique(array_column($parsed['lines'], 'fingerprint')));
        if (DB::table('bank_statement_lines')->where('account_id', $account->id)->whereIn('fingerprint', $fingerprints)->count() >= count($fingerprints)) {
            throw ValidationException::withMessages(['file' => ['Todas las líneas de este extracto ya se habían importado en esta cuenta.']]);
        }
        // El archivo se guarda como documento (tipos permitidos, huella, carpeta de la empresa), antes de la operación.
        $version = DocumentStore::store($account->tenant_id, $request->user()->id, $file, [
            'title' => Str::limit("Extracto {$account->name} · {$fileName}", 250, ''), 'category' => 'Extracto bancario', 'visibility' => 'INTERNAL',
        ]);

        $result = DB::transaction(function () use ($request, $account, $parsed, $fileName, $sha, $version) {
            // La cuenta se bloquea: dos importaciones a la vez no concilian el mismo movimiento.
            Treasury::lock($account->tenant_id, $account->id);

            $known = DB::table('bank_statement_lines')->where('account_id', $account->id)
                ->whereIn('fingerprint', array_column($parsed['lines'], 'fingerprint'))->pluck('fingerprint')->flip();
            $fresh = array_values(array_filter($parsed['lines'], fn (array $line) => ! isset($known[$line['fingerprint']])));
            $duplicates = count($parsed['lines']) - count($fresh);
            if (! $fresh) {
                throw ValidationException::withMessages(['file' => ['Todas las líneas de este extracto ya se habían importado en esta cuenta.']]);
            }

            $statementId = (string) Str::uuid();
            DB::table('bank_statements')->insert([
                'id' => $statementId, 'tenant_id' => $account->tenant_id, 'account_id' => $account->id,
                'file_name' => $fileName, 'file_sha256' => $sha, 'lines_count' => count($fresh), 'duplicates_count' => $duplicates,
                'imported_by' => $request->user()->id, 'created_at' => now(), 'document_id' => $version->document_id,
            ]);
            DocumentStore::link($version->document, 'BankStatement', $statementId);

            $pool = DB::table('bank_movements')->where('account_id', $account->id)->where('reconciled', false)->where('ref_type', '<>', 'OPENING')
                ->orderBy('movement_date')->orderBy('created_at')->lockForUpdate()->get()->keyBy('id');
            $matched = 0;
            foreach ($fresh as $position => $line) {
                $lineId = (string) Str::uuid();
                $date = Carbon::parse($line['date']);
                $candidates = $pool->filter(fn ($movement) => abs((float) $movement->amount - $line['amount']) < 0.01
                    && abs(Carbon::parse($movement->movement_date)->diffInDays($date, false)) <= self::MATCH_DAYS);
                $byNumber = $line['op_number'] !== '' ? $candidates->first(fn ($movement) => (string) $movement->op_number === $line['op_number']) : null;
                $best = $byNumber ?? $candidates->sortBy(fn ($movement) => abs(Carbon::parse($movement->movement_date)->diffInDays($date, false)))->first();

                DB::table('bank_statement_lines')->insert([
                    'id' => $lineId, 'tenant_id' => $account->tenant_id, 'statement_id' => $statementId, 'account_id' => $account->id,
                    'position' => $position + 1, 'line_date' => $line['date'], 'description' => Str::limit($line['description'], 500, ''),
                    'amount' => $line['amount'], 'op_number' => $line['op_number'] !== '' ? Str::limit($line['op_number'], 255, '') : null,
                    'fingerprint' => $line['fingerprint'], 'status' => 'UNMATCHED',
                ]);
                if ($best) {
                    $pool->forget($best->id);
                    $this->pair($request, $lineId, $best, $byNumber ? 'Monto + n.º de operación' : 'Monto + fecha (±'.self::MATCH_DAYS.' días)');
                    $matched++;
                }
            }

            $this->audit($request, 'treasury.statement_import', 'BankStatement', $statementId, [
                'note' => "{$account->name}: ".count($fresh).' líneas, '.$matched.' conciliadas', 'duplicates' => $duplicates,
            ]);

            return ['statement_id' => $statementId, 'lines' => count($fresh), 'matched' => $matched, 'unmatched' => count($fresh) - $matched, 'duplicates' => $duplicates];
        });

        $lines = $result['lines'];
        $warnings = $parsed['warnings'];
        if ($result['duplicates']) {
            array_unshift($warnings, $result['duplicates'] === 1 ? '1 línea ya importada antes se omitió.' : "{$result['duplicates']} líneas ya importadas antes se omitieron.");
        }

        return response()->json([
            ...$result,
            // Lo que muestra la ventana de importación.
            'imported' => $lines,
            'message' => ($lines === 1 ? '1 línea importada' : "{$lines} líneas importadas").': '
                .($result['matched'] === 1 ? '1 conciliada' : "{$result['matched']} conciliadas").' automáticamente y '.$result['unmatched'].' por revisar.',
            'warnings' => $warnings,
        ], 201);
    }

    /** Empareja a mano una línea del extracto con un movimiento en libros del mismo importe. */
    public function matchLine(string $line, Request $request)
    {
        $data = $request->validate(['movement_id' => ['required', 'uuid']], ['movement_id.required' => 'Selecciona el movimiento en libros.']);

        DB::transaction(function () use ($request, $line, $data) {
            $row = $this->lockLine($request, $line);
            $movement = DB::table('bank_movements')->where('id', $data['movement_id'])->where('account_id', $row->account_id)->lockForUpdate()->first();
            if (! $movement || $movement->reconciled || $movement->ref_type === 'OPENING') {
                throw ValidationException::withMessages(['movement_id' => ['Ese movimiento ya está conciliado o no es de esta cuenta.']]);
            }
            if (abs((float) $movement->amount - (float) $row->amount) > 0.01) {
                throw ValidationException::withMessages(['movement_id' => ['Los montos no coinciden.']]);
            }
            $this->pair($request, $row->id, $movement, 'Manual');
            $this->audit($request, 'treasury.line_match', 'BankStatement', $row->statement_id, ['note' => "{$row->description} ↔ {$movement->description}"]);
        });

        return response()->json(['matched' => true]);
    }

    /**
     * Resuelve una línea que no tiene movimiento en libros: una comisión o cargo del banco (FEE),
     * un abono que aún no se sabe de quién es (INCOME), o se deja fuera (IGNORE).
     */
    public function resolveLine(string $line, Request $request)
    {
        $kind = $request->validate(['kind' => ['required', 'in:FEE,INCOME,IGNORE']])['kind'];

        DB::transaction(function () use ($request, $line, $kind) {
            $row = $this->lockLine($request, $line);
            $amount = (float) $row->amount;
            if ($kind === 'FEE' && $amount >= 0) {
                throw ValidationException::withMessages(['kind' => ['Solo un cargo puede registrarse como comisión.']]);
            }
            if ($kind === 'INCOME' && $amount <= 0) {
                throw ValidationException::withMessages(['kind' => ['Solo un abono puede registrarse como abono por identificar.']]);
            }

            $resolved = ['resolved_by' => $request->user()->id, 'resolved_at' => now()];
            if ($kind === 'IGNORE') {
                DB::table('bank_statement_lines')->where('id', $row->id)->update(['status' => 'IGNORED', 'rule' => 'Ignorada', ...$resolved]);
            } else {
                $account = Treasury::lock($row->tenant_id, $row->account_id);
                $label = $kind === 'FEE' ? 'Comisión / gasto bancario' : 'Abono por identificar';
                $movementId = Treasury::move($account, $amount, $kind === 'FEE' ? 'BANK_FEE' : 'UNIDENTIFIED', "{$label} · {$row->description}", [
                    'date' => $row->line_date, 'op_number' => $row->op_number, 'reconciled' => true, 'statement_line_id' => $row->id, 'user_id' => $request->user()->id,
                ]);
                DB::table('bank_statement_lines')->where('id', $row->id)->update([
                    'status' => 'CREATED', 'movement_id' => $movementId, 'rule' => $kind === 'FEE' ? 'Comisión registrada' : 'Abono por identificar', ...$resolved,
                ]);
                Posting::bankMovement($row->tenant_id, $movementId, $request->user()->id);
            }
            $this->audit($request, 'treasury.line_'.strtolower($kind), 'BankStatement', $row->statement_id, ['note' => $row->description, 'amount' => $amount]);
        });

        return response()->json(['resolved' => true]);
    }

    // ------------------------------------------------------------------ Caja chica

    public function cashExpenses(Request $request)
    {
        $account = $this->findAccount($request, (string) $request->query('account_id'));
        $search = trim((string) $request->query('search', ''));

        $query = DB::table('cash_expenses')->where('account_id', $account->id)
            ->select('cash_expenses.*')
            ->selectRaw('(SELECT count(*) FROM cash_expense_reclassifications r WHERE r.cash_expense_id = cash_expenses.id) AS corrections')
            ->when($search !== '', function ($q) use ($search) {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w->where('description', 'ILIKE', $like)->orWhere('expense_number', 'ILIKE', $like)->orWhere('receipt', 'ILIKE', $like));
            });

        ListSort::apply($query, $request, [
            'number' => 'expense_number',
            'date' => 'expense_date',
            'description' => 'lower(description)',
            'cost_center' => 'lower(cost_center)',
            'account' => 'lower(expense_account)',
            'amount' => 'amount',
        ], fn ($q) => $q->orderByDesc('expense_date')->orderByDesc('created_at')->orderByDesc('id'), 'id');

        $page = $query->paginate(min(max($request->integer('per_page', 10), 1), 200));
        $page->getCollection()->transform(fn ($expense) => [
            'id' => $expense->id, 'expense_number' => $expense->expense_number, 'expense_date' => $expense->expense_date,
            'description' => $expense->description, 'receipt' => $expense->receipt, 'cost_center' => $expense->cost_center,
            // Las cuentas del plan se muestran con su nombre; los gastos antiguos, con su texto.
            'expense_account' => preg_match('/^\d{2,10}$/', (string) $expense->expense_account) ? Chart::label($expense->tenant_id, $expense->expense_account) : $expense->expense_account,
            'amount' => (float) $expense->amount,
            // Para corregir la clasificación: lo que tiene hoy, en códigos.
            'cost_center_id' => $expense->cost_center_id,
            'expense_account_code' => preg_match('/^\d{2,10}$/', (string) $expense->expense_account) ? $expense->expense_account : null,
            'corrections' => (int) $expense->corrections,
        ]);

        return response()->json($page);
    }

    /**
     * Corrige el centro de costo o la cuenta de gasto de un gasto de caja ya registrado: deja la
     * corrección registrada (antes, después y motivo), extorna su asiento y lo contabiliza de nuevo
     * con la clasificación correcta. El importe, la fecha y la caja no cambian.
     */
    public function reclassifyCashExpense(string $cashExpense, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        abort_unless(Str::isUuid($cashExpense), 404);
        $data = $request->validate([
            'cost_center_id' => ['required', 'uuid'],
            'expense_account' => ['required', 'string', 'max:20', Purchasing::expenseAccountRule($tenantId)],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'cost_center_id.required' => 'Selecciona el centro de costo.',
            'expense_account.required' => 'Selecciona la cuenta de gasto.',
            'reason.required' => 'Indica el motivo de la corrección.',
        ]);
        $center = DB::table('cost_centers')->where('tenant_id', $tenantId)->where('id', $data['cost_center_id'])->where('active', true)->first(['id', 'code', 'name']);
        if (! $center) {
            throw ValidationException::withMessages(['cost_center_id' => ['El centro de costo indicado no existe o está inactivo.']]);
        }

        $result = DB::transaction(function () use ($request, $tenantId, $cashExpense, $data, $center) {
            $expense = DB::table('cash_expenses')->where('tenant_id', $tenantId)->where('id', $cashExpense)->lockForUpdate()->first();
            abort_unless((bool) $expense, 404);
            $account = trim($data['expense_account']);
            if ($expense->cost_center_id === $center->id && $expense->expense_account === $account) {
                throw ValidationException::withMessages(['cost_center_id' => ['El gasto ya tiene ese centro de costo y esa cuenta.']]);
            }
            $reason = trim($data['reason']);

            // El asiento vigente se extorna en el periodo del gasto (o hoy, si está cerrado) y se rehace.
            $entry = Ledger::entryFor($tenantId, 'CASH_EXPENSE', $expense->id);
            $date = Ledger::openDate($tenantId, Carbon::parse($expense->expense_date)->toDateString());
            $reversed = $entry ? Ledger::reverse($tenantId, $entry->id, "Corrección de {$expense->expense_number}: {$reason}", $request->user()->id, $date) : null;

            // Primero la corrección: la base solo deja cambiar la clasificación si está registrada.
            $correctionId = (string) Str::uuid();
            DB::table('cash_expense_reclassifications')->insert([
                'id' => $correctionId, 'tenant_id' => $tenantId, 'cash_expense_id' => $expense->id,
                'old_cost_center_id' => $expense->cost_center_id, 'new_cost_center_id' => $center->id,
                'old_account' => $expense->expense_account, 'new_account' => $account,
                'reason' => $reason, 'reversed_entry_id' => $entry?->id, 'created_by' => $request->user()->id,
            ]);
            DB::table('cash_expenses')->where('id', $expense->id)->update([
                'cost_center_id' => $center->id, 'cost_center' => Str::limit("{$center->code} · {$center->name}", 100, ''), 'expense_account' => $account,
            ]);
            $new = Posting::cashExpense($tenantId, $expense->id, $request->user()->id);
            DB::table('cash_expense_reclassifications')->where('id', $correctionId)->update(['new_entry_id' => $new]);

            $number = fn (?string $id) => $id ? DB::table('acc_journal_entries')->where('id', $id)->value('entry_number') : null;
            $this->audit($request, 'treasury.cash_reclassify', 'CashExpense', $expense->id, [
                'before' => ['cost_center' => $expense->cost_center, 'expense_account' => $expense->expense_account],
                'after' => ['cost_center' => "{$center->code} · {$center->name}", 'expense_account' => $account],
                'note' => trim("{$expense->expense_number}: {$reason}".($entry ? " · {$entry->entry_number} extornado por {$number($reversed)}, nuevo {$number($new)}" : '')),
            ]);

            return ['id' => $expense->id, 'expense_number' => $expense->expense_number, 'reversed' => $number($reversed), 'entry' => $number($new)];
        });

        return response()->json($result);
    }

    /** Listas del gasto de caja chica: centros de costo activos y cuentas de gasto del plan. */
    public function cashExpenseOptions(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'cost_centers' => Purchasing::costCenters($tenantId),
            'expense_accounts' => Purchasing::expenseAccounts($tenantId),
        ]);
    }

    /** Enlace temporal para descargar el archivo del extracto tal como se importó. */
    public function statementFile(string $statement, Request $request)
    {
        abort_unless(Str::isUuid($statement), 404);
        $row = DB::table('bank_statements')->where('tenant_id', $request->attributes->get('tenant_id'))->where('id', $statement)->first(['id', 'document_id']);
        abort_unless((bool) $row, 404);
        $document = $row->document_id ? Document::find($row->document_id) : null;
        $url = $document ? DocumentStore::downloadUrl($document) : null;
        if (! $url) {
            throw ValidationException::withMessages(['file' => ['Este extracto se importó antes de que se guardaran los archivos.']]);
        }

        return response()->json(['url' => $url]);
    }

    /** Gasto de caja chica: sale de la caja, con tope por comprobante y sin dejarla en negativo. */
    public function storeCashExpense(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'description' => ['required', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expense_date' => ['nullable', 'date', 'before_or_equal:today'],
            'receipt' => ['nullable', 'string', 'max:150'],
            'cost_center_id' => ['required', 'uuid'],
            'expense_account' => ['required', 'string', 'max:20', Purchasing::expenseAccountRule($request->attributes->get('tenant_id'))],
        ], [
            'account_id.required' => 'Selecciona la caja.',
            'cost_center_id.required' => 'Selecciona el centro de costo.',
            'expense_account.required' => 'Selecciona la cuenta de gasto.',
            'description.required' => 'Indica el concepto.',
            'amount.required' => 'Indica el monto.',
            'amount.gt' => 'El monto debe ser mayor a cero.',
            'expense_date.before_or_equal' => 'La fecha no puede ser futura.',
        ]);

        $center = DB::table('cost_centers')->where('tenant_id', $request->attributes->get('tenant_id'))->where('id', $data['cost_center_id'])->where('active', true)->first(['id', 'code', 'name']);
        if (! $center) {
            throw ValidationException::withMessages(['cost_center_id' => ['El centro de costo indicado no existe o está inactivo.']]);
        }

        $expense = DB::transaction(function () use ($request, $data, $center) {
            $cash = $this->lockCash($request, $data['account_id']);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $cash->expenseLimit() + 0.001) {
                throw ValidationException::withMessages(['amount' => [
                    'Los gastos de caja chica tienen un tope de '.number_format($cash->expenseLimit(), 2).' por comprobante.',
                ]]);
            }
            if (Treasury::balance($cash->id) < $amount - 0.001) {
                throw ValidationException::withMessages(['amount' => ['Saldo de caja insuficiente. Solicita la reposición del fondo.']]);
            }

            $id = (string) Str::uuid();
            $number = SequentialNumberGenerator::next($cash->tenant_id, 'cash_expenses', 'expense_number', 'CCH');
            $date = Carbon::parse($data['expense_date'] ?? now())->toDateString();
            DB::table('cash_expenses')->insert([
                'id' => $id, 'tenant_id' => $cash->tenant_id, 'account_id' => $cash->id, 'expense_number' => $number, 'expense_date' => $date,
                'description' => trim($data['description']), 'amount' => $amount,
                'receipt' => $this->blankToNull($data['receipt'] ?? null),
                // El código y nombre quedan escritos como se veían al registrar; el centro, enlazado.
                'cost_center_id' => $center->id, 'cost_center' => Str::limit("{$center->code} · {$center->name}", 100, ''),
                'expense_account' => $data['expense_account'],
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
            Treasury::move($cash, -1 * $amount, 'CASH_EXPENSE', 'Caja chica · '.trim($data['description']), ['date' => $date, 'ref_id' => $id, 'user_id' => $request->user()->id]);
            Posting::cashExpense($cash->tenant_id, $id, $request->user()->id);
            $this->audit($request, 'treasury.cash_expense', 'CashExpense', $id, ['note' => "{$number}: ".trim($data['description']), 'amount' => $amount]);

            return ['id' => $id, 'expense_number' => $number, 'amount' => $amount];
        });

        return response()->json($expense, 201);
    }

    public function cashCounts(Request $request)
    {
        $account = $this->findAccount($request, (string) $request->query('account_id'));

        return response()->json(
            DB::table('cash_counts')->leftJoin('users', 'users.id', '=', 'cash_counts.created_by')->where('cash_counts.account_id', $account->id)
                ->orderByDesc('cash_counts.created_at')->limit(50)
                ->get(['cash_counts.id', 'cash_counts.book_balance', 'cash_counts.counted', 'cash_counts.difference', 'cash_counts.note', 'cash_counts.created_at', 'users.name as by'])
                ->map(fn ($count) => [
                    'id' => $count->id, 'book' => (float) $count->book_balance, 'counted' => (float) $count->counted,
                    'difference' => (float) $count->difference, 'note' => $count->note, 'by' => $count->by,
                    'at' => Carbon::parse($count->created_at)->toIso8601String(),
                ])->values()
        );
    }

    /** Arqueo: lo contado contra el saldo en libros. No mueve el saldo; deja constancia de la diferencia. */
    public function storeCashCount(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['required', 'uuid'],
            'counted' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'account_id.required' => 'Selecciona la caja.',
            'counted.required' => 'Indica el monto contado.',
            'counted.min' => 'El monto contado no puede ser negativo.',
        ]);

        $count = DB::transaction(function () use ($request, $data) {
            $cash = $this->lockCash($request, $data['account_id']);
            $book = Treasury::balance($cash->id);
            $counted = round((float) $data['counted'], 2);
            $id = (string) Str::uuid();
            DB::table('cash_counts')->insert([
                'id' => $id, 'tenant_id' => $cash->tenant_id, 'account_id' => $cash->id, 'book_balance' => $book, 'counted' => $counted,
                'difference' => round($counted - $book, 2), 'note' => $this->blankToNull($data['note'] ?? null),
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);
            $difference = round($counted - $book, 2);
            $this->audit($request, 'treasury.cash_count', 'CashCount', $id, [
                'note' => "{$cash->name}: contado ".number_format($counted, 2).', en libros '.number_format($book, 2), 'difference' => $difference,
            ]);

            return ['id' => $id, 'book' => $book, 'counted' => $counted, 'difference' => $difference];
        });

        return response()->json($count, 201);
    }

    // ------------------------------------------------------------------ Apoyo

    private function accountQuery(Request $request)
    {
        return FinancialAccount::query()->with('custodian:id,name')
            ->where('financial_accounts.tenant_id', $request->attributes->get('tenant_id'))
            ->whereIn('financial_accounts.account_type', array_keys(FinancialAccount::KINDS))
            ->select('financial_accounts.*')
            ->selectRaw(Treasury::BALANCE.' AS balance')
            ->selectRaw(self::PENDING.' AS pending_count');
    }

    private function findAccount(Request $request, string $id): FinancialAccount
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->accountQuery($request)->findOrFail($id);
    }

    private function accountDetail(Request $request, string $id): array
    {
        return $this->accountRow($this->findAccount($request, $id), (float) (ExchangeRate::current('USD')?->sell ?? 0));
    }

    /** Caja activa de esta empresa, bloqueada hasta terminar la operación. */
    private function lockCash(Request $request, string $accountId): FinancialAccount
    {
        $cash = Treasury::lock($request->attributes->get('tenant_id'), $accountId);
        if (! $cash || $cash->account_type !== 'CASH' || $cash->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['account_id' => ['Selecciona una caja activa.']]);
        }

        return $cash;
    }

    private function lockLine(Request $request, string $line): object
    {
        abort_unless(Str::isUuid($line), 404);
        $row = DB::table('bank_statement_lines')->where('tenant_id', $request->attributes->get('tenant_id'))->where('id', $line)->lockForUpdate()->first();
        abort_unless((bool) $row, 404);
        if ($row->status !== 'UNMATCHED') {
            throw ValidationException::withMessages(['status' => ['La línea ya fue resuelta.']]);
        }

        return $row;
    }

    /** Concilia una línea con su movimiento; si el movimiento es de un cobro, el cobro queda conciliado. */
    private function pair(Request $request, string $lineId, object $movement, string $rule): void
    {
        DB::table('bank_statement_lines')->where('id', $lineId)->update([
            'status' => 'MATCHED', 'movement_id' => $movement->id, 'rule' => $rule, 'resolved_by' => $request->user()->id, 'resolved_at' => now(),
        ]);
        DB::table('bank_movements')->where('id', $movement->id)->update(['reconciled' => true, 'reconciled_at' => now(), 'statement_line_id' => $lineId]);

        if ($movement->ref_type === 'PAYMENT' && $movement->ref_id) {
            $payment = Payment::ignoringAccessScope()->whereKey($movement->ref_id)->where('reconciled', false)->first();
            if ($payment) {
                $payment->update(['reconciled' => true, 'reconciled_at' => now(), 'reconciled_by' => $request->user()->id]);
                $this->audit($request, 'payment.reconcile', 'Payment', $payment->id, ['note' => 'Por el extracto bancario']);
            }
        }
    }

    /**
     * Saca de una cuenta y pone en otra, de la misma moneda y con saldo suficiente. Las dos se
     * bloquean siempre en el mismo orden. Debe llamarse dentro de una transacción.
     */
    private function moveBetween(Request $request, string $fromId, string $toId, float $amount, string $reference, ?string $date, string $action): array
    {
        $tenantId = $request->attributes->get('tenant_id');
        $locked = [];
        foreach (collect([$fromId, $toId])->sort()->values() as $id) {
            $locked[$id] = Treasury::lock($tenantId, $id);
        }
        [$from, $to] = [$locked[$fromId] ?? null, $locked[$toId] ?? null];

        if (! $from || $from->status !== 'ACTIVE' || $from->account_type === 'DETRACTION') {
            throw ValidationException::withMessages(['from_account_id' => ['Selecciona la cuenta de origen.']]);
        }
        if (! $to || $to->status !== 'ACTIVE' || $to->account_type === 'DETRACTION') {
            throw ValidationException::withMessages(['to_account_id' => ['Selecciona la cuenta de destino.']]);
        }
        if ($from->currency !== $to->currency) {
            throw ValidationException::withMessages(['to_account_id' => ['Las dos cuentas deben estar en la misma moneda.']]);
        }
        if (Treasury::balance($from->id) < $amount - 0.001) {
            throw ValidationException::withMessages(['amount' => ["Saldo insuficiente en {$from->name}."]]);
        }

        $transferId = (string) Str::uuid();
        // El registro de la transferencia: la base comprueba moneda, cuentas activas y saldo de origen.
        DB::table('bank_transfers')->insert([
            'id' => $transferId, 'tenant_id' => $tenantId, 'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => $amount,
            'currency' => $from->currency, 'reference' => $reference, 'transfer_date' => Carbon::parse($date ?? now())->toDateString(),
            'kind' => $action === 'treasury.refill' ? 'REFILL' : 'TRANSFER', 'created_by' => $request->user()->id,
        ]);
        $common = ['date' => $date ?? now(), 'ref_id' => $transferId, 'op_number' => $reference, 'user_id' => $request->user()->id];
        Treasury::move($from, -1 * $amount, 'TRANSFER', "Transferencia a {$to->name}", $common);
        Treasury::move($to, $amount, 'TRANSFER', "Transferencia desde {$from->name}", $common);
        Posting::transfer($tenantId, $transferId, $request->user()->id);
        $this->audit($request, $action, 'FinancialAccount', $from->id, [
            'note' => "{$from->name} → {$to->name} · {$reference}", 'amount' => $amount,
        ]);

        return ['reference' => $reference, 'amount' => $amount, 'from' => $from->name, 'to' => $to->name];
    }

    private function validateAccount(Request $request, ?FinancialAccount $account): array
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_type' => [$account ? 'prohibited' : 'required', 'in:BANK,CASH,DETRACTION'],
            'currency' => [$account ? 'prohibited' : 'required', 'in:PEN,USD'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'cci' => ['nullable', 'string', 'max:40'],
            'ledger_account' => ['nullable', 'string', 'max:30'],
            'fund_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'expense_limit' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'custodian_user_id' => ['nullable', 'uuid'],
            'opening_balance' => [$account ? 'prohibited' : 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'opening_date' => [$account ? 'prohibited' : 'nullable', 'date', 'before_or_equal:today'],
            'status' => [$account ? 'sometimes' : 'prohibited', 'in:ACTIVE,INACTIVE'],
        ], [
            'name.required' => 'Indica el nombre de la cuenta.',
            'account_type.required' => 'Selecciona el tipo de cuenta.',
            'account_type.prohibited' => 'El tipo de una cuenta no se modifica.',
            'currency.prohibited' => 'La moneda de una cuenta no se modifica.',
            'opening_balance.prohibited' => 'El saldo de apertura se indica al crear la cuenta.',
            'opening_date.before_or_equal' => 'La fecha no puede ser futura.',
        ]);

        $kind = $account?->account_type ?? $data['account_type'];
        $currency = $account?->currency ?? $data['currency'];
        if ($kind === 'DETRACTION' && $currency !== 'PEN') {
            throw ValidationException::withMessages(['currency' => ['La cuenta de detracciones del Banco de la Nación es en soles.']]);
        }
        if ($kind !== 'CASH' && trim((string) ($data['account_number'] ?? '')) === '') {
            throw ValidationException::withMessages(['account_number' => ['Indica el número de la cuenta.']]);
        }
        // Una sola cuenta de detracciones: a ella llega lo que depositan los clientes.
        if ($kind === 'DETRACTION' && ($data['status'] ?? 'ACTIVE') === 'ACTIVE'
            && FinancialAccount::where('tenant_id', $tenantId)->where('account_type', 'DETRACTION')->where('status', 'ACTIVE')
                ->when($account, fn ($q) => $q->whereKeyNot($account->id))->exists()) {
            throw ValidationException::withMessages(['account_type' => ['La empresa ya tiene una cuenta de detracciones activa.']]);
        }
        if (! empty($data['custodian_user_id'])
            && ! DB::table('tenant_users')->where('tenant_id', $tenantId)->where('user_id', $data['custodian_user_id'])->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['custodian_user_id' => ['El custodio debe ser una persona con acceso vigente a la empresa.']]);
        }

        return $data + ['account_type' => $kind, 'currency' => $currency];
    }

    /** Campos editables de una cuenta; los de la caja solo valen en una caja. */
    private function accountFields(array $data): array
    {
        $cash = $data['account_type'] === 'CASH';

        return [
            'name' => trim($data['name']),
            'bank_name' => $cash ? null : $this->blankToNull($data['bank_name'] ?? null),
            'account_number' => $this->blankToNull($data['account_number'] ?? null),
            'cci' => $cash ? null : $this->blankToNull($data['cci'] ?? null),
            'ledger_account' => $this->blankToNull($data['ledger_account'] ?? null),
            'fund_amount' => $cash && isset($data['fund_amount']) && $data['fund_amount'] !== '' ? round((float) $data['fund_amount'], 2) : null,
            'expense_limit' => $cash && ! empty($data['expense_limit']) ? round((float) $data['expense_limit'], 2) : null,
            'custodian_user_id' => $cash ? ($data['custodian_user_id'] ?? null) : null,
        ];
    }

    private function accountRow(FinancialAccount $account, float $rate): array
    {
        $balance = round((float) $account->balance, 2);

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'currency' => $account->currency,
            'status' => $account->status,
            'bank_name' => $account->bank_name,
            'account_number' => $account->account_number,
            'cci' => $account->cci,
            'ledger_account' => $account->ledger_account,
            'fund_amount' => $account->fund_amount !== null ? (float) $account->fund_amount : null,
            'expense_limit' => $account->expenseLimit(),
            'own_expense_limit' => $account->expense_limit !== null ? (float) $account->expense_limit : null,
            'custodian' => $account->custodian ? ['id' => $account->custodian->id, 'name' => $account->custodian->name] : null,
            'balance' => $balance,
            // En soles al tipo de cambio de venta vigente; null si la cuenta es en dólares y no hay tipo de cambio.
            'balance_pen' => $account->currency === 'USD' ? ($rate > 0 ? round($balance * $rate, 2) : null) : $balance,
            'pending_count' => (int) $account->pending_count,
            'lock_version' => (int) $account->lock_version,
        ];
    }

    private function movementRow(object $movement): array
    {
        return [
            'id' => $movement->id,
            'date' => $movement->movement_date,
            'description' => $movement->description,
            'op_number' => $movement->op_number,
            'ref_type' => $movement->ref_type,
            'amount' => (float) $movement->amount,
            'running' => isset($movement->running) ? round((float) $movement->running, 2) : null,
            'reconciled' => (bool) $movement->reconciled,
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function audit(Request $request, string $action, string $entityType, string $entityId, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'), userId: $request->user()->id, action: $action,
            entityType: $entityType, entityId: $entityId, metadata: $metadata,
        );
    }
}
