import type { Tone } from "@/components/ui/proto";
import { customerName, shortDate } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import type { HistoryEntry } from "@/types/commercial";
import type {
  AgingBucket, BillableEventSource, BillableEventStatus, FiscalStatus, InvoiceDetail, InvoiceStatus, InvoiceType, MovementType,
  AccountKind, CycleStatus, VoidStatus, Withholding, WithholdingStatus, PaymentDetail, PaymentStatus, ReceivableStatus, RecurringStatus, StatementLineStatus,
} from "@/types/finance";

export const EVENT_STATES: [BillableEventStatus, string, Tone][] = [
  ["PENDING", "Por facturar", "warning"],
  ["INVOICED", "Facturado", "success"],
  ["CANCELLED", "Anulado", "muted"],
];

export const EVENT_SOURCES: [BillableEventSource, string][] = [
  ["WORK_ORDER", "OT completada"],
  ["RECURRING_CYCLE", "Ciclo recurrente"],
  ["CONTRACT_MILESTONE", "Hito de contrato"],
  ["TIME_ENTRY", "Horas aprobadas"],
  ["MANUAL", "Manual"],
];

export const sourceLabel = (source: string) => EVENT_SOURCES.find(([key]) => key === source)?.[1] ?? source;

export const INVOICE_TYPES: [InvoiceType, string][] = [
  ["INVOICE", "Factura"],
  ["RECEIPT", "Boleta"],
  ["CREDIT_NOTE", "Nota de crédito"],
];

export const typeLabel = (type: string) => INVOICE_TYPES.find(([key]) => key === type)?.[1] ?? "Comprobante";

export const INVOICE_STATES: [InvoiceStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["ISSUED", "Emitido", "success"],
  ["CORRECTED", "Corregido", "warning"],
  ["VOID", "Anulado", "muted"],
  ["CANCELLED", "Descartado", "muted"],
  // Estados de los comprobantes anteriores a esta pantalla.
  ["PAID", "Pagado", "success"],
  ["PARTIALLY_PAID", "Pago parcial", "info"],
  ["OVERDUE", "Vencido", "danger"],
];

/** Recorrido de estados que muestra la ficha. */
export const INVOICE_TRACK: [InvoiceStatus, string, Tone][] = INVOICE_STATES.filter(([key]) => ["DRAFT", "ISSUED", "CORRECTED"].includes(key));

export const FISCAL_STATES: [FiscalStatus, string, Tone][] = [
  ["NOT_SENT", "Sin enviar", "neutral"],
  ["CONTINGENCY", "Contingencia", "warning"],
  ["PENDING", "En envío", "info"],
  ["ACCEPTED", "Aceptado", "success"],
  ["REJECTED", "Rechazado", "danger"],
];

export const RECEIVABLE_STATES: [ReceivableStatus, string, Tone][] = [
  ["OPEN", "Pendiente", "info"],
  ["PARTIAL", "Parcial", "warning"],
  ["PAID", "Pagada", "success"],
  ["CANCELLED", "Anulada", "muted"],
];

export const AGING: [AgingBucket, string][] = [
  ["CURRENT", "Por vencer"],
  ["D30", "1–30 días"],
  ["D60", "31–60 días"],
  ["D90", "61–90 días"],
  ["OVER", "> 90 días"],
];

export const bucketLabel = (bucket: string | null) => AGING.find(([key]) => key === bucket)?.[1] ?? "";

export const MOVEMENT_LABELS: Record<MovementType, string> = {
  CHARGE: "Cargo",
  PAYMENT: "Abono",
  REVERSAL: "Reversión",
  CREDIT_NOTE: "Nota de crédito",
  WITHHOLDING: "Detracción / retención",
  VOID: "Baja",
};

export const PAYMENT_STATES: [PaymentStatus, string, Tone][] = [
  ["CONFIRMED", "Sin aplicar", "info"],
  ["PARTIALLY_ALLOCATED", "Aplicado parcial", "warning"],
  ["ALLOCATED", "Aplicado", "success"],
  ["REFUNDED", "Reembolsado", "muted"],
  ["PENDING", "Pendiente", "neutral"],
  ["FAILED", "Fallido", "danger"],
  ["CANCELLED", "Cancelado", "muted"],
];

