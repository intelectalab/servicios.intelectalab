import type { Tone } from "@/components/ui/proto";
import type { HistoryEntry } from "@/types/commercial";
import type {
  SlaMode, SlaStatus, TicketCategory, TicketChannel, TicketPriority, TicketSla, TicketStatus, WarrantyStatus,
} from "@/types/support";

// Estados y textos del prototipo (core/states.js: ticket y warrantyClaim).
export const TICKET_STATES: [TicketStatus, string, Tone][] = [
  ["OPEN", "Abierto", "info"],
  ["TRIAGED", "Clasificado", "info"],
  ["ASSIGNED", "Asignado", "accent"],
  ["IN_PROGRESS", "En atención", "accent"],
  ["WAITING_CUSTOMER", "Esperando cliente", "warning"],
  ["RESOLVED", "Resuelto", "success"],
  ["CLOSED", "Cerrado", "muted"],
  ["REOPENED", "Reabierto", "danger"],
];

/** Filtro de estado del prototipo: "Abiertos (todos)" y los estados que se buscan a diario. */
export const TICKET_STATUS_FILTER: [string, string][] = [
  ["OPEN_ALL", "Abiertos (todos)"],
  ["OPEN", "Sin clasificar"],
  ["IN_PROGRESS", "En atención"],
  ["WAITING_CUSTOMER", "Esperando cliente"],
  ["RESOLVED", "Resueltos"],
  ["CLOSED", "Cerrados"],
  ["REOPENED", "Reabiertos"],
];

export const PRIORITY_LABELS: Record<TicketPriority, string> = { P1: "Crítica", P2: "Alta", P3: "Media", P4: "Baja" };

export const PRIORITY_FILTER: [string, string][] = [
  ["P1", "P1 Crítica"], ["P2", "P2 Alta"], ["P3", "P3 Media"], ["P4", "P4 Baja"],
];

export const SLA_FILTER: [SlaStatus, string][] = [
  ["breached", "Vencido"], ["risk", "En riesgo"], ["ok", "A tiempo"], ["paused", "En pausa"], ["met", "Cumplido"],
];

export const CHANNELS: Record<TicketChannel, string> = { PHONE: "Teléfono", EMAIL: "Correo", BACKOFFICE: "Backoffice", PORTAL: "Portal" };

export const CATEGORIES: Record<TicketCategory, string> = { INCIDENT: "Incidencia", REQUEST: "Requerimiento", QUESTION: "Consulta" };

export const SLA_MODES: Record<SlaMode, string> = { BUSINESS: "Horario hábil", "24X7": "24×7" };

/** Sin resolver: corre (o está en pausa) su reloj. */
export const TICKET_UNRESOLVED: TicketStatus[] = ["OPEN", "TRIAGED", "ASSIGNED", "IN_PROGRESS", "WAITING_CUSTOMER", "REOPENED"];

export type TicketMove = { path: string; to: TicketStatus; from: TicketStatus[]; label: string; done: string; reason?: string; danger?: boolean };

/** Transiciones del prototipo. Clasificar y asignar van aparte: piden prioridad o responsable. */
export const TICKET_MOVES: TicketMove[] = [
  { path: "start", to: "IN_PROGRESS", from: ["ASSIGNED", "REOPENED"], label: "Atender", done: "Ticket en atención" },
  { path: "wait", to: "WAITING_CUSTOMER", from: ["IN_PROGRESS"], label: "Esperar al cliente (pausa SLA)", done: "SLA en pausa: esperando al cliente" },
  { path: "resume", to: "IN_PROGRESS", from: ["WAITING_CUSTOMER"], label: "Retomar", done: "Ticket retomado" },
  { path: "resolve", to: "RESOLVED", from: ["IN_PROGRESS"], label: "Resolver", done: "Ticket resuelto", reason: "Resolución" },
  { path: "close", to: "CLOSED", from: ["RESOLVED"], label: "Cerrar", done: "Ticket cerrado" },
  { path: "reopen", to: "REOPENED", from: ["RESOLVED", "CLOSED"], label: "Reabrir", done: "Ticket reabierto", reason: "Motivo de la reapertura", danger: true },
];

