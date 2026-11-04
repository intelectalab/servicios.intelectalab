<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Posting;
use App\Modules\Core\Models\Customer;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Models\ReceivableMovement;
use App\Modules\Finance\Models\Refund;
use App\Modules\Finance\Support\Billing;
use App\Modules\Finance\Support\Treasury;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\LocalTime;

/**
 * Cobros: se registran, se aplican a una o varias cuentas por cobrar del cliente (de la misma
 * moneda, la más antigua primero), se concilian con el banco y, si hace falta, se reembolsan.
 * Lo que no se aplica queda como anticipo. Un reembolso nunca borra el cobro.
 */
class PaymentController extends Controller
{
    private const OPTIONS_LIMIT = 500;

    private const WITH = ['customer.party.person', 'customer.party.organization'];

    private const ACCOUNT_NAME = '(SELECT fa.name FROM financial_accounts fa WHERE fa.id = payments.financial_account_id)';

    private const ALLOCATED = '(SELECT coalesce(sum(pa.amount), 0) FROM payment_allocations pa WHERE pa.payment_id = payments.id)';

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $reconciled = $request->query('reconciled');

        $query = $this->listing()
            ->when(isset(Payment::STATES[$request->query('status')]), fn ($q) => $q->where('payments.status', $request->query('status')))
            ->when(in_array($reconciled, ['true', 'false'], true), fn ($q) => $q->where('payments.reconciled', $reconciled === 'true'))
            ->when(Str::isUuid((string) $request->query('customer_id')), fn ($q) => $q->where('payments.customer_id', $request->query('customer_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por recibo, cliente o número de operación.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('payments.payment_number', 'ILIKE', $like)
                    ->orWhere('payments.reference', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('payments').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'payment' => ColumnFilters::text("concat_ws(' ', payments.payment_number, payments.payment_method, payments.reference)"),
            'customer' => ColumnFilters::text(ListSort::customerName('payments')),
            'date' => ColumnFilters::date('payments.paid_at'),
            'amount' => ColumnFilters::number('payments.amount'),
            'allocated' => ColumnFilters::number(self::ALLOCATED),
            'account' => ColumnFilters::text(self::ACCOUNT_NAME),
        ]);

        ListSort::apply($query, $request, [
            'payment' => 'payments.payment_number',
            'customer' => 'lower('.ListSort::customerName('payments').')',
            'date' => 'payments.paid_at',
            'amount' => 'payments.amount',
            'allocated' => self::ALLOCATED,
            'account' => 'lower('.self::ACCOUNT_NAME.')',
            'status' => ListSort::labels('payments.status', Payment::STATES),
        ], fn ($q) => $q->orderByDesc('payments.paid_at')->orderByDesc('payments.created_at')->orderByDesc('payments.id'), 'payments.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (Payment $payment) => $this->row($payment));

        return response()->json($page);
    }

    /** Listas del formulario "Registrar pago": clientes, cuentas de destino y métodos. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'customers' => Customer::query()->with('party.person', 'party.organization')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
                ->orderByDesc('created_at')->limit(self::OPTIONS_LIMIT)->get()
                ->map(fn (Customer $customer) => ['id' => $customer->id, 'name' => Billing::identity($customer)['name'], 'currency' => $customer->preferred_currency === 'USD' ? 'USD' : 'PEN'])
                ->filter(fn (array $customer) => $customer['name'] !== '')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            // A la cuenta de detracciones solo entra lo que depositan los clientes por ese concepto.
            'accounts' => DB::table('financial_accounts')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')
                ->whereIn('account_type', ['BANK', 'CASH'])->orderBy('name')
                ->get(['id', 'name', 'currency', 'account_type']),
            'methods' => Payment::METHODS,
        ]);
    }

    public function show(string $payment)
    {
        return response()->json($this->detail($this->find($payment)));
    }

    /** Reembolsos de los cobros que la persona alcanza, del más reciente al más antiguo. */
    public function refunds()
    {
        return response()->json(
            Refund::query()->with('creator:id,name')
                ->join('payments', 'payments.id', '=', 'refunds.payment_id')
                // Por su modelo: solo los de los cobros dentro del alcance.
                ->whereIn('refunds.payment_id', Payment::query()->select('payments.id'))
                ->orderByDesc('refunds.created_at')->limit(100)
                ->get(['refunds.*', 'payments.payment_number', 'payments.currency'])
                ->map(fn (Refund $refund) => [
                    'id' => $refund->id, 'payment_id' => $refund->payment_id, 'payment_number' => $refund->payment_number,
                    'amount' => (float) $refund->amount, 'currency' => $refund->currency, 'reason' => $refund->reason,
                    'by' => $refund->creator?->name, 'at' => $refund->created_at?->toIso8601String(),
                ])->values()
        );
    }

    /** Registra un cobro confirmado y, si se pide, lo aplica a las cuentas más antiguas del cliente. */
    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'customer_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', 'in:PEN,USD'],
            'payment_method' => ['required', 'in:'.implode(',', Payment::METHODS)],
            // El efectivo no tiene número de operación.
            'reference' => ['nullable', 'required_unless:payment_method,EFECTIVO', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'financial_account_id' => ['nullable', 'uuid'],
            'auto_allocate' => ['sometimes', 'boolean'],
        ], [
            'customer_id.required' => 'Selecciona el cliente.',
            'amount.required' => 'Indica el monto.',
            'amount.gt' => 'El monto debe ser mayor a cero.',
            'reference.required_unless' => 'Indica el número de operación.',
            'paid_at.before_or_equal' => 'La fecha no puede ser futura.',
        ]);

