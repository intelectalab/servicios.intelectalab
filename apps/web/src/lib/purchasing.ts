import type { Tone } from "@/components/ui/proto";
import { shortDate } from "@/lib/commercial";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import type { HistoryEntry } from "@/types/commercial";
import type {
  PayableMovementType, PayableStatus, PurchaseOrderDetail, PurchaseOrderStatus, PurchaseOrderTab, SupplierDocType, SupplierInvoiceStatus, SupplierInvoiceTab,
} from "@/types/purchasing";

export const PURCHASE_ORDER_STATES: [PurchaseOrderStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["SUBMITTED", "Por aprobar", "info"],
  ["APPROVED", "Aprobada", "success"],
  ["PARTIALLY_RECEIVED", "Recepción parcial", "accent"],
  ["RECEIVED", "Recibida", "success"],
  ["CLOSED", "Cerrada", "muted"],
  ["REJECTED", "Rechazada", "danger"],
  ["CANCELLED", "Anulada", "muted"],
];

export const PURCHASE_ORDER_TABS: [PurchaseOrderTab, string][] = [
  ["ALL", "Todas"],
  ["DRAFT", "Borradores"],
  ["SUBMITTED", "Por aprobar"],
  ["RECEIVE", "Por recibir"],
  ["RECEIVED", "Recibidas"],
  ["CLOSED", "Cerradas"],
];

/** Se puede registrar su recepción. */
export const ORDER_RECEIVABLE: PurchaseOrderStatus[] = ["APPROVED", "PARTIALLY_RECEIVED"];

/** Aprobada y aún abierta: admite comprobantes del proveedor y se puede cerrar. */
export const ORDER_COMMITTED: PurchaseOrderStatus[] = ["APPROVED", "PARTIALLY_RECEIVED", "RECEIVED"];

export const SUPPLIER_INVOICE_STATES: [SupplierInvoiceStatus, string, Tone][] = [
  ["REGISTERED", "Registrada", "info"],
  ["APPROVED", "Aprobada para pago", "success"],
  ["REJECTED", "Rechazada", "danger"],
  ["VOIDED", "Anulada", "muted"],
];

export const SUPPLIER_INVOICE_TABS: [SupplierInvoiceTab, string][] = [
  ["ALL", "Todos"],
  ["REGISTERED", "Por aprobar"],
  ["APPROVED", "Aprobados"],
  ["REJECTED", "Rechazados"],
];

export const DOC_TYPES: [SupplierDocType, string][] = [
  ["FACTURA", "Factura"],
  ["RECIBO_HONORARIOS", "Recibo por honorarios"],
];

export const docTypeLabel = (type: string) => DOC_TYPES.find(([key]) => key === type)?.[1] ?? type;

export const SUNAT_VALIDATION: Record<string, [string, Tone]> = {
  NOT_CHECKED: ["Sin consultar", "neutral"],
  VALID: ["Válido", "success"],
  INVALID: ["No válido", "danger"],
};

export const PAYABLE_STATES: [PayableStatus, string, Tone][] = [
  ["OPEN", "Pendiente", "info"],
  ["PARTIAL", "Pago parcial", "accent"],
  ["PAID", "Pagada", "success"],
  ["CANCELLED", "Anulada", "muted"],
];

export const PAYABLE_MOVEMENTS: Record<PayableMovementType, string> = {
  CHARGE: "Cargo",
  PAYMENT: "Pago",
  DETRACTION: "Depósito de detracción",
  RETENTION_4TA: "Retención 4.ª categoría",
  PAYMENT_REVERSAL: "Pago revertido",
};

export const SUPPLIER_PAYMENT_METHODS: Record<string, string> = { TRANSFERENCIA: "Transferencia", CHEQUE: "Cheque", EFECTIVO: "Efectivo" };

export const supplierMethodLabel = (method: string) => SUPPLIER_PAYMENT_METHODS[method] ?? method;

/** "60" o "33.5": el porcentaje sin ceros de más. */
export const pctText = (value: number) => String(Number(value.toFixed(2)));

/** Importe abreviado de los indicadores: "S/ 12.4 mil". */
export function kMoney(value: number) {
  const abs = Math.abs(value);
  if (abs >= 1_000_000) return `S/ ${(value / 1_000_000).toFixed(1)} M`;
  if (abs >= 1_000) return `S/ ${(value / 1_000).toFixed(1)} mil`;
  return `S/ ${value.toFixed(0)}`;
}

// Cómo se lee en el historial cada movimiento que no cambia de estado.
const HISTORY_LABELS: Record<string, string> = {
  "purchase_order.create": "Orden creada",
  "purchase_order.update": "Orden editada",
  "purchase_order.receive": "Recepción registrada",
  "supplier_invoice.create": "Comprobante registrado",
  "supplier_invoice.payment": "Pago aplicado",
  "supplier_invoice.payment_reversed": "Pago revertido",
  "supplier_payment.create": "Pago registrado",
  "supplier_payment.reverse": "Pago revertido",
};

export const purchasingHistoryLabel = (entry: HistoryEntry) => HISTORY_LABELS[entry.action] ?? entry.action;

/** Orden de compra en PDF, para enviar al proveedor. Sin aprobar sale con marca de agua. */
export async function downloadPurchaseOrderPdf(order: PurchaseOrderDetail) {
  const currency = order.currency;
  const watermark: Partial<Record<PurchaseOrderStatus, string>> = {
    DRAFT: "BORRADOR", SUBMITTED: "POR APROBAR", REJECTED: "RECHAZADA", CANCELLED: "ANULADA",
  };

  await downloadDocumentPdf({
    file: `Orden_de_compra_${order.order_number}`,
    title: "ORDEN DE COMPRA",
    number: order.order_number,
    watermark: watermark[order.status],
    left: [
      ["Proveedor", order.supplier.name ?? "-"],
      ["RUC / documento", order.supplier.tax_id ?? "-"],
      ["Centro de costo", order.cost_center.name ?? "-"],
    ],
    right: [
      ["Fecha", shortDate(order.created_at)],
      ["Requerida para", order.needed_by ? shortDate(order.needed_by) : "-"],
      ["Moneda", currency === "USD" ? "Dólares" : "Soles"],
      ["Solicitante", order.creator ?? "-"],
    ],
    head: ["#", "Descripción", "Cant.", "Costo unit.", "Subtotal"],
    rows: order.items.map((item, index) => [String(index + 1), item.description, String(item.quantity), pdfAmount(item.unit_cost), pdfAmount(item.subtotal)]),
    numCols: [2, 3, 4],
    totals: [
      ["Subtotal", pdfMoney(order.subtotal, currency)],
      ["IGV 18%", pdfMoney(order.tax, currency)],
      ["Total", pdfMoney(order.total, currency), true],
    ],
    notes: [
      ...(order.notes ? [["Notas", order.notes] as [string, string]] : []),
      ...(order.approver ? [["Aprobada por", order.approver] as [string, string]] : []),
    ],
    signatures: (companyName) => [companyName, order.supplier.name ?? "Proveedor"],
  });
}
