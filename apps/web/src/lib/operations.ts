import type { Tone } from "@/components/ui/proto";
import { BILLING_MODES, FREQUENCIES, customerDocument, customerName, shortDate } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { downloadDocumentPdf } from "@/lib/document-pdf";
import type { BillingMode, HistoryEntry } from "@/types/commercial";
import type {
  ChecklistStatus, ContractedService, ContractedServiceStatus, WorkOrder, WorkOrderDetail, WorkOrderPriority, WorkOrderStatus,
} from "@/types/operations";

export const CONTRACTED_STATES: [ContractedServiceStatus, string, Tone][] = [
  ["PENDING", "Pendiente", "neutral"],
  ["ACTIVE", "Activo", "success"],
  ["SUSPENDED", "Suspendido", "warning"],
  ["COMPLETED", "Completado", "muted"],
  ["CANCELLED", "Cancelado", "muted"],
];

/** Cambios de estado de un servicio contratado: ruta, estados de origen y si piden motivo. */
export const CONTRACTED_MOVES: { path: string; from: ContractedServiceStatus[]; label: string; done: string; reason?: boolean }[] = [
  { path: "activate", from: ["PENDING"], label: "Activar servicio", done: "Servicio activado" },
  { path: "reactivate", from: ["SUSPENDED"], label: "Reactivar", done: "Servicio reactivado" },
  { path: "complete", from: ["ACTIVE"], label: "Completar", done: "Servicio completado" },
  { path: "suspend", from: ["ACTIVE"], label: "Suspender", done: "Servicio suspendido", reason: true },
  { path: "cancel", from: ["PENDING", "ACTIVE", "SUSPENDED"], label: "Cancelar", done: "Servicio cancelado", reason: true },
];

export const WORK_ORDER_STATES: [WorkOrderStatus, string, Tone][] = [
  ["PLANNED", "Planificada", "neutral"],
  ["ASSIGNED", "Asignada", "info"],
  ["IN_PROGRESS", "En ejecución", "accent"],
  ["BLOCKED", "Bloqueada", "warning"],
  ["COMPLETED", "Completada", "success"],
  ["CANCELLED", "Cancelada", "muted"],
];

/** Estados en los que la orden sigue viva: se edita, se reasigna y se marca su checklist. */
export const WORK_ORDER_OPEN: WorkOrderStatus[] = ["PLANNED", "ASSIGNED", "IN_PROGRESS", "BLOCKED"];

/** Estados de la orden en los que se marca su checklist. */
export const CHECKABLE: WorkOrderStatus[] = ["ASSIGNED", "IN_PROGRESS", "BLOCKED"];

export type WorkOrderMove = { path: string; to: WorkOrderStatus; from: WorkOrderStatus[]; label: string; done: string; reason?: string };

/** Cambios de estado de una orden de trabajo (asignar va aparte: pide técnico y otro permiso). */
export const WORK_ORDER_MOVES: WorkOrderMove[] = [
  { path: "start", to: "IN_PROGRESS", from: ["ASSIGNED"], label: "Iniciar", done: "Orden iniciada" },
  { path: "unblock", to: "IN_PROGRESS", from: ["BLOCKED"], label: "Desbloquear", done: "Orden desbloqueada" },
  { path: "complete", to: "COMPLETED", from: ["IN_PROGRESS"], label: "Completar", done: "Orden completada" },
  { path: "block", to: "BLOCKED", from: ["IN_PROGRESS"], label: "Bloquear", done: "Orden bloqueada", reason: "Motivo del bloqueo" },
  { path: "cancel", to: "CANCELLED", from: ["PLANNED", "ASSIGNED", "BLOCKED"], label: "Cancelar", done: "Orden cancelada", reason: "Motivo de cancelación" },
];

export const PRIORITIES: [WorkOrderPriority, string, Tone][] = [
  ["HIGH", "Alta", "danger"],
  ["NORMAL", "Media", "warning"],
  ["LOW", "Baja", "neutral"],
];

export const CHECKLIST_STATES: [ChecklistStatus, string, Tone][] = [
  ["PENDING", "Pendiente", "neutral"],
  ["IN_PROGRESS", "En curso", "accent"],
  ["COMPLETED", "Completado", "info"],
  ["APPROVED", "Aprobado", "success"],
];

