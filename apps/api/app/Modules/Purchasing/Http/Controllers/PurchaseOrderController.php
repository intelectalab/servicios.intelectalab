<?php

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\Budgets;
use App\Http\Middleware\CheckPermission;
use App\Modules\Core\Http\Controllers\TenantSettingsController;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\SupplierInvoice;
use App\Modules\Purchasing\Support\Purchasing;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use App\Services\SequentialNumberGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Notifier;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Órdenes de compra: se piden en borrador, se envían a aprobación, las aprueba otra persona,
 * se reciben por porcentaje y se cierran. Solo se editan en borrador.
 */
class PurchaseOrderController extends Controller
{
    private const FIRST_ITEM = '(SELECT i.description FROM purchase_order_items i WHERE i.purchase_order_id = purchase_orders.id ORDER BY i.position, i.id LIMIT 1)';

    private const ITEMS_COUNT = '(SELECT count(*) FROM purchase_order_items i WHERE i.purchase_order_id = purchase_orders.id)';

    private const CREATOR = '(SELECT u.name FROM users u WHERE u.id = purchase_orders.created_by)';

    /** Pestaña del listado => estados que muestra. */
    private const TABS = [
        'DRAFT' => ['DRAFT'], 'SUBMITTED' => ['SUBMITTED'], 'RECEIVE' => PurchaseOrder::RECEIVABLE, 'RECEIVED' => ['RECEIVED'], 'CLOSED' => ['CLOSED'],
    ];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $tab = self::TABS[$request->query('tab')] ?? null;

        $query = $this->listing()
            ->when($tab, fn ($q) => $q->whereIn('purchase_orders.status', $tab))
            ->when(Str::isUuid((string) $request->query('cost_center_id')), fn ($q) => $q->where('purchase_orders.cost_center_id', $request->query('cost_center_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por número de orden, proveedor o descripción de alguna línea.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('purchase_orders.order_number', 'ILIKE', $like)
                    ->orWhereRaw(Purchasing::supplierName('purchase_orders').' ILIKE ?', [$like])
                    ->orWhereExists(fn ($items) => $items->selectRaw('1')->from('purchase_order_items')
                        ->whereColumn('purchase_order_items.purchase_order_id', 'purchase_orders.id')->where('purchase_order_items.description', 'ILIKE', $like)));
            });

        ColumnFilters::apply($query, $request, [
            'order' => ColumnFilters::text("concat_ws(' ', purchase_orders.order_number, ".self::FIRST_ITEM.')'),
            'supplier' => ColumnFilters::text(Purchasing::supplierName('purchase_orders')),
            'total' => ColumnFilters::number('purchase_orders.total'),
            'received' => ColumnFilters::number(PurchaseOrder::RECEIVED_PCT),
            'creator' => ColumnFilters::text(self::CREATOR),
        ]);

        ListSort::apply($query, $request, [
            'order' => 'purchase_orders.order_number',
            'supplier' => 'lower('.Purchasing::supplierName('purchase_orders').')',
            'cost_center' => 'lower('.Purchasing::costCenterName('purchase_orders').')',
            'total' => 'purchase_orders.total',
            'received' => PurchaseOrder::RECEIVED_PCT,
            'creator' => 'lower('.self::CREATOR.')',
            'status' => ListSort::labels('purchase_orders.status', PurchaseOrder::STATES),
        ], fn ($q) => $q->orderByDesc('purchase_orders.created_at')->orderByDesc('purchase_orders.id'), 'purchase_orders.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (PurchaseOrder $order) => $this->row($order));

        return response()->json($page);
    }

