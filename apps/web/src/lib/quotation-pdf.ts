import { customerDocument, customerName, shortDate } from "@/lib/commercial";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import type { Quotation } from "@/types/commercial";

// Marca de agua de los documentos que no son una oferta vigente.
const WATERMARK: Record<string, string> = {
  DRAFT: "BORRADOR", CANCELLED: "ANULADO", REJECTED: "RECHAZADO", EXPIRED: "VENCIDA",
};

/** Genera y descarga la cotización en PDF, con los datos de su ficha. */
export async function downloadQuotationPdf(quotation: Quotation) {
  const currency = quotation.currency;
  const gross = Number(quotation.subtotal) + Number(quotation.discount_total);

  await downloadDocumentPdf({
    file: `Cotizacion_${quotation.quotation_number}`,
    title: "COTIZACIÓN",
    number: quotation.quotation_number + (quotation.version > 1 ? ` (v${quotation.version})` : ""),
    watermark: WATERMARK[quotation.status],
    left: [
      ["Cliente", customerName(quotation.customer)],
      ["Documento", customerDocument(quotation.customer) || "-"],
      ["Dirección", quotation.customer_contact?.address || "-"],
      ["Correo", quotation.customer_contact?.email || "-"],
    ],
    right: [
      ["Fecha de emisión", shortDate(quotation.created_at)],
      ["Válida hasta", shortDate(quotation.valid_until)],
      ["Moneda", currency === "USD" ? `Dólares (T.C. ${quotation.exchange_rate ? Number(quotation.exchange_rate).toFixed(3) : "-"})` : "Soles"],
      ["Ejecutivo", quotation.creator?.name ?? "-"],
    ],
    head: ["#", "Descripción", "Unidad", "Cant.", "P. unitario", "Desc.", "Total c/IGV"],
    rows: quotation.items.map((item, index) => {
      const lineGross = Number(item.quantity) * Number(item.unit_price);
      const pct = lineGross > 0 ? Math.round((Number(item.discount) / lineGross) * 1000) / 10 : 0;
      return [
        String(index + 1),
        item.description,
        item.service_snapshot?.unit ?? item.service?.unit ?? "-",
        String(Number(item.quantity)),
        pdfAmount(item.unit_price),
        `${pct}%`,
        pdfAmount(item.line_total),
      ];
    }),
    numCols: [3, 4, 5, 6],
    totals: [
      ["Subtotal", pdfMoney(gross, currency)],
      ["Descuentos", `- ${pdfMoney(quotation.discount_total, currency)}`],
      ["IGV 18%", pdfMoney(quotation.tax_total, currency)],
      ["TOTAL", pdfMoney(quotation.total, currency), true],
    ],
    notes: [
      ["Condiciones", quotation.notes || "-"],
      [
        "Validez",
        `Esta cotización es válida hasta el ${shortDate(quotation.valid_until)}. Los precios corresponden a la versión del catálogo vigente al emitirla.`,
      ],
    ],
    signatures: (companyName) => ["Aceptación del cliente", `Por ${companyName}`],
  });
}
