<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DocumentStore;
use App\Modules\Finance\Support\FiscalSettings;
use App\Modules\Portal\Support\PortalCustomer;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Portal: facturas y pagos (con sus PDF y el estado de cuenta) y documentos compartidos. */
class PortalFinanceController extends Controller
{
    /** Comprobantes vivos que aparecen al cliente (sin borradores ni descartados). */
    private const VISIBLE_INVOICES = ['ISSUED', 'PAID', 'PARTIALLY_PAID', 'OVERDUE', 'CORRECTED', 'VOID'];

    /** Cobros que existen para el cliente (sin pendientes ni fallidos). */
    private const VISIBLE_PAYMENTS = ['CONFIRMED', 'PARTIALLY_ALLOCATED', 'ALLOCATED', 'REFUNDED'];

    private const ALLOCATED = '(SELECT coalesce(sum(pa.amount), 0) FROM payment_allocations pa WHERE pa.payment_id = payments.id)';

    /** Pendientes de pago, comprobantes y pagos realizados. */
    public function invoices(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');
        $today = now()->toDateString();

        $open = DB::table('receivables')->join('invoices', 'invoices.id', '=', 'receivables.invoice_id')
            ->where('receivables.customer_id', $customerId)->whereIn('receivables.status', ['OPEN', 'PARTIAL'])
            ->orderByRaw('receivables.due_date ASC NULLS LAST')
            ->get(['receivables.id', 'receivables.invoice_id', 'invoices.invoice_number', 'invoices.invoice_type', 'receivables.due_date',
                'receivables.balance_amount', 'receivables.original_amount', 'receivables.currency', 'receivables.status',
                'invoices.withholding_type', 'invoices.withholding_status', 'invoices.withholding_amount']);

        $invoices = DB::table('invoices')->where('customer_id', $customerId)->whereIn('status', self::VISIBLE_INVOICES)
            ->orderByDesc('issue_date')->orderByDesc('created_at')
            ->get(['id', 'invoice_number', 'invoice_type', 'issue_date', 'due_date', 'currency', 'total', 'balance_due', 'status', 'fiscal_status']);

        $payments = DB::table('payments')->where('customer_id', $customerId)->whereIn('status', self::VISIBLE_PAYMENTS)
            ->orderByDesc('paid_at')
            ->get(['id', 'payment_number', 'paid_at', 'payment_method', 'amount', 'currency', 'status', 'refunded_amount']);

        return response()->json([
            'receivables' => $open->map(fn ($row) => [
                'id' => $row->id,
                'invoice_id' => $row->invoice_id,
                'invoice_number' => $row->invoice_number,
                'invoice_type' => $row->invoice_type,
                'due_date' => $row->due_date,
                'overdue' => $row->due_date && $row->due_date < $today,
                'original_amount' => (float) $row->original_amount,
                'balance_amount' => (float) $row->balance_amount,
                'currency' => $row->currency,
                'status' => $row->status,
                // Detracción por depositar en el Banco de la Nación (en soles), incluida en el saldo.
                'detraction' => $row->withholding_type === 'DETRACTION' && $row->withholding_status === 'PENDING'
                    ? (float) $row->withholding_amount : null,
            ]),
            'invoices' => $invoices->map(fn ($row) => [
                ...(array) $row, 'total' => (float) $row->total, 'balance_due' => (float) $row->balance_due,
            ]),
            'payments' => $payments->map(fn ($row) => [
                ...(array) $row, 'amount' => (float) $row->amount, 'refunded_amount' => (float) $row->refunded_amount,
                'paid_at' => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
            ]),
            // La pasarela de pago no está conectada: el portal lo dice y no simula el pago.
            'online_payment' => false,
        ]);
    }

