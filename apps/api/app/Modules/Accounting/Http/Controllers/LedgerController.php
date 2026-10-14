<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Chart;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mayor y balance de comprobación (sumas y saldos por cuenta en un rango de periodos, con o sin
 * centro de costo) y estados financieros (resultados y situación financiera). Todo sale del libro
 * diario, en soles.
 */
class LedgerController extends Controller
{
    /** Balance de comprobación del rango. */
    public function trialBalance(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Chart::ensure($tenantId);
        [$from, $to, $center] = $this->range($request);

        $rows = $this->sums($tenantId, $from, $to, $center)
            ->map(function ($row) {
                $net = round((float) $row->debit - (float) $row->credit, 2);

                return [
                    'code' => $row->code, 'name' => $row->name, 'type' => $row->account_type, 'type_label' => Chart::TYPES[$row->account_type] ?? $row->account_type,
                    'debit' => round((float) $row->debit, 2), 'credit' => round((float) $row->credit, 2),
                    'debtor' => $net > 0 ? $net : 0.0, 'creditor' => $net < 0 ? -$net : 0.0,
                ];
            })->values();

        $totals = [
            'debit' => round($rows->sum('debit'), 2), 'credit' => round($rows->sum('credit'), 2),
            'debtor' => round($rows->sum('debtor'), 2), 'creditor' => round($rows->sum('creditor'), 2),
        ];

        return response()->json([
            'from' => $from, 'to' => $to, 'cost_center_id' => $center,
            'rows' => $rows, 'totals' => $totals,
            'balanced' => abs($totals['debit'] - $totals['credit']) < 0.05,
        ]);
    }