/** Estados que ofrece el filtro del listado, los del prototipo. */
export const PAYMENT_FILTER_STATES = PAYMENT_STATES.slice(0, 4);

/** Estados en los que el cobro tiene dinero vivo: se puede aplicar o reembolsar. */
export const PAYMENT_LIVE: PaymentStatus[] = ["CONFIRMED", "PARTIALLY_ALLOCATED", "ALLOCATED"];

export const PAYMENT_METHODS: Record<string, string> = {
  TRANSFERENCIA: "Transferencia",
  DEPOSITO: "Depósito",
  EFECTIVO: "Efectivo",
  CHEQUE: "Cheque",
  PASARELA: "Pasarela",
};

export const methodLabel = (method: string) => PAYMENT_METHODS[method] ?? method;

export const SOURCE_LABELS: Record<string, string> = { BACKOFFICE: "Registrado aquí", GATEWAY: "Pasarela", PORTAL: "Portal" };

export const RECURRING_STATES: [RecurringStatus, string, Tone][] = [
  ["ACTIVE", "Activa", "success"],
  ["SUSPENDED", "Suspendida", "warning"],
  ["EXPIRED", "Vencida", "muted"],
  ["CANCELLED", "Cancelada", "muted"],
];

export const CYCLE_STATES: [CycleStatus, string, Tone][] = [
  ["PENDING", "Pendiente", "neutral"],
  ["PROCESSING", "Procesando", "info"],
  ["COMPLETED", "Completado", "success"],
  ["FAILED", "Fallido", "danger"],
  ["SKIPPED", "Omitido", "muted"],
];

export const RECURRING_FREQUENCIES: Record<string, string> = {
  DAILY: "Diaria", WEEKLY: "Semanal", MONTHLY: "Mensual", QUARTERLY: "Trimestral", YEARLY: "Anual", CUSTOM: "Personalizada",
};

/** "Mensual", o "Cada 2 meses" si el intervalo no es uno. */
export function frequencyLabel(frequency: string, interval = 1) {
  const label = RECURRING_FREQUENCIES[frequency] ?? frequency;
  return interval > 1 ? `${label} · cada ${interval}` : label;
}

export const WITHHOLDING_STATES: [WithholdingStatus, string, Tone][] = [
  ["PENDING", "Por recibir", "warning"],
  ["RECEIVED", "Constancia recibida", "success"],
];

export const VOID_STATES: [VoidStatus, string, Tone][] = [
  ["PENDING", "Enviada (ticket)", "info"],
  ["ACCEPTED", "Aceptada", "success"],
  ["REJECTED", "Rechazada", "danger"],
];

const pct = (rate: number) => `${Number(rate.toFixed(2))} %`;

/** "Detracción 12 % (código 037)" o "Retención 3 %". */
export function withholdingLabel(withholding: Pick<Withholding, "type" | "code" | "rate">) {
  return withholding.type === "DETRACTION"
    ? `Detracción ${pct(withholding.rate)}${withholding.code ? ` (código ${withholding.code})` : ""}`
    : `Retención ${pct(withholding.rate)}`;
}

/** Lo que el cliente paga directamente, en la moneda del comprobante: el total menos lo detraído o retenido. */
export function netToCollect(invoice: { total: number; currency: string; exchange_rate: number | null }, amountPen: number) {
  const inDocument = invoice.currency === "USD" ? (invoice.exchange_rate ? amountPen / invoice.exchange_rate : 0) : amountPen;
  return Math.round((invoice.total - inDocument) * 100) / 100;
}

export const ACCOUNT_KINDS: Record<AccountKind, string> = { BANK: "Cuenta bancaria", CASH: "Caja", DETRACTION: "Detracciones (BN)" };

