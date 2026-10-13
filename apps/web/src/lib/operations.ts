import type { Tone } from "@/components/ui/proto";
import { BILLING_MODES, FREQUENCIES, customerDocument, customerName, shortDate } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { downloadDocumentPdf } from "@/lib/document-pdf";
import type { BillingMode, HistoryEntry } from "@/types/commercial";
import type {
  AgendaEventStatus, AgendaEventType, ChecklistStatus, ContractedService, ContractedServiceStatus, DeliverableStatus, EntryStatus, ProjectStatus, TaskStatus,
  WorkOrder, WorkOrderDetail, WorkOrderPriority, WorkOrderStatus,
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

// ------------------------------------------------------------------ Proyectos, horas y costos

export const PROJECT_STATES: [ProjectStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["PLANNED", "Planificado", "info"],
  ["IN_PROGRESS", "En ejecución", "accent"],
  ["ON_HOLD", "En pausa", "warning"],
  ["COMPLETED", "Completado", "success"],
  ["CANCELLED", "Cancelado", "muted"],
];

/** Estados en los que el proyecto sigue vivo: se edita y cambian sus tareas y entregables. */
export const PROJECT_OPEN: ProjectStatus[] = ["DRAFT", "PLANNED", "IN_PROGRESS", "ON_HOLD"];

export type ProjectMove = { path: string; to: ProjectStatus; from: ProjectStatus[]; label: string; done: string; reason?: string; permission: string };

/** Cambios de estado de un proyecto. Cerrarlo es de quien supervisa (`projects.approve`). */
export const PROJECT_MOVES: ProjectMove[] = [
  { path: "plan", to: "PLANNED", from: ["DRAFT"], label: "Planificar", done: "Proyecto planificado", permission: "projects.manage" },
  { path: "start", to: "IN_PROGRESS", from: ["PLANNED"], label: "Iniciar", done: "Proyecto iniciado", permission: "projects.manage" },
  { path: "resume", to: "IN_PROGRESS", from: ["ON_HOLD"], label: "Reanudar", done: "Proyecto reanudado", permission: "projects.manage" },
  { path: "complete", to: "COMPLETED", from: ["IN_PROGRESS"], label: "Cerrar proyecto", done: "Proyecto cerrado", permission: "projects.approve" },
  { path: "hold", to: "ON_HOLD", from: ["IN_PROGRESS"], label: "Pausar", done: "Proyecto en pausa", reason: "Motivo de la pausa", permission: "projects.manage" },
  { path: "cancel", to: "CANCELLED", from: ["DRAFT", "PLANNED", "ON_HOLD"], label: "Cancelar", done: "Proyecto cancelado", reason: "Motivo de cancelación", permission: "projects.manage" },
];

export const TASK_STATES: [TaskStatus, string, Tone][] = [
  ["TODO", "Por hacer", "neutral"],
  ["IN_PROGRESS", "En curso", "accent"],
  ["BLOCKED", "Bloqueada", "warning"],
  ["DONE", "Hecha", "success"],
  ["CANCELLED", "Cancelada", "muted"],
];

/** Cambios de estado de una tarea: destino → estados de origen (los mismos que comprueba la API). */
export const TASK_MOVES: Record<Exclude<TaskStatus, "TODO">, TaskStatus[]> = {
  IN_PROGRESS: ["TODO", "BLOCKED"],
  BLOCKED: ["IN_PROGRESS"],
  DONE: ["IN_PROGRESS"],
  CANCELLED: ["TODO", "IN_PROGRESS", "BLOCKED"],
};

export const DELIVERABLE_STATES: [DeliverableStatus, string, Tone][] = [
  ["DRAFT", "En preparación", "neutral"],
  ["IN_REVIEW", "En revisión", "info"],
  ["RETURNED", "Devuelto", "warning"],
  ["APPROVED", "Aprobado", "success"],
  ["DELIVERED", "Entregado", "success"],
];

export const ENTRY_STATES: [EntryStatus, string, Tone][] = [
  ["SUBMITTED", "Enviado", "info"],
  ["APPROVED", "Aprobado", "success"],
  ["REJECTED", "Rechazado", "danger"],
];

export const AGENDA_TYPES: [AgendaEventType, string, Tone][] = [
  ["MEETING", "Reunión", "info"],
  ["VISIT", "Visita", "info"],
  ["MAINTENANCE", "Mantenimiento", "info"],
  ["SESSION", "Sesión", "info"],
];

export const AGENDA_STATES: [AgendaEventStatus, string, Tone][] = [
  ["SCHEDULED", "Programado", "info"],
  ["DONE", "Realizado", "success"],
  ["CANCELLED", "Cancelado", "muted"],
];

/** Categorías de un documento, las del prototipo (la API admite solo estas). */
export const DOCUMENT_CATEGORIES = ["Contrato", "Entregable", "Evidencia", "Proyecto", "Cliente", "Proveedor", "Servidor", "Dominio", "Hosting", "Cloud", "Otros"];

/** Tamaño de un archivo en texto: "850 KB", "2.4 MB". */
export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export const COST_CATEGORIES = ["Proveedor", "Licencias", "Infraestructura", "Materiales", "Personal externo"];

export const projectPeriod = (project: { start_date: string | null; end_date: string | null }) =>
  project.start_date || project.end_date ? `${shortDate(project.start_date)} → ${shortDate(project.end_date)}` : "Sin fechas";

export const hoursText = (hours: number) => `${hours.toLocaleString("es-PE", { minimumFractionDigits: 1, maximumFractionDigits: 1 })} h`;

/** Proyecto u orden de trabajo donde se trabajaron unas horas. */
export function entryContext(entry: { project: { code: string; name: string } | null; work_order: { code: string; title: string } | null }) {
  if (entry.project) return `${entry.project.code} · ${entry.project.name}`;
  return entry.work_order ? `${entry.work_order.code} · ${entry.work_order.title}` : "—";
}

// Cómo se lee en el historial del proyecto cada movimiento que no cambia su estado.
const PROJECT_HISTORY_LABELS: Record<string, string> = {
  "project.create": "Proyecto creado",
  "project.update": "Datos editados",
  "project.task.create": "Tarea creada",
  "project.task.update": "Tarea editada",
  "project.task.move": "Tarea movida",
  "project.task.check": "Control marcado",
  "project.task.uncheck": "Control desmarcado",
  "project.deliverable.create": "Entregable creado",
  "project.deliverable.update": "Entregable editado",
  "project.deliverable.submit": "Entregable a revisión",
  "project.deliverable.approve": "Entregable aprobado",
  "project.deliverable.return": "Entregable devuelto",
  "project.deliverable.deliver": "Entregable entregado",
};

export const projectHistoryLabel = (entry: HistoryEntry) => PROJECT_HISTORY_LABELS[entry.action] ?? entry.action;
