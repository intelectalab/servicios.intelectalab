import type { Tone } from "@/components/ui/proto";
import type { ContractType, EmployeeStatus, LeaveStatus, PayrollStatus } from "@/types/personal";

// Estados y textos de Personal (kit v1.5: employee, leaveRequest; envío a planilla y sincronización).
export const EMPLOYEE_STATES: [EmployeeStatus, string, Tone][] = [
  ["ACTIVE", "Activo", "success"],
  ["INACTIVE", "Inactivo", "muted"],
  ["TERMINATED", "Cesado", "neutral"],
];

export const LEAVE_STATES: [LeaveStatus, string, Tone][] = [
  ["REQUESTED", "Solicitada", "warning"],
  ["APPROVED", "Aprobada", "success"],
  ["REJECTED", "Rechazada", "danger"],
  ["CANCELLED", "Anulada", "muted"],
];

export const PAYROLL_STATES: [PayrollStatus, string, Tone][] = [
  ["GENERATED", "Generado", "info"],
  ["SENT", "Enviado", "accent"],
  ["ACKNOWLEDGED", "Recibido por RRHH", "success"],
  ["FAILED", "Fallido", "danger"],
];

export const CONTRACT_TYPES: [ContractType, string][] = [
  ["INDEFINIDO", "Indefinido"],
  ["PLAZO_FIJO", "Plazo fijo"],
  ["TIEMPO_PARCIAL", "Tiempo parcial"],
  ["PRACTICAS", "Prácticas"],
];

export const DOCUMENT_TYPES: [string, string][] = [["DNI", "DNI"], ["CE", "Carné de extranjería"], ["PASSPORT", "Pasaporte"]];

export const RUN_STATES: [string, string, Tone][] = [
  ["RUNNING", "En curso", "info"],
  ["COMPLETED", "Completada", "success"],
  ["FAILED", "Fallida", "danger"],
];

export const INBOUND_STATES: [string, string, Tone][] = [
  ["RECEIVED", "Recibido", "info"],
  ["APPLIED", "Aplicado", "success"],
  ["SKIPPED", "Sin cambios", "muted"],
  ["UNMATCHED", "En la bandeja", "warning"],
  ["FAILED", "Fallido", "danger"],
];

export const OUTBOUND_STATES: [string, string, Tone][] = [
  ["PENDING", "Por enviar", "info"],
  ["RETRY", "Reintentando", "warning"],
  ["SENT", "Recibido por RRHH", "success"],
  ["DEAD", "Fallido", "danger"],
];

export const EVENT_LABELS: Record<string, string> = {
  "employee.upserted": "Trabajador",
  "employee.terminated": "Cese",
  "position.upserted": "Puesto",
  "leave.upserted": "Ausencia",
  "timesheet.period_approved": "Horas del periodo",
  "employee.user_linked": "Vínculo con usuario",
};

/** Aviso de los registros que mantiene el SaaS de RRHH (RN-HRM-06). */
export const HR_MASTERED_NOTE = "Se edita en el SaaS de RRHH";

/** "2026-11" → "noviembre 2026". */
export function periodLabel(period: string): string {
  const [year, month] = period.split("-").map(Number);
  if (!year || !month) return period;
  return new Date(year, month - 1, 1).toLocaleDateString("es-PE", { month: "long", year: "numeric" });
}

/** Minutos → "12 h 30 min". */
export function minutesLabel(minutes: number): string {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return m ? `${h} h ${m} min` : `${h} h`;
}

export function money(value: number | null | undefined, currency = "PEN"): string {
  if (value === null || value === undefined) return "—";
  return `${currency === "USD" ? "US$" : "S/"} ${value.toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// --- Trabajadores y Ausencias (pantallas /hr/employees y /hr/leaves) ---

/** Filtro "Estado" del listado de trabajadores: los estados y "Ausentes hoy". */
export const EMPLOYEE_FILTER_STATES: [string, string][] = [
  ...EMPLOYEE_STATES.map(([key, label]): [string, string] => [key, label]),
  ["ON_LEAVE", "Ausentes hoy"],
];

export const HR_SOURCES: [string, string][] = [["LOCAL", "ERP"], ["HR_SAAS", "SaaS de RRHH"]];

/** Lo que llegó del SaaS de RRHH con la conexión activa: solo se edita allí. */
export const isMastered = (source: string | null | undefined, mode: string | null | undefined) => source === "HR_SAAS" && mode === "HR_SAAS_MASTER";

const HR_HISTORY: Record<string, string> = {
  "employee.create": "Alta del trabajador",
  "employee.update": "Datos actualizados",
  "employee.terminate": "Cese registrado",
  "employee.reinstate": "Cese deshecho",
};

export const hrHistoryLabel = (action: string) => HR_HISTORY[action] ?? action;

/** Fecha "YYYY-MM-DD" de un día local. */
export function isoDay(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** Días de calendario entre dos fechas "YYYY-MM-DD", ambas incluidas (0 si faltan o están al revés). */
export function calendarDays(from: string, to: string): number {
  if (!from || !to) return 0;
  const [a, b] = [from, to].map((value) => {
    const [y, m, d] = value.slice(0, 10).split("-").map(Number);
    return Date.UTC(y, m - 1, d);
  });
  if (Number.isNaN(a) || Number.isNaN(b) || b < a) return 0;
  return Math.round((b - a) / 86_400_000) + 1;
}

/**
 * Fecha y hora de la API. Las que salen tal cual de la base ("2026-11-16 15:04:05", sin zona)
 * están en UTC: se leen así para mostrarlas en la hora de quien mira.
 */
export function hrDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const plainUtc = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/.test(value);
  const date = new Date(plainUtc ? `${value.replace(" ", "T")}Z` : value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
}