    /**
     * Indicadores y conteo de cada pestaña, sobre las órdenes que la persona alcanza. Lo que
     * está en dólares se lleva a soles con el tipo de cambio de venta vigente.
     */
    public function summary()
    {
        $rate = (float) (Purchasing::rate() ?? 0);
        $inPen = "CASE WHEN purchase_orders.currency = 'USD' THEN purchase_orders.total * {$rate} ELSE purchase_orders.total END";
        $invoiced = PurchaseOrder::INVOICED;

        $totals = PurchaseOrder::query()->selectRaw("
            count(*) AS all_count,
            count(*) FILTER (WHERE status = 'DRAFT') AS draft,
            count(*) FILTER (WHERE status = 'SUBMITTED') AS submitted,
            coalesce(sum({$inPen}) FILTER (WHERE status = 'SUBMITTED'), 0) AS submitted_amount,
            count(*) FILTER (WHERE status IN ('APPROVED', 'PARTIALLY_RECEIVED')) AS receive,
            count(*) FILTER (WHERE status = 'RECEIVED') AS received,
            count(*) FILTER (WHERE status = 'CLOSED') AS closed,
            count(*) FILTER (WHERE status = 'RECEIVED' AND {$invoiced} < purchase_orders.total * 0.98) AS uninvoiced,
            coalesce(sum({$inPen}) FILTER (WHERE status IN ('APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED')), 0) AS committed,
            count(*) FILTER (WHERE currency = 'USD' AND status IN ('SUBMITTED', 'APPROVED', 'PARTIALLY_RECEIVED', 'RECEIVED')) AS usd_count
        ")->toBase()->first();

        return response()->json([
            'tabs' => [
                'ALL' => (int) $totals->all_count, 'DRAFT' => (int) $totals->draft, 'SUBMITTED' => (int) $totals->submitted,
                'RECEIVE' => (int) $totals->receive, 'RECEIVED' => (int) $totals->received, 'CLOSED' => (int) $totals->closed,
            ],
            'to_approve' => (int) $totals->submitted,
            'to_approve_amount' => round((float) $totals->submitted_amount, 2),
            'to_receive' => (int) $totals->receive,
            'received_uninvoiced' => (int) $totals->uninvoiced,
            'committed' => round((float) $totals->committed, 2),
            // Hay órdenes en dólares y ningún tipo de cambio registrado: no entran en los importes.
            'missing_rate' => (int) $totals->usd_count > 0 && $rate <= 0,
        ]);
    }

    /** Listas del formulario de la orden y del filtro por centro de costo. */
    public function options(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');

        return response()->json([
            'suppliers' => Purchasing::suppliers($tenantId),
            // Para filtrar se ofrecen todos; para elegir en un documento nuevo, solo los activos.
            'cost_centers' => Purchasing::costCenters($tenantId, false),
            'expense_accounts' => Purchasing::expenseAccounts($tenantId),
            'default_account' => Purchasing::DEFAULT_EXPENSE_ACCOUNT,
        ]);
    }

    public function show(string $purchaseOrder, Request $request)
    {
        return response()->json($this->detail($this->find($purchaseOrder), $request));
    }

    public function store(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $this->validated($request, $tenantId);
        $this->authorizeSubmit($request);

        $order = DB::transaction(function () use ($request, $tenantId, $data) {
            $order = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'order_number' => SequentialNumberGenerator::next($tenantId, 'purchase_orders', 'order_number', 'OC'),
                'status' => 'DRAFT',
                'owner_user_id' => $request->user()->id,
                'created_by' => $request->user()->id,
                ...$data['order'],
            ]);
            $this->writeItems($order, $data['items']);
            $this->audit($request, $order, 'purchase_order.create', ['to' => 'DRAFT', 'total' => (float) $order->total]);
            if ($request->boolean('submit')) {
                $this->move($request, $order, 'SUBMITTED', 'purchase_order.submit');
            }

            return $order;
        });