    /** Datos del PDF de un comprobante; 404 si no es del cliente. */
    public function invoice(string $invoice, Request $request)
    {
        $row = DB::table('invoices')->where('id', $invoice)->where('customer_id', $request->attributes->get('portal_customer_id'))
            ->whereIn('status', self::VISIBLE_INVOICES)->first();
        abort_if(! $row, 404);

        $settings = FiscalSettings::for($row->tenant_id);

        return response()->json([
            'id' => $row->id,
            'invoice_number' => $row->invoice_number,
            'invoice_type' => $row->invoice_type,
            'status' => $row->status,
            'fiscal_status' => $row->fiscal_status,
            'customer' => PortalCustomer::of($row->customer_id),
            'customer_name' => $row->customer_name ?: PortalCustomer::name($row->customer_id),
            'customer_tax_id' => $row->customer_tax_id,
            'issue_date' => $row->issue_date,
            'due_date' => $row->due_date,
            'currency' => $row->currency,
            'exchange_rate' => $row->exchange_rate !== null ? (float) $row->exchange_rate : null,
            'subtotal' => (float) $row->subtotal,
            'tax_total' => (float) $row->tax_total,
            'total' => (float) $row->total,
            'balance_due' => (float) $row->balance_due,
            'reason' => $row->reason,
            'withholding' => $row->withholding_type ? [
                'type' => $row->withholding_type, 'code' => $row->withholding_code, 'rate' => (float) $row->withholding_rate,
                'amount' => (float) $row->withholding_amount, 'status' => $row->withholding_status,
            ] : null,
            'withholding_preview' => null,
            'detraction_account' => $settings['detraction']['account'],
            'contract' => $row->contract_id ? ['contract_number' => DB::table('contracts')->where('id', $row->contract_id)->value('contract_number')] : null,
            'related' => $row->related_invoice_id ? ['invoice_number' => DB::table('invoices')->where('id', $row->related_invoice_id)->value('invoice_number')] : null,
            'items' => DB::table('invoice_items')->where('invoice_id', $row->id)->orderBy('position')
                ->get(['description', 'quantity', 'unit_price', 'line_total'])
                ->map(fn ($item) => ['description' => $item->description, 'quantity' => (float) $item->quantity, 'unit_price' => (float) $item->unit_price, 'line_total' => (float) $item->line_total]),
        ]);
    }

    /** Datos del recibo de un cobro; 404 si no es del cliente. */
    public function payment(string $payment, Request $request)
    {
        $row = DB::table('payments')->where('id', $payment)->where('customer_id', $request->attributes->get('portal_customer_id'))
            ->whereIn('status', self::VISIBLE_PAYMENTS)
            ->select('payments.*')->selectRaw(self::ALLOCATED.' AS allocated')->first();
        abort_if(! $row, 404);

        $amount = (float) $row->amount;
        $refunded = (float) $row->refunded_amount;
        $allocated = round((float) $row->allocated, 2);

        return response()->json([
            'id' => $row->id,
            'payment_number' => $row->payment_number,
            'status' => $row->status,
            'customer' => PortalCustomer::of($row->customer_id),
            'payment_method' => $row->payment_method,
            'reference' => $row->reference,
            'paid_at' => $row->paid_at ? Carbon::parse($row->paid_at)->toIso8601String() : null,
            'account_name' => $row->financial_account_id ? DB::table('financial_accounts')->where('id', $row->financial_account_id)->value('name') : null,
            'creator' => $row->created_by ? DB::table('users')->where('id', $row->created_by)->value('name') : null,
            'currency' => $row->currency,
            'amount' => $amount,
            'refunded_amount' => $refunded,
            'allocated' => $allocated,
            'free' => round($amount - $refunded - $allocated, 2),
            'allocations' => DB::table('payment_allocations')
                ->join('receivables', 'receivables.id', '=', 'payment_allocations.receivable_id')
                ->join('invoices', 'invoices.id', '=', 'receivables.invoice_id')
                ->where('payment_allocations.payment_id', $row->id)->orderBy('payment_allocations.created_at')
                ->get(['invoices.invoice_number', 'payment_allocations.amount', 'payment_allocations.created_at'])
                ->map(fn ($allocation) => [
                    'invoice_number' => $allocation->invoice_number, 'amount' => (float) $allocation->amount,
                    'at' => Carbon::parse($allocation->created_at)->toIso8601String(),
                ]),
        ]);
    }

    /** Estado de cuenta: comprobantes con su saldo, del más antiguo al más reciente. */
    public function statement(Request $request)
    {
        $customerId = $request->attributes->get('portal_customer_id');
        $rows = DB::table('receivables')->join('invoices', 'invoices.id', '=', 'receivables.invoice_id')
            ->where('receivables.customer_id', $customerId)->where('receivables.status', '!=', 'CANCELLED')
            ->orderBy('invoices.issue_date')->orderBy('invoices.invoice_number')
            ->get(['invoices.invoice_number', 'invoices.invoice_type', 'invoices.issue_date', 'receivables.due_date',
                'receivables.original_amount', 'receivables.balance_amount', 'receivables.currency', 'receivables.status']);

        return response()->json([
            'customer' => PortalCustomer::of($customerId),
            'customer_name' => PortalCustomer::name($customerId),
            'date' => now()->toDateString(),
            'rows' => $rows->map(fn ($row) => [
                ...(array) $row, 'original_amount' => (float) $row->original_amount, 'balance_amount' => (float) $row->balance_amount,
                'overdue' => in_array($row->status, ['OPEN', 'PARTIAL'], true) && $row->due_date && $row->due_date < now()->toDateString(),
            ]),
            'totals' => $rows->groupBy('currency')->map(fn ($group, $currency) => [
                'currency' => $currency,
                'original' => round($group->sum('original_amount'), 2),
                'balance' => round($group->sum('balance_amount'), 2),
            ])->values(),
        ]);
    }