export const WARRANTY_STATES: [WarrantyStatus, string, Tone][] = [
  ["SUBMITTED", "Recibido", "info"],
  ["UNDER_REVIEW", "En revisión", "accent"],
  ["APPROVED", "Cubierto", "success"],
  ["REJECTED", "No cubierto", "danger"],
  ["RESOLVED", "Resuelto", "muted"],
];

export const COVERAGE_FILTER: [string, string][] = [
  ["covered", "Dentro de garantía"], ["not_covered", "Fuera de cobertura"], ["unchecked", "Sin validar"],
];

/** "45 min", "5 h", "3 d", como el prototipo. */
export function duration(ms: number) {
  const hours = ms / 3_600_000;
  if (hours < 1) return `${Math.max(1, Math.round(hours * 60))} min`;
  if (hours < 48) return `${Math.round(hours)} h`;
  return `${Math.round(hours / 24)} d`;
}

/** Minutos de una política en texto: "30 min", "4 h", "72 h". */
export function minutesText(minutes: number) {
  if (minutes < 60) return `${minutes} min`;
  const hours = minutes / 60;
  return `${Number.isInteger(hours) ? hours : hours.toLocaleString("es-PE", { maximumFractionDigits: 2 })} h`;
}

export type SlaView = { label: string; tone: Tone; pct: number };

/** Estado del SLA de resolución para mostrar: barra, texto y tono (los del prototipo). */
export function slaView(sla: TicketSla, now = Date.now()): SlaView {
  if (!sla.resolution_due_at || sla.status === "none") return { label: "Sin SLA (pendiente de triage)", tone: "neutral", pct: 0 };
  const start = new Date(sla.started_at ?? sla.resolution_due_at).getTime();
  const due = new Date(sla.resolution_due_at).getTime();
  const end = sla.resolved_at ? new Date(sla.resolved_at).getTime() : sla.paused_at ? new Date(sla.paused_at).getTime() : now;
  const pct = Math.min(100, Math.max(0, Math.round(((end - start) / Math.max(1, due - start)) * 100)));
  const remaining = due - now;

  switch (sla.status) {
    case "met": return { label: "SLA cumplido", tone: "success", pct };
    case "paused": return { label: "SLA en pausa", tone: "neutral", pct };
    case "breached": return sla.resolved_at ? { label: "SLA incumplido", tone: "danger", pct: 100 } : { label: `Vencido hace ${duration(-remaining)}`, tone: "danger", pct: 100 };
    case "risk": return { label: `En riesgo · quedan ${duration(Math.max(0, remaining))}`, tone: "warning", pct };
    default: return { label: `A tiempo · quedan ${duration(Math.max(0, remaining))}`, tone: "success", pct };
  }
}

/** Tiempo en pausa (lo acumulado más la pausa en curso), en minutos. */
export function pausedMinutes(sla: TicketSla, now = Date.now()) {
  const live = sla.paused_at ? now - new Date(sla.paused_at).getTime() : 0;
  return Math.round((sla.paused_seconds * 1000 + live) / 60_000);
}

// Cómo se lee en el historial cada movimiento que no cambia de estado.
const HISTORY_LABELS: Record<string, string> = {
  "ticket.create": "Ticket creado",
  "ticket.triage": "Clasificado",
  "ticket.assign": "Asignado",
  "ticket.comment.public": "Respuesta al cliente",
  "ticket.comment.internal": "Nota interna",
  "ticket.escalate": "Escalado",
  "ticket.work_order": "Orden de trabajo creada",
  "ticket.warranty_claim": "Reclamo de garantía registrado",
  "warranty_claim.create": "Reclamo registrado",
  "warranty_claim.coverage": "Cobertura validada",
  "sla.calendar.update": "Horario de atención guardado",
  "sla.policies.update": "Tiempos de SLA guardados",
  "sla.holiday.add": "Feriado agregado",
  "sla.holiday.remove": "Feriado quitado",
};

export const supportHistoryLabel = (entry: HistoryEntry) => HISTORY_LABELS[entry.action] ?? entry.action;

export const DAYS = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];

/** Fecha corta de un día (AAAA-MM-DD) sin correrse por la zona horaria. */
export function dayText(date: string) {
  return new Date(`${date}T12:00:00`).toLocaleDateString("es-PE", { day: "2-digit", month: "short", year: "numeric" });
}
