<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Chart;
use App\Modules\Accounting\Support\Ledger;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cierre del ejercicio: lleva al 31 de diciembre los ingresos y gastos del año a resultados
 * acumulados (utilidad a 5911, pérdida a 5921), cierra diciembre y da por cerrado el presupuesto
 * del año. Solo se cierra un año terminado, después del anterior y con enero a noviembre cerrados.
 * Reabrirlo (con motivo) extorna el asiento de cierre el mismo 31 de diciembre y reabre diciembre.
 */
class FiscalYearController extends Controller
{
    public const PROFIT_ACCOUNT = '5911';

    public const LOSS_ACCOUNT = '5921';

    /** Ejercicios con movimientos o periodos, del más reciente al más antiguo. */
    public function index(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json(collect($this->years($tenantId))->map(fn (int $year) => $this->row($tenantId, $year))->values());
    }

    public function close(string $year, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $year = $this->validYear($year);
        $blocker = $this->closeBlocker($tenantId, $year);
        if ($blocker) {
            throw ValidationException::withMessages(['status' => [$blocker]]);
        }
        $december = "{$year}-12";
        Chart::ensure($tenantId);

        DB::transaction(function () use ($request, $tenantId, $year, $december) {
            $row = $this->lockYear($tenantId, $year);
            if ($row->status === 'CLOSED') {
                throw ValidationException::withMessages(['status' => ["El ejercicio {$year} ya está cerrado."]]);
            }

            [$lines, $result] = $this->closingLines($tenantId, $year);
            $entryId = $lines ? Ledger::post($tenantId, "{$year}-12-31", "Cierre del ejercicio {$year}", [
                'type' => 'YEAR_CLOSE', 'id' => $row->id, 'number' => (string) $year,
            ], $lines, $request->user()->id) : null;

            DB::table('acc_fiscal_years')->where('id', $row->id)->update([
                'status' => 'CLOSED', 'closing_entry_id' => $entryId, 'result' => $result,
                'closed_at' => now(), 'closed_by' => $request->user()->id, 'reason' => null,
            ]);
            // Diciembre queda cerrado con el asiento de cierre adentro.
            Ledger::openPeriod($tenantId, $december);
            DB::table('acc_periods')->where('tenant_id', $tenantId)->where('period', $december)->where('status', 'OPEN')
                ->update(['status' => 'CLOSED', 'closed_at' => now(), 'closed_by' => $request->user()->id, 'reason' => null]);
            // El presupuesto aprobado del año se da por cerrado (como "Cerrar ejercicio" del prototipo).
            $budget = DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('status', 'APPROVED')->first(['id', 'name']);
            if ($budget) {
                DB::table('budgets')->where('id', $budget->id)->update(['status' => 'CLOSED']);
                $this->audit($request, 'budget.close', 'Budget', $budget->id, ['from' => 'APPROVED', 'to' => 'CLOSED', 'note' => "{$budget->name}: ejercicio {$year} cerrado"]);
            }

            $number = $entryId ? DB::table('acc_journal_entries')->where('id', $entryId)->value('entry_number') : null;
            $this->audit($request, 'fiscal_year.close', 'FiscalYear', $row->id, [
                'from' => 'OPEN', 'to' => 'CLOSED', 'amount' => $result,
                'note' => $number ? "{$year} · {$number} · resultado ".number_format($result, 2) : "{$year} · sin ingresos ni gastos que cerrar",
            ]);
        });

        return response()->json($this->row($tenantId, $year));
    }

