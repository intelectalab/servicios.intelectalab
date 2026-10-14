<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Fiscal\FiscalGateway;
use App\Modules\Finance\Models\BillableEvent;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Models\ReceivableMovement;
use App\Modules\Finance\Support\Billing;
use App\Modules\Finance\Support\FiscalSettings;
use App\Modules\Finance\Support\Treasury;
use App\Modules\Finance\Support\Withholding;
use App\Services\AuditHistory;
use App\Services\AuditLogger;
use App\Services\ColumnFilters;
use App\Services\ListSort;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comprobantes: nacen en borrador desde eventos facturables del mismo cliente y moneda; al
 * emitirse reciben serie y correlativo, crean su cuenta por cobrar y ya no se modifican. Uno
 * emitido se corrige con una nota de crédito, nunca editándolo.
 *
 * No hay proveedor de facturación electrónica conectado: el comprobante se emite en el sistema
 * y queda "Sin enviar" ante SUNAT.
 */
class InvoiceController extends Controller
{
    private const MAX_EVENTS = 100;

    /** Días desde la emisión en que SUNAT admite la comunicación de baja. */
    private const VOID_DAYS = 7;

    private const WITH = ['customer.party.person', 'customer.party.organization'];

    /** Pestañas del listado: clave => condición. */
    private const TABS = ['DRAFT', 'ISSUED', 'CORRECTED'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $tab = (string) $request->query('tab', '');

        $query = Invoice::query()->with(self::WITH)
            ->when(in_array($tab, self::TABS, true), fn ($q) => $q->where('invoices.status', $tab))
            ->when($tab === 'REJECTED', fn ($q) => $q->where('invoices.fiscal_status', 'REJECTED'))
            ->when(isset(Invoice::TYPES[$request->query('type')]), fn ($q) => $q->where('invoices.invoice_type', $request->query('type')))
            ->when(Str::isUuid((string) $request->query('customer_id')), fn ($q) => $q->where('invoices.customer_id', $request->query('customer_id')))
            ->when($search !== '', function ($q) use ($search) {
                // Por número o cliente.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $q->where(fn ($w) => $w
                    ->where('invoices.invoice_number', 'ILIKE', $like)
                    ->orWhereRaw(ListSort::customerName('invoices').' ILIKE ?', [$like]));
            });

        ColumnFilters::apply($query, $request, [
            'invoice' => ColumnFilters::text('invoices.invoice_number'),
            'customer' => ColumnFilters::text("concat_ws(' ', ".ListSort::customerName('invoices').', invoices.customer_tax_id)'),
            'issue' => ColumnFilters::date('invoices.issue_date'),
            'total' => ColumnFilters::number('invoices.total'),
        ]);

        ListSort::apply($query, $request, [
            'invoice' => 'invoices.invoice_number',
            'customer' => 'lower('.ListSort::customerName('invoices').')',
            'issue' => 'invoices.issue_date',
            'total' => 'invoices.total',
            'fiscal' => ListSort::labels('invoices.fiscal_status', Invoice::FISCAL),
            'status' => ListSort::labels('invoices.status', Invoice::STATES),
        ], fn ($q) => $q->orderByDesc('invoices.created_at')->orderByDesc('invoices.id'), 'invoices.id');

        $page = $query->paginate(min(max($request->integer('per_page', 20), 1), 200));
        $page->getCollection()->transform(fn (Invoice $invoice) => $this->row($invoice));

