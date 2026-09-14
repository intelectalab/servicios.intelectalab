<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Http\Requests\StorePaymentRequest;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Models\Receivable;
use App\Services\AuditLogger;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(Payment::query()->paginate($request->integer('per_page', 20)));
    }

    public function receivables(Request $request)
    {
        $query = Receivable::query()
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->query('customer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));

        return response()->json($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Registra un pago y lo aplica contra una o varias cuentas por cobrar.
     * Regla dura: el monto aplicado nunca puede exceder el saldo pendiente
     * de cada cuenta (verificado con bloqueo de fila para evitar condiciones
     * de carrera si dos pagos se aplican al mismo tiempo).
     */
    public function store(StorePaymentRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        $totalAllocated = collect($request->allocations)->sum('amount');
        if (round($totalAllocated, 4) != round((float) $request->amount, 4)) {
            throw ValidationException::withMessages([
                'allocations' => ['La suma de las aplicaciones debe ser igual al monto total del pago.'],
            ]);
        }

        $payment = DB::transaction(function () use ($request, $tenantId) {
            $paymentNumber = SequentialNumberGenerator::next($tenantId, 'payments', 'payment_number', 'PAG');

            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'customer_id' => $request->customer_id,
                'payment_number' => $paymentNumber,
                'amount' => $request->amount,
                'currency' => $request->currency ?? 'PEN',
                'payment_method' => $request->payment_method,
                'status' => 'CONFIRMED',
                'paid_at' => now(),
            ]);

            foreach ($request->allocations as $allocation) {
                // Bloqueamos la fila para evitar que dos pagos simultáneos
                // sobre-apliquen el mismo saldo pendiente.
                $receivable = Receivable::where('id', $allocation['receivable_id'])->lockForUpdate()->firstOrFail();

                $amountToApply = (float) $allocation['amount'];

                if ($amountToApply > (float) $receivable->balance_amount) {
                    throw ValidationException::withMessages([
                        'allocations' => ["El monto aplicado (S/ {$amountToApply}) excede el saldo pendiente de la cuenta por cobrar (S/ {$receivable->balance_amount})."],
                    ]);
                }

                PaymentAllocation::create([
                    'tenant_id' => $tenantId,
                    'payment_id' => $payment->id,
                    'receivable_id' => $receivable->id,
                    'amount' => $amountToApply,
                ]);

                $newBalance = round((float) $receivable->balance_amount - $amountToApply, 4);
                $receivable->update([
                    'balance_amount' => $newBalance,
                    'status' => $newBalance <= 0 ? 'PAID' : 'OPEN',
                ]);
            }

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'payment.register',
                entityType: 'Payment',
                entityId: $payment->id,
                metadata: ['payment_number' => $paymentNumber, 'amount' => (string) $request->amount],
            );

            return $payment;
        });

        return response()->json($payment, 201);
    }
}