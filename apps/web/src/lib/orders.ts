import type { Tone } from "@/components/ui/proto";
import { customerDocument, customerName, shortDate } from "@/lib/commercial";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import type { Order, OrderItem, OrderStatus } from "@/types/commercial";

export const ORDER_STATES: [OrderStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["CONFIRMED", "Confirmada", "info"],
  ["IN_PROGRESS", "En ejecución", "warning"],
  ["COMPLETED", "Completada", "success"],
  ["CANCELLED", "Cancelada", "muted"],
];

/** "Contrato CTR-2026-0002" o "Cotización COT-2026-0005": de dónde nació la orden. */
export function orderOrigin(order: Pick<Order, "contract" | "quotation">) {
  if (order.contract) return `Contrato ${order.contract.contract_number}`;
  return order.quotation ? `Cotización ${order.quotation.quotation_number}` : "—";
}

// Marca de agua de las órdenes que no son un encargo en firme.
const WATERMARK: Record<string, string> = { DRAFT: "BORRADOR", CANCELLED: "ANULADO" };

/** Unidad de la línea: la copiada con el servicio o, si no vino, la del catálogo. */
export const orderItemUnit = (item: OrderItem) => item.service_snapshot?.unit ?? item.service?.unit ?? null;

/** Genera y descarga la orden en PDF, con los datos de su ficha. */
export async function downloadOrderPdf(order: Order) {
  await downloadDocumentPdf({
    file: `Orden_${order.order_number}`,
    title: "ORDEN DE SERVICIO",
    number: order.order_number,
    watermark: WATERMARK[order.status],
    left: [
      ["Cliente", customerName(order.customer)],
      ["Documento", customerDocument(order.customer) || "-"],
      ["Dirección", order.customer_contact?.address || "-"],
    ],
    right: [
      ["Fecha", shortDate(order.order_date)],
      ["Contrato", order.contract?.contract_number ?? "-"],
      ["Cotización", order.quotation?.quotation_number ?? "-"],
      ["Responsable", order.creator?.name ?? "-"],
    ],
    head: ["#", "Servicio", "Unidad", "Cant.", "P. unitario", "Total"],
    rows: (order.items ?? []).map((item, index) => [
      String(index + 1),
      item.description,
      orderItemUnit(item) ?? "-",
      String(Number(item.quantity)),
      pdfAmount(item.unit_price),
      pdfAmount(item.line_total),
    ]),
    numCols: [3, 4, 5],
    totals: [["TOTAL", pdfMoney(order.total, order.currency), true]],
    notes: [["Observaciones", order.notes || "-"]],
    signatures: (companyName) => ["Conformidad del cliente", `Por ${companyName}`],
  });
}
