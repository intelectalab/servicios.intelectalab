<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\FinancialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de contabilización automática: qué asiento deja cada documento. Se llaman en la misma
 * operación que registra el documento (un documento y su asiento existen juntos) y son
 * idempotentes: un documento genera un único asiento vigente.
 *
 * Moneda funcional: soles. Lo que está en dólares se lleva a soles con el tipo de cambio que
 * guarda el propio documento.
 */
class Posting
{
    /** Las reglas, como se muestran en Plan de cuentas → Reglas automáticas. */
    public const RULES = [
        ['event' => 'invoice.issued', 'label' => 'Comprobante de venta emitido', 'lines' => 'D 1212 Total · H 40111 IGV · H 7041 Valor de venta (con el centro de ingresos de la sede)'],
        ['event' => 'credit_note.issued', 'label' => 'Nota de crédito emitida', 'lines' => 'Inverso del comprobante (D 7041 · D 40111 · H 1212)'],
        ['event' => 'invoice.voided', 'label' => 'Baja aceptada por SUNAT', 'lines' => 'Extorno del asiento del comprobante'],
        ['event' => 'payment.confirmed', 'label' => 'Cobro de cliente', 'lines' => 'D 1041/1011 Banco o caja · H 1212 Cuentas por cobrar'],
        ['event' => 'payment.refunded', 'label' => 'Reembolso de un cobro', 'lines' => 'D 1212 · H 1041/1011 Banco o caja'],
        ['event' => 'withholding.received', 'label' => 'Detracción depositada por el cliente / retención', 'lines' => 'D 1042 Cta. detracciones o D 40113 · H 1212'],
        ['event' => 'fx.difference', 'label' => 'Diferencia de cambio en cobros y pagos en US$', 'lines' => 'D 6761 o H 7761 según resultado'],
        ['event' => 'supplier_invoice.approved', 'label' => 'Comprobante de proveedor aprobado', 'lines' => 'D 63xx Gasto (con centro de costo) · D 40111 IGV crédito fiscal · H 4212 (· H 40172 retención de 4.ª)'],
        ['event' => 'supplier_payment.paid', 'label' => 'Pago a proveedor', 'lines' => 'D 4212 · H 1041 Banco (más depósito de detracción si aplica)'],
        ['event' => 'cash.expense', 'label' => 'Gasto de caja chica', 'lines' => 'D 63xx/65xx Gasto · H 1011 Caja'],
        ['event' => 'bank.fee', 'label' => 'Comisión bancaria del extracto', 'lines' => 'D 6391 Gastos bancarios · H 1041'],
        ['event' => 'bank.unidentified', 'label' => 'Abono por identificar del extracto', 'lines' => 'D 1041 · H 122 Anticipos / partida por identificar'],
        ['event' => 'bank.transfer', 'label' => 'Transferencia entre cuentas', 'lines' => 'D cuenta destino · H cuenta origen'],
        ['event' => 'bank.opening', 'label' => 'Saldo de apertura de una cuenta', 'lines' => 'D 1041/1011 · H 5911 Resultados acumulados'],
    ];

    /** Cuenta de gasto de caja chica cuando el gasto no indica una del plan. */
    public const CASH_EXPENSE_ACCOUNT = '6563';