/** "Recurrente · mensual", "Por evento"… */
export function billingOf(service: { billing_mode: BillingMode; frequency?: string | null } | null | undefined) {
  if (!service) return "—";
  const mode = BILLING_MODES[service.billing_mode] ?? service.billing_mode;
  const frequency = service.frequency ? FREQUENCIES[service.frequency as keyof typeof FREQUENCIES] ?? service.frequency.toLowerCase() : null;
  return frequency ? `${mode} · ${frequency}` : mode;
}

/** Cómo se factura el servicio de una orden de trabajo (el cierre de la orden no factura por sí mismo). */
export const BILLING_NOTES: Record<BillingMode, string> = {
  RECURRING: "Recurrente (se factura por ciclo)",
  PER_EVENT: "Por evento",
  ONE_TIME: "Único",
};

export const contractedPeriod = (service: Pick<ContractedService, "start_date" | "end_date">) =>
  service.start_date || service.end_date ? `${shortDate(service.start_date)} → ${shortDate(service.end_date)}` : "Sin fechas";

export const scheduledText = (value: string | null | undefined) => (value ? dateTime(value) : "Sin programar");

/** Avance del checklist en porcentaje; null si la orden no tiene checklist. */
export function checklistProgress(order: Pick<WorkOrder, "checklist">) {
  const checklist = order.checklist;
  if (!checklist) return null;
  return checklist.total ? Math.round((checklist.done / checklist.total) * 100) : 0;
}

// Cómo se lee en el historial cada movimiento que no cambia de estado.
const HISTORY_LABELS: Record<string, string> = {
  "work_order.create": "Orden creada",
  "work_order.update": "Datos editados",
  "work_order.assign": "Técnico asignado",
  "work_order.checklist.check": "Ítem marcado",
  "work_order.checklist.uncheck": "Ítem desmarcado",
  "work_order.checklist.approve": "Checklist aprobado",
  "work_order.checklist.return": "Checklist devuelto",
};

export const operationsHistoryLabel = (entry: HistoryEntry) => HISTORY_LABELS[entry.action] ?? entry.action;

// Marca de agua del informe de una orden que no llegó a ejecutarse.
const WATERMARK: Partial<Record<WorkOrderStatus, string>> = { CANCELLED: "ANULADO" };

/** Informe de servicio de la orden de trabajo en PDF: datos, checklist con sus evidencias y firmas. */
export async function downloadWorkOrderPdf(order: WorkOrderDetail) {
  const label = (states: [string, string, Tone][], key: string) => states.find(([code]) => code === key)?.[1] ?? key;

  await downloadDocumentPdf({
    file: `Informe_${order.code}`,
    title: "INFORME DE SERVICIO",
    number: order.code,
    watermark: WATERMARK[order.status],
    left: [
      ["Cliente", customerName(order.customer)],
      ["Documento", customerDocument(order.customer) || "-"],
      ["Dirección", order.customer_contact?.address || "-"],
      ["Lugar del servicio", order.location || "-"],
    ],
    right: [
      ["Trabajo", order.title],
      ["Servicio", order.service?.name ?? "-"],
      ["Contrato", order.contract?.contract_number ?? "-"],
      ["Técnico", order.assignee?.name ?? "Sin asignar"],
      ["Programada", order.scheduled_start ? dateTime(order.scheduled_start) : "-"],
      ["Estado", label(WORK_ORDER_STATES, order.status)],
    ],
    head: ["#", "Checklist", "Obligatorio", "Hecho", "Evidencia"],
    rows: order.checklist_items.map((item, index) => [
      String(index + 1),
      item.label,
      item.required ? "Sí" : "No",
      item.done ? `Sí${item.done_by ? ` · ${item.done_by}` : ""}` : "No",
      item.evidence_note ?? "",
    ]),
    numCols: [],
    totals: [],
    notes: [
      ["Descripción", order.description || "-"],
      ...(order.checklist ? [["Checklist", `${order.checklist.name}: ${label(CHECKLIST_STATES, order.checklist.status)} (${order.checklist.done}/${order.checklist.total} ítems)`] as [string, string]] : []),
      ...(order.started_at ? [["Ejecución", `Inicio ${dateTime(order.started_at)}${order.completed_at ? ` · Fin ${dateTime(order.completed_at)}` : ""}`] as [string, string]] : []),
      ...(order.blocked_reason ? [["Motivo del bloqueo", order.blocked_reason] as [string, string]] : []),
      ...(order.cancel_reason ? [["Motivo de cancelación", order.cancel_reason] as [string, string]] : []),
    ],
    signatures: (companyName) => ["Conformidad del cliente", `Técnico · ${companyName}`],
  });
}
