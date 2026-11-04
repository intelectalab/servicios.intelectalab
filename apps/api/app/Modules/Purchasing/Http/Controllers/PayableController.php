<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Ledger;
use App\Modules\Accounting\Support\Posting;
use App\Http\Middleware\CheckPermission;
use App\Modules\Finance\Models\FinancialAccount;
use App\Modules\Finance\Support\Treasury;
use App\Modules\Purchasing\Models\Payable;
use App\Modules\Purchasing\Models\SupplierPayment;
use App\Modules\Purchasing\Support\Purchasing;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\LocalTime;

/**
 * Cuentas por pagar y pagos a proveedores. Una cuenta nace al aprobar el comprobante del
 * proveedor; su saldo sale de sus movimientos. El pago transfiere el neto al proveedor y, si
 * corresponde, deposita la detracción en su cuenta del Banco de la Nación.
 */
class PayableController extends Controller
{
    private const DOC_NUMBER = '(SELECT si.doc_number FROM supplier_invoices si WHERE si.id = payables.supplier_invoice_id)';

    private const DOC_DESCRIPTION = '(SELECT si.description FROM supplier_invoices si WHERE si.id = payables.supplier_invoice_id)';

    private const DOC_RATE = '(SELECT si.exchange_rate FROM supplier_invoices si WHERE si.id = payables.supplier_invoice_id)';

    private const BN_ACCOUNT = '(SELECT s.detraction_account FROM suppliers s WHERE s.id = payables.supplier_id)';

    private const PAYMENT_ACCOUNT = '(SELECT fa.name FROM financial_accounts fa WHERE fa.id = supplier_payments.financial_account_id)';

    private const PAYMENT_CREATOR = '(SELECT u.name FROM users u WHERE u.id = supplier_payments.created_by)';

