<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Models\ReceivableMovement;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Cuentas por cobrar: una por comprobante emitido. El saldo sale de sus movimientos; "vencida"
 * se calcula por la fecha de vencimiento, no es un estado.
 */
class ReceivableController extends Controller
{
    /** Número del comprobante de la fila: es un dato de la cuenta, se muestre o no el comprobante. */
    private const INVOICE_NUMBER = '(SELECT i.invoice_number FROM invoices i WHERE i.id = receivables.invoice_id)';

    private const WITH = ['customer.party.person', 'customer.party.organization'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $bucket = Receivable::BUCKETS[$request->query('bucket')] ?? null;

        $query = Receivable::query()->with(self::WITH)
            ->select('receivables.*')
            ->selectRaw(self::INVOICE_NUMBER.' AS invoice_number')
            ->selectRaw(Receivable::DAYS_OVERDUE.' AS days_overdue')
            ->when(isset(Receivable::STATES[$status]), fn ($q) => $q->where('receivables.status', $status))
            ->when($status === 'OVERDUE', fn ($q) => $this->overdue($q))
            ->when($bucket, function ($q) use ($bucket) {
                // La antigüedad es de las cuentas con saldo.
                [, $from, $to] = $bucket;
                $q->whereIn('receivables.status', Receivable::OPEN)
                    ->when($from !== null, fn ($w) => $w->whereRaw(Receivable::DAYS_OVERDUE.' >= ?', [$from]))
                    ->when($to !== null, fn ($w) => $w->where(fn ($d) => $d->whereRaw(Receivable::DAYS_OVERDUE.' <= ?', [$to])->orWhereNull('receivables.due_date')));
            })
            ->when(Str::isUuid((string) $request->query('customer_id')), fn ($q) => $q->where('receivables.customer_id', $request->query('customer_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por cliente o número de comprobante.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw(ListSort::customerName('receivables').' ILIKE ?', [$like])
                    ->orWhereRaw(self::INVOICE_NUMBER.' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'invoice' => ColumnFilters::text(self::INVOICE_NUMBER),
            'customer' => ColumnFilters::text(ListSort::customerName('receivables')),
            'due' => ColumnFilters::date('receivables.due_date'),
            'original' => ColumnFilters::number('receivables.original_amount'),
            'balance' => ColumnFilters::number('receivables.balance_amount'),
        ]);

        ListSort::apply($query, $request, [
            'invoice' => self::INVOICE_NUMBER,
            'customer' => 'lower('.ListSort::customerName('receivables').')',
            'due' => 'receivables.due_date',
            'original' => 'receivables.original_amount',
            'balance' => 'receivables.balance_amount',
            'status' => ListSort::labels('receivables.status', Receivable::STATES),
        ], fn ($q) => $q->orderBy('receivables.due_date')->orderBy('receivables.id'), 'receivables.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (Receivable $receivable) => $this->row($receivable));

        return response()->json($page);
    }

    /**
     * Antigüedad del saldo y resumen, sobre las cuentas que la persona alcanza. Los saldos en
     * dólares se llevan a soles con el tipo de cambio de venta vigente.
     */
    public function summary()
    {
        $rate = (float) (ExchangeRate::current('USD')?->sell ?? 0);
        $inPen = "CASE WHEN receivables.currency = 'USD' THEN receivables.balance_amount * {$rate} ELSE receivables.balance_amount END";
        $days = Receivable::DAYS_OVERDUE;

        $totals = Receivable::query()->whereIn('receivables.status', Receivable::OPEN)->selectRaw("
            count(*) AS open_count,
            coalesce(sum({$inPen}), 0) AS outstanding,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} > 0), 0) AS overdue,
            count(DISTINCT receivables.customer_id) FILTER (WHERE {$days} > 0) AS overdue_customers,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} <= 0 OR receivables.due_date IS NULL), 0) AS b_current,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} BETWEEN 1 AND 30), 0) AS b_d30,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} BETWEEN 31 AND 60), 0) AS b_d60,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} BETWEEN 61 AND 90), 0) AS b_d90,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} > 90), 0) AS b_over,
            count(*) FILTER (WHERE receivables.currency = 'USD') AS usd_count
        ")->toBase()->first();

        return response()->json([
            'outstanding' => round((float) $totals->outstanding, 2),
            'overdue' => round((float) $totals->overdue, 2),
            'open_count' => (int) $totals->open_count,
            'overdue_customers' => (int) $totals->overdue_customers,
            'aging' => collect(Receivable::BUCKETS)->map(fn (array $bucket, string $key) => [
                'key' => $key, 'label' => $bucket[0], 'amount' => round((float) $totals->{'b_'.strtolower($key)}, 2),
            ])->values(),
            // Hay saldo en dólares y ningún tipo de cambio registrado: ese saldo no entra en los totales.
            'missing_rate' => (int) $totals->usd_count > 0 && $rate <= 0,
        ]);
    }

    public function show(string $receivable)
    {
        abort_unless(Str::isUuid($receivable), 404);

        $receivable = Receivable::query()->with([...self::WITH, 'movements'])
            ->select('receivables.*')
            ->selectRaw(self::INVOICE_NUMBER.' AS invoice_number')
            ->selectRaw(Receivable::DAYS_OVERDUE.' AS days_overdue')
            ->findOrFail($receivable);

        return response()->json([
            ...$this->row($receivable),
            'movements' => $receivable->movements->map(fn (ReceivableMovement $movement) => [
                'id' => $movement->id, 'movement_type' => $movement->movement_type, 'amount' => (float) $movement->amount,
                'reference' => $movement->reference, 'at' => $movement->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    private function overdue($query)
    {
        return $query->whereIn('receivables.status', Receivable::OPEN)->whereRaw(Receivable::DAYS_OVERDUE.' > 0');
    }

    private function row(Receivable $receivable): array
    {
        $open = in_array($receivable->status, Receivable::OPEN, true);
        $days = $receivable->days_overdue !== null ? (int) $receivable->days_overdue : null;
        $overdue = $open && $days !== null && $days > 0;

        return [
            'id' => $receivable->id,
            'invoice_id' => $receivable->invoice_id,
            'invoice_number' => $receivable->invoice_number,
            'customer' => $receivable->customer,
            'due_date' => $receivable->due_date?->toDateString(),
            'original_amount' => (float) $receivable->original_amount,
            'balance_amount' => (float) $receivable->balance_amount,
            'currency' => $receivable->currency,
            'status' => $receivable->status,
            'overdue' => $overdue,
            'days_overdue' => $overdue ? $days : 0,
            'bucket' => $open ? Receivable::bucketOf($days) : null,
        ];
    }
}