        return response()->json($this->detail($order, $request), 201);
    }

    public function update(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        if ($order->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => ['Solo se edita una orden de compra en borrador.']]);
        }
        $data = $this->validated($request, $order->tenant_id);

        DB::transaction(function () use ($request, $order, $data) {
            $before = (float) $order->total;
            $order->update($data['order']);
            PurchaseOrderItem::where('purchase_order_id', $order->id)->delete();
            $this->writeItems($order, $data['items']);
            $this->audit($request, $order, 'purchase_order.update', ['total_before' => $before, 'total' => (float) $order->total]);
            if ($request->boolean('submit')) {
                $this->move($request, $order, 'SUBMITTED', 'purchase_order.submit');
            }
        });

        return response()->json($this->detail($order, $request));
    }

    public function submit(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        $this->from($order, ['DRAFT'], 'enviar a aprobación');
        if (! PurchaseOrderItem::where('purchase_order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['status' => ['La orden de compra necesita al menos una línea.']]);
        }
        DB::transaction(function () use ($request, $order) {
            $this->move($request, $order, 'SUBMITTED', 'purchase_order.submit');
            Notifier::send('purchase_order.submitted', 'Orden de compra por aprobar', "{$order->order_number} · ".Notifier::money($order->total, $order->currency),
                "/purchase-orders/{$order->id}", array_diff(Notifier::withPermission('purchases.approve', $order), [$order->created_by]));
        });

        return response()->json($this->detail($order, $request));
    }

    /** Aprueba la orden. Quien la creó no la aprueba, salvo que la empresa desactive la segregación de funciones. */
    public function approve(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        $this->from($order, ['SUBMITTED'], 'aprobar');
        if ($this->segregated($order, $request)) {
            throw ValidationException::withMessages(['status' => ['Segregación de funciones: no puedes aprobar una orden de compra que tú creaste.']]);
        }

        DB::transaction(function () use ($request, $order) {
            $order->approved_by = $request->user()->id;
            $order->approved_at = now();
            // Como en el prototipo: sobre el presupuesto disponible se aprueba con advertencia, que queda registrada.
            $budget = Budgets::check($order->tenant_id, $order->cost_center_id, $this->subtotalPen($order), $order->id);
            $warning = $budget && $budget['over'] ? 'Aprobada sobre presupuesto: disponible S/ '.number_format($budget['available'], 2) : null;
            $order->budget_warning = $warning;
            $this->move($request, $order, 'APPROVED', 'purchase_order.approve', $warning);
        });

        return response()->json($this->detail($order, $request));
    }

    public function reject(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        $this->from($order, ['SUBMITTED'], 'rechazar');
        $reason = $this->reason($request);
        DB::transaction(function () use ($request, $order, $reason) {
            $order->reason = $reason;
            $this->move($request, $order, 'REJECTED', 'purchase_order.reject', $reason);
        });

        return response()->json($this->detail($order, $request));
    }

    /** "Corregir": una orden rechazada vuelve a borrador para editarla y enviarla otra vez. */
    public function reopen(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        $this->from($order, ['REJECTED'], 'corregir');
        DB::transaction(function () use ($request, $order) {
            $order->reason = null;
            $this->move($request, $order, 'DRAFT', 'purchase_order.reopen');
        });

        return response()->json($this->detail($order, $request));
    }

    /** Cierra la orden: ya no se recibe ni se factura más contra ella. Exige al menos una recepción. */
    public function close(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        $this->from($order, PurchaseOrder::COMMITTED, 'cerrar');
        if (! DB::table('purchase_receipts')->where('purchase_order_id', $order->id)->exists()) {
            throw ValidationException::withMessages(['status' => ['Registra la recepción (conformidad) antes de cerrar.']]);
        }
        DB::transaction(fn () => $this->move($request, $order, 'CLOSED', 'purchase_order.close'));

        return response()->json($this->detail($order, $request));
    }

    public function cancel(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        $this->from($order, ['DRAFT', 'SUBMITTED', 'APPROVED'], 'anular');
        $reason = $this->reason($request);
        // Con un comprobante del proveedor ya registrado contra ella, primero se anula ese comprobante.
        if (DB::table('supplier_invoices')->where('purchase_order_id', $order->id)->where('status', '<>', 'VOIDED')->exists()) {
            throw ValidationException::withMessages(['status' => ['La orden tiene comprobantes del proveedor registrados: anúlalos antes de anular la orden.']]);
        }
        DB::transaction(function () use ($request, $order, $reason) {
            $order->reason = $reason;
            $this->move($request, $order, 'CANCELLED', 'purchase_order.cancel', $reason);
        });

        return response()->json($this->detail($order, $request));
    }

    /** Recepción conforme por porcentaje. Lo acumulado no pasa del 100 % (también lo impide la base). */
    public function receive(string $purchaseOrder, Request $request)
    {
        $order = $this->find($purchaseOrder);
        if (! in_array($order->status, PurchaseOrder::RECEIVABLE, true)) {
            throw ValidationException::withMessages(['status' => ['Solo se recibe una orden aprobada.']]);
        }
        $data = $request->validate([
            'pct' => ['required', 'numeric', 'gt:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['pct.required' => 'Indica el porcentaje recibido.', 'pct.gt' => 'El porcentaje debe ser mayor a cero.']);
        $pct = round((float) $data['pct'], 2);
        $note = trim((string) ($data['note'] ?? ''));

        DB::transaction(function () use ($request, $order, $pct, $note) {
            // La orden se bloquea hasta terminar: dos recepciones a la vez no pasan juntas del 100 %.
            $order = PurchaseOrder::ignoringAccessScope()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $done = round((float) DB::table('purchase_receipts')->where('purchase_order_id', $order->id)->sum('received_pct'), 2);
            if ($done + $pct > 100.001) {
                $pending = rtrim(rtrim(number_format(100 - $done, 2, '.', ''), '0'), '.');
                throw ValidationException::withMessages(['pct' => ["Porcentaje inválido. Pendiente por recibir: {$pending} %."]]);
            }

            DB::table('purchase_receipts')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $order->tenant_id, 'purchase_order_id' => $order->id,
                'received_pct' => $pct, 'note' => $note !== '' ? $note : null, 'received_by' => $request->user()->id,
            ]);
            $shown = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
            $this->move($request, $order, $done + $pct >= 99.999 ? 'RECEIVED' : 'PARTIALLY_RECEIVED', 'purchase_order.receive', "Conformidad {$shown} %".($note !== '' ? " · {$note}" : ''), true);
        });

        return response()->json($this->detail($order, $request));
    }

    private function find(string $id): PurchaseOrder
    {
        abort_unless(Str::isUuid($id), 404);

        return PurchaseOrder::findOrFail($id);
    }

    private function listing()
    {
        return PurchaseOrder::query()
            ->select('purchase_orders.*')
            ->selectRaw(Purchasing::supplierName('purchase_orders').' AS supplier_name')
            ->selectRaw(Purchasing::costCenterName('purchase_orders').' AS cost_center_name')
            ->selectRaw(self::FIRST_ITEM.' AS first_item')
            ->selectRaw(self::ITEMS_COUNT.' AS items_count')
            ->selectRaw(PurchaseOrder::RECEIVED_PCT.' AS received_pct')
            ->selectRaw(PurchaseOrder::INVOICED.' AS invoiced')
            ->selectRaw(self::CREATOR.' AS creator_name');
    }

    /** Enviar a aprobación es gestionar la orden: quien solo crea la deja en borrador. */
    private function authorizeSubmit(Request $request): void
    {
        if ($request->boolean('submit') && ! CheckPermission::granted($request, 'purchases.manage')) {
            abort(response()->json(['message' => 'No tienes el permiso requerido: purchases.manage', 'code' => 'PERMISSION_DENIED'], 403));
        }
    }

    /** @return array{order: array, items: array<int, array>} */
    private function validated(Request $request, string $tenantId): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'cost_center_id' => ['required', 'uuid'],
            'currency' => ['required', 'in:PEN,USD'],
            'needed_by' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'items.*.account_code' => ['required', 'string', Purchasing::expenseAccountRule($request->attributes->get('tenant_id'))],
        ], [
            'supplier_id.required' => 'Selecciona un proveedor.',
            'cost_center_id.required' => 'Selecciona el centro de costo.',
            'items.required' => 'Agrega al menos una línea.',
            'items.min' => 'Agrega al menos una línea.',
            'items.*.description.required' => 'Cada línea necesita descripción, cantidad y costo unitario.',
            'items.*.quantity.required' => 'Cada línea necesita descripción, cantidad y costo unitario.',
            'items.*.quantity.gt' => 'Cada línea necesita descripción, cantidad y costo unitario.',
            'items.*.unit_cost.required' => 'Cada línea necesita descripción, cantidad y costo unitario.',
            'items.*.unit_cost.gt' => 'Cada línea necesita descripción, cantidad y costo unitario.',
            'items.*.account_code.in' => 'La cuenta de gasto indicada no existe.',
        ]);

        $supplier = Purchasing::supplier($tenantId, $data['supplier_id']);
        if (! $supplier || $supplier->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['supplier_id' => ['Selecciona un proveedor activo de Clientes y actores (rol Proveedor).']]);
        }
        if (! DB::table('cost_centers')->where('tenant_id', $tenantId)->where('id', $data['cost_center_id'])->where('active', true)->exists()) {
            throw ValidationException::withMessages(['cost_center_id' => ['El centro de costo indicado no existe o está inactivo.']]);
        }

        $items = array_values(array_map(fn (array $item) => [
            'description' => trim($item['description']),
            'quantity' => round((float) $item['quantity'], 3),
            'unit_cost' => round((float) $item['unit_cost'], 2),
            'account_code' => (string) $item['account_code'],
        ], $data['items']));
        $subtotal = round(array_sum(array_map(fn (array $item) => round($item['quantity'] * $item['unit_cost'], 2), $items)), 2);
        $total = Purchasing::withTax($subtotal);

        return [
            'order' => [
                'supplier_id' => $supplier->id,
                'cost_center_id' => $data['cost_center_id'],
                'currency' => $data['currency'],
                'needed_by' => $data['needed_by'] ?? now()->addDays(10)->toDateString(),
                'notes' => isset($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null,
                'subtotal' => $subtotal,
                'tax' => round($total - $subtotal, 2),
                'total' => $total,
            ],
            'items' => $items,
        ];
    }

    private function writeItems(PurchaseOrder $order, array $items): void
    {
        foreach ($items as $index => $item) {
            PurchaseOrderItem::create(['tenant_id' => $order->tenant_id, 'purchase_order_id' => $order->id, 'position' => $index + 1, ...$item]);
        }
    }

    private function from(PurchaseOrder $order, array $states, string $verb): void
    {
        if (! in_array($order->status, $states, true)) {
            $current = mb_strtolower(PurchaseOrder::STATES[$order->status] ?? $order->status);
            throw ValidationException::withMessages(['status' => ["No se puede {$verb} una orden de compra en estado «{$current}»."]]);
        }
    }

    private function reason(Request $request): string
    {
        return trim($request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indica el motivo.'])['reason']);
    }

    private function segregated(PurchaseOrder $order, Request $request): bool
    {
        return $order->created_by === $request->user()->id
            && TenantSettingsController::settings($order->tenant_id)['segregation'];
    }

    /** Cambia el estado y lo deja en el historial. Debe llamarse dentro de una transacción. */
    private function move(Request $request, PurchaseOrder $order, string $to, string $action, ?string $note = null, bool $always = false): void
    {
        $from = $order->status;
        $order->status = $to;
        $order->save();
        $this->audit($request, $order, $action, array_filter([
            'from' => $from !== $to || $always ? $from : null, 'to' => $to, 'note' => $note,
        ]));
    }

    private function row(PurchaseOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'first_item' => $order->first_item,
            'items_count' => (int) $order->items_count,
            'supplier' => ['id' => $order->supplier_id, 'name' => $order->supplier_name],
            'cost_center' => ['id' => $order->cost_center_id, 'name' => $order->cost_center_name],
            'currency' => $order->currency,
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'received_pct' => round((float) $order->received_pct, 2),
            'invoiced' => round((float) $order->invoiced, 2),
            'creator' => $order->creator_name,
            'needed_by' => $order->needed_by?->toDateString(),
            'status' => $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
            'lock_version' => (int) $order->lock_version,
        ];
    }

    /** Subtotal de la orden en soles (en dólares, al tipo de cambio vigente). */
    private function subtotalPen(PurchaseOrder $order): float
    {
        return round((float) $order->subtotal * ($order->currency === 'USD' ? (Purchasing::rate() ?? 1.0) : 1.0), 2);
    }

    private function detail(PurchaseOrder $order, Request $request): array
    {
        $order = $this->listing()->with('items', 'approver:id,name')->findOrFail($order->id);
        $supplier = Purchasing::identity(Purchasing::supplier($order->tenant_id, $order->supplier_id));
        // Los comprobantes del proveedor son de Cuentas por pagar: solo con su permiso, y por su modelo.
        $seesInvoices = CheckPermission::granted($request, 'payables.view');

        return [
            ...$this->row($order),
            'supplier' => ['id' => $order->supplier_id, 'name' => $order->supplier_name, 'tax_id' => $supplier['tax_id'], 'party_id' => $supplier['party_id']],
            'notes' => $order->notes,
            'reason' => $order->reason,
            'created_by' => $order->created_by,
            'approver' => $order->approver?->name,
            'approved_at' => $order->approved_at?->toIso8601String(),
            'items' => $order->items->map(fn (PurchaseOrderItem $item) => [
                'id' => $item->id, 'description' => $item->description, 'quantity' => (float) $item->quantity, 'unit_cost' => (float) $item->unit_cost,
                'account_code' => $item->account_code, 'account' => Purchasing::accountLabel($item->account_code),
                'subtotal' => round((float) $item->quantity * (float) $item->unit_cost, 2),
            ])->values(),
            'receipts' => DB::table('purchase_receipts')->leftJoin('users', 'users.id', '=', 'purchase_receipts.received_by')
                ->where('purchase_receipts.purchase_order_id', $order->id)->orderBy('purchase_receipts.received_at')
                ->get(['purchase_receipts.id', 'purchase_receipts.received_pct', 'purchase_receipts.note', 'purchase_receipts.received_at', 'users.name'])
                ->map(fn ($receipt) => [
                    'id' => $receipt->id, 'pct' => (float) $receipt->received_pct, 'note' => $receipt->note, 'by' => $receipt->name,
                    'at' => \Illuminate\Support\Carbon::parse($receipt->received_at)->toIso8601String(),
                ])->values(),
            // Quien la creó no la aprueba mientras la empresa tenga activa la segregación de funciones.
            'segregated' => $order->status === 'SUBMITTED' && $this->segregated($order, $request),
            // Presupuesto del centro de costo (Contabilidad → Centros de costo y presupuestos), sin contar esta orden.
            'budget' => Budgets::check($order->tenant_id, $order->cost_center_id, $this->subtotalPen($order), $order->id),
            'supplier_invoices' => $seesInvoices
                ? SupplierInvoiceController::listing()->where('supplier_invoices.purchase_order_id', $order->id)
                    ->orderByDesc('supplier_invoices.issue_date')->orderByDesc('supplier_invoices.created_at')->limit(200)->get()
                    ->map(fn (SupplierInvoice $invoice) => SupplierInvoiceController::row($invoice, true))->values()
                : null,
            'history' => AuditHistory::for('PurchaseOrder', $order->id),
        ];
    }

    private function audit(Request $request, PurchaseOrder $order, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'PurchaseOrder',
            entityId: $order->id,
            metadata: $metadata,
        );
    }
}