    /** Comprobante de venta emitido o nota de crédito (sus importes ya vienen en negativo). */
    public static function invoice(string $tenantId, string $invoiceId, ?string $userId): ?string
    {
        $invoice = DB::table('invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->first();
        if (! $invoice || ! $invoice->invoice_number) {
            return null;
        }
        $rate = self::rate($invoice->currency, $invoice->exchange_rate);
        $pen = fn ($value) => round((float) $value * $rate, 2);
        $party = self::customerParty($invoice->customer_id);
        $center = Ledger::revenueCenter($tenantId, $invoice->branch_id);
        $note = (float) $invoice->total < 0;

        // La nota de crédito trae importes negativos: las mismas líneas quedan del otro lado.
        $lines = [
            ['account' => '1212', 'debit' => $pen($invoice->total), 'party_id' => $party],
            ['account' => '40111', 'credit' => $pen($invoice->tax_total)],
            ['account' => '7041', 'credit' => $pen($invoice->subtotal), 'cost_center_id' => $center],
        ];
        if ($note) {
            $lines = [
                ['account' => '7041', 'debit' => -1 * $pen($invoice->subtotal), 'cost_center_id' => $center],
                ['account' => '40111', 'debit' => -1 * $pen($invoice->tax_total)],
                ['account' => '1212', 'credit' => -1 * $pen($invoice->total), 'party_id' => $party],
            ];
        }

        return Ledger::post($tenantId, self::day($invoice->issue_date ?? $invoice->issued_at),
            trim(($note ? 'Nota de crédito ' : 'Venta ').$invoice->invoice_number.' · '.$invoice->customer_name, ' ·').self::fxNote($invoice->currency, $rate),
            ['type' => 'INVOICE', 'id' => $invoice->id, 'number' => $invoice->invoice_number], $lines, $userId);
    }

    /** Baja aceptada por SUNAT: el comprobante deja de existir, su asiento se extorna. */
    public static function invoiceVoided(string $tenantId, string $invoiceId, ?string $userId): ?string
    {
        $entry = Ledger::entryFor($tenantId, 'INVOICE', $invoiceId);

        return $entry ? Ledger::reverse($tenantId, $entry->id, 'Comunicación de baja aceptada', $userId) : null;
    }

    /** Cobro de cliente: entra al banco o caja y baja la cuenta por cobrar (lo no aplicado queda como anticipo en la misma cuenta). */
    public static function payment(string $tenantId, string $paymentId, ?string $userId): ?string
    {
        $payment = DB::table('payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->first();
        if (! $payment) {
            return null;
        }
        $amount = round((float) $payment->amount * self::rate($payment->currency, $payment->exchange_rate), 2);
        $account = $payment->financial_account_id ? FinancialAccount::where('tenant_id', $tenantId)->find($payment->financial_account_id) : null;
        $cash = Ledger::cashAccount($account, $payment->payment_method === 'EFECTIVO' ? 'CASH' : 'BANK', $payment->currency);

        return Ledger::post($tenantId, self::day($payment->paid_at), "Cobro {$payment->payment_number} · ".self::methodLabel($payment->payment_method),
            ['type' => 'PAYMENT', 'id' => $payment->id, 'number' => $payment->payment_number], [
                ['account' => $cash, 'debit' => $amount],
                ['account' => '1212', 'credit' => $amount, 'party_id' => self::customerParty($payment->customer_id)],
            ], $userId);
    }

    /** Reembolso de un cobro: sale de la cuenta donde entró, al tipo de cambio del cobro. */
    public static function refund(string $tenantId, string $refundId, ?string $userId): ?string
    {
        $refund = DB::table('refunds')->where('tenant_id', $tenantId)->where('id', $refundId)->first();
        $payment = $refund ? DB::table('payments')->where('id', $refund->payment_id)->first() : null;
        if (! $refund || ! $payment) {
            return null;
        }
        $amount = round((float) $refund->amount * self::rate($payment->currency, $payment->exchange_rate), 2);
        $account = $payment->financial_account_id ? FinancialAccount::where('tenant_id', $tenantId)->find($payment->financial_account_id) : null;

        return Ledger::post($tenantId, self::day($refund->created_at), "Reembolso de cobro {$payment->payment_number} · {$refund->reason}",
            ['type' => 'REFUND', 'id' => $refund->id, 'number' => $payment->payment_number], [
                ['account' => '1212', 'debit' => $amount, 'party_id' => self::customerParty($payment->customer_id)],
                ['account' => Ledger::cashAccount($account, $payment->payment_method === 'EFECTIVO' ? 'CASH' : 'BANK', $payment->currency), 'credit' => $amount],
            ], $userId);
    }

    /** Constancia de detracción o certificado de retención que entrega el cliente (importe en soles). */
    public static function withholding(string $tenantId, string $invoiceId, ?string $userId): ?string
    {
        $invoice = DB::table('invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->first();
        if (! $invoice || $invoice->withholding_status !== 'RECEIVED' || ! $invoice->withholding_type) {
            return null;
        }
        $detraction = $invoice->withholding_type === 'DETRACTION';
        $amount = round((float) $invoice->withholding_amount, 2);
        $bank = FinancialAccount::where('tenant_id', $tenantId)->where('account_type', 'DETRACTION')->where('status', 'ACTIVE')->orderBy('created_at')->first();

        return Ledger::post($tenantId, self::day($invoice->withholding_date ?? now()),
            ($detraction ? 'Detracción depositada · ' : 'Retención · ').$invoice->invoice_number.' · '.($detraction ? 'constancia ' : 'certificado ').$invoice->withholding_number,
            ['type' => 'WITHHOLDING', 'id' => $invoice->id, 'number' => $invoice->invoice_number], [
                ['account' => $detraction ? Ledger::cashAccount($bank, 'DETRACTION') : '40113', 'debit' => $amount],
                ['account' => '1212', 'credit' => $amount, 'party_id' => self::customerParty($invoice->customer_id)],
            ], $userId);
    }

    /**
     * Diferencia de cambio al aplicar un cobro en dólares a una cuenta nacida a otro tipo de cambio.
     * Positiva = ganancia. Un asiento por aplicación (movimiento de la cuenta por cobrar).
     */
    public static function receivableFx(string $tenantId, string $movementId, float $difference, string $date, string $reference, ?string $customerId, ?string $userId): ?string
    {
        $difference = round($difference, 2);
        if (abs($difference) < 0.01) {
            return null;
        }
        $party = self::customerParty($customerId);
        $lines = $difference > 0
            ? [['account' => '1212', 'debit' => $difference, 'party_id' => $party], ['account' => '7761', 'credit' => $difference]]
            : [['account' => '6761', 'debit' => -$difference], ['account' => '1212', 'credit' => -$difference, 'party_id' => $party]];

        return Ledger::post($tenantId, $date, "Diferencia de cambio · {$reference}", ['type' => 'FX', 'id' => $movementId, 'number' => $reference], $lines, $userId);
    }

    /** Comprobante de proveedor aprobado para pago. */
    public static function supplierInvoice(string $tenantId, string $invoiceId, ?string $userId): ?string
    {
        $invoice = DB::table('supplier_invoices')->where('tenant_id', $tenantId)->where('id', $invoiceId)->first();
        if (! $invoice || $invoice->status !== 'APPROVED') {
            return null;
        }
        $rate = self::rate($invoice->currency, $invoice->exchange_rate);
        $pen = fn ($value) => round((float) $value * $rate, 2);
        $retention = round((float) $invoice->retention_4ta, 2);
        $party = DB::table('suppliers')->where('id', $invoice->supplier_id)->value('party_id');
        $expense = Chart::postable($tenantId, $invoice->expense_account) ? $invoice->expense_account : '6392';

        return Ledger::post($tenantId, self::day($invoice->issue_date),
            "Compra {$invoice->doc_number} · {$invoice->supplier_name}".self::fxNote($invoice->currency, $rate),
            ['type' => 'SUPPLIER_INVOICE', 'id' => $invoice->id, 'number' => $invoice->doc_number], [
                ['account' => $expense, 'debit' => $pen($invoice->subtotal), 'cost_center_id' => $invoice->cost_center_id],
                ['account' => '40111', 'debit' => $pen($invoice->tax), 'note' => 'IGV crédito fiscal'],
                ['account' => '4212', 'credit' => $pen((float) $invoice->total - $retention), 'party_id' => $party],
                ['account' => '40172', 'credit' => $pen($retention), 'note' => 'Retención de 4.ª categoría'],
            ], $userId);
    }

    /**
     * Pago a proveedor: baja la cuenta por pagar por lo pagado y la detracción depositada; sale el
     * neto del banco y la detracción de la cuenta en soles de la que se depositó. En dólares, lo
     * que cambie entre el tipo de cambio del comprobante y el del día del pago es diferencia de cambio.
     */
    public static function supplierPayment(string $tenantId, string $paymentId, ?string $userId): ?string
    {
        $payment = DB::table('supplier_payments')->where('tenant_id', $tenantId)->where('id', $paymentId)->first();
        if (! $payment) {
            return null;
        }
        $party = DB::table('suppliers')->where('id', $payment->supplier_id)->value('party_id');
        $applied = DB::table('supplier_payment_allocations as a')
            ->join('payables as p', 'p.id', '=', 'a.payable_id')
            ->join('supplier_invoices as i', 'i.id', '=', 'p.supplier_invoice_id')
            ->where('a.supplier_payment_id', $payment->id)
            ->get(['a.amount', 'i.currency', 'i.exchange_rate']);
        $payable = round($applied->sum(fn ($row) => (float) $row->amount * self::rate($row->currency, $row->exchange_rate)), 2);

        $payRate = 1.0;
        if ($payment->currency === 'USD') {
            // El del día del pago; sin tipo de cambio registrado, el del comprobante (sin diferencia).
            $payRate = (float) (ExchangeRate::current('USD', self::day($payment->paid_at))?->sell ?? 0);
            if ($payRate <= 0) {
                $payRate = (float) ($applied->first()->exchange_rate ?? 1);
            }
        }
        $net = round((float) $payment->amount * $payRate, 2);
        $detraction = round((float) $payment->detraction_amount, 2);
        $difference = round($payable - $net - $detraction, 2);

        $account = FinancialAccount::where('tenant_id', $tenantId)->find($payment->financial_account_id);
        $detractionAccount = $payment->detraction_account_id ? FinancialAccount::where('tenant_id', $tenantId)->find($payment->detraction_account_id) : null;
        $lines = [
            ['account' => '4212', 'debit' => $payable, 'party_id' => $party],
            ['account' => Ledger::cashAccount($account, 'BANK', $payment->currency), 'credit' => $net],
            ['account' => Ledger::cashAccount($detractionAccount), 'credit' => $detraction, 'note' => 'Depósito de detracción en cuenta BN del proveedor'],
        ];
        // Pagar menos soles de los que se debían es ganancia; más, pérdida.
        if (abs($difference) >= 0.05) {
            $lines[] = $difference > 0 ? ['account' => '7761', 'credit' => $difference] : ['account' => '6761', 'debit' => -$difference];
        }

        return Ledger::post($tenantId, self::day($payment->paid_at), "Pago a proveedor {$payment->payment_number}".($payment->currency === 'USD' ? " (US$ a T.C. {$payRate})" : ''),
            ['type' => 'SUPPLIER_PAYMENT', 'id' => $payment->id, 'number' => $payment->payment_number], $lines, $userId);
    }

    /** Gasto de caja chica. La cuenta y el centro de costo se toman del gasto si son del plan y de la empresa. */
    public static function cashExpense(string $tenantId, string $expenseId, ?string $userId): ?string
    {
        $expense = DB::table('cash_expenses')->where('tenant_id', $tenantId)->where('id', $expenseId)->first();
        if (! $expense) {
            return null;
        }
        $code = preg_match('/^\s*(\d{2,10})/', (string) $expense->expense_account, $match) ? $match[1] : null;
        $expenseAccount = Chart::postable($tenantId, $code, ['EXPENSE']) ? $code : self::CASH_EXPENSE_ACCOUNT;
        // Los gastos nuevos traen su centro enlazado; en los antiguos (texto libre) se busca por código.
        $centerCode = trim(explode('·', (string) $expense->cost_center)[0]);
        $center = $expense->cost_center_id ?? ($centerCode !== ''
            ? DB::table('cost_centers')->where('tenant_id', $tenantId)->whereRaw('upper(code) = upper(?)', [$centerCode])->value('id')
            : null);
        $cash = FinancialAccount::where('tenant_id', $tenantId)->find($expense->account_id);
        $amount = round((float) $expense->amount, 2);

        return Ledger::post($tenantId, self::day($expense->expense_date), "Caja chica · {$expense->description}",
            ['type' => 'CASH_EXPENSE', 'id' => $expense->id, 'number' => $expense->expense_number], [
                ['account' => $expenseAccount, 'debit' => $amount, 'cost_center_id' => $center],
                ['account' => Ledger::cashAccount($cash, 'CASH'), 'credit' => $amount],
            ], $userId);
    }

    /** Movimiento de banco que nace del extracto (comisión o abono por identificar) o un saldo de apertura. */
    public static function bankMovement(string $tenantId, string $movementId, ?string $userId): ?string
    {
        $movement = DB::table('bank_movements')->where('tenant_id', $tenantId)->where('id', $movementId)->first();
        if (! $movement || ! in_array($movement->ref_type, ['BANK_FEE', 'UNIDENTIFIED', 'OPENING'], true)) {
            return null;
        }
        $account = FinancialAccount::where('tenant_id', $tenantId)->find($movement->account_id);
        $amount = round(abs((float) $movement->amount) * self::currentRate($account?->currency, $movement->movement_date), 2);
        $cash = Ledger::cashAccount($account);
        $label = $account ? ($account->bank_name ?: $account->name) : '';

        [$description, $lines] = match ($movement->ref_type) {
            'BANK_FEE' => ["Comisión bancaria {$label} · ".($movement->op_number ?: $movement->description),
                [['account' => '6391', 'debit' => $amount], ['account' => $cash, 'credit' => $amount]]],
            'UNIDENTIFIED' => ["Abono por identificar · {$movement->description}",
                [['account' => $cash, 'debit' => $amount], ['account' => '122', 'credit' => $amount, 'note' => 'Anticipo / partida por identificar']]],
            default => ["Saldo de apertura · {$label}",
                [['account' => $cash, 'debit' => $amount], ['account' => '5911', 'credit' => $amount, 'note' => 'Saldo de apertura']]],
        };

        return Ledger::post($tenantId, self::day($movement->movement_date), $description,
            ['type' => $movement->ref_type, 'id' => $movement->id, 'number' => $movement->op_number], $lines, $userId);
    }

    /** Transferencia entre cuentas propias (también la reposición de una caja). */
    public static function transfer(string $tenantId, string $transferId, ?string $userId): ?string
    {
        $movements = DB::table('bank_movements')->where('tenant_id', $tenantId)->where('ref_type', 'TRANSFER')->where('ref_id', $transferId)->get();
        $out = $movements->first(fn ($movement) => (float) $movement->amount < 0);
        $in = $movements->first(fn ($movement) => (float) $movement->amount > 0);
        if (! $out || ! $in) {
            return null;
        }
        $from = FinancialAccount::where('tenant_id', $tenantId)->find($out->account_id);
        $to = FinancialAccount::where('tenant_id', $tenantId)->find($in->account_id);
        $amount = round((float) $in->amount * self::currentRate($to?->currency, $in->movement_date), 2);

        return Ledger::post($tenantId, self::day($in->movement_date), 'Transferencia '.($from?->name ?? '').' → '.($to?->name ?? ''),
            ['type' => 'TRANSFER', 'id' => $transferId, 'number' => $in->op_number], [
                ['account' => Ledger::cashAccount($to), 'debit' => $amount],
                ['account' => Ledger::cashAccount($from), 'credit' => $amount],
            ], $userId);
    }

    /** Tipo de cambio del documento (1 en soles). */
    private static function rate(?string $currency, $stored): float
    {
        return $currency === 'USD' && (float) $stored > 0 ? (float) $stored : 1.0;
    }

    /** Tipo de cambio de venta a una fecha, para lo que no guarda el suyo (movimientos de banco en dólares). */
    private static function currentRate(?string $currency, $date): float
    {
        if ($currency !== 'USD') {
            return 1.0;
        }

        return (float) (ExchangeRate::current('USD', self::day($date))?->sell ?? 1);
    }

    private static function fxNote(?string $currency, float $rate): string
    {
        return $currency === 'USD' ? " (US$ a T.C. {$rate})" : '';
    }

    private static function day($value): string
    {
        return Carbon::parse($value ?? now())->toDateString();
    }

    private static function customerParty(?string $customerId): ?string
    {
        return $customerId ? DB::table('customers')->where('id', $customerId)->value('party_id') : null;
    }

    private static function methodLabel(?string $method): string
    {
        return match ($method) {
            'TRANSFERENCIA' => 'Transferencia', 'DEPOSITO' => 'Depósito', 'EFECTIVO' => 'Efectivo', 'CHEQUE' => 'Cheque', 'PASARELA' => 'Pasarela',
            default => (string) $method,
        };
    }
}