    /** Documentos compartidos con el cliente: los entregables aprobados de sus proyectos y los marcados para el portal. */
    public function documents(Request $request)
    {
        return response()->json($this->visibleDocuments($request->attributes->get('portal_customer_id'))->values());
    }

    /** Enlace temporal (5 minutos) para descargar un documento compartido; queda registrado. */
    public function documentUrl(string $document, Request $request)
    {
        $visible = $this->visibleDocuments($request->attributes->get('portal_customer_id'))->firstWhere('id', $document);
        abort_if(! $visible, 404);

        $model = Document::query()->with('latestVersion.fileObject')->findOrFail($document);
        $file = $model->latestVersion?->fileObject;
        if (in_array($file?->antivirus_status, ['INFECTED', 'QUARANTINED'], true)) {
            throw ValidationException::withMessages(['document' => ['El archivo está en cuarentena por el antivirus: no se puede descargar.']]);
        }
        $url = DocumentStore::downloadUrl($model, 5);
        abort_if(! $url, 404);

        AuditLogger::log(
            tenantId: $model->tenant_id, userId: $request->user()->id, action: 'document.portal_download', entityType: 'Document', entityId: $model->id,
            metadata: ['version' => $model->latestVersion->version, 'title' => $model->title],
        );

        return response()->json([
            'url' => $url,
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
            'name' => $file->original_name,
            'size' => (int) $file->size_bytes,
        ]);
    }

    private function visibleDocuments(string $customerId)
    {
        $projects = DB::table('projects')->where('customer_id', $customerId)->pluck('id');
        $contracts = DB::table('contracts')->where('customer_id', $customerId)->where('status', '!=', 'DRAFT')->pluck('id');
        $latest = fn ($query) => $query->select('document_id', DB::raw('max(version) AS versions'), DB::raw('max(created_at) AS updated_at'))
            ->from('document_versions')->groupBy('document_id');

        // Entregables aprobados o entregados, con archivo.
        $deliverables = DB::table('deliverables')
            ->join('projects', 'projects.id', '=', 'deliverables.project_id')
            ->join('documents', 'documents.id', '=', 'deliverables.document_id')
            ->joinSub($latest(DB::query()), 'v', 'v.document_id', '=', 'documents.id')
            ->whereIn('deliverables.project_id', $projects)
            ->whereIn('deliverables.status', ['APPROVED', 'DELIVERED'])
            ->get(['documents.id', 'deliverables.name as title', 'projects.code as source', 'v.versions', 'v.updated_at'])
            ->map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'category' => 'Entregable', 'source' => $row->source,
                'versions' => (int) $row->versions, 'updated_at' => Carbon::parse($row->updated_at)->toIso8601String()]);

        // Documentos marcados como visibles en el portal y vinculados al cliente, a un contrato o a un proyecto suyo.
        $shared = DB::table('documents')
            ->joinSub($latest(DB::query()), 'v', 'v.document_id', '=', 'documents.id')
            ->where('documents.visibility', 'PORTAL')->where('documents.status', 'ACTIVE')
            ->whereExists(fn ($query) => $query->from('document_links')->whereColumn('document_links.document_id', 'documents.id')
                ->where(fn ($link) => $link
                    ->where(fn ($q) => $q->where('entity_type', 'Customer')->where('entity_id', $customerId))
                    ->orWhere(fn ($q) => $q->where('entity_type', 'Contract')->whereIn('entity_id', $contracts))
                    ->orWhere(fn ($q) => $q->where('entity_type', 'Project')->whereIn('entity_id', $projects))))
            ->get(['documents.id', 'documents.title', 'documents.document_type', 'v.versions', 'v.updated_at'])
            ->map(fn ($row) => ['id' => $row->id, 'title' => $row->title, 'category' => $row->document_type, 'source' => null,
                'versions' => (int) $row->versions, 'updated_at' => Carbon::parse($row->updated_at)->toIso8601String()]);

        return $deliverables->concat($shared)->unique('id')->sortByDesc('updated_at');
    }
}
