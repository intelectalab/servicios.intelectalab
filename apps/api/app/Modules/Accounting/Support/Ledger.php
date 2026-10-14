<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Finance\Models\FinancialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Libro diario: escribe asientos y extornos. Todo asiento pasa por aquí.
 *
 * Un asiento cuadra, usa cuentas imputables del plan y cae en un periodo abierto; si la fecha del
 * documento es de un periodo cerrado, se contabiliza en la fecha de hoy. Un documento genera un
 * único asiento vigente: si ya lo tiene, no se escribe otro. Debe llamarse dentro de la misma
 * transacción que registra el documento, para que el documento y su asiento existan juntos.
 */
class Ledger
{
    /** Origen => texto visible (los del prototipo). */
    public const SOURCES = [
        'INVOICE' => 'Venta', 'PAYMENT' => 'Cobro', 'WITHHOLDING' => 'Detracción / retención', 'FX' => 'Dif. de cambio',
        'REFUND' => 'Reembolso', 'SUPPLIER_INVOICE' => 'Compra', 'SUPPLIER_PAYMENT' => 'Pago a proveedor', 'CASH_EXPENSE' => 'Caja chica',
        'BANK_FEE' => 'Extracto', 'UNIDENTIFIED' => 'Extracto', 'TRANSFER' => 'Transferencia', 'OPENING' => 'Apertura',
        'MANUAL' => 'Manual', 'REVERSAL' => 'Extorno', 'IMPORT' => 'Importado', 'YEAR_CLOSE' => 'Cierre de ejercicio',
    ];

    /** Orígenes que se eligen en el filtro del libro diario (dos tipos comparten "Extracto"). */
    public const SOURCE_FILTERS = [
        'INVOICE' => 'Venta', 'PAYMENT' => 'Cobro', 'SUPPLIER_INVOICE' => 'Compra', 'SUPPLIER_PAYMENT' => 'Pago a proveedor',
        'CASH_EXPENSE' => 'Caja chica', 'BANK' => 'Extracto', 'TRANSFER' => 'Transferencia', 'OPENING' => 'Apertura',
        'MANUAL' => 'Manual', 'IMPORT' => 'Importado', 'REVERSAL' => 'Extorno', 'FX' => 'Dif. de cambio', 'REFUND' => 'Reembolso',
        'WITHHOLDING' => 'Detracción / retención', 'YEAR_CLOSE' => 'Cierre de ejercicio',
    ];

    /** Orígenes que registra una persona (no un documento): no cuentan como automáticos. */
    public const BY_HAND = ['MANUAL', 'IMPORT', 'REVERSAL'];

