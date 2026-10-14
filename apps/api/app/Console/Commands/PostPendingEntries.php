<?php

namespace App\Console\Commands;

use App\Modules\Accounting\Support\Chart;
use App\Modules\Accounting\Support\Posting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contabiliza los documentos que aún no tienen asiento: los registrados antes de que existiera
 * Contabilidad. Usa las mismas reglas que los documentos nuevos y no duplica: un documento con
 * asiento se salta. Se puede correr las veces que haga falta.
 */
class PostPendingEntries extends Command
{
    protected $signature = 'accounting:post-pending {--tenant= : Código de una sola empresa}';

    protected $description = 'Genera el asiento de los documentos que aún no lo tienen (ventas, cobros, compras, pagos, caja, bancos)';

    public function handle(): int
    {
        $tenants = DB::table('tenants')->where('status', 'ACTIVE')
            ->when($this->option('tenant'), fn ($q) => $q->where('code', $this->option('tenant')))
            ->orderBy('created_at')->get(['id', 'code']);

        foreach ($tenants as $tenant) {
            // Los documentos están bajo RLS: hay que entrar al contexto de la empresa.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);
            Chart::forget();
            $posted = 0;
            $failed = 0;

            foreach ($this->pending((string) $tenant->id) as [$date, $rule]) {
                try {
                    DB::transaction(function () use ($rule, &$posted) {
                        if ($rule()) {
                            $posted++;
                        }
                    });
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn("{$tenant->code}: ".$e->getMessage());
                }
            }

            $this->line("{$tenant->code}: {$posted} asientos generados".($failed ? ", {$failed} con error" : ''));
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        return self::SUCCESS;
    }

    /**
     * Documentos sin asiento, en orden de fecha (para que la numeración siga la del negocio).
     *
     * @return array<int, array{0: string, 1: callable}>
     */
    private function pending(string $tenantId): array
    {
        $without = fn (string $type) => fn ($q) => $q->selectRaw('1')->from('acc_journal_entries as e')
            ->where('e.tenant_id', $tenantId)->where('e.source_type', $type)->whereColumn('e.source_id', 'x.id');
        $day = fn ($value) => Carbon::parse($value)->toDateString();
        $jobs = [];

        foreach (DB::table('invoices as x')->where('tenant_id', $tenantId)->whereNotNull('invoice_number')->whereIn('status', ['ISSUED', 'CORRECTED', 'VOID'])
            ->whereNotExists($without('INVOICE'))->get(['id', 'issue_date', 'issued_at', 'status']) as $row) {
            $jobs[] = [$day($row->issue_date ?? $row->issued_at), fn () => Posting::invoice($tenantId, $row->id, null)];
            if ($row->status === 'VOID') {
                $jobs[] = [$day($row->issue_date ?? $row->issued_at).'~', fn () => Posting::invoiceVoided($tenantId, $row->id, null)];
            }
        }
        foreach (DB::table('invoices as x')->where('tenant_id', $tenantId)->where('withholding_status', 'RECEIVED')
            ->whereNotExists($without('WITHHOLDING'))->get(['id', 'withholding_date']) as $row) {
            $jobs[] = [$day($row->withholding_date ?? now()), fn () => Posting::withholding($tenantId, $row->id, null)];
        }
        foreach (DB::table('payments as x')->where('tenant_id', $tenantId)->whereNotExists($without('PAYMENT'))->get(['id', 'paid_at']) as $row) {
            $jobs[] = [$day($row->paid_at), fn () => Posting::payment($tenantId, $row->id, null)];
        }
        foreach (DB::table('refunds as x')->where('tenant_id', $tenantId)->whereNotExists($without('REFUND'))->get(['id', 'created_at']) as $row) {
            $jobs[] = [$day($row->created_at), fn () => Posting::refund($tenantId, $row->id, null)];
        }
        // Diferencia de cambio de cada aplicación de un cobro en dólares.
        $fx = DB::table('receivable_movements as x')
            ->join('payments as p', 'p.id', '=', 'x.payment_id')
            ->join('receivables as r', 'r.id', '=', 'x.receivable_id')
            ->join('invoices as i', 'i.id', '=', 'r.invoice_id')
            ->where('x.tenant_id', $tenantId)->where('x.movement_type', 'PAYMENT')->where('p.currency', 'USD')
            ->where('p.exchange_rate', '>', 0)->where('i.exchange_rate', '>', 0)
            ->whereNotExists($without('FX'))
            ->get(['x.id', 'x.amount', 'x.created_at', 'p.exchange_rate as pay_rate', 'i.exchange_rate as doc_rate', 'p.payment_number', 'p.customer_id']);
        foreach ($fx as $row) {
            $difference = round(-1 * (float) $row->amount * ((float) $row->pay_rate - (float) $row->doc_rate), 2);
            $jobs[] = [$day($row->created_at).'~', fn () => Posting::receivableFx($tenantId, $row->id, $difference, $day($row->created_at), $row->payment_number, $row->customer_id, null)];
        }
        foreach (DB::table('supplier_invoices as x')->where('tenant_id', $tenantId)->where('status', 'APPROVED')
            ->whereNotExists($without('SUPPLIER_INVOICE'))->get(['id', 'issue_date']) as $row) {
            $jobs[] = [$day($row->issue_date), fn () => Posting::supplierInvoice($tenantId, $row->id, null)];
        }
        foreach (DB::table('supplier_payments as x')->where('tenant_id', $tenantId)->whereNotExists($without('SUPPLIER_PAYMENT'))->get(['id', 'paid_at']) as $row) {
            $jobs[] = [$day($row->paid_at).'~', fn () => Posting::supplierPayment($tenantId, $row->id, null)];
        }
        foreach (DB::table('cash_expenses as x')->where('tenant_id', $tenantId)->whereNotExists($without('CASH_EXPENSE'))->get(['id', 'expense_date']) as $row) {
            $jobs[] = [$day($row->expense_date), fn () => Posting::cashExpense($tenantId, $row->id, null)];
        }
        foreach (DB::table('bank_movements as x')->where('tenant_id', $tenantId)->whereIn('ref_type', ['BANK_FEE', 'UNIDENTIFIED', 'OPENING'])
            ->whereRaw("NOT EXISTS (SELECT 1 FROM acc_journal_entries e WHERE e.tenant_id = x.tenant_id AND e.source_type = x.ref_type AND e.source_id = x.id)")
            ->get(['id', 'movement_date', 'ref_type']) as $row) {
            // La apertura va antes que todo lo de su día.
            $jobs[] = [$day($row->movement_date).($row->ref_type === 'OPENING' ? '!' : ''), fn () => Posting::bankMovement($tenantId, $row->id, null)];
        }
        foreach (DB::table('bank_movements as x')->where('tenant_id', $tenantId)->where('ref_type', 'TRANSFER')->whereNotNull('ref_id')->where('amount', '>', 0)
            ->whereRaw("NOT EXISTS (SELECT 1 FROM acc_journal_entries e WHERE e.tenant_id = x.tenant_id AND e.source_type = 'TRANSFER' AND e.source_id = x.ref_id)")
            ->get(['ref_id', 'movement_date']) as $row) {
            $jobs[] = [$day($row->movement_date), fn () => Posting::transfer($tenantId, $row->ref_id, null)];
        }

        // "!" (apertura) antes que el resto del día y "~" (lo que depende de otro asiento) después.
        $key = fn (string $date) => substr($date, 0, 10).(str_ends_with($date, '!') ? '0' : (str_ends_with($date, '~') ? '2' : '1'));
        usort($jobs, fn ($a, $b) => strcmp($key($a[0]), $key($b[0])));

        return $jobs;
    }
}
