import type { Tone } from "@/components/ui/proto";
import { ApiError } from "@/lib/api";
import type { HistoryEntry } from "@/types/commercial";
import type { Lead, LeadStatus, Opportunity, OpportunityStatus } from "@/types/crm";

export const LEAD_STATES: [LeadStatus, string, Tone][] = [
  ["NEW", "Nuevo", "info"],
  ["CONTACTED", "Contactado", "warning"],
  ["QUALIFIED", "Calificado", "success"],
  ["CONVERTED", "Convertido a oportunidad", "success"],
  ["DISQUALIFIED", "Descartado", "muted"],
];

/** Estados en los que el lead sigue abierto: se edita, se califica o se descarta. */
export const LEAD_OPEN: LeadStatus[] = ["NEW", "CONTACTED", "QUALIFIED"];

export const OPPORTUNITY_STATES: [OpportunityStatus, string, Tone][] = [
  ["OPEN", "Abierta", "info"],
  ["WON", "Ganada", "success"],
  ["LOST", "Perdida", "danger"],
];

export const LOSS_REASONS = ["Precio", "Eligió a la competencia", "Sin presupuesto", "Proyecto postergado", "Sin respuesta del cliente"];

/** La empresa del lead o, si es una persona, su nombre. */
export const leadName = (lead: Pick<Lead, "organization_name" | "person_name">) => lead.organization_name || lead.person_name || "Sin nombre";

/** Monto por probabilidad de cierre. */
export const weighted = (opportunity: Pick<Opportunity, "expected_value" | "probability">) =>
  (Number(opportunity.expected_value) * Number(opportunity.probability)) / 100;

/** Monto abreviado de los indicadores y del tablero: "S/ 12.5k", "S/ 1.2M". */
export function compactMoney(value: number) {
  const amount = Number.isFinite(value) ? value : 0;
  if (Math.abs(amount) >= 1_000_000) return `S/ ${(amount / 1_000_000).toFixed(1)}M`;
  if (Math.abs(amount) >= 1_000) return `S/ ${(amount / 1_000).toFixed(1)}k`;
  return `S/ ${Math.round(amount)}`;
}

/** "hoy", "en 5 días", "hace 3 días": distancia en días de una fecha a hoy. */
export function relativeDay(value: string | null | undefined) {
  if (!value) return "—";
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  const date = match && /T00:00:00|^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  date.setHours(0, 0, 0, 0);
  const days = Math.round((date.getTime() - today.getTime()) / 86_400_000);
  if (days === 0) return "hoy";
  if (days === 1) return "mañana";
  if (days === -1) return "ayer";
  return days > 0 ? `en ${days} días` : `hace ${-days} días`;
}

// Cómo se lee en el historial cada movimiento que no cambia el estado.
const HISTORY_LABELS: Record<string, string> = {
  "crm_lead.update": "Datos editados",
  "crm_lead.convert": "Convertido",
  "crm_opportunity.update": "Datos editados",
  "crm_opportunity.stage": "Cambio de etapa",
  "crm_opportunity.convert": "Convertida a cliente",
};

export const crmHistoryLabel = (entry: HistoryEntry) => HISTORY_LABELS[entry.action] ?? entry.action;

export function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

/** Errores de validación de la API, uno por campo, para pintarlos bajo cada control. */
export function fieldErrors(err: unknown): Record<string, string> {
  if (!(err instanceof ApiError) || !err.errors) return {};
  return Object.fromEntries(Object.entries(err.errors).map(([field, messages]) => [field, messages[0]]));
}

export const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

const VIEW_KEY = "erp.view.";

export type ViewMode = "table" | "kanban";

/** Vista elegida en un listado con tablero (se recuerda en el navegador). */
export function readView(key: string, fallback: ViewMode): ViewMode {
  try {
    const stored = window.localStorage.getItem(VIEW_KEY + key);
    return stored === "table" || stored === "kanban" ? stored : fallback;
  } catch {
    return fallback;
  }
}

export function saveView(key: string, mode: ViewMode) {
  try { window.localStorage.setItem(VIEW_KEY + key, mode); } catch { /* sin almacenamiento, no se recuerda */ }
}