    public function reopen(string $year, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $year = $this->validYear($year);
        $reason = trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo para reabrir el ejercicio.'])['reason']);
        $later = DB::table('acc_fiscal_years')->where('tenant_id', $tenantId)->where('fiscal_year', '>', $year)->where('status', 'CLOSED')->min('fiscal_year');
        if ($later) {
            throw ValidationException::withMessages(['status' => ["Reabre primero el ejercicio {$later}."]]);
        }
        $december = "{$year}-12";

        DB::transaction(function () use ($request, $tenantId, $year, $reason, $december) {
            $row = $this->lockYear($tenantId, $year);
            if ($row->status !== 'CLOSED') {
                throw ValidationException::withMessages(['status' => ["El ejercicio {$year} está abierto."]]);
            }

            // Diciembre se reabre para que el extorno caiga el mismo 31 de diciembre.
            DB::table('acc_periods')->where('tenant_id', $tenantId)->where('period', $december)
                ->update(['status' => 'OPEN', 'reason' => "Ejercicio {$year} reabierto: {$reason}"]);
            $reversal = null;
            $entry = $row->closing_entry_id ? DB::table('acc_journal_entries')->where('id', $row->closing_entry_id)->first(['id', 'status', 'entry_number']) : null;
            if ($entry && $entry->status === 'POSTED') {
                $reversal = Ledger::reverse($tenantId, $entry->id, "Reapertura del ejercicio {$year}: {$reason}", $request->user()->id, "{$year}-12-31");
            }
            DB::table('acc_fiscal_years')->where('id', $row->id)->update(['status' => 'OPEN', 'closing_entry_id' => null, 'reason' => $reason]);
            $budget = DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('status', 'CLOSED')->first(['id', 'name']);
            if ($budget) {
                DB::table('budgets')->where('id', $budget->id)->update(['status' => 'APPROVED']);
                $this->audit($request, 'budget.reopen_year', 'Budget', $budget->id, ['from' => 'CLOSED', 'to' => 'APPROVED', 'note' => "{$budget->name}: ejercicio {$year} reabierto"]);
            }

            $number = $reversal ? DB::table('acc_journal_entries')->where('id', $reversal)->value('entry_number') : null;
            $this->audit($request, 'fiscal_year.reopen', 'FiscalYear', $row->id, [
                'from' => 'CLOSED', 'to' => 'OPEN', 'note' => trim("{$year}".($entry ? " · {$entry->entry_number} extornado por {$number}" : '').": {$reason}"),
            ]);
        });

        return response()->json($this->row($tenantId, $year));
    }

    // ------------------------------------------------------------------------------------------

    /** Por qué no se puede cerrar el año, o null si se puede. */
    private function closeBlocker(string $tenantId, int $year): ?string
    {
        if ($year >= (int) now()->year) {
            return "El ejercicio {$year} aún no termina: se cierra a partir del 1 de enero de ".($year + 1).'.';
        }
        $row = DB::table('acc_fiscal_years')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->first(['status']);
        if ($row?->status === 'CLOSED') {
            return "El ejercicio {$year} ya está cerrado.";
        }
        // Los años se cierran en orden: el anterior con movimientos debe estar cerrado.
        $previous = collect($this->years($tenantId))->filter(fn (int $other) => $other < $year)->sortDesc()->first();
        if ($previous && DB::table('acc_fiscal_years')->where('tenant_id', $tenantId)->where('fiscal_year', $previous)->value('status') !== 'CLOSED') {
            return "Cierra primero el ejercicio {$previous}.";
        }
        $open = DB::table('acc_periods')->where('tenant_id', $tenantId)->where('period', 'like', "{$year}-%")->where('period', '<', "{$year}-12")
            ->where('status', 'OPEN')->orderBy('period')->pluck('period')->map(fn ($period) => trim($period));
        if ($open->isNotEmpty()) {
            return 'Cierra antes los periodos de enero a noviembre: siguen abiertos '.$open->join(', ', ' y ').'.';
        }
        if (Ledger::isClosed($tenantId, "{$year}-12")) {
            return "Diciembre de {$year} está cerrado: reábrelo para registrar el asiento de cierre.";
        }
        $pending = DB::table('bank_movements')->where('tenant_id', $tenantId)->where('reconciled', false)->where('ref_type', '<>', 'OPENING')
            ->whereRaw("to_char(movement_date, 'YYYY-MM') = ?", ["{$year}-12"])->count();
        if ($pending > 0) {
            return "Hay {$pending} movimiento(s) bancario(s) de diciembre sin conciliar. Concilia antes de cerrar.";
        }
        foreach ([self::PROFIT_ACCOUNT, self::LOSS_ACCOUNT] as $code) {
            if (! Chart::postable($tenantId, $code, ['EQUITY'])) {
                return "La cuenta {$code} debe existir y estar activa en el plan de cuentas para cerrar el ejercicio.";
            }
        }

        return null;
    }

