<?php

namespace App\Modules\Finance\Fiscal;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceItem;
use App\Modules\Finance\Support\Billing;
use Illuminate\Support\Facades\DB;

/**
 * El comprobante tal como lo necesita un proveedor de facturación electrónica, con los códigos de
 * SUNAT ya puestos. Cada proveedor solo traduce este arreglo a su propio formato.
 */
class FiscalDocument
{
    /** Tipo de comprobante => código SUNAT (catálogo 01). */
    private const TYPE_CODES = ['INVOICE' => '01', 'RECEIPT' => '03', 'CREDIT_NOTE' => '07'];

    public static function from(Invoice $invoice): array
    {
        $invoice->loadMissing('items', 'related');
        $company = DB::table('companies')->where('tenant_id', $invoice->tenant_id)->where('status', 'ACTIVE')->orderBy('created_at')
            ->first(['legal_name', 'trade_name', 'tax_identifier', 'address']);

        return [
            'type' => $invoice->invoice_type,
            'type_code' => self::TYPE_CODES[$invoice->invoice_type] ?? null,
            'series' => $invoice->series,
            'number' => (int) $invoice->number,
            'full_number' => $invoice->invoice_number,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            // Tipo de cambio de venta del día de emisión; solo en dólares.
            'exchange_rate' => $invoice->exchange_rate !== null ? (float) $invoice->exchange_rate : null,
            'issuer' => [
                'tax_identifier' => $company?->tax_identifier,
                'legal_name' => $company?->legal_name,
                'trade_name' => $company?->trade_name,
                'address' => $company && $company->address ? json_decode($company->address, true) : null,
            ],
            'customer' => [
                // Catálogo 06 de SUNAT: 6 = RUC, 1 = DNI, 0 = otro o sin documento.
                'document_type_code' => self::documentTypeCode($invoice->customer_tax_id),
                'document_number' => $invoice->customer_tax_id,
                'name' => $invoice->customer_name,
            ],
            // Una nota de crédito guarda sus importes en negativo; a SUNAT van en positivo.
            'totals' => [
                'taxable' => abs((float) $invoice->subtotal),
                'tax' => abs((float) $invoice->tax_total),
                'total' => abs((float) $invoice->total),
                'tax_rate' => Billing::TAX_RATE,
            ],
            'lines' => $invoice->items->map(fn (InvoiceItem $item) => [
                'position' => (int) $item->position,
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_value' => abs((float) $item->unit_price),
                'tax_rate' => (float) $item->tax_rate,
                'tax' => abs((float) $item->tax_amount),
                'total' => abs((float) $item->line_total),
            ])->values()->all(),
            // Detracción (SPOT) o retención del IGV, en soles; null si no le corresponde.
            'withholding' => $invoice->withholding_type ? [
                'type' => $invoice->withholding_type,
                'code' => $invoice->withholding_code,
                'rate' => (float) $invoice->withholding_rate,
                'amount_pen' => (float) $invoice->withholding_amount,
                // Cuenta de detracciones de la empresa en el Banco de la Nación.
                'account' => $invoice->withholding_type === 'DETRACTION' ? (\App\Modules\Finance\Support\FiscalSettings::for($invoice->tenant_id)['detraction']['account']) : null,
            ] : null,
            // Emitido con la serie de contingencia: se informa a SUNAT en un resumen.
            'contingency' => (bool) preg_match(\App\Modules\Finance\Support\FiscalSettings::CONTINGENCY_PATTERN, (string) $invoice->series),
            // Solo en notas de crédito: el comprobante que corrige y por qué.
            'corrects' => $invoice->related ? [
                'type_code' => self::TYPE_CODES[$invoice->related->invoice_type] ?? null,
                'full_number' => $invoice->related->invoice_number,
                'reason' => $invoice->reason,
                // Catálogo 09 de SUNAT: 01 = anulación de la operación (la nota es siempre por el total).
                'reason_code' => '01',
            ] : null,
        ];
    }

    private static function documentTypeCode(?string $number): string
    {
        return match (true) {
            (bool) preg_match('/^\d{11}$/', (string) $number) => '6',
            (bool) preg_match('/^\d{8}$/', (string) $number) => '1',
            default => '0',
        };
    }
}