        return response()->json($page);
    }

    /** Conteos de las pestañas del listado, sobre lo que la persona alcanza. */
    public function summary()
    {
        $counts = Invoice::query()->selectRaw("count(*) AS total,
            count(*) FILTER (WHERE status = 'DRAFT') AS draft,
            count(*) FILTER (WHERE status = 'ISSUED') AS issued,
            count(*) FILTER (WHERE fiscal_status = 'REJECTED') AS rejected,
            count(*) FILTER (WHERE status = 'CORRECTED') AS corrected")->toBase()->first();

        return response()->json([
            'ALL' => (int) $counts->total, 'DRAFT' => (int) $counts->draft, 'ISSUED' => (int) $counts->issued,
            'REJECTED' => (int) $counts->rejected, 'CORRECTED' => (int) $counts->corrected,
        ]);
    }

    public function show(string $invoice, Request $request)
    {
        return response()->json($this->detail($this->find($invoice), $request));
    }

    /**
     * Borrador a partir de eventos facturables pendientes, todos del mismo cliente y moneda. Los
     * eventos quedan facturados en el mismo paso: dos personas no facturan el mismo evento.
     */
    public function fromEvents(Request $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $data = $request->validate([
            'event_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
            'event_ids.*' => ['uuid', 'distinct'],
            'type' => ['required', 'in:INVOICE,RECEIPT'],
        ], [
            'event_ids.required' => 'Selecciona al menos un evento facturable.',
            'event_ids.max' => 'Un comprobante admite como mucho '.self::MAX_EVENTS.' eventos.',
        ]);

        $invoice = DB::transaction(function () use ($request, $tenantId, $data) {
            // Por su modelo: un evento fuera del alcance de la persona "no existe". Se bloquean hasta terminar.
            // Las líneas salen en el orden en que se eligieron.
            $order = array_flip($data['event_ids']);
            $events = BillableEvent::query()->whereIn('id', $data['event_ids'])->lockForUpdate()->get()
                ->sortBy(fn (BillableEvent $event) => $order[$event->id])->values();

            if ($events->count() !== count($data['event_ids'])) {
                throw ValidationException::withMessages(['event_ids' => ['Alguno de los eventos seleccionados ya no existe.']]);
            }
            if ($events->contains(fn (BillableEvent $event) => $event->status !== 'PENDING')) {
                throw ValidationException::withMessages(['event_ids' => ['Alguno de los eventos ya fue facturado o anulado: un evento no se factura dos veces.']]);
            }
            if ($events->pluck('customer_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['event_ids' => ['Todos los eventos deben ser del mismo cliente.']]);
            }
            if ($events->pluck('currency')->unique()->count() > 1) {
                throw ValidationException::withMessages(['event_ids' => ['Todos los eventos deben estar en la misma moneda (soles o dólares).']]);
            }

            $first = $events->first();
            $customer = \App\Modules\Core\Models\Customer::with('party.person', 'party.organization')->where('tenant_id', $tenantId)->findOrFail($first->customer_id);
            $identity = Billing::identity($customer);
            $this->assertFiscalData($data['type'], $identity);

            $subtotal = round((float) $events->sum(fn (BillableEvent $event) => (float) $event->base), 2);
            $total = round((float) $events->sum(fn (BillableEvent $event) => (float) $event->amount), 2);
            $contracts = $events->pluck('contract_id')->unique();

            $invoice = Invoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'contract_id' => $contracts->count() === 1 ? $contracts->first() : null,
                'invoice_type' => $data['type'],
                // Provisional: la serie definitiva se toma al emitir (normal o de contingencia).
                'series' => FiscalSettings::seriesFor(FiscalSettings::for($tenantId), $data['type'])[1],
                'status' => 'DRAFT',
                'currency' => $first->currency,
                'due_date' => now()->addDays((int) ($customer->payment_terms_days ?: Billing::DEFAULT_TERMS_DAYS))->toDateString(),
                'subtotal' => $subtotal,
                'tax_total' => round($total - $subtotal, 2),
                'total' => $total,
                'balance_due' => 0,
                'fiscal_status' => 'NOT_SENT',
                'customer_tax_id' => $identity['tax_id'],
                'customer_name' => $identity['name'],
                'owner_user_id' => $first->owner_user_id ?? $request->user()->id,
                'created_by' => $request->user()->id,
                // El comprobante es de la sede de lo que factura; si no tiene, de la de quien lo prepara.
                ...($first->branch_id ? ['branch_id' => $first->branch_id] : []),
            ]);

            foreach ($events->values() as $position => $event) {
                InvoiceItem::create([
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice->id,
                    'position' => $position + 1,
                    'description' => $event->description,
                    'quantity' => 1,
                    'unit_price' => $event->base,
                    'tax_rate' => Billing::TAX_RATE,
                    'tax_amount' => round((float) $event->amount - (float) $event->base, 2),
                    'line_total' => $event->amount,
                    'billable_event_id' => $event->id,
                ]);
                $event->update(['status' => 'INVOICED', 'invoice_id' => $invoice->id]);
            }

            $this->audit($request, $invoice, 'invoice.create', ['to' => 'DRAFT', 'events' => $events->count(), 'total' => $total]);

            return $invoice;
        });

        return response()->json($this->detail($invoice, $request), 201);
    }

    /**
     * Emite el borrador: serie y correlativo sin repetir, fecha de emisión, datos del cliente a
     * ese momento y su cuenta por cobrar. Después ya no se modifica.
     */
    public function issue(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        $this->assertStatus($invoice, ['DRAFT'], 'emitir');
        $tenantId = $request->attributes->get('tenant_id');

        $invoice->load(self::WITH);
        $identity = Billing::identity($invoice->customer);
        $this->assertFiscalData($invoice->invoice_type, $identity);

        $rate = null;
        if ($invoice->currency === 'USD') {
            $rate = ExchangeRate::current('USD')?->sell;
            if (! $rate) {
                throw ValidationException::withMessages(['currency' => ['Registra el tipo de cambio antes de emitir un comprobante en dólares.']]);
            }
        }

        DB::transaction(function () use ($request, $invoice, $tenantId, $identity, $rate) {
            // La configuración se lee dentro de la operación: la serie (normal o de contingencia) es la de este momento.
            $settings = FiscalSettings::for($tenantId);
            $contingency = $settings['mode'] === 'CONTINGENCY';
            [$seriesType, $series] = FiscalSettings::seriesFor($settings, $invoice->invoice_type);
            $withholding = Withholding::compute($invoice, $settings, (bool) $invoice->customer?->retention_agent, $rate ? (float) $rate : null);

            $number = Billing::nextNumber($tenantId, $seriesType, $series);
            $invoice->update([
                'series' => $series,
                'number' => $number,
                'invoice_number' => Billing::formatNumber($series, $number),
                'issue_date' => now()->toDateString(),
                'issued_at' => now(),
                'status' => 'ISSUED',
                'exchange_rate' => $rate,
                'fiscal_status' => $contingency ? 'CONTINGENCY' : 'NOT_SENT',
                'fiscal_response' => $contingency ? FiscalSettings::CONTINGENCY_NOTE : Billing::NOT_SENT_NOTE,
                'customer_tax_id' => $identity['tax_id'],
                'customer_name' => $identity['name'],
                // Su detracción o retención queda fijada al emitir, en soles.
                'withholding_type' => $withholding['type'] ?? null,
                'withholding_code' => $withholding['code'] ?? null,
                'withholding_rate' => $withholding['rate'] ?? null,
                'withholding_amount' => $withholding['amount'] ?? null,
                'withholding_status' => $withholding ? 'PENDING' : null,
            ]);

            $this->openReceivable($request, $invoice);
            $this->audit($request, $invoice, 'invoice.issue', array_filter([
                'from' => 'DRAFT', 'to' => 'ISSUED',
                'note' => $invoice->invoice_number.($contingency ? ' · en contingencia' : '').($withholding ? ' · '.Withholding::label($withholding['type'], $withholding['code'], $withholding['rate']) : ''),
            ]));
            // Con proveedor conectado queda "En envío" y sale hacia SUNAT al cerrar esta operación.
            // En contingencia no sale: se informa al restablecer el servicio.
            if (! $contingency) {
                FiscalGateway::queue($invoice, $request->user()->id);
            }
        });

        return response()->json($this->detail($invoice, $request));
    }

    /**
     * Nota de crédito por el total: corrige un comprobante emitido sin tocarlo. El original queda
     * "Corregido" y su cuenta por cobrar, anulada. Si ya tiene cobros aplicados, primero se
     * reembolsan o se llevan a otra cuenta: una cuenta anulada no retiene dinero del cliente.
     */
    public function creditNote(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        if ($invoice->status !== 'ISSUED' || $invoice->invoice_type === 'CREDIT_NOTE') {
            throw ValidationException::withMessages(['status' => ['Solo un comprobante emitido admite nota de crédito.']]);
        }
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Indica el motivo de la nota de crédito.'],
        )['reason']);
        $tenantId = $request->attributes->get('tenant_id');

        $note = DB::transaction(function () use ($request, $invoice, $tenantId, $reason) {
            // Sin importar el alcance de quien corrige: la cuenta es del comprobante.
            $receivable = Receivable::ignoringAccessScope()->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($receivable && (float) $receivable->balance_amount < (float) $receivable->original_amount) {
                throw ValidationException::withMessages([
                    'status' => ['El comprobante tiene cobros o constancias aplicados a su cuenta por cobrar. Reembolsa esos cobros antes de emitir la nota de crédito.'],
                ]);
            }

            $settings = FiscalSettings::for($tenantId);
            $contingency = $settings['mode'] === 'CONTINGENCY';
            $series = FiscalSettings::seriesFor($settings, 'CREDIT_NOTE', $invoice)[1];
            $note = Invoice::create([
                'tenant_id' => $tenantId,
                'customer_id' => $invoice->customer_id,
                'contract_id' => $invoice->contract_id,
                'invoice_type' => 'CREDIT_NOTE',
                'series' => $series,
                'status' => 'DRAFT',
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate,
                'due_date' => now()->toDateString(),
                'subtotal' => -1 * (float) $invoice->subtotal,
                'tax_total' => -1 * (float) $invoice->tax_total,
                'total' => -1 * (float) $invoice->total,
                'balance_due' => 0,
                'fiscal_status' => 'NOT_SENT',
                'customer_tax_id' => $invoice->customer_tax_id,
                'customer_name' => $invoice->customer_name,
                'related_invoice_id' => $invoice->id,
                'reason' => $reason,
                'owner_user_id' => $invoice->owner_user_id,
                'created_by' => $request->user()->id,
                'branch_id' => $invoice->branch_id,
            ]);
            InvoiceItem::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $note->id,
                'position' => 1,
                'description' => Str::limit("Anulación de {$invoice->invoice_number} · {$reason}", 1000, ''),
                'quantity' => 1,
                'unit_price' => -1 * (float) $invoice->subtotal,
                'tax_rate' => Billing::TAX_RATE,
                'tax_amount' => -1 * (float) $invoice->tax_total,
                'line_total' => -1 * (float) $invoice->total,
            ]);

            $number = Billing::nextNumber($tenantId, 'CREDIT_NOTE', $series);
            $note->update([
                'number' => $number,
                'invoice_number' => Billing::formatNumber($series, $number),
                'issue_date' => now()->toDateString(),
                'issued_at' => now(),
                'status' => 'ISSUED',
                'fiscal_status' => $contingency ? 'CONTINGENCY' : 'NOT_SENT',
                'fiscal_response' => $contingency ? FiscalSettings::CONTINGENCY_NOTE : Billing::NOT_SENT_NOTE,
            ]);

            $invoice->update(['status' => 'CORRECTED']);
            if ($receivable && (float) $receivable->balance_amount > 0) {
                // El saldo y el estado de la cuenta los actualiza la base con este movimiento.
                ReceivableMovement::create([
                    'tenant_id' => $tenantId, 'receivable_id' => $receivable->id, 'movement_type' => 'CREDIT_NOTE',
                    'amount' => -1 * (float) $receivable->balance_amount, 'reference' => $note->invoice_number, 'created_by' => $request->user()->id,
                ]);
            }

            $this->audit($request, $note, 'invoice.credit_note', ['to' => 'ISSUED', 'note' => "{$reason} (corrige {$invoice->invoice_number})"]);
            $this->audit($request, $invoice, 'invoice.correct', ['from' => 'ISSUED', 'to' => 'CORRECTED', 'note' => "{$note->invoice_number}: {$reason}"]);
            if (! $contingency) {
                FiscalGateway::queue($note, $request->user()->id);
            }

            return $note;
        });

        return response()->json($this->detail($note, $request), 201);
    }

    /**
     * Envía (o reintenta enviar) a SUNAT un comprobante ya emitido: uno rechazado, uno que quedó
     * "En envío" sin respuesta, o uno emitido antes de conectar el proveedor. Uno aceptado no se reenvía.
     */
    public function fiscalRetry(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        if (! in_array($invoice->status, ['ISSUED', 'CORRECTED'], true) || ! $invoice->number || $invoice->fiscal_status === 'ACCEPTED') {
            throw ValidationException::withMessages(['status' => ['Solo se envía a SUNAT un comprobante emitido que aún no fue aceptado.']]);
        }

        DB::transaction(function () use ($request, $invoice) {
            if (! FiscalGateway::queue($invoice, $request->user()->id)) {
                throw ValidationException::withMessages(['status' => ['No hay un proveedor de facturación electrónica conectado.']]);
            }
            $this->audit($request, $invoice, 'invoice.fiscal_retry');
        });

        return response()->json($this->detail($invoice, $request));
    }

    /**
     * En un borrador de factura, decide cómo se calcula su detracción o retención: según la
     * configuración de la empresa, con un código de detracción concreto, como retención o ninguna.
     */
    public function withholdingMode(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        $this->assertStatus($invoice, ['DRAFT'], 'cambiar la detracción de');
        if ($invoice->invoice_type !== 'INVOICE') {
            throw ValidationException::withMessages(['mode' => ['La detracción y la retención aplican solo a facturas.']]);
        }
        $data = $request->validate([
            'mode' => ['required', 'in:'.implode(',', array_keys(Withholding::MODES))],
            'code' => ['nullable', 'required_if:mode,DETRACTION', 'in:'.implode(',', array_keys(Withholding::CODES))],
        ], ['code.required_if' => 'Selecciona el código de detracción.']);

        DB::transaction(function () use ($request, $invoice, $data) {
            $invoice->update(['withholding_mode' => $data['mode'], 'withholding_code' => $data['mode'] === 'DETRACTION' ? $data['code'] : null]);
            $this->audit($request, $invoice, 'invoice.withholding_mode', [
                'note' => Withholding::MODES[$data['mode']].($data['mode'] === 'DETRACTION' ? " · código {$data['code']}" : ''),
            ]);
        });

        return response()->json($this->detail($invoice, $request));
    }

    /**
     * Registra la constancia de depósito de la detracción o el certificado de retención que
     * entrega el cliente: ese importe baja de la cuenta por cobrar y, si es detracción, entra a la
     * cuenta de detracciones de la empresa.
     */
    public function withholdingReceipt(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        if (! $invoice->withholding_type || ! in_array($invoice->status, ['ISSUED', 'CORRECTED'], true)) {
            throw ValidationException::withMessages(['status' => ['El comprobante no tiene detracción ni retención.']]);
        }
        if ($invoice->withholding_status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => ['Ya se registró la constancia.']]);
        }
        $data = $request->validate([
            'number' => ['required', 'string', 'max:100'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ], [
            'number.required' => 'Indica el número de constancia o certificado.',
            'date.before_or_equal' => 'La fecha no puede ser futura.',
        ]);
        $number = trim($data['number']);
        $date = \Illuminate\Support\Carbon::parse($data['date'] ?? now())->toDateString();
        $detraction = $invoice->withholding_type === 'DETRACTION';

        DB::transaction(function () use ($request, $invoice, $number, $date, $detraction) {
            // Sin importar el alcance de quien registra: la cuenta es del comprobante.
            $receivable = Receivable::ignoringAccessScope()->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if (! $receivable || ! in_array($receivable->status, Receivable::OPEN, true) || (float) $receivable->balance_amount <= 0) {
                throw ValidationException::withMessages(['status' => ['La cuenta por cobrar no tiene saldo.']]);
            }

            $amountPen = (float) $invoice->withholding_amount;
            // La cuenta está en la moneda del comprobante; el importe retenido, en soles.
            $inDocument = $invoice->currency === 'USD' && (float) $invoice->exchange_rate > 0 ? round($amountPen / (float) $invoice->exchange_rate, 2) : $amountPen;
            $applied = round(min($inDocument, (float) $receivable->balance_amount), 2);

            // El saldo y el estado de la cuenta los actualiza la base con este movimiento.
            ReceivableMovement::create([
                'tenant_id' => $invoice->tenant_id, 'receivable_id' => $receivable->id, 'movement_type' => 'WITHHOLDING',
                'amount' => -1 * $applied, 'reference' => ($detraction ? 'Detracción · constancia ' : 'Retención · certificado ').$number, 'created_by' => $request->user()->id,
            ]);
            $invoice->update([
                'withholding_status' => 'RECEIVED', 'withholding_number' => $number, 'withholding_date' => $date, 'withholding_registered_by' => $request->user()->id,
            ]);
            $banked = $detraction && Treasury::detractionIn($invoice, $amountPen, $number, $date, $request->user()->id);

            $this->audit($request, $invoice, 'invoice.withholding_receipt', [
                'note' => ($detraction ? 'Constancia de detracción ' : 'Certificado de retención ').$number.' · S/ '.number_format($amountPen, 2)
                    .($detraction && ! $banked ? ' (sin cuenta de detracciones registrada en Bancos y caja)' : ''),
                'amount' => $amountPen,
            ]);
        });

        return response()->json($this->detail($invoice, $request));
    }

    /**
     * Comunicación de baja: anula ante SUNAT un comprobante aceptado, dentro de los 7 días de
     * emitido y sin cobros. Fuera de eso, se corrige con nota de crédito. La presenta el proveedor;
     * mientras no responda que la aceptó, el comprobante sigue emitido.
     */
    public function void(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        $reason = trim($request->validate(
            ['reason' => ['required', 'string', 'max:1000']],
            ['reason.required' => 'Indica el motivo de la baja.'],
        )['reason']);

        $requestId = DB::transaction(function () use ($request, $invoice, $reason) {
            $receivable = Receivable::ignoringAccessScope()->where('invoice_id', $invoice->id)->lockForUpdate()->first();
            if ($blocked = $this->voidBlocked($invoice, $receivable)) {
                throw ValidationException::withMessages(['status' => [$blocked]]);
            }

            $id = (string) Str::uuid();
            DB::table('fiscal_void_requests')->insert([
                'id' => $id, 'tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
                'reason' => $reason, 'status' => 'PENDING', 'requested_by' => $request->user()->id, 'created_at' => now(),
            ]);
            $this->audit($request, $invoice, 'invoice.void_request', ['note' => $reason]);
            // Sale hacia el proveedor al cerrar esta operación, nunca dentro de ella.
            FiscalGateway::queueVoid($id, $invoice->tenant_id, $request->user()->id);

            return $id;
        });

        return response()->json([...$this->detail($invoice, $request), 'void_request_id' => $requestId], 201);
    }

    /** Por qué no procede la baja de un comprobante; null si procede. */
    private function voidBlocked(Invoice $invoice, ?Receivable $receivable): ?string
    {
        return match (true) {
            $invoice->status !== 'ISSUED' || $invoice->fiscal_status !== 'ACCEPTED' => 'Solo se da de baja un comprobante emitido y aceptado por SUNAT.',
            $invoice->invoice_type === 'CREDIT_NOTE' => 'Una nota de crédito no se da de baja desde aquí.',
            ! FiscalGateway::supportsVoid($invoice->tenant_id) => 'El proveedor de facturación electrónica conectado no ofrece comunicaciones de baja.',
            $invoice->issue_date && $invoice->issue_date->diffInDays(today()) > self::VOID_DAYS => 'Pasaron más de '.self::VOID_DAYS.' días desde la emisión: corrige con nota de crédito.',
            $receivable && (float) $receivable->balance_amount < (float) $receivable->original_amount => 'El comprobante tiene cobros aplicados: corrige con nota de crédito.',
            DB::table('fiscal_void_requests')->where('invoice_id', $invoice->id)->whereIn('status', ['PENDING', 'ACCEPTED'])->exists() => 'El comprobante ya tiene una comunicación de baja en curso.',
            default => null,
        };
    }

    /** Descarta un borrador: no llegó a emitirse, así que sus eventos vuelven a quedar por facturar. */
    public function discard(string $invoice, Request $request)
    {
        $invoice = $this->find($invoice);
        $this->assertStatus($invoice, ['DRAFT'], 'descartar');

        DB::transaction(function () use ($request, $invoice) {
            $released = BillableEvent::ignoringAccessScope()->where('invoice_id', $invoice->id)->where('status', 'INVOICED')
                ->update(['status' => 'PENDING', 'invoice_id' => null]);
            $invoice->update(['status' => 'CANCELLED']);
            $this->audit($request, $invoice, 'invoice.discard', ['from' => 'DRAFT', 'to' => 'CANCELLED', 'events' => $released]);
        });

        return response()->json($this->detail($invoice, $request));
    }

    private function find(string $invoice): Invoice
    {
        abort_unless(Str::isUuid($invoice), 404);

        return Invoice::findOrFail($invoice);
    }

    private function assertStatus(Invoice $invoice, array $allowed, string $verb): void
    {
        if (! in_array($invoice->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["No se puede {$verb} un comprobante ".mb_strtolower(Invoice::STATES[$invoice->status] ?? $invoice->status).'.'],
            ]);
        }
    }

    /** Una factura exige el RUC del cliente; sin él corresponde boleta. */
    private function assertFiscalData(string $type, array $identity): void
    {
        if ($type === 'INVOICE' && ! $identity['has_ruc']) {
            throw ValidationException::withMessages([
                'type' => ['La factura requiere el RUC del cliente. Emite boleta o completa sus datos en Clientes y actores.'],
            ]);
        }
    }

    /** Cuenta por cobrar del comprobante emitido. Nace en cero: su cargo la lleva al importe (lo hace la base). */
    private function openReceivable(Request $request, Invoice $invoice): void
    {
        $receivable = Receivable::create([
            'tenant_id' => $invoice->tenant_id,
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'original_amount' => $invoice->total,
            'balance_amount' => 0,
            'currency' => $invoice->currency,
            'due_date' => $invoice->due_date,
            'status' => 'OPEN',
            'owner_user_id' => $invoice->owner_user_id,
            'branch_id' => $invoice->branch_id,
        ]);
        ReceivableMovement::create([
            'tenant_id' => $invoice->tenant_id, 'receivable_id' => $receivable->id, 'movement_type' => 'CHARGE',
            'amount' => $invoice->total, 'reference' => $invoice->invoice_number, 'created_by' => $request->user()->id,
        ]);
    }

    private function row(Invoice $invoice): array
    {
        // En borrador se muestran los datos vigentes del cliente; emitido, los que quedaron en el comprobante
        // (los comprobantes anteriores a esta pantalla no los guardaron).
        $identity = $invoice->status === 'DRAFT' || ! $invoice->customer_name ? Billing::identity($invoice->customer) : null;

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'invoice_type' => $invoice->invoice_type,
            'series' => $invoice->series,
            'customer' => $invoice->customer,
            'customer_name' => $identity['name'] ?? $invoice->customer_name,
            'customer_tax_id' => $identity ? $identity['tax_id'] : $invoice->customer_tax_id,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'exchange_rate' => $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null,
            'subtotal' => (float) $invoice->subtotal,
            'tax_total' => (float) $invoice->tax_total,
            'total' => (float) $invoice->total,
            'balance_due' => (float) $invoice->balance_due,
            'status' => $invoice->status,
            'fiscal_status' => $invoice->fiscal_status,
            // Detracción o retención fijada al emitir; el importe, en soles.
            'withholding' => $invoice->withholding_type ? [
                'type' => $invoice->withholding_type,
                'code' => $invoice->withholding_code,
                'rate' => (float) $invoice->withholding_rate,
                'amount' => (float) $invoice->withholding_amount,
                'status' => $invoice->withholding_status,
                'number' => $invoice->withholding_number,
                'date' => $invoice->withholding_date?->toDateString(),
            ] : null,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'lock_version' => (int) $invoice->lock_version,
        ];
    }

    private function detail(Invoice $invoice, Request $request): array
    {
        $invoice = Invoice::query()
            ->with([...self::WITH, 'items', 'contract:id,contract_number', 'related:id,invoice_number', 'creditNote:id,invoice_number,related_invoice_id'])
            ->findOrFail($invoice->id);

        // La cuenta por cobrar es un dato del comprobante: quien lo ve, ve su saldo y sus movimientos.
        $receivable = Receivable::ignoringAccessScope()->with('movements')->where('invoice_id', $invoice->id)->first();

        // Por su modelo: solo los cobros que la persona alcanza (y solo con permiso de verlos).
        $payments = $receivable && \App\Http\Middleware\CheckPermission::granted($request, 'payments.view')
            ? Payment::query()
                ->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
                ->where('payment_allocations.receivable_id', $receivable->id)
                ->orderBy('payments.paid_at')
                ->get(['payments.id', 'payments.payment_number', 'payments.payment_method', 'payments.paid_at', 'payment_allocations.amount as applied'])
                ->map(fn ($payment) => [
                    'id' => $payment->id, 'payment_number' => $payment->payment_number, 'payment_method' => $payment->payment_method,
                    'paid_at' => $payment->paid_at?->toIso8601String(), 'applied' => (float) $payment->applied,
                ])->values()
            : [];

        $settings = FiscalSettings::for($invoice->tenant_id);
        $draft = $invoice->status === 'DRAFT';
        $void = DB::table('fiscal_void_requests')->where('invoice_id', $invoice->id)->orderByDesc('created_at')->first();
        // En borrador, lo que le correspondería si se emitiera ahora (en dólares, al tipo de cambio vigente).
        $preview = $draft && $invoice->invoice_type === 'INVOICE'
            ? Withholding::compute($invoice, $settings, (bool) $invoice->customer?->retention_agent, $invoice->currency === 'USD' ? (float) (ExchangeRate::current('USD')?->sell ?? 0) : null)
            : null;

        return [
            ...$this->row($invoice),
            'withholding_mode' => $invoice->withholding_mode,
            'withholding_mode_code' => $draft ? $invoice->withholding_code : null,
            'withholding_preview' => $preview ? [...$preview, 'label' => Withholding::label($preview['type'], $preview['code'], $preview['rate'])] : null,
            // Opciones del borrador de factura: códigos de detracción con su porcentaje.
            'detraction_codes' => $draft && $invoice->invoice_type === 'INVOICE'
                ? collect(Withholding::CODES)->map(fn (array $code, string $key) => ['code' => $key, 'name' => $code[0], 'rate' => $code[1]])->values()
                : [],
            'detraction_account' => $settings['detraction']['account'],
            'contingency_mode' => $settings['mode'] === 'CONTINGENCY',
            'void_request' => $void ? [
                'id' => $void->id, 'status' => $void->status, 'ticket' => $void->ticket, 'reason' => $void->reason, 'response' => $void->response,
                'at' => \Illuminate\Support\Carbon::parse($void->created_at)->toIso8601String(),
            ] : null,
            // null = procede la baja; si no, el motivo por el que no.
            'void_blocked' => $invoice->status === 'ISSUED' && $invoice->fiscal_status === 'ACCEPTED' ? $this->voidBlocked($invoice, $receivable) : 'Solo se da de baja un comprobante emitido y aceptado por SUNAT.',
            'reason' => $invoice->reason,
            'fiscal_response' => $invoice->fiscal_response,
            'fiscal_provider' => FiscalGateway::summary($invoice->tenant_id),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'items' => $invoice->items->map(fn (InvoiceItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'tax_rate' => (float) $item->tax_rate,
                'line_total' => (float) $item->line_total,
                'billable_event_id' => $item->billable_event_id,
            ])->values(),
            // null si el contrato queda fuera del alcance de quien mira.
            'contract' => $invoice->contract ? ['id' => $invoice->contract->id, 'contract_number' => $invoice->contract->contract_number] : null,
            'related' => $invoice->related ? ['id' => $invoice->related->id, 'invoice_number' => $invoice->related->invoice_number] : null,
            'credit_note' => $invoice->creditNote ? ['id' => $invoice->creditNote->id, 'invoice_number' => $invoice->creditNote->invoice_number] : null,
            'receivable' => $receivable ? [
                'id' => $receivable->id,
                'due_date' => $receivable->due_date?->toDateString(),
                'original_amount' => (float) $receivable->original_amount,
                'balance_amount' => (float) $receivable->balance_amount,
                'currency' => $receivable->currency,
                'status' => $receivable->status,
                'overdue' => in_array($receivable->status, Receivable::OPEN, true) && $receivable->due_date?->isBefore(today()),
                'movements' => $receivable->movements->map(fn (ReceivableMovement $movement) => [
                    'id' => $movement->id, 'movement_type' => $movement->movement_type, 'amount' => (float) $movement->amount,
                    'reference' => $movement->reference, 'at' => $movement->created_at?->toIso8601String(),
                ])->values(),
            ] : null,
            'payments' => $payments,
            'history' => AuditHistory::for('Invoice', $invoice->id),
        ];
    }

    private function audit(Request $request, Invoice $invoice, string $action, array $metadata = []): void
    {
        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: $action,
            entityType: 'Invoice',
            entityId: $invoice->id,
            metadata: $metadata,
        );
    }
}