    /**
     * Líneas del asiento de cierre: cada cuenta de ingreso o gasto con saldo se salda, y la
     * diferencia va a utilidades (5911) o pérdidas (5921) acumuladas. Devuelve [líneas, resultado].
     */
    private function closingLines(string $tenantId, int $year): array
    {
        $balances = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('acc_accounts as a', fn ($join) => $join->on('a.tenant_id', '=', 'l.tenant_id')->on('a.code', '=', 'l.account_code'))
            ->where('l.tenant_id', $tenantId)->where('e.period', 'like', "{$year}-%")
            ->tap(fn ($q) => Ledger::withoutYearClose($q))
            ->whereIn('a.account_type', ['INCOME', 'EXPENSE'])
            ->groupBy('a.code')->orderBy('a.code')
            ->selectRaw('a.code, sum(l.debit) - sum(l.credit) AS net')
            ->get();

        $lines = [];
        $net = 0.0;
        foreach ($balances as $balance) {
            $value = round((float) $balance->net, 2);
            if (abs($value) < 0.005) {
                continue;
            }
            // Se salda al revés: un saldo deudor (gasto) va al haber; uno acreedor (ingreso), al debe.
            $lines[] = ['account' => $balance->code, 'debit' => $value < 0 ? -$value : 0, 'credit' => $value > 0 ? $value : 0, 'note' => "Cierre {$year}"];
            $net = round($net + $value, 2);
        }
        if (! $lines) {
            return [[], 0.0];
        }
        // net = gastos − ingresos: negativo es utilidad.
        $result = round(-$net, 2);
        if (abs($result) >= 0.005) {
            $lines[] = $result > 0
                ? ['account' => self::PROFIT_ACCOUNT, 'credit' => $result, 'note' => "Utilidad del ejercicio {$year}"]
                : ['account' => self::LOSS_ACCOUNT, 'debit' => -$result, 'note' => "Pérdida del ejercicio {$year}"];
        }

        return [$lines, $result];
    }

    /** Años con asientos o periodos (siempre incluye el actual). */
    private function years(string $tenantId): array
    {
        return collect(DB::table('acc_periods')->where('tenant_id', $tenantId)->pluck('period'))
            ->merge(DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->distinct()->pluck('period'))
            ->map(fn ($period) => (int) substr(trim($period), 0, 4))
            ->merge(DB::table('acc_fiscal_years')->where('tenant_id', $tenantId)->pluck('fiscal_year'))
            ->push((int) now()->year)
            ->unique()->sortDesc()->values()->all();
    }

    private function row(string $tenantId, int $year): array
    {
        $row = DB::table('acc_fiscal_years')->leftJoin('users', 'users.id', '=', 'acc_fiscal_years.closed_by')
            ->where('acc_fiscal_years.tenant_id', $tenantId)->where('fiscal_year', $year)
            ->first(['acc_fiscal_years.*', 'users.name as closed_by_name']);
        $periods = DB::table('acc_periods')->where('tenant_id', $tenantId)->where('period', 'like', "{$year}-%")
            ->selectRaw("count(*) FILTER (WHERE status = 'CLOSED') AS closed")->value('closed');
        $entry = $row?->closing_entry_id ? DB::table('acc_journal_entries')->where('id', $row->closing_entry_id)->first(['id', 'entry_number']) : null;
        $status = $row->status ?? 'OPEN';
        $budget = DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->value('status');

        return [
            'year' => $year,
            'status' => $status,
            'closed_periods' => (int) $periods,
            // Ingresos menos gastos del año, sin el asiento de cierre.
            'result' => $status === 'CLOSED' && $row?->result !== null ? round((float) $row->result, 2) : -1 * round(
                (float) DB::table('acc_journal_lines as l')->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
                    ->join('acc_accounts as a', fn ($join) => $join->on('a.tenant_id', '=', 'l.tenant_id')->on('a.code', '=', 'l.account_code'))
                    ->where('l.tenant_id', $tenantId)->where('e.period', 'like', "{$year}-%")->tap(fn ($q) => Ledger::withoutYearClose($q))
                    ->whereIn('a.account_type', ['INCOME', 'EXPENSE'])->sum(DB::raw('l.debit - l.credit')), 2) + 0.0,
            'closing_entry' => $entry ? ['id' => $entry->id, 'number' => $entry->entry_number] : null,
            'budget_status' => $budget,
            'closed_at' => $row?->closed_at ? Carbon::parse($row->closed_at)->toIso8601String() : null,
            'closed_by' => $row->closed_by_name ?? null,
            'reason' => $row->reason ?? null,
            'blocker' => $status === 'CLOSED' ? null : $this->closeBlocker($tenantId, $year),
        ];
    }

    /** El registro del año (lo crea abierto si no existe), bloqueado para esta operación. */
    private function lockYear(string $tenantId, int $year): object
    {
        DB::table('acc_fiscal_years')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'fiscal_year' => $year, 'status' => 'OPEN']);

        return DB::table('acc_fiscal_years')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->lockForUpdate()->first();
    }

    private function validYear(string $year): int
    {
        abort_unless((bool) preg_match('/^2\d{3}$/', $year), 404);

        return (int) $year;
    }

    private function audit(Request $request, string $action, string $entityType, string $entityId, array $metadata): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'), userId: $request->user()->id, action: $action,
            entityType: $entityType, entityId: $entityId, metadata: array_filter($metadata, fn ($value) => $value !== null),
        );
    }
}