    /** Libro mayor de una cuenta en el rango: sus líneas con el saldo acumulado, la más reciente primero. */
    public function account(string $code, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Chart::ensure($tenantId);
        $account = DB::table('acc_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first(['code', 'name', 'account_type']);
        abort_unless((bool) $account, 404);
        [$from, $to, $center] = $this->range($request);

        // El saldo corrido se calcula en orden de fecha, antes de paginar.
        $inner = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('l.tenant_id', $tenantId)->where('l.account_code', $code)
            ->where('e.period', '>=', $from)->where('e.period', '<=', $to)
            ->when($center, fn ($q) => $q->where('l.cost_center_id', $center))
            ->select('l.id', 'l.debit', 'l.credit', 'l.note', 'e.id as entry_id', 'e.entry_number', 'e.entry_date', 'e.description')
            ->selectRaw('sum(l.debit - l.credit) OVER (ORDER BY e.entry_date, e.entry_number, l.position) AS running');
        $page = DB::query()->fromSub($inner, 'm')->orderByDesc('entry_date')->orderByDesc('entry_number')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 12), 1), 200));
        $page->getCollection()->transform(fn ($line) => [
            'id' => $line->id, 'entry_id' => $line->entry_id, 'entry_number' => $line->entry_number,
            'entry_date' => Carbon::parse($line->entry_date)->toDateString(), 'description' => $line->description, 'note' => $line->note,
            'debit' => (float) $line->debit, 'credit' => (float) $line->credit, 'running' => round((float) $line->running, 2),
        ]);

        return response()->json([...$page->toArray(), 'account' => ['code' => $account->code, 'name' => $account->name, 'type' => $account->account_type]]);
    }

    /** Estados financieros referenciales del rango (resultados) y al cierre del rango (situación). */
    public function statements(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        Chart::ensure($tenantId);
        [$from, $to] = $this->range($request, now()->format('Y').'-01');

        $range = $this->sums($tenantId, $from, $to);
        $all = $this->sums($tenantId, '0000-00', $to);
        // Saldo con el signo de su naturaleza: deudor positivo en activo y gasto; acreedor positivo en lo demás.
        $sum = fn ($rows, callable $match, int $sign = 1) => round($rows->filter(fn ($row) => $match($row))->sum(fn ($row) => $sign * ((float) $row->debit - (float) $row->credit)), 2);
        $starts = fn (string $prefix) => fn ($row) => str_starts_with($row->code, $prefix);
        $type = fn (string $kind) => fn ($row) => $row->account_type === $kind;

        $sales = $sum($range, $starts('70'), -1);
        $financialIncome = $sum($range, $starts('77'), -1);
        $personnel = $sum($range, $starts('62'));
        $services = $sum($range, $starts('63'));
        $management = $sum($range, $starts('65'));
        $financialCost = $sum($range, $starts('67'));
        // Otros ingresos y gastos que la empresa agregue a su plan fuera de esos grupos.
        $otherIncome = round($sum($range, $type('INCOME'), -1) - $sales - $financialIncome, 2);
        $otherExpense = round($sum($range, $type('EXPENSE')) - $personnel - $services - $management - $financialCost, 2);
        $expenses = round($personnel + $services + $management + $financialCost + $otherExpense, 2);
        $result = round($sales + $financialIncome + $otherIncome - $expenses, 2);

        $assets = $sum($all, $type('ASSET'));
        $liabilities = $sum($all, $type('LIABILITY'), -1);
        $equity = $sum($all, $type('EQUITY'), -1);
        $accumulated = round($sum($all, $type('INCOME'), -1) - $sum($all, $type('EXPENSE')), 2);

        // Ingresos y gastos de cada mes del rango (como mucho los últimos 24, para que el gráfico se lea).
        $end = Carbon::createFromFormat('Y-m-d', "{$to}-01");
        $span = min(24, Carbon::createFromFormat('Y-m-d', "{$from}-01")->diffInMonths($end) + 1);
        $months = collect(range((int) $span - 1, 0))->map(fn (int $back) => $end->copy()->subMonthsNoOverflow($back)->format('Y-m'));
        $byMonth = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('acc_accounts as a', fn ($join) => $join->on('a.tenant_id', '=', 'l.tenant_id')->on('a.code', '=', 'l.account_code'))
            ->where('l.tenant_id', $tenantId)->whereIn('e.period', $months->all())->whereIn('a.account_type', ['INCOME', 'EXPENSE'])
            ->groupBy('e.period', 'a.account_type')
            ->selectRaw('e.period, a.account_type, sum(l.credit - l.debit) AS net')->get();

        // Sin ceros negativos ("-0.00") en lo que se muestra.
        $clean = fn (array $lines) => array_map(fn (array $line) => [...$line, 'value' => $line['value'] + 0.0 === 0.0 ? 0.0 : $line['value']], $lines);

        return response()->json([
            'from' => $from, 'to' => $to,
            'kpis' => ['income' => round($sales + $financialIncome + $otherIncome, 2), 'expenses' => $expenses, 'result' => $result,
                'margin' => $sales > 0 ? (int) round($result / $sales * 100) : null],
            'income_statement' => $clean(array_values(array_filter([
                ['label' => 'Ventas – prestación de servicios (70)', 'value' => $sales],
                ['label' => 'Ingresos financieros (77)', 'value' => $financialIncome],
                abs($otherIncome) >= 0.01 ? ['label' => 'Otros ingresos', 'value' => $otherIncome] : null,
                ['label' => 'Gastos de personal (62)', 'value' => -$personnel],
                ['label' => 'Servicios de terceros (63)', 'value' => -$services],
                ['label' => 'Otros gastos de gestión (65)', 'value' => -$management],
                ['label' => 'Gastos financieros (67)', 'value' => -$financialCost],
                abs($otherExpense) >= 0.01 ? ['label' => 'Otros gastos', 'value' => -$otherExpense] : null,
                ['label' => 'Resultado del periodo', 'value' => $result, 'total' => true],
            ]))),
            'balance_sheet' => $clean([
                ['label' => 'Efectivo y equivalentes (10)', 'value' => $sum($all, $starts('10'))],
                ['label' => 'Cuentas por cobrar comerciales (12)', 'value' => $sum($all, $starts('121'))],
                ['label' => 'Otras cuentas por cobrar (16)', 'value' => $sum($all, $starts('16'))],
                ['label' => 'Total activo', 'value' => $assets, 'total' => true],
                ['label' => 'Tributos por pagar (40)', 'value' => $sum($all, $starts('40'), -1)],
                ['label' => 'Cuentas por pagar comerciales (42)', 'value' => $sum($all, $starts('42'), -1)],
                ['label' => 'Anticipos de clientes (122)', 'value' => $sum($all, fn ($row) => $row->code === '122', -1)],
                ['label' => 'Total pasivo', 'value' => $liabilities, 'total' => true],
                ['label' => 'Capital', 'value' => $equity],
                ['label' => 'Resultados acumulados', 'value' => $accumulated],
                ['label' => 'Pasivo + patrimonio', 'value' => round($liabilities + $equity + $accumulated, 2), 'total' => true],
            ]),
            'balanced' => abs($assets - ($liabilities + $equity + $accumulated)) < 1,
            'months' => $months->map(fn (string $period) => [
                'period' => $period,
                'income' => round((float) ($byMonth->first(fn ($row) => $row->period === $period && $row->account_type === 'INCOME')?->net ?? 0), 2) + 0.0,
                'expenses' => round(-1 * (float) ($byMonth->first(fn ($row) => $row->period === $period && $row->account_type === 'EXPENSE')?->net ?? 0), 2) + 0.0,
            ])->map(fn (array $month) => [...$month, 'result' => round($month['income'] - $month['expenses'], 2) + 0.0])->values(),
        ]);
    }

    /** Sumas del debe y el haber por cuenta imputable con movimientos en el rango. */
    private function sums(string $tenantId, string $from, string $to, ?string $center = null)
    {
        return DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('acc_accounts as a', fn ($join) => $join->on('a.tenant_id', '=', 'l.tenant_id')->on('a.code', '=', 'l.account_code'))
            ->where('l.tenant_id', $tenantId)->where('e.period', '>=', $from)->where('e.period', '<=', $to)
            ->when($center, fn ($q) => $q->where('l.cost_center_id', $center))
            ->groupBy('a.code', 'a.name', 'a.account_type')
            ->orderBy('a.code')
            ->selectRaw('a.code, a.name, a.account_type, sum(l.debit) AS debit, sum(l.credit) AS credit')
            ->get();
    }

    /** @return array{0: string, 1: string, 2: ?string} desde, hasta (AAAA-MM) y centro de costo */
    private function range(Request $request, ?string $defaultFrom = null): array
    {
        $tenantId = $request->attributes->get('tenant_id');
        $valid = fn ($value) => is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value);
        $first = DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->min('period');
        $to = $valid($request->query('to')) ? $request->query('to') : now()->format('Y-m');
        $from = $valid($request->query('from')) ? $request->query('from') : ($defaultFrom ?? ($first ? trim($first) : $to));
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $center = Str::isUuid((string) $request->query('cost_center_id'))
            && DB::table('cost_centers')->where('tenant_id', $tenantId)->where('id', $request->query('cost_center_id'))->exists()
            ? (string) $request->query('cost_center_id') : null;

        return [$from, $to, $center];
    }
}