        if (! Customer::where('tenant_id', $tenantId)->whereKey($data['customer_id'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => ['El cliente indicado no existe.']]);
        }

        $paidAt = isset($data['paid_at']) ? \Illuminate\Support\Carbon::parse($data['paid_at']) : now();
        $rate = null;
        if ($data['currency'] === 'USD') {
            // El tipo de cambio del día del cobro: con él se medirá la diferencia de cambio.
            $rate = ExchangeRate::current('USD', $paidAt->toDateString())?->sell;
            if (! $rate) {
                throw ValidationException::withMessages(['currency' => ['Registra el tipo de cambio antes de registrar un cobro en dólares.']]);
            }
        }

        $payment = DB::transaction(function () use ($request, $tenantId, $data, $paidAt, $rate) {
            // La cuenta a la que entra el dinero: la indicada o, si solo hay una posible, esa.
            $account = Treasury::destinationFor($tenantId, $data['currency'], $data['payment_method'], $data['financial_account_id'] ?? null);

            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'customer_id' => $data['customer_id'],
                'payment_number' => SequentialNumberGenerator::next($tenantId, 'payments', 'payment_number', 'PAG'),
                'amount' => round((float) $data['amount'], 2),
                'currency' => $data['currency'],
                'exchange_rate' => $rate,
                'payment_method' => $data['payment_method'],
                'reference' => isset($data['reference']) ? trim($data['reference']) : null,
                'source' => 'BACKOFFICE',
                'financial_account_id' => $account?->id,
                'status' => 'CONFIRMED',
                'paid_at' => $paidAt,
                'created_by' => $request->user()->id,
            ]);
            $this->audit($request, $payment, 'payment.create', ['to' => 'CONFIRMED', 'amount' => (float) $payment->amount, 'method' => $payment->payment_method]);
            if ($account) {
                // Todo cobro deja su movimiento en la cuenta: de ahí sale el saldo de bancos y caja.
                $customer = Customer::with('party.person', 'party.organization')->where('tenant_id', $tenantId)->find($payment->customer_id);
                Treasury::paymentIn($payment, $account, Billing::identity($customer)['name'], $request->user()->id);
            }
            Posting::payment($tenantId, $payment->id, $request->user()->id);

            if ($request->boolean('auto_allocate')) {
                $this->allocateInto($request, $payment, null);
            }

