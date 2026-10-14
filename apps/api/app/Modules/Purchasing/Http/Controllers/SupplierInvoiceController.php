<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Support\Billing;
use App\Modules\Finance\Support\Withholding;
use App\Modules\Purchasing\Models\Payable;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Support\Purchasing;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comprobantes de proveedor: se registran (sin duplicados), se cruzan con su orden de compra y
 * lo recibido de ella, y al aprobarse para pago nace su cuenta por pagar. Registrado, un
 * comprobante no se modifica: se rechaza o se anula.
 */
class SupplierInvoiceController extends Controller
{
    private const ORDER_NUMBER = '(SELECT po.order_number FROM purchase_orders po WHERE po.id = supplier_invoices.purchase_order_id)';

    private const TABS = ['REGISTERED', 'APPROVED', 'REJECTED'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $tab = (string) $request->query('tab', '');

        $query = self::listing()
            ->when(in_array($tab, self::TABS, true), fn ($q) => $q->where('supplier_invoices.status', $tab))
            ->when(Str::isUuid((string) $request->query('cost_center_id')), fn ($q) => $q->where('supplier_invoices.cost_center_id', $request->query('cost_center_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por comprobante, proveedor o concepto.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('supplier_invoices.doc_number', 'ILIKE', $like)
                    ->orWhere('supplier_invoices.supplier_name', 'ILIKE', $like)
                    ->orWhere('supplier_invoices.description', 'ILIKE', $like));
            });

        ColumnFilters::apply($query, $request, [
            'document' => ColumnFilters::text("concat_ws(' ', supplier_invoices.doc_number, supplier_invoices.description)"),
            'supplier' => ColumnFilters::text('supplier_invoices.supplier_name'),
            'issue' => ColumnFilters::date('supplier_invoices.issue_date'),
            'expense' => ColumnFilters::text('supplier_invoices.expense_account'),
            'total' => ColumnFilters::number('supplier_invoices.total'),
            'order' => ColumnFilters::text(self::ORDER_NUMBER),
        ]);

        ListSort::apply($query, $request, [
            'document' => 'supplier_invoices.doc_number',
            'supplier' => 'lower(supplier_invoices.supplier_name)',
            'issue' => 'supplier_invoices.issue_date',
            'expense' => 'supplier_invoices.expense_account',
            'total' => 'supplier_invoices.total',
            'order' => self::ORDER_NUMBER,
            'status' => ListSort::labels('supplier_invoices.status', SupplierInvoice::STATES),
        ], fn ($q) => $q->orderByDesc('supplier_invoices.issue_date')->orderByDesc('supplier_invoices.created_at')->orderByDesc('supplier_invoices.id'), 'supplier_invoices.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (SupplierInvoice $invoice) => self::row($invoice));

        return response()->json($page);
    }

    /** Conteo de cada pestaña, sobre los comprobantes que la persona alcanza. */
    public function summary()
    {
        $totals = SupplierInvoice::query()->selectRaw("
            count(*) AS all_count,
            count(*) FILTER (WHERE status = 'REGISTERED') AS registered,
            count(*) FILTER (WHERE status = 'APPROVED') AS approved,
            count(*) FILTER (WHERE status = 'REJECTED') AS rejected
        ")->toBase()->first();

        return response()->json(['tabs' => [
            'ALL' => (int) $totals->all_count, 'REGISTERED' => (int) $totals->registered, 'APPROVED' => (int) $totals->approved, 'REJECTED' => (int) $totals->rejected,
        ]]);
    }

    /** Listas del formulario "Registrar comprobante" y del filtro por centro de costo. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'suppliers' => Purchasing::suppliers($tenantId),
            'cost_centers' => Purchasing::costCenters($tenantId, false),
            'expense_accounts' => Purchasing::expenseAccounts(),
            'default_account' => Purchasing::DEFAULT_EXPENSE_ACCOUNT,
            'detraction_codes' => Purchasing::detractionCodes(),
            'terms_days' => Billing::DEFAULT_TERMS_DAYS,
            // Por su modelo: las órdenes aprobadas que la persona alcanza. Con lo que ya recibió cada una.
            'purchase_orders' => PurchaseOrder::query()->whereIn('purchase_orders.status', PurchaseOrder::COMMITTED)
                ->select('purchase_orders.*')
                ->selectRaw(Purchasing::supplierName('purchase_orders').' AS supplier_name')
                ->selectRaw(PurchaseOrder::RECEIVED_PCT.' AS received_pct')
                ->selectRaw('(SELECT i.description FROM purchase_order_items i WHERE i.purchase_order_id = purchase_orders.id ORDER BY i.position, i.id LIMIT 1) AS first_item')
                ->selectRaw('(SELECT i.account_code FROM purchase_order_items i WHERE i.purchase_order_id = purchase_orders.id ORDER BY i.position, i.id LIMIT 1) AS first_account')
                ->orderByDesc('purchase_orders.created_at')->limit(300)->get()
                ->map(fn (PurchaseOrder $order) => [
                    'id' => $order->id, 'order_number' => $order->order_number, 'supplier_id' => $order->supplier_id, 'supplier_name' => $order->supplier_name,
                    'cost_center_id' => $order->cost_center_id, 'currency' => $order->currency, 'subtotal' => (float) $order->subtotal,
                    'received_pct' => round((float) $order->received_pct, 2), 'first_item' => $order->first_item, 'first_account' => $order->first_account,
                ])->values(),
        ]);
    }

    public function show(string $supplierInvoice)
    {
        return response()->json($this->detail($this->find($supplierInvoice)));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $request->merge(['doc_number' => strtoupper(trim((string) $request->input('doc_number')))]);
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'doc_type' => ['required', 'in:'.implode(',', array_keys(SupplierInvoice::TYPES))],
            'doc_number' => ['required', 'regex:/^[A-Z0-9]{4}-\d{1,8}$/'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'subtotal' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', 'in:PEN,USD'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'cost_center_id' => ['required', 'uuid'],
            'expense_account' => ['required', 'in:'.implode(',', array_keys(Purchasing::EXPENSE_ACCOUNTS))],
            'description' => ['nullable', 'string', 'max:500'],
            'detraction_code' => ['nullable', 'in:'.implode(',', array_keys(Withholding::CODES))],
        ], [
            'supplier_id.required' => 'Selecciona un proveedor.',
            'doc_number.required' => 'Indica la serie y el número del comprobante.',
            'doc_number.regex' => 'Número de comprobante inválido. Formato: SERIE-CORRELATIVO (p. ej. E001-1234 o F001-88).',
            'issue_date.required' => 'Indica la fecha de emisión.',
            'issue_date.before_or_equal' => 'La fecha de emisión no puede ser futura.',
            'due_date.after_or_equal' => 'El vencimiento no puede ser anterior a la emisión.',
            'subtotal.required' => 'Indica el valor de venta (sin IGV).',
            'subtotal.gt' => 'Indica el valor de venta (sin IGV).',
            'cost_center_id.required' => 'Centro de costo y cuenta de gasto son obligatorios.',
            'expense_account.required' => 'Centro de costo y cuenta de gasto son obligatorios.',
        ]);

        $supplier = Purchasing::supplier($tenantId, $data['supplier_id']);
        if (! $supplier || $supplier->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['supplier_id' => ['Selecciona un proveedor activo de Clientes y actores (rol Proveedor).']]);
        }
        if (! DB::table('cost_centers')->where('tenant_id', $tenantId)->where('id', $data['cost_center_id'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['cost_center_id' => ['El centro de costo indicado no existe o está inactivo.']]);
        }
        // Entre todos los de la empresa, no solo los que la persona alcanza.
        if (DB::table('supplier_invoices')->where('tenant_id', $tenantId)->where('supplier_id', $supplier->id)->where('doc_type', $data['doc_type'])
            ->where('doc_number', $data['doc_number'])->where('status', '<>', 'VOIDED')->exists()) {
            throw ValidationException::withMessages(['doc_number' => ['Ese comprobante del proveedor ya está registrado.']]);
        }

        $order = null;
        if (! empty($data['purchase_order_id'])) {
            // Por su modelo: solo una orden que la persona alcanza.
            $order = PurchaseOrder::find($data['purchase_order_id']);
            if (! $order || ! in_array($order->status, PurchaseOrder::COMMITTED, true)) {
                throw ValidationException::withMessages(['purchase_order_id' => ['La orden de compra no existe o no está aprobada.']]);
            }
            if ($order->supplier_id !== $supplier->id) {
                throw ValidationException::withMessages(['purchase_order_id' => ["La orden {$order->order_number} es de otro proveedor."]]);
            }
            if ($order->currency !== $data['currency']) {
                throw ValidationException::withMessages(['currency' => ["La orden {$order->order_number} está en {$order->currency}."]]);
            }
        }

        $issue = Carbon::parse($data['issue_date'])->toDateString();
        $rate = null;
        if ($data['currency'] === 'USD') {
            // El tipo de cambio del día de emisión: con él se miden la detracción y los topes, que van en soles.
            $rate = Purchasing::rate($issue);
            if (! $rate) {
                throw ValidationException::withMessages(['currency' => ['Registra el tipo de cambio antes de registrar un comprobante en dólares.']]);
            }
        }

        $honorarium = $data['doc_type'] === 'RECIBO_HONORARIOS';
        $subtotal = round((float) $data['subtotal'], 2);
        // Un recibo por honorarios no lleva IGV.
        $total = $honorarium ? $subtotal : Purchasing::withTax($subtotal);
        $totalPen = round($total * ($rate ?? 1), 2);
        $detraction = Purchasing::detraction($data['doc_type'], $data['detraction_code'] ?? null, $totalPen);
        $identity = Purchasing::identity($supplier);

        $invoice = DB::transaction(function () use ($request, $tenantId, $data, $supplier, $identity, $order, $issue, $rate, $subtotal, $total, $totalPen, $detraction) {
            $invoice = SupplierInvoice::create([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplier->id,
                'supplier_name' => Str::limit($identity['name'], 255, ''),
                'supplier_tax_id' => $identity['tax_id'],
                'doc_type' => $data['doc_type'],
                'doc_number' => $data['doc_number'],
                'issue_date' => $issue,
                'due_date' => isset($data['due_date']) ? Carbon::parse($data['due_date'])->toDateString() : Carbon::parse($issue)->addDays(Billing::DEFAULT_TERMS_DAYS)->toDateString(),
                'purchase_order_id' => $order?->id,
                'cost_center_id' => $data['cost_center_id'],
                'expense_account' => $data['expense_account'],
                'description' => isset($data['description']) && trim($data['description']) !== '' ? trim($data['description']) : null,
                'currency' => $data['currency'],
                'exchange_rate' => $rate,
                'subtotal' => $subtotal,
                'tax' => round($total - $subtotal, 2),
                'total' => $total,
                'detraction_code' => $detraction['code'] ?? null,
                'detraction_pct' => $detraction['pct'] ?? null,
                'detraction_amount' => $detraction['amount'] ?? 0,
                'retention_4ta' => Purchasing::retention4ta($data['doc_type'], $total, $totalPen),
                // Su validez ante SUNAT no se consulta todavía: queda dicho, no se da por válido.
                'sunat_validation' => 'NOT_CHECKED',
                'status' => 'REGISTERED',
                'owner_user_id' => $request->user()->id,
                'created_by' => $request->user()->id,
            ]);
            $this->audit($request, $invoice, 'supplier_invoice.create', ['to' => 'REGISTERED', 'total' => (float) $invoice->total, 'note' => $invoice->doc_number]);

            return $invoice;
        });

        return response()->json($this->detail($invoice), 201);
    }

    /** Aprueba para pago: exige que cuadre con su orden de compra y lo recibido, y crea la cuenta por pagar. */
    public function approve(string $supplierInvoice, Request $request)
    {
        $invoice = $this->find($supplierInvoice);
        $this->from($invoice, ['REGISTERED'], 'aprobar');
        $match = Purchasing::match($invoice);
        if (! $match['ok']) {
            throw ValidationException::withMessages(['status' => ["No cuadra con la orden de compra: {$match['label']}."]]);
        }

        DB::transaction(function () use ($request, $invoice) {
            $invoice->approved_by = $request->user()->id;
            $invoice->approved_at = now();
            $this->move($request, $invoice, 'APPROVED', 'supplier_invoice.approve');

            $payable = Payable::create([
                'tenant_id' => $invoice->tenant_id,
                'supplier_invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date,
                'original_amount' => $invoice->total,
                'detraction_amount' => $invoice->detraction_amount,
                'status' => 'OPEN',
                'owner_user_id' => $invoice->owner_user_id,
                'branch_id' => $invoice->branch_id,
            ]);
            // El saldo lo lleva la base con cada movimiento.
            $movement = fn (string $type, float $amount) => DB::table('payable_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $invoice->tenant_id, 'payable_id' => $payable->id, 'movement_type' => $type,
                'amount' => $amount, 'reference' => $invoice->doc_number, 'created_by' => $request->user()->id,
            ]);
            $movement('CHARGE', round((float) $invoice->total, 2));
            if ((float) $invoice->retention_4ta > 0) {
                // Lo retenido no se le paga al proveedor: se declara y paga a SUNAT.
                $movement('RETENTION_4TA', -1 * round((float) $invoice->retention_4ta, 2));
            }
        });

        return response()->json($this->detail($invoice));
    }

    public function reject(string $supplierInvoice, Request $request)
    {
        $invoice = $this->find($supplierInvoice);
        $this->from($invoice, ['REGISTERED'], 'rechazar');
        $reason = $this->reason($request);
        DB::transaction(function () use ($request, $invoice, $reason) {
            $invoice->reason = $reason;
            $this->move($request, $invoice, 'REJECTED', 'supplier_invoice.reject', $reason);
        });

        return response()->json($this->detail($invoice));
    }

    /** Anula un comprobante registrado o rechazado: deja de contar como facturado en su orden y se puede registrar de nuevo. */
    public function void(string $supplierInvoice, Request $request)
    {
        $invoice = $this->find($supplierInvoice);
        $this->from($invoice, ['REGISTERED', 'REJECTED'], 'anular');
        $reason = $this->reason($request);
        DB::transaction(function () use ($request, $invoice, $reason) {
            $invoice->reason = $reason;
            $this->move($request, $invoice, 'VOIDED', 'supplier_invoice.void', $reason);
        });

        return response()->json($this->detail($invoice));
    }

    public static function listing()
    {
        return SupplierInvoice::query()
            ->select('supplier_invoices.*')
            ->selectRaw(self::ORDER_NUMBER.' AS order_number')
            ->selectRaw(Purchasing::costCenterName('supplier_invoices').' AS cost_center_name');
    }

    /** `$withMatch`: incluye el cruce de 3 vías (una consulta más por fila; solo donde se muestra). */
    public static function row(SupplierInvoice $invoice, bool $withMatch = false): array
    {
        return [
            'id' => $invoice->id,
            'doc_type' => $invoice->doc_type,
            'doc_number' => $invoice->doc_number,
            'description' => $invoice->description,
            'supplier' => ['id' => $invoice->supplier_id, 'name' => $invoice->supplier_name, 'tax_id' => $invoice->supplier_tax_id],
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'expense_account' => $invoice->expense_account,
            'expense_account_label' => Purchasing::accountLabel($invoice->expense_account),
            'cost_center' => ['id' => $invoice->cost_center_id, 'name' => $invoice->cost_center_name],
            'currency' => $invoice->currency,
            'subtotal' => (float) $invoice->subtotal,
            'tax' => (float) $invoice->tax,
            'total' => (float) $invoice->total,
            'detraction' => (float) $invoice->detraction_amount > 0
                ? ['code' => $invoice->detraction_code, 'pct' => (float) $invoice->detraction_pct, 'amount' => (float) $invoice->detraction_amount]
                : null,
            'retention_4ta' => (float) $invoice->retention_4ta,
            'purchase_order' => $invoice->purchase_order_id ? ['id' => $invoice->purchase_order_id, 'order_number' => $invoice->order_number] : null,
            'sunat_validation' => $invoice->sunat_validation,
            'status' => $invoice->status,
            'lock_version' => (int) $invoice->lock_version,
            ...($withMatch ? ['match' => Purchasing::match($invoice)] : []),
        ];
    }

    private function find(string $id): SupplierInvoice
    {
        abort_unless(Str::isUuid($id), 404);

        return SupplierInvoice::findOrFail($id);
    }

    private function from(SupplierInvoice $invoice, array $states, string $verb): void
    {
        if (! in_array($invoice->status, $states, true)) {
            $current = mb_strtolower(SupplierInvoice::STATES[$invoice->status] ?? $invoice->status);
            throw ValidationException::withMessages(['status' => ["No se puede {$verb} un comprobante en estado «{$current}»."]]);
        }
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo.'])['reason']);
    }

    private function move(Request $request, SupplierInvoice $invoice, string $to, string $action, ?string $note = null): void
    {
        $from = $invoice->status;
        $invoice->status = $to;
        $invoice->save();
        $this->audit($request, $invoice, $action, array_filter(['from' => $from, 'to' => $to, 'note' => $note]));
    }

    private function detail(SupplierInvoice $invoice): array
    {
        $invoice = self::listing()->with('creator:id,name', 'approver:id,name')->findOrFail($invoice->id);
        // Por su modelo, como el comprobante: quien lo ve, alcanza su cuenta por pagar.
        $payable = Payable::where('supplier_invoice_id', $invoice->id)->first();

        return [
            ...self::row($invoice, true),
            'exchange_rate' => $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null,
            'reason' => $invoice->reason,
            'creator' => $invoice->creator?->name,
            'approver' => $invoice->approver?->name,
            'payable' => $payable ? [
                'id' => $payable->id, 'status' => $payable->status, 'balance_amount' => (float) $payable->balance_amount, 'currency' => $payable->currency,
                'detraction_amount' => (float) $payable->detraction_amount, 'detraction_deposited' => (bool) $payable->detraction_deposited,
                // De dónde sale su saldo: cargo, retención, pagos y depósito de la detracción.
                'movements' => DB::table('payable_movements')->where('payable_id', $payable->id)->orderBy('created_at')->orderBy('id')
                    ->get(['id', 'movement_type', 'amount', 'reference', 'created_at'])
                    ->map(fn ($movement) => [
                        'id' => $movement->id, 'movement_type' => $movement->movement_type, 'amount' => (float) $movement->amount,
                        'reference' => $movement->reference, 'at' => Carbon::parse($movement->created_at)->toIso8601String(),
                    ])->values(),
            ] : null,
            'history' => AuditHistory::for('SupplierInvoice', $invoice->id),
        ];
    }

    private function audit(Request $request, SupplierInvoice $invoice, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'SupplierInvoice',
            entityId: $invoice->id,
            metadata: $metadata,
        );
    }
}