/** Origen de un movimiento de banco o caja. */
export const MOVEMENT_ORIGINS: [string, string][] = [
  ["PAYMENT", "Cobro"],
  ["REFUND", "Reembolso"],
  ["TRANSFER", "Transferencia"],
  ["CASH_EXPENSE", "Caja chica"],
  ["BANK_FEE", "Comisión"],
  ["DETRACTION", "Detracción"],
  ["UNIDENTIFIED", "Por identificar"],
  ["OPENING", "Apertura"],
  ["SUPPLIER_PAYMENT", "Pago proveedor"],
  ["MANUAL", "Manual"],
];

export const originLabel = (origin: string) => MOVEMENT_ORIGINS.find(([key]) => key === origin)?.[1] ?? origin;

export const LINE_STATES: [StatementLineStatus, string, Tone][] = [
  ["UNMATCHED", "Sin conciliar", "warning"],
  ["MATCHED", "Conciliada", "success"],
  ["CREATED", "Registrada", "info"],
  ["IGNORED", "Ignorada", "muted"],
];

/** Nombre del cliente: el del comprobante si ya quedó escrito en él, si no el vigente. */
export const invoiceCustomer = (invoice: { customer_name: string | null; customer: InvoiceDetail["customer"] }) =>
  invoice.customer_name || customerName(invoice.customer);

/** "RUC 20…" si el documento es un RUC; si no (DNI, carné…), "Doc. …". */
export const taxIdText = (taxId: string | null | undefined) => (!taxId ? "RUC —" : /^\d{11}$/.test(taxId) ? `RUC ${taxId}` : `Doc. ${taxId}`);

// Cómo se lee en el historial cada movimiento que no cambia de estado.
const HISTORY_LABELS: Record<string, string> = {
  "invoice.create": "Borrador generado",
  "invoice.issue": "Emitido",
  "invoice.credit_note": "Nota de crédito emitida",
  "invoice.correct": "Corregido con nota de crédito",
  "invoice.discard": "Borrador descartado",
  "invoice.fiscal_retry": "Envío a SUNAT solicitado",
  "invoice.fiscal_accepted": "Aceptado por SUNAT",
  "invoice.fiscal_rejected": "Rechazado por SUNAT",
  "invoice.fiscal_pending": "Recibido por el proveedor",
  "invoice.withholding_mode": "Detracción o retención definida",
  "invoice.withholding_receipt": "Constancia registrada",
  "invoice.void_request": "Comunicación de baja solicitada",
  "invoice.void_accepted": "Baja aceptada por SUNAT",
  "invoice.void_rejected": "Baja rechazada",
  "invoice.void_pending": "Baja recibida por SUNAT",
  "fiscal.contingency_start": "Contingencia activada",
  "fiscal.contingency_end": "Servicio restablecido",
  "payment.create": "Cobro registrado",
  "payment.register": "Cobro registrado",
  "payment.allocate": "Aplicado a cuentas por cobrar",
  "payment.reconcile": "Conciliado con el banco",
  "payment.refund": "Reembolso",
  "recurring.cycle_completed": "Ciclo procesado",
  "recurring.cycle_failed": "Ciclo fallido",
  "recurring.renew": "Renovado",
};

export const financeHistoryLabel = (entry: HistoryEntry) => HISTORY_LABELS[entry.action] ?? entry.action;