            return $payment;
        });

        return response()->json($this->detail($payment), 201);
    }

    /**
     * Aplica el saldo libre del cobro a cuentas por cobrar del cliente: las indicadas o, si no se
     * indica ninguna, todas las pendientes de su moneda, la más antigua primero.
     */
    public function allocate(string $payment, Request $request)
    {
        $payment = $this->find($payment);
        $data = $request->validate([
            'receivable_ids' => ['sometimes', 'array', 'max:200'],
            'receivable_ids.*' => ['uuid', 'distinct'],
        ]);

        if (! in_array($payment->status, Payment::LIVE, true)) {
            throw ValidationException::withMessages(['status' => ['No se puede aplicar un cobro '.mb_strtolower(Payment::STATES[$payment->status] ?? $payment->status).'.']]);
        }

        DB::transaction(function () use ($request, $payment, $data) {
            // El cobro se bloquea hasta terminar: dos aplicaciones a la vez no reparten el mismo saldo.
            $payment = Payment::ignoringAccessScope()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($this->free($payment) < 0.01) {
                throw ValidationException::withMessages(['status' => ['El cobro no tiene saldo por aplicar.']]);
            }

            if ($this->allocateInto($request, $payment, $data['receivable_ids'] ?? null) < 0.01) {
                throw ValidationException::withMessages([
                    'receivable_ids' => ["El cliente no tiene cuentas pendientes en {$payment->currency}. El saldo queda como anticipo."],
                ]);
            }
        });

        return response()->json($this->detail($payment));
    }

    /** Marca el cobro como conciliado con el extracto bancario. */
    public function reconcile(string $payment, Request $request)
    {
        $payment = $this->find($payment);
        if ($payment->reconciled) {
            throw ValidationException::withMessages(['status' => ['El cobro ya está conciliado.']]);
        }
        if (! in_array($payment->status, Payment::LIVE, true)) {
            throw ValidationException::withMessages(['status' => ['No se concilia un cobro '.mb_strtolower(Payment::STATES[$payment->status] ?? $payment->status).'.']]);
        }

        DB::transaction(function () use ($request, $payment) {
            $payment->update(['reconciled' => true, 'reconciled_at' => now(), 'reconciled_by' => $request->user()->id]);
            Treasury::reconcilePayment($payment);
            $this->audit($request, $payment, 'payment.reconcile');
        });

        return response()->json($this->detail($payment));
    }

    /**
     * Reembolsa todo o parte del cobro. Sale primero de lo que no estaba aplicado; si no alcanza,
     * se revierten sus aplicaciones (la más reciente primero) y esas cuentas vuelven a tener saldo.
     */
    public function refund(string $payment, Request $request)
    {
        $payment = $this->find($payment);
        if (! in_array($payment->status, Payment::LIVE, true)) {
            throw ValidationException::withMessages(['status' => ['El cobro no admite reembolso.']]);
        }
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'amount.required' => 'Indica el monto a reembolsar.',
            'amount.gt' => 'El monto a reembolsar debe ser mayor a cero.',
            'reason.required' => 'Indica el motivo.',
        ]);
        $amount = round((float) $data['amount'], 2);
        $reason = trim($data['reason']);
        $tenantId = $request->attributes->get('tenant_id');

        DB::transaction(function () use ($request, $payment, $tenantId, $amount, $reason) {
            $payment = Payment::ignoringAccessScope()->whereKey($payment->id)->lockForUpdate()->first();
            $refundable = round((float) $payment->amount - (float) $payment->refunded_amount, 2);
            if ($amount > $refundable + 0.001) {
                throw ValidationException::withMessages(['amount' => ['El monto supera lo que queda por reembolsar de este cobro.']]);
            }

            $rest = round($amount - min($this->free($payment), $amount), 2);
            // La aplicación más reciente primero; entre las de un mismo momento, la deuda más nueva
            // (las cuentas más antiguas se quedan pagadas).
            $allocations = PaymentAllocation::query()->select('payment_allocations.*')
                ->join('receivables', 'receivables.id', '=', 'payment_allocations.receivable_id')
                ->where('payment_allocations.payment_id', $payment->id)
                ->orderByDesc('payment_allocations.created_at')->orderByRaw('receivables.due_date DESC NULLS FIRST')
                ->orderByDesc('receivables.created_at')->orderByDesc('payment_allocations.id')
                ->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                if ($rest < 0.01) {
                    break;
                }
                $back = round(min((float) $allocation->amount, $rest), 2);
                // La cuenta recupera ese saldo: lo aplica la base con el movimiento.
                ReceivableMovement::create([
                    'tenant_id' => $tenantId, 'receivable_id' => $allocation->receivable_id, 'movement_type' => 'REVERSAL',
                    'amount' => $back, 'reference' => $payment->payment_number, 'payment_id' => $payment->id, 'created_by' => $request->user()->id,
                ]);
                $left = round((float) $allocation->amount - $back, 2);
                $left < 0.01 ? $allocation->delete() : $allocation->update(['amount' => $left]);
                $rest = round($rest - $back, 2);
            }

            $refund = Refund::create([
                'tenant_id' => $tenantId, 'payment_id' => $payment->id, 'amount' => $amount, 'reason' => $reason,
                'status' => 'PROCESSED', 'created_by' => $request->user()->id,
            ]);
            // El dinero sale de la cuenta donde entró el cobro.
            Treasury::refundOut($payment, $refund->id, $amount, $request->user()->id);
            Posting::refund($tenantId, $refund->id, $request->user()->id);
            $previous = $payment->status;
            $payment->refunded_amount = round((float) $payment->refunded_amount + $amount, 2);
            $payment->status = $this->statusOf($payment);
            $payment->save();

            $this->audit($request, $payment, 'payment.refund', array_filter([
                'from' => $previous !== $payment->status ? $previous : null, 'to' => $previous !== $payment->status ? $payment->status : null,
                'amount' => $amount, 'note' => ($payment->currency === 'USD' ? 'US$ ' : 'S/ ').number_format($amount, 2).": {$reason}",
            ]));
        });

        return response()->json($this->detail($payment));
    }

    private function find(string $payment): Payment
    {
        abort_unless(Str::isUuid($payment), 404);

        return Payment::findOrFail($payment);
    }

    private function listing()
    {
        return Payment::query()->with(self::WITH)
            ->select('payments.*')
            ->selectRaw(self::ALLOCATED.' AS allocated')
            ->selectRaw(self::ACCOUNT_NAME.' AS account_name');
    }

    /** Lo aplicado hoy a cuentas por cobrar. */
    private function allocated(Payment $payment): float
    {
        return round((float) DB::table('payment_allocations')->where('payment_id', $payment->id)->sum('amount'), 2);
    }

    /** Lo que queda del cobro sin aplicar ni reembolsar. */
    private function free(Payment $payment): float
    {
        return round((float) $payment->amount - (float) $payment->refunded_amount - $this->allocated($payment), 2);
    }

    private function statusOf(Payment $payment): string
    {
        $allocated = $this->allocated($payment);

        return match (true) {
            (float) $payment->refunded_amount >= (float) $payment->amount - 0.009 => 'REFUNDED',
            $allocated < 0.01 => 'CONFIRMED',
            $allocated + (float) $payment->refunded_amount >= (float) $payment->amount - 0.009 => 'ALLOCATED',
            default => 'PARTIALLY_ALLOCATED',
        };
    }

    /**
     * Reparte el saldo libre del cobro entre cuentas por cobrar del cliente y de su moneda, la
     * más antigua primero. Devuelve lo aplicado. Debe llamarse dentro de una transacción.
     *
     * @param  string[]|null  $receivableIds  cuentas elegidas; null = todas las pendientes
     */
    private function allocateInto(Request $request, Payment $payment, ?array $receivableIds): float
    {
        $available = $this->free($payment);

        // Por su modelo: solo las cuentas que la persona alcanza. Se bloquean hasta terminar.
        $targets = Receivable::query()
            ->where('customer_id', $payment->customer_id)->where('currency', $payment->currency)->whereIn('status', Receivable::OPEN)
            ->when($receivableIds !== null, fn ($q) => $q->whereIn('id', $receivableIds))
            ->orderByRaw('due_date ASC NULLS LAST')->orderBy('created_at')->orderBy('id')
            ->lockForUpdate()->get();

        $applied = 0.0;
        foreach ($targets as $receivable) {
            if ($available < 0.01) {
                break;
            }
            $amount = round(min($available, (float) $receivable->balance_amount), 2);
            if ($amount < 0.01) {
                continue;
            }

            // En dólares la cuenta nació al tipo de cambio de la emisión y se cobra al del día del cobro.
            $difference = 0.0;
            if ($payment->currency === 'USD' && $payment->exchange_rate) {
                $issuedAt = (float) DB::table('invoices')->where('id', $receivable->invoice_id)->value('exchange_rate');
                $difference = $issuedAt > 0 ? round($amount * ((float) $payment->exchange_rate - $issuedAt), 2) : 0.0;
            }

            $allocation = PaymentAllocation::where('payment_id', $payment->id)->where('receivable_id', $receivable->id)->first();
            if ($allocation) {
                $allocation->update(['amount' => round((float) $allocation->amount + $amount, 2), 'fx_difference_pen' => round((float) $allocation->fx_difference_pen + $difference, 2)]);
            } else {
                PaymentAllocation::create([
                    'tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'receivable_id' => $receivable->id,
                    'amount' => $amount, 'fx_difference_pen' => $difference,
                ]);
            }
            // El saldo y el estado de la cuenta los actualiza la base con este movimiento.
            $movement = ReceivableMovement::create([
                'tenant_id' => $payment->tenant_id, 'receivable_id' => $receivable->id, 'movement_type' => 'PAYMENT',
                'amount' => -1 * $amount, 'reference' => $payment->payment_number, 'payment_id' => $payment->id, 'created_by' => $request->user()->id,
            ]);
            // La diferencia de cambio de esta aplicación va al libro diario.
            Posting::receivableFx($payment->tenant_id, $movement->id, $difference, LocalTime::today()->toDateString(), $payment->payment_number, $payment->customer_id, $request->user()->id);

            $available = round($available - $amount, 2);
            $applied = round($applied + $amount, 2);
        }

        if ($applied >= 0.01) {
            $previous = $payment->status;
            $payment->update(['status' => $this->statusOf($payment)]);
            $this->audit($request, $payment, 'payment.allocate', array_filter([
                'from' => $previous !== $payment->status ? $previous : null, 'to' => $previous !== $payment->status ? $payment->status : null,
                'amount' => $applied,
            ]));
        }

        return $applied;
    }

    private function row(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'source' => $payment->source,
            'customer' => $payment->customer,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'refunded_amount' => (float) $payment->refunded_amount,
            'allocated' => round((float) $payment->allocated, 2),
            'account_name' => $payment->account_name,
            'status' => $payment->status,
            'reconciled' => (bool) $payment->reconciled,
            'lock_version' => (int) $payment->lock_version,
        ];
    }

    private function detail(Payment $payment): array
    {
        $payment = $this->listing()->with('creator:id,name', 'refunds.creator:id,name')->findOrFail($payment->id);
        $row = $this->row($payment);

        $invoiceNumber = '(SELECT i.invoice_number FROM invoices i WHERE i.id = receivables.invoice_id)';

        return [
            ...$row,
            'free' => round($row['amount'] - $row['refunded_amount'] - $row['allocated'], 2),
            'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
            'reconciled_at' => $payment->reconciled_at?->toIso8601String(),
            'creator' => $payment->creator?->name,
            'allocations' => DB::table('payment_allocations')
                ->join('receivables', 'receivables.id', '=', 'payment_allocations.receivable_id')
                ->where('payment_allocations.payment_id', $payment->id)
                ->orderBy('payment_allocations.created_at')
                ->selectRaw("payment_allocations.receivable_id, payment_allocations.amount, payment_allocations.created_at, receivables.invoice_id, {$invoiceNumber} AS invoice_number")
                ->get()
                ->map(fn ($allocation) => [
                    'receivable_id' => $allocation->receivable_id, 'invoice_id' => $allocation->invoice_id, 'invoice_number' => $allocation->invoice_number,
                    'amount' => (float) $allocation->amount, 'at' => \Illuminate\Support\Carbon::parse($allocation->created_at)->toIso8601String(),
                ])->values(),
            // Cuentas del cliente a las que todavía se puede aplicar, por su modelo: las que la persona alcanza.
            'open_receivables' => in_array($payment->status, Payment::LIVE, true)
                ? Receivable::query()->where('customer_id', $payment->customer_id)->where('currency', $payment->currency)->whereIn('status', Receivable::OPEN)
                    ->select('receivables.*')->selectRaw("{$invoiceNumber} AS invoice_number")
                    ->orderByRaw('due_date ASC NULLS LAST')->orderBy('created_at')->limit(200)->get()
                    ->map(fn (Receivable $receivable) => [
                        'id' => $receivable->id, 'invoice_number' => $receivable->invoice_number,
                        'due_date' => $receivable->due_date?->toDateString(), 'balance_amount' => (float) $receivable->balance_amount,
                    ])->values()
                : [],
            'refunds' => $payment->refunds->map(fn (Refund $refund) => [
                'id' => $refund->id, 'amount' => (float) $refund->amount, 'reason' => $refund->reason,
                'by' => $refund->creator?->name, 'at' => $refund->created_at?->toIso8601String(),
            ])->values(),
            'history' => AuditHistory::for('Payment', $payment->id),
        ];
    }

    private function audit(Request $request, Payment $payment, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Payment',
            entityId: $payment->id,
            metadata: $metadata,
        );
    }
}