    /** Tramo de vencimiento => [texto visible, días para vencer desde, hasta]. */
    private const BUCKETS = [
        'OVERDUE' => ['Vencido', null, -1],
        'D7' => ['0–7 días', 0, 6],
        'D30' => ['8–30 días', 7, 29],
        'LATER' => ['> 30 días', 30, null],
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');
        $days = Payable::DAYS_TO_DUE;

        $query = $this->listing()
            // "Pendiente" son las que tienen saldo; "Vencida" se calcula por la fecha.
            ->when($status === 'OPEN', fn ($q) => $q->whereIn('payables.status', Payable::OPEN))
            ->when($status === 'OVERDUE', fn ($q) => $q->whereIn('payables.status', Payable::OPEN)->whereRaw("{$days} < 0"))
            ->when($status === 'PAID', fn ($q) => $q->where('payables.status', 'PAID'))
            ->when($search !== '', function ($q) use ($search) {
                // Por proveedor o comprobante.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->whereRaw(Purchasing::supplierName('payables').' ILIKE ?', [$like])
                    ->orWhereRaw(self::DOC_NUMBER.' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'document' => ColumnFilters::text("concat_ws(' ', ".self::DOC_NUMBER.', '.self::DOC_DESCRIPTION.')'),
            'supplier' => ColumnFilters::text(Purchasing::supplierName('payables')),
            'due' => ColumnFilters::date('payables.due_date'),
            'original' => ColumnFilters::number('payables.original_amount'),
            'detraction' => ColumnFilters::number('payables.detraction_amount'),
            'balance' => ColumnFilters::number('payables.balance_amount'),
        ]);

        ListSort::apply($query, $request, [
            'document' => self::DOC_NUMBER,
            'supplier' => 'lower('.Purchasing::supplierName('payables').')',
            'due' => 'payables.due_date',
            'original' => 'payables.original_amount',
            'detraction' => 'payables.detraction_amount',
            'balance' => 'payables.balance_amount',
            'status' => ListSort::labels('payables.status', Payable::STATES),
            // Las que tienen saldo primero y, entre ellas, la que vence antes.
        ], fn ($q) => $q->orderByRaw("CASE WHEN payables.status IN ('OPEN', 'PARTIAL') THEN 0 ELSE 1 END")->orderBy('payables.due_date')->orderBy('payables.id'), 'payables.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (Payable $payable) => $this->row($payable));

        return response()->json($page);
    }

    /**
     * Indicadores y vencimientos sobre las cuentas que la persona alcanza, en soles (lo que está
     * en dólares, al tipo de cambio de venta vigente), y los saldos de bancos y caja para pagar.
     */
    public function summary(Request $request)
    {
        $rate = (float) (Purchasing::rate() ?? 0);
        $inPen = "CASE WHEN payables.currency = 'USD' THEN payables.balance_amount * {$rate} ELSE payables.balance_amount END";
        $days = Payable::DAYS_TO_DUE;

        $totals = Payable::query()->whereIn('payables.status', Payable::OPEN)->selectRaw("
            count(*) AS open_count,
            coalesce(sum({$inPen}), 0) AS outstanding,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} < 0), 0) AS b_overdue,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} BETWEEN 0 AND 6), 0) AS b_d7,
            count(*) FILTER (WHERE {$days} BETWEEN 0 AND 6) AS d7_count,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} BETWEEN 7 AND 29), 0) AS b_d30,
            coalesce(sum({$inPen}) FILTER (WHERE {$days} >= 30), 0) AS b_later,
            coalesce(sum(payables.detraction_amount) FILTER (WHERE NOT payables.detraction_deposited), 0) AS detraction_pending,
            count(*) FILTER (WHERE payables.currency = 'USD') AS usd_count
        ")->toBase()->first();

        return response()->json([
            'outstanding' => round((float) $totals->outstanding, 2),
            'open_count' => (int) $totals->open_count,
            'overdue' => round((float) $totals->b_overdue, 2),
            'due_soon' => round((float) $totals->b_d7, 2),
            'due_soon_count' => (int) $totals->d7_count,
            'detraction_pending' => round((float) $totals->detraction_pending, 2),
            'buckets' => collect(self::BUCKETS)->map(fn (array $bucket, string $key) => [
                'key' => $key, 'label' => $bucket[0], 'amount' => round((float) $totals->{'b_'.strtolower($key)}, 2),
            ])->values(),
            // Los saldos son de Bancos y caja: solo con su permiso.
            'accounts' => CheckPermission::granted($request, 'treasury.view') ? $this->accounts($request->attributes->get('tenant_id')) : null,
            'missing_rate' => (int) $totals->usd_count > 0 && $rate <= 0,
        ]);
    }

    public function show(string $payable)
    {
        abort_unless(Str::isUuid($payable), 404);
        $payable = $this->listing()->findOrFail($payable);

        return response()->json([
            ...$this->row($payable),
            'movements' => DB::table('payable_movements')->where('payable_id', $payable->id)->orderBy('created_at')->orderBy('id')
                ->get(['id', 'movement_type', 'amount', 'reference', 'created_at'])
                ->map(fn ($movement) => [
                    'id' => $movement->id, 'movement_type' => $movement->movement_type, 'amount' => (float) $movement->amount,
                    'reference' => $movement->reference, 'at' => Carbon::parse($movement->created_at)->toIso8601String(),
                ])->values(),
        ]);
    }

    public function payments(Request $request)
    {
        $query = SupplierPayment::query()
            ->select('supplier_payments.*')
            ->selectRaw(Purchasing::supplierName('supplier_payments').' AS supplier_name')
            ->selectRaw(self::PAYMENT_ACCOUNT.' AS account_name')
            ->selectRaw(self::PAYMENT_CREATOR.' AS creator_name');

        ColumnFilters::apply($query, $request, [
            'payment' => ColumnFilters::text("concat_ws(' ', supplier_payments.payment_number, supplier_payments.payment_method, supplier_payments.reference)"),
            'supplier' => ColumnFilters::text(Purchasing::supplierName('supplier_payments')),
            'account' => ColumnFilters::text(self::PAYMENT_ACCOUNT),
            'date' => ColumnFilters::date('supplier_payments.paid_at'),
            'amount' => ColumnFilters::number('supplier_payments.amount'),
            'detraction' => ColumnFilters::number('supplier_payments.detraction_amount'),
        ]);

        ListSort::apply($query, $request, [
            'payment' => 'supplier_payments.payment_number',
            'supplier' => 'lower('.Purchasing::supplierName('supplier_payments').')',
            'account' => 'lower('.self::PAYMENT_ACCOUNT.')',
            'date' => 'supplier_payments.paid_at',
            'amount' => 'supplier_payments.amount',
            'detraction' => 'supplier_payments.detraction_amount',
        ], fn ($q) => $q->orderByDesc('supplier_payments.paid_at')->orderByDesc('supplier_payments.created_at')->orderByDesc('supplier_payments.id'), 'supplier_payments.id');

        $page = $query->paginate(min(max($request->integer('per_page', 8), 1), 200));
        $page->getCollection()->transform(fn (SupplierPayment $payment) => $this->paymentRow($payment));

        return response()->json($page);
    }

    /** Listas del formulario de pago: cuentas de origen con su saldo y medios de pago. */
    public function paymentOptions(Request $request)
    {
        return response()->json([
            'accounts' => $this->accounts($request->attributes->get('tenant_id')),
            'methods' => array_keys(SupplierPayment::METHODS),
        ]);
    }

    /**
     * Paga cuentas por pagar de un mismo proveedor y moneda. Del saldo de cada una se separa su
     * detracción pendiente: lo demás es el neto que se transfiere. Con "incluir el depósito de la
     * detracción" la cuenta queda pagada; sin él, queda con la detracción por depositar.
     */
    public function pay(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'payable_ids' => ['required', 'array', 'min:1', 'max:100'],
            'payable_ids.*' => ['uuid', 'distinct'],
            'financial_account_id' => ['required', 'uuid'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(SupplierPayment::METHODS))],
            // El efectivo no tiene número de operación.
            'reference' => ['nullable', 'required_unless:payment_method,EFECTIVO', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'deposit_detraction' => ['sometimes', 'boolean'],
            'detraction_number' => ['nullable', 'string', 'max:100'],
            'detraction_account_id' => ['nullable', 'uuid'],
        ], [
            'payable_ids.required' => 'Selecciona al menos una cuenta por pagar.',
            'financial_account_id.required' => 'Selecciona la cuenta bancaria o caja de origen.',
            'reference.required_unless' => 'Indica el número de operación.',
            'paid_at.before_or_equal' => 'La fecha no puede ser futura.',
        ]);
        $deposit = $request->boolean('deposit_detraction');
        $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at'])->toDateString() : LocalTime::today()->toDateString();
        $userId = $request->user()->id;

        $payment = DB::transaction(function () use ($request, $tenantId, $data, $deposit, $paidAt, $userId) {
            // Por su modelo (solo las que la persona alcanza) y bloqueadas: dos pagos a la vez no pagan lo mismo.
            $payables = Payable::query()->whereIn('payables.id', $data['payable_ids'])
                ->select('payables.*')->selectRaw(self::DOC_RATE.' AS doc_rate')->selectRaw(self::DOC_NUMBER.' AS doc_number')
                ->orderBy('payables.due_date')->orderBy('payables.id')->lockForUpdate()->get();
            if ($payables->count() !== count($data['payable_ids'])) {
                throw ValidationException::withMessages(['payable_ids' => ['Alguna de las cuentas por pagar seleccionadas no existe.']]);
            }
            if ($payables->pluck('supplier_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['payable_ids' => ['Selecciona cuentas de un mismo proveedor por pago.']]);
            }
            if ($payables->contains(fn (Payable $payable) => ! in_array($payable->status, Payable::OPEN, true))) {
                throw ValidationException::withMessages(['payable_ids' => ['Alguna cuenta ya está pagada.']]);
            }
            if ($payables->pluck('currency')->unique()->count() > 1) {
                throw ValidationException::withMessages(['payable_ids' => ['Selecciona cuentas de una misma moneda por pago.']]);
            }
            $currency = $payables->first()->currency;

            $account = Treasury::lock($tenantId, $data['financial_account_id']);
            if (! $account || $account->status !== 'ACTIVE' || $account->account_type === 'DETRACTION') {
                throw ValidationException::withMessages(['financial_account_id' => ['Selecciona la cuenta bancaria o caja de origen.']]);
            }
            if ($account->currency !== $currency) {
                throw ValidationException::withMessages(['financial_account_id' => ["La cuenta de origen está en {$account->currency} y la deuda en {$currency}."]]);
            }

            // Qué sale por cada cuenta: el neto para el proveedor y su detracción pendiente.
            $net = 0.0;
            $detraction = 0.0;
            $parts = [];
            foreach ($payables as $payable) {
                $balance = round((float) $payable->balance_amount, 2);
                $pendingPen = $payable->detraction_deposited ? 0.0 : round((float) $payable->detraction_amount, 2);
                // La detracción va en soles; en la cuenta, en la moneda del comprobante.
                $rate = $currency === 'USD' ? (float) $payable->doc_rate : 1.0;
                $pendingDoc = $pendingPen > 0 && $rate > 0 ? min($balance, round($pendingPen / $rate, 2)) : 0.0;
                $toSupplier = round($balance - $pendingDoc, 2);
                $deposited = $deposit && $pendingPen > 0;

                $parts[] = ['payable' => $payable, 'net' => $toSupplier, 'detraction_doc' => $deposited ? $pendingDoc : 0.0, 'deposited' => $deposited];
                $net = round($net + $toSupplier, 2);
                $detraction = round($detraction + ($deposited ? $pendingPen : 0.0), 2);
            }
            if ($net + $detraction < 0.01) {
                throw ValidationException::withMessages(['deposit_detraction' => ['De estas cuentas solo queda por depositar la detracción: marca «Incluir el depósito de la detracción».']]);
            }

            $detractionAccount = null;
            $detractionNumber = trim((string) ($data['detraction_number'] ?? ''));
            if ($detraction > 0) {
                if ($detractionNumber === '') {
                    throw ValidationException::withMessages(['detraction_number' => ['Indica el número de la constancia del depósito de detracción.']]);
                }
                // El depósito se hace en soles: sale de la misma cuenta si está en soles; si no, de otra que lo esté.
                $detractionAccountId = $data['detraction_account_id'] ?? ($currency === 'PEN' ? $account->id : null);
                $detractionAccount = $detractionAccountId === $account->id ? $account : Treasury::lock($tenantId, $detractionAccountId);
                if (! $detractionAccount || $detractionAccount->status !== 'ACTIVE' || $detractionAccount->account_type === 'DETRACTION' || $detractionAccount->currency !== 'PEN') {
                    throw ValidationException::withMessages(['detraction_account_id' => ['Selecciona la cuenta en soles de la que sale el depósito de la detracción.']]);
                }
            }

            $this->requireBalance($account, $net + ($detractionAccount?->id === $account->id ? $detraction : 0));
            if ($detractionAccount && $detractionAccount->id !== $account->id) {
                $this->requireBalance($detractionAccount, $detraction);
            }

            $supplierName = Purchasing::identity(Purchasing::supplier($tenantId, $payables->first()->supplier_id))['name'];
            $payment = SupplierPayment::create([
                'tenant_id' => $tenantId,
                'payment_number' => SequentialNumberGenerator::next($tenantId, 'supplier_payments', 'payment_number', 'PP'),
                'supplier_id' => $payables->first()->supplier_id,
                'financial_account_id' => $account->id,
                'amount' => $net,
                'currency' => $currency,
                'detraction_amount' => $detraction,
                'detraction_number' => $detraction > 0 ? $detractionNumber : null,
                'detraction_account_id' => $detractionAccount?->id,
                'payment_method' => $data['payment_method'],
                'reference' => isset($data['reference']) && trim($data['reference']) !== '' ? trim($data['reference']) : null,
                'paid_at' => $paidAt,
                'status' => 'PAID',
                'owner_user_id' => $userId,
                'created_by' => $userId,
            ]);

            foreach ($parts as $part) {
                /** @var Payable $payable */
                $payable = $part['payable'];
                $applied = round($part['net'] + $part['detraction_doc'], 2);
                if ($applied < 0.01) {
                    continue;
                }
                DB::table('supplier_payment_allocations')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'supplier_payment_id' => $payment->id, 'payable_id' => $payable->id, 'amount' => $applied,
                ]);
                // El saldo y el estado de la cuenta los actualiza la base con cada movimiento.
                foreach (['PAYMENT' => $part['net'], 'DETRACTION' => $part['detraction_doc']] as $type => $amount) {
                    if ($amount >= 0.01) {
                        DB::table('payable_movements')->insert([
                            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'payable_id' => $payable->id, 'movement_type' => $type,
                            'amount' => -1 * $amount, 'reference' => $type === 'DETRACTION' ? "{$payment->payment_number} · constancia {$detractionNumber}" : $payment->payment_number,
                            'supplier_payment_id' => $payment->id, 'created_by' => $userId,
                        ]);
                    }
                }
                if ($part['deposited']) {
                    DB::table('payables')->where('id', $payable->id)->update(['detraction_deposited' => true]);
                }
                AuditLogger::log(
                    tenantId: $tenantId, userId: $userId, action: 'supplier_invoice.payment', entityType: 'SupplierInvoice', entityId: $payable->supplier_invoice_id,
                    metadata: ['amount' => $applied, 'note' => $payment->payment_number.' · '.($currency === 'USD' ? 'US$ ' : 'S/ ').number_format($applied, 2)],
                );
            }

            // Todo egreso deja su movimiento en la cuenta: de ahí sale el saldo de bancos y caja.
            if ($net >= 0.01) {
                Treasury::move($account, -1 * $net, 'SUPPLIER_PAYMENT', trim("Pago a proveedor {$payment->payment_number} · {$supplierName}", ' ·'), [
                    'date' => $paidAt, 'ref_id' => $payment->id, 'op_number' => $payment->reference, 'user_id' => $userId,
                ]);
            }
            if ($detraction > 0) {
                Treasury::move($detractionAccount, -1 * $detraction, 'SUPPLIER_PAYMENT', trim("Depósito de detracción {$payment->payment_number} · {$supplierName}", ' ·'), [
                    'date' => $paidAt, 'ref_id' => $payment->id, 'op_number' => $detractionNumber, 'user_id' => $userId,
                ]);
            }

            Posting::supplierPayment($tenantId, $payment->id, $userId);
            AuditLogger::log(
                tenantId: $tenantId, userId: $userId, action: 'supplier_payment.create', entityType: 'SupplierPayment', entityId: $payment->id,
                metadata: ['to' => 'PAID', 'amount' => $net, 'detraction' => $detraction, 'documents' => count($parts), 'note' => $payment->payment_number],
            );

            return $payment;
        });

        $payment = SupplierPayment::query()->select('supplier_payments.*')
            ->selectRaw(Purchasing::supplierName('supplier_payments').' AS supplier_name')
            ->selectRaw(self::PAYMENT_ACCOUNT.' AS account_name')
            ->selectRaw(self::PAYMENT_CREATOR.' AS creator_name')
            ->findOrFail($payment->id);

        return response()->json($this->paymentRow($payment), 201);
    }

    private function listing()
    {
        return Payable::query()
            ->select('payables.*')
            ->selectRaw(self::DOC_NUMBER.' AS doc_number')
            ->selectRaw(self::DOC_DESCRIPTION.' AS doc_description')
            ->selectRaw(self::DOC_RATE.' AS doc_rate')
            ->selectRaw(self::BN_ACCOUNT.' AS bn_account')
            ->selectRaw(Purchasing::supplierName('payables').' AS supplier_name')
            ->selectRaw(Payable::DAYS_TO_DUE.' AS days_to_due');
    }

    /** Cuentas de las que se puede pagar (bancos y cajas activos), con su saldo en libros. */
    private function accounts(string $tenantId): array
    {
        return FinancialAccount::query()->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->whereIn('account_type', ['BANK', 'CASH'])
            ->select('financial_accounts.*')->selectRaw(Treasury::BALANCE.' AS balance')->orderBy('name')->get()
            ->map(fn (FinancialAccount $account) => [
                'id' => $account->id, 'name' => $account->name, 'currency' => $account->currency, 'account_type' => $account->account_type,
                'balance' => round((float) $account->balance, 2),
            ])->all();
    }

    private function requireBalance(FinancialAccount $account, float $amount): void
    {
        $balance = Treasury::balance($account->id);
        if ($balance < $amount - 0.001) {
            $shown = number_format($balance, 2);
            throw ValidationException::withMessages(['financial_account_id' => ["Saldo insuficiente en {$account->name}: disponible {$shown}."]]);
        }
    }

    private function row(Payable $payable): array
    {
        $open = in_array($payable->status, Payable::OPEN, true);
        $days = (int) $payable->days_to_due;

        return [
            'id' => $payable->id,
            'supplier_invoice' => ['id' => $payable->supplier_invoice_id, 'doc_number' => $payable->doc_number, 'description' => $payable->doc_description],
            'supplier' => ['id' => $payable->supplier_id, 'name' => $payable->supplier_name, 'detraction_account' => $payable->bn_account],
            'due_date' => $payable->due_date?->toDateString(),
            'overdue' => $open && $days < 0,
            'days_overdue' => $open && $days < 0 ? -1 * $days : 0,
            'currency' => $payable->currency,
            'exchange_rate' => $payable->doc_rate !== null ? (float) $payable->doc_rate : null,
            'original_amount' => (float) $payable->original_amount,
            'balance_amount' => (float) $payable->balance_amount,
            // En soles.
            'detraction_amount' => (float) $payable->detraction_amount,
            'detraction_deposited' => (bool) $payable->detraction_deposited,
            'status' => $payable->status,
        ];
    }

    private function paymentRow(SupplierPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_number' => $payment->payment_number,
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'supplier' => ['id' => $payment->supplier_id, 'name' => $payment->supplier_name],
            'account_name' => $payment->account_name,
            'paid_at' => $payment->paid_at?->toDateString(),
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'detraction_amount' => (float) $payment->detraction_amount,
            'detraction_number' => $payment->detraction_number,
            'status' => $payment->status,
            'creator' => $payment->creator_name,
            'reversed_at' => $payment->reversed_at ? Carbon::parse($payment->reversed_at)->toIso8601String() : null,
            'reversal_reason' => $payment->reversal_reason,
        ];
    }

    /** Ficha del pago: a qué cuentas se aplicó, sus movimientos de banco o caja, su asiento e historial. */
    public function showPayment(string $supplierPayment)
    {
        return response()->json($this->paymentDetail($this->findPayment($supplierPayment)->id));
    }

    /**
     * Revierte un pago a proveedor, con motivo: cada cuenta por pagar recupera lo que se le aplicó
     * (con un movimiento), el dinero vuelve a la cuenta de origen (con otro) y el asiento se extorna.
     * El pago queda revertido; no se borra. Un pago con depósito de detracción no se revierte: la
     * constancia es de SUNAT y no se deshace desde aquí.
     */
    public function reversePayment(string $supplierPayment, Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $reason = trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo de la reversión.'])['reason']);
        $userId = $request->user()->id;
        $id = $this->findPayment($supplierPayment)->id;

        DB::transaction(function () use ($tenantId, $id, $reason, $userId) {
            $payment = SupplierPayment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'PAID') {
                throw ValidationException::withMessages(['status' => ['El pago ya fue revertido.']]);
            }
            if ((float) $payment->detraction_amount > 0) {
                throw ValidationException::withMessages(['status' => [
                    "El pago incluye el depósito de la detracción (constancia {$payment->detraction_number}): no se revierte desde aquí.",
                ]]);
            }
            $account = Treasury::lock($tenantId, $payment->financial_account_id);
            $supplierName = Purchasing::identity(Purchasing::supplier($tenantId, $payment->supplier_id))['name'];

            $allocations = DB::table('supplier_payment_allocations as a')->join('payables as p', 'p.id', '=', 'a.payable_id')
                ->where('a.supplier_payment_id', $payment->id)->get(['a.payable_id', 'a.amount', 'p.supplier_invoice_id']);
            foreach ($allocations as $allocation) {
                // El saldo lo devuelve la base con el movimiento.
                DB::table('payable_movements')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'payable_id' => $allocation->payable_id, 'movement_type' => 'PAYMENT_REVERSAL',
                    'amount' => round((float) $allocation->amount, 2), 'reference' => "{$payment->payment_number} revertido: {$reason}",
                    'supplier_payment_id' => $payment->id, 'created_by' => $userId,
                ]);
                AuditLogger::log(
                    tenantId: $tenantId, userId: $userId, action: 'supplier_invoice.payment_reversed', entityType: 'SupplierInvoice', entityId: $allocation->supplier_invoice_id,
                    metadata: ['amount' => round((float) $allocation->amount, 2), 'note' => "{$payment->payment_number} revertido: {$reason}"],
                );
            }
            if ((float) $payment->amount >= 0.01 && $account) {
                Treasury::move($account, round((float) $payment->amount, 2), 'SUPPLIER_PAYMENT_REVERSAL', trim("Reversión del pago {$payment->payment_number} · {$supplierName}", ' ·'), [
                    'ref_id' => $payment->id, 'op_number' => $payment->reference, 'user_id' => $userId,
                ]);
            }
            $entry = Ledger::entryFor($tenantId, 'SUPPLIER_PAYMENT', $payment->id);
            $reversal = $entry ? Ledger::reverse($tenantId, $entry->id, "Reversión del pago {$payment->payment_number}: {$reason}", $userId) : null;

            DB::table('supplier_payments')->where('id', $payment->id)->update([
                'status' => 'REVERSED', 'reversed_at' => now(), 'reversed_by' => $userId, 'reversal_reason' => $reason,
            ]);
            $number = $reversal ? DB::table('acc_journal_entries')->where('id', $reversal)->value('entry_number') : null;
            AuditLogger::log(
                tenantId: $tenantId, userId: $userId, action: 'supplier_payment.reverse', entityType: 'SupplierPayment', entityId: $payment->id,
                metadata: ['from' => 'PAID', 'to' => 'REVERSED', 'amount' => round((float) $payment->amount, 2),
                    'note' => trim("{$payment->payment_number}: {$reason}".($entry ? " · {$entry->entry_number} extornado por {$number}" : ''))],
            );
        });

        return response()->json($this->paymentDetail($id));
    }

    private function findPayment(string $id): SupplierPayment
    {
        abort_unless(Str::isUuid($id), 404);

        // Por su modelo: un pago fuera del alcance de la persona "no existe".
        return SupplierPayment::query()->findOrFail($id);
    }

    private function paymentDetail(string $id): array
    {
        $payment = SupplierPayment::query()->select('supplier_payments.*')
            ->selectRaw(Purchasing::supplierName('supplier_payments').' AS supplier_name')
            ->selectRaw(self::PAYMENT_ACCOUNT.' AS account_name')
            ->selectRaw(self::PAYMENT_CREATOR.' AS creator_name')
            ->findOrFail($id);
        $tenantId = $payment->tenant_id;
        $entry = DB::table('acc_journal_entries')->where('tenant_id', $tenantId)->where('source_type', 'SUPPLIER_PAYMENT')->where('source_id', $payment->id)
            ->orderByDesc('created_at')->first(['id', 'entry_number', 'status']);

        return [
            ...$this->paymentRow($payment),
            'reversed_by' => $payment->reversed_by ? DB::table('users')->where('id', $payment->reversed_by)->value('name') : null,
            'allocations' => DB::table('supplier_payment_allocations as a')
                ->join('payables as p', 'p.id', '=', 'a.payable_id')
                ->join('supplier_invoices as i', 'i.id', '=', 'p.supplier_invoice_id')
                ->where('a.supplier_payment_id', $payment->id)->orderBy('i.doc_number')
                ->get(['a.amount', 'p.id as payable_id', 'p.status as payable_status', 'p.balance_amount', 'i.id as invoice_id', 'i.doc_number', 'i.description'])
                ->map(fn ($row) => [
                    'payable_id' => $row->payable_id, 'invoice_id' => $row->invoice_id, 'doc_number' => $row->doc_number, 'description' => $row->description,
                    'amount' => round((float) $row->amount, 2), 'payable_status' => $row->payable_status, 'balance' => round((float) $row->balance_amount, 2),
                ])->values(),
            'movements' => DB::table('bank_movements as m')->join('financial_accounts as f', 'f.id', '=', 'm.account_id')
                ->where('m.ref_id', $payment->id)->whereIn('m.ref_type', ['SUPPLIER_PAYMENT', 'SUPPLIER_PAYMENT_REVERSAL'])
                ->orderBy('m.created_at')->get(['m.id', 'm.movement_date', 'm.description', 'm.amount', 'm.ref_type', 'f.name as account'])
                ->map(fn ($row) => [
                    'id' => $row->id, 'date' => Carbon::parse($row->movement_date)->toDateString(), 'description' => $row->description,
                    'amount' => round((float) $row->amount, 2), 'type' => Treasury::REF_TYPES[$row->ref_type] ?? $row->ref_type, 'account' => $row->account,
                ])->values(),
            'journal_entry' => $entry ? ['id' => $entry->id, 'number' => $entry->entry_number, 'status' => $entry->status] : null,
            'history' => AuditHistory::for('SupplierPayment', $payment->id),
        ];
    }
}
