<?php

namespace App\Modules\Finance\Support;

use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bancos y caja: todo cobro, reembolso, transferencia, gasto de caja o constancia de detracción
 * deja su movimiento en la cuenta. El saldo de una cuenta es la suma de sus movimientos.
 */
class Treasury
{
    /** Origen del movimiento => texto visible. */
    public const REF_TYPES = [
        'OPENING' => 'Apertura', 'PAYMENT' => 'Cobro', 'REFUND' => 'Reembolso', 'TRANSFER' => 'Transferencia', 'CASH_EXPENSE' => 'Caja chica',
        'BANK_FEE' => 'Comisión', 'DETRACTION' => 'Detracción', 'UNIDENTIFIED' => 'Por identificar', 'SUPPLIER_PAYMENT' => 'Pago proveedor', 'MANUAL' => 'Manual',
        'SUPPLIER_PAYMENT_REVERSAL' => 'Reversión de pago',
    ];

    /** Saldo en libros de una cuenta (expresión SQL sobre `financial_accounts`). */
    public const BALANCE = '(SELECT coalesce(sum(m.amount), 0) FROM bank_movements m WHERE m.account_id = financial_accounts.id)';

    public static function balance(string $accountId): float
    {
        return round((float) DB::table('bank_movements')->where('account_id', $accountId)->sum('amount'), 2);
    }

    /**
     * Bloquea la cuenta hasta que termine la transacción: dos operaciones a la vez no gastan el
     * mismo saldo. Devuelve null si no existe en esta empresa.
     */
    public static function lock(string $tenantId, ?string $accountId): ?FinancialAccount
    {
        return $accountId && Str::isUuid($accountId)
            ? FinancialAccount::where('tenant_id', $tenantId)->whereKey($accountId)->lockForUpdate()->first()
            : null;
    }

    /** Anota un movimiento. El de una caja nace conciliado: una caja no tiene extracto. */
    public static function move(FinancialAccount $account, float $amount, string $refType, string $description, array $data = []): string
    {
        $id = (string) Str::uuid();
        $reconciled = $data['reconciled'] ?? ($account->account_type === 'CASH' || $refType === 'OPENING');
        DB::table('bank_movements')->insert([
            'id' => $id,
            'tenant_id' => $account->tenant_id,
            'account_id' => $account->id,
            'movement_date' => Carbon::parse($data['date'] ?? now())->toDateString(),
            'description' => Str::limit($description, 500, ''),
            'amount' => round($amount, 2),
            'ref_type' => $refType,
            'ref_id' => $data['ref_id'] ?? null,
            'op_number' => isset($data['op_number']) && $data['op_number'] !== '' ? Str::limit((string) $data['op_number'], 255, '') : null,
            'reconciled' => $reconciled,
            'reconciled_at' => $reconciled ? now() : null,
            'statement_line_id' => $data['statement_line_id'] ?? null,
            'created_by' => $data['user_id'] ?? null,
        ]);

        return $id;
    }

    /**
     * Cuenta a la que entra un cobro. Si no se indica y la empresa tiene una sola posible (la caja
     * para el efectivo, el banco de esa moneda para lo demás), va a esa; si tiene varias hay que
     * elegir, para no dejar el dinero en la cuenta equivocada. Sin cuentas, el cobro queda sin cuenta.
     */
    public static function destinationFor(string $tenantId, string $currency, string $method, ?string $accountId): ?FinancialAccount
    {
        if ($accountId) {
            $account = self::lock($tenantId, $accountId);
            if (! $account || $account->status !== 'ACTIVE' || $account->account_type === 'DETRACTION') {
                throw ValidationException::withMessages(['financial_account_id' => ['La cuenta de destino no existe.']]);
            }
            if ($account->currency !== $currency) {
                throw ValidationException::withMessages(['financial_account_id' => ["La cuenta {$account->name} está en {$account->currency}."]]);
            }

            return $account;
        }

        $candidates = FinancialAccount::where('tenant_id', $tenantId)->where('status', 'ACTIVE')->where('currency', $currency)
            ->where('account_type', $method === 'EFECTIVO' ? 'CASH' : 'BANK')->orderBy('name')->limit(2)->pluck('id');
        if ($candidates->count() > 1) {
            throw ValidationException::withMessages(['financial_account_id' => ['Selecciona la cuenta de destino: hay más de una posible para este cobro.']]);
        }

        return $candidates->count() === 1 ? self::lock($tenantId, $candidates->first()) : null;
    }

    /** Movimiento de ingreso de un cobro en su cuenta de destino. */
    public static function paymentIn(Payment $payment, FinancialAccount $account, string $customerName, ?string $userId): void
    {
        self::move($account, (float) $payment->amount, 'PAYMENT', trim("Cobro {$payment->payment_number} · {$customerName}", ' ·'), [
            'date' => $payment->paid_at, 'ref_id' => $payment->id, 'op_number' => $payment->reference, 'user_id' => $userId,
        ]);
    }

    /** Egreso por el reembolso de un cobro, de la cuenta donde entró. Una caja no reembolsa más de lo que tiene. */
    public static function refundOut(Payment $payment, string $refundId, float $amount, ?string $userId): void
    {
        $account = self::lock($payment->tenant_id, $payment->financial_account_id);
        if (! $account) {
            return;
        }
        if ($account->account_type === 'CASH' && self::balance($account->id) < $amount - 0.001) {
            throw ValidationException::withMessages(['amount' => ["Saldo insuficiente en {$account->name} para reembolsar ese monto."]]);
        }

        self::move($account, -1 * $amount, 'REFUND', "Reembolso de {$payment->payment_number}", ['ref_id' => $refundId, 'user_id' => $userId]);
    }

    /** Al conciliar un cobro a mano, su movimiento en libros queda conciliado también. */
    public static function reconcilePayment(Payment $payment): void
    {
        DB::table('bank_movements')->where('ref_type', 'PAYMENT')->where('ref_id', $payment->id)->where('reconciled', false)
            ->update(['reconciled' => true, 'reconciled_at' => now()]);
    }

    /**
     * La detracción que depositó el cliente entra a la cuenta de detracciones de la empresa, si
     * tiene una registrada. Devuelve si quedó anotada.
     */
    public static function detractionIn(Invoice $invoice, float $amountPen, string $number, string $date, ?string $userId): bool
    {
        $accountId = FinancialAccount::where('tenant_id', $invoice->tenant_id)->where('account_type', 'DETRACTION')->where('status', 'ACTIVE')
            ->orderBy('created_at')->value('id');
        $account = self::lock($invoice->tenant_id, $accountId);
        if (! $account) {
            return false;
        }

        self::move($account, $amountPen, 'DETRACTION', "Detracción depositada por {$invoice->customer_name} · {$invoice->invoice_number}", [
            'date' => $date, 'ref_id' => $invoice->id, 'op_number' => $number, 'user_id' => $userId,
        ]);

        return true;
    }
}