    /**
     * Escribe un asiento. Devuelve su id, o null si el documento ya tenía su asiento o no hay nada
     * que contabilizar (todas las líneas en cero).
     *
     * @param  array<int, array{account: string, debit?: float, credit?: float, cost_center_id?: ?string, party_id?: ?string, note?: ?string}>  $lines
     * @param  array{type: string, id?: ?string, number?: ?string}  $source
     */
    public static function post(string $tenantId, string $date, string $description, array $source, array $lines, ?string $userId): ?string
    {
        Chart::ensure($tenantId);
        $sourceId = $source['id'] ?? null;
        if ($sourceId && self::posted($tenantId, $source['type'], $sourceId)) {
            return null;
        }

        $clean = self::clean($lines);
        if (count($clean) < 2) {
            return null;
        }
        $debit = round(array_sum(array_column($clean, 'debit')), 2);
        $credit = round(array_sum(array_column($clean, 'credit')), 2);
        // Céntimos de redondeo (conversiones de moneda): se ajustan en la última línea, como el prototipo.
        $gap = round($debit - $credit, 2);
        if (abs($gap) > 0 && abs($gap) < 0.05) {
            $last = array_key_last($clean);
            $gap > 0 ? $clean[$last]['credit'] = round($clean[$last]['credit'] + $gap, 2) : $clean[$last]['debit'] = round($clean[$last]['debit'] - $gap, 2);
            if ($clean[$last]['debit'] > 0 && $clean[$last]['credit'] > 0) {
                $net = round($clean[$last]['debit'] - $clean[$last]['credit'], 2);
                $clean[$last]['debit'] = max($net, 0);
                $clean[$last]['credit'] = max(-$net, 0);
            }
        } elseif (abs($gap) >= 0.05) {
            throw ValidationException::withMessages(['lines' => ['El asiento no cuadra: el debe debe ser igual al haber.']]);
        }

        foreach ($clean as $line) {
            if (! Chart::postable($tenantId, $line['account'])) {
                throw ValidationException::withMessages(['lines' => ["La cuenta {$line['account']} no es imputable o está inactiva en el plan de cuentas."]]);
            }
        }

        $date = self::openDate($tenantId, Carbon::parse($date)->toDateString());
        $period = substr($date, 0, 7);
        $id = (string) Str::uuid();
        DB::table('acc_journal_entries')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'entry_number' => self::nextNumber($tenantId, $period),
            'entry_date' => $date,
            'period' => $period,
            'description' => Str::limit(trim($description), 1000, ''),
            'source_type' => $source['type'],
            'source_id' => $sourceId,
            'source_number' => isset($source['number']) ? Str::limit((string) $source['number'], 100, '') : null,
            'reversal_of' => $source['reversal_of'] ?? null,
            'status' => 'POSTED',
            'created_by' => $userId,
            // created_at lo pone la base: es la hora de esta operación, y sus líneas se comprueban contra ella.
        ]);
        foreach ($clean as $index => $line) {
            DB::table('acc_journal_lines')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'entry_id' => $id,
                'position' => $index + 1,
                'account_code' => $line['account'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'party_id' => $line['party_id'] ?? null,
                'note' => isset($line['note']) && $line['note'] !== '' ? Str::limit($line['note'], 500, '') : null,
            ]);
        }

        return $id;
    }

    /**
     * Extorna un asiento: escribe el inverso hoy (o en la fecha indicada, si su periodo está abierto)
     * y deja el original como extornado. Devuelve el id del extorno.
     */
    public static function reverse(string $tenantId, string $entryId, string $reason, ?string $userId, ?string $date = null): string
    {
        $entry = DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->where('id', $entryId)->lockForUpdate()->first();
        if (! $entry) {
            throw ValidationException::withMessages(['entry' => ['El asiento no existe.']]);
        }
        if ($entry->status !== 'POSTED') {
            throw ValidationException::withMessages(['status' => ['El asiento ya fue extornado.']]);
        }
        if ($entry->source_type === 'REVERSAL') {
            throw ValidationException::withMessages(['status' => ['Un extorno no se extorna: registra un asiento manual si hace falta.']]);
        }

        $lines = DB::table('acc_journal_lines')->where('entry_id', $entry->id)->orderBy('position')->get()
            ->map(fn ($line) => [
                'account' => $line->account_code, 'debit' => (float) $line->credit, 'credit' => (float) $line->debit,
                'cost_center_id' => $line->cost_center_id, 'party_id' => $line->party_id, 'note' => $line->note,
            ])->all();

        $reversal = self::post($tenantId, $date ?? now()->toDateString(), "Extorno de {$entry->entry_number} · {$reason}",
            ['type' => 'REVERSAL', 'id' => $entry->id, 'number' => $entry->entry_number, 'reversal_of' => $entry->id], $lines, $userId);

        DB::table('acc_journal_entries')->where('id', $entry->id)->update(['status' => 'REVERSED', 'reversed_by' => $reversal, 'reason' => $reason]);

        return $reversal;
    }

    /**
     * Deja fuera de una consulta de líneas el asiento de cierre del ejercicio y sus extornos: no son
     * ingresos ni gastos del año, solo los trasladan a resultados acumulados (y de vuelta al reabrir).
     *
     * @param  \Illuminate\Database\Query\Builder  $query  con el asiento unido como `$alias`
     */
    public static function withoutYearClose($query, string $alias = 'e')
    {
        return $query->where("{$alias}.source_type", '<>', 'YEAR_CLOSE')
            ->where(fn ($q) => $q->where("{$alias}.source_type", '<>', 'REVERSAL')->orWhereNull("{$alias}.reversal_of")
                ->orWhereNotExists(fn ($closing) => $closing->selectRaw('1')->from('acc_journal_entries as yc')
                    ->whereColumn('yc.id', "{$alias}.reversal_of")->where('yc.source_type', 'YEAR_CLOSE')));
    }

    /** Asiento vigente de un documento, si lo tiene. */
    public static function entryFor(string $tenantId, string $sourceType, string $sourceId): ?object
    {
        return DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->where('source_type', $sourceType)
            ->where('source_id', $sourceId)->where('status', 'POSTED')->first(['id', 'entry_number', 'entry_date', 'status']);
    }

    public static function posted(string $tenantId, string $sourceType, string $sourceId): bool
    {
        return DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->where('source_type', $sourceType)
            ->where('source_id', $sourceId)->where('status', 'POSTED')->exists();
    }

    /** La fecha del documento si su periodo está abierto; si está cerrado, hoy. */
    public static function openDate(string $tenantId, string $date): string
    {
        $period = substr($date, 0, 7);
        if (self::isClosed($tenantId, $period)) {
            $date = now()->toDateString();
            if (self::isClosed($tenantId, substr($date, 0, 7))) {
                throw ValidationException::withMessages(['date' => ['El periodo '.substr($date, 0, 7).' está cerrado: reábrelo para contabilizar.']]);
            }
        }
        self::openPeriod($tenantId, substr($date, 0, 7));

        return $date;
    }

    public static function isClosed(string $tenantId, string $period): bool
    {
        return DB::table('acc_periods')->where('tenant_id', $tenantId)->where('period', $period)->where('status', 'CLOSED')->exists();
    }

    /** Da de alta el periodo (abierto) si aún no existe. */
    public static function openPeriod(string $tenantId, string $period): void
    {
        DB::table('acc_periods')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'period' => $period, 'status' => 'OPEN']);
    }

    /** Cuenta contable de una cuenta de banco o caja: la que tenga indicada o la que le toca por su tipo y moneda. */
    public static function cashAccount(?FinancialAccount $account, string $fallbackType = 'BANK', string $currency = 'PEN'): string
    {
        $tenantId = $account?->tenant_id;
        if ($account && $account->ledger_account && Chart::postable($tenantId, $account->ledger_account, ['ASSET'])) {
            return $account->ledger_account;
        }
        $type = $account?->account_type ?? $fallbackType;
        $currency = $account?->currency ?? $currency;

        return match (true) {
            $type === 'CASH' => '1011',
            $type === 'DETRACTION' => '1042',
            $currency === 'USD' => '10412',
            default => '1041',
        };
    }

    /** Centro de ingresos de una sede: al que van las ventas de esa sede, si la empresa lo definió. */
    public static function revenueCenter(string $tenantId, ?string $branchId): ?string
    {
        if (! $branchId) {
            return null;
        }

        return DB::table('cost_centers')->where('tenant_id', $tenantId)->where('kind', 'REVENUE')->where('active', true)
            ->where('branch_id', $branchId)->orderBy('code')->value('id');
    }

    /** Correlativo por periodo: AS-2026-10-0001. */
    private static function nextNumber(string $tenantId, string $period): string
    {
        DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["{$tenantId}:acc_journal_entries:{$period}"]);
        $last = DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->where('period', $period)
            ->where('entry_number', 'like', "AS-{$period}-%")->orderByDesc('entry_number')->value('entry_number');
        $next = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        return sprintf('AS-%s-%04d', $period, $next);
    }

    /** Quita las líneas en cero, redondea a céntimos y deja cada línea solo en el debe o solo en el haber. */
    private static function clean(array $lines): array
    {
        $clean = [];
        foreach ($lines as $line) {
            $net = round((float) ($line['debit'] ?? 0) - (float) ($line['credit'] ?? 0), 2);
            if (abs($net) < 0.005 || empty($line['account'])) {
                continue;
            }
            $clean[] = [
                'account' => (string) $line['account'], 'debit' => max($net, 0), 'credit' => max(-$net, 0),
                'cost_center_id' => $line['cost_center_id'] ?? null, 'party_id' => $line['party_id'] ?? null, 'note' => $line['note'] ?? null,
            ];
        }

        return $clean;
    }
}