/** Representación impresa del comprobante. En borrador sale con marca de agua. */
export async function downloadInvoicePdf(invoice: InvoiceDetail) {
  const currency = invoice.currency;
  const draft = invoice.status === "DRAFT";
  // Emitido, lo fijado en el comprobante; en borrador, lo que le correspondería.
  const withheld = invoice.withholding ?? invoice.withholding_preview;

  await downloadDocumentPdf({
    file: `${typeLabel(invoice.invoice_type).replace(/ /g, "_")}_${invoice.invoice_number ?? "borrador"}`,
    title: typeLabel(invoice.invoice_type).toUpperCase(),
    number: invoice.invoice_number ?? "BORRADOR",
    watermark: draft ? "BORRADOR" : invoice.status === "CANCELLED" ? "DESCARTADO" : invoice.status === "CORRECTED" ? "CORREGIDO" : invoice.status === "VOID" ? "ANULADO" : undefined,
    left: [
      ["Cliente", invoiceCustomer(invoice)],
      ["RUC / documento", invoice.customer_tax_id ?? "-"],
      ...(invoice.contract ? [["Contrato", invoice.contract.contract_number] as [string, string]] : []),
    ],
    right: [
      ["Emisión", invoice.issue_date ? shortDate(invoice.issue_date) : "Sin emitir"],
      ["Vencimiento", invoice.due_date ? shortDate(invoice.due_date) : "-"],
      ["Moneda", currency === "USD" ? `Dólares (T.C. ${invoice.exchange_rate ?? "-"})` : "Soles"],
      ...(invoice.related ? [["Corrige a", invoice.related.invoice_number ?? "-"] as [string, string]] : []),
    ],
    head: ["#", "Descripción", "Cant.", "Valor unit.", "Total"],
    rows: invoice.items.map((item, index) => [
      String(index + 1), item.description, String(item.quantity), pdfAmount(item.unit_price), pdfAmount(item.line_total),
    ]),
    numCols: [2, 3, 4],
    totals: [
      ["Op. gravada", pdfMoney(invoice.subtotal, currency)],
      ["IGV 18%", pdfMoney(invoice.tax_total, currency)],
      ["Importe total", pdfMoney(invoice.total, currency), true],
      ...(withheld ? [
        [withholdingLabel(withheld), `- ${pdfMoney(withheld.amount, "PEN")}`] as [string, string],
        ["Neto a cobrar al cliente", pdfMoney(netToCollect(invoice, withheld.amount), currency)] as [string, string],
      ] : []),
    ],
    notes: [
      ...(invoice.reason ? [["Motivo", invoice.reason] as [string, string]] : []),
      ...(withheld?.type === "DETRACTION" ? [[
        "Detracción",
        `Operación sujeta al Sistema de Pago de Obligaciones Tributarias (SPOT). Depositar ${pdfMoney(withheld.amount, "PEN")} en la cuenta del Banco de la Nación ${invoice.detraction_account ?? "(sin registrar)"}.`,
      ] as [string, string]] : []),
      // Mientras SUNAT no lo haya aceptado, este documento no es el comprobante electrónico.
      ...(invoice.fiscal_status === "ACCEPTED" ? [] : [["Aviso", "Documento interno. No es la representación impresa de un comprobante electrónico: no fue aceptado por SUNAT."] as [string, string]]),
    ],
    signatures: (companyName) => [companyName],
  });
}

/** Recibo de un cobro en PDF, con las cuentas a las que se aplicó. */
export async function downloadPaymentPdf(payment: PaymentDetail) {
  const currency = payment.currency;

  await downloadDocumentPdf({
    file: `Recibo_${payment.payment_number}`,
    title: "RECIBO DE COBRO",
    number: payment.payment_number,
    watermark: payment.status === "REFUNDED" ? "REEMBOLSADO" : undefined,
    left: [
      ["Cliente", customerName(payment.customer)],
      ["Método", methodLabel(payment.payment_method)],
      ["N.º de operación", payment.reference || "-"],
    ],
    right: [
      ["Fecha", payment.paid_at ? shortDate(payment.paid_at) : "-"],
      ["Cuenta de destino", payment.account_name ?? "-"],
      ["Registrado por", payment.creator ?? "-"],
    ],
    head: ["#", "Comprobante", "Aplicado el", "Importe"],
    rows: payment.allocations.map((allocation, index) => [
      String(index + 1), allocation.invoice_number ?? "-", dateTime(allocation.at), pdfAmount(allocation.amount),
    ]),
    numCols: [3],
    totals: [
      ["Aplicado", pdfMoney(payment.allocated, currency)],
      ...(payment.refunded_amount ? [["Reembolsado", pdfMoney(payment.refunded_amount, currency)] as [string, string]] : []),
      ...(payment.free > 0 ? [["Sin aplicar (anticipo)", pdfMoney(payment.free, currency)] as [string, string]] : []),
      ["Monto recibido", pdfMoney(payment.amount, currency), true],
    ],
    notes: [],
    signatures: (companyName) => [`Recibido · ${companyName}`],
  });
}
