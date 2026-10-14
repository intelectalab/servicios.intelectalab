<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Purchasing\Support\Purchasing;
use Illuminate\Support\Facades\DB;

/**
 * Presupuesto por centro de costo y cuenta frente a lo ejecutado (libro diario) y lo comprometido
 * (órdenes de compra aprobadas que aún no se facturan). Todo en soles.
 */
class Budgets
{
    /** Estados de una orden de compra cuyo importe ya está comprometido. */
    public const COMMITTING = ['APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED'];

    /**
     * Lo ejecutado en el año por centro de costo y cuenta, mes a mes: gasto = debe − haber;
     * ingreso = haber − debe. Los extornos restan solos (son asientos inversos).
     *
     * @return array<string, array<string, float[]>> [centro][cuenta] => 12 meses
     */
    public static function actuals(string $tenantId, int $year, ?array $centerIds = null): array
    {
        $rows = DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('acc_accounts as a', fn ($join) => $join->on('a.tenant_id', '=', 'l.tenant_id')->on('a.code', '=', 'l.account_code'))
            ->where('l.tenant_id', $tenantId)->whereNotNull('l.cost_center_id')
            ->where('e.period', 'like', "{$year}-%")
            ->when($centerIds !== null, fn ($q) => $q->whereIn('l.cost_center_id', $centerIds ?: ['00000000-0000-0000-0000-000000000000']))
            ->groupBy('l.cost_center_id', 'l.account_code', 'e.period', 'a.account_type')
            ->selectRaw('l.cost_center_id, l.account_code, e.period, a.account_type, sum(l.debit) AS debit, sum(l.credit) AS credit')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $month = (int) substr($row->period, 5, 2) - 1;
            $value = $row->account_type === 'INCOME' ? (float) $row->credit - (float) $row->debit : (float) $row->debit - (float) $row->credit;
            $out[$row->cost_center_id][$row->account_code] ??= array_fill(0, 12, 0.0);
            $out[$row->cost_center_id][$row->account_code][$month] = round($out[$row->cost_center_id][$row->account_code][$month] + $value, 2);
        }

        return $out;
    }

    /**
     * Comprometido por centro de costo: lo que falta facturar de sus órdenes aprobadas (sin IGV).
     *
     * @return array<string, float> [centro] => soles
     */
    public static function committed(string $tenantId, ?array $centerIds = null, ?string $exceptOrder = null): array
    {
        $rate = Purchasing::rate() ?? 1.0;
        $invoiced = "(SELECT coalesce(sum(si.subtotal * coalesce(si.exchange_rate, 1)), 0) FROM supplier_invoices si
            WHERE si.purchase_order_id = purchase_orders.id AND si.status = 'APPROVED')";

        return DB::table('purchase_orders')->where('tenant_id', $tenantId)->whereIn('status', self::COMMITTING)
            ->when($centerIds !== null, fn ($q) => $q->whereIn('cost_center_id', $centerIds ?: ['00000000-0000-0000-0000-000000000000']))
            ->when($exceptOrder, fn ($q) => $q->where('id', '<>', $exceptOrder))
            ->groupBy('cost_center_id')
            ->selectRaw("cost_center_id, sum(GREATEST(subtotal * (CASE WHEN currency = 'USD' THEN CAST(? AS numeric) ELSE 1 END) - {$invoiced}, 0)) AS amount", [$rate])
            ->pluck('amount', 'cost_center_id')->map(fn ($amount) => round((float) $amount, 2))->all();
    }

    /** Presupuesto aprobado del año, si existe. */
    public static function approved(string $tenantId, int $year): ?object
    {
        return DB::table('budgets')->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('status', 'APPROVED')->first();
    }

    /**
     * Control de presupuesto de una orden de compra: anual del centro, ejecutado, comprometido en
     * otras órdenes y disponible. null si el centro no tiene presupuesto aprobado este año.
     */
    public static function check(string $tenantId, string $centerId, float $amountPen, ?string $exceptOrder = null): ?array
    {
        $year = (int) now()->year;
        $budget = self::approved($tenantId, $year);
        if (! $budget) {
            return null;
        }
        $annual = round((float) DB::table('budget_lines')->where('budget_id', $budget->id)->where('cost_center_id', $centerId)->sum('amount'), 2);
        if ($annual <= 0) {
            return null;
        }
        $actual = round(array_sum(array_map('array_sum', self::actuals($tenantId, $year, [$centerId])[$centerId] ?? [])), 2);
        $committed = self::committed($tenantId, [$centerId], $exceptOrder)[$centerId] ?? 0.0;
        $available = round($annual - $actual - $committed, 2);

        return ['annual' => $annual, 'actual' => $actual, 'committed' => $committed, 'available' => $available, 'over' => $amountPen > $available + 0.001];
    }
}
