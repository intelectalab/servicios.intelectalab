import type { Tone } from "@/components/ui/proto";
import type { Customer } from "@/types/customer";
import type { BillingMode, Frequency, HistoryEntry, QuotationStatus, Service, ServiceStatus } from "@/types/commercial";

/** IGV que se aplica a cada línea cotizada. */
export const IGV_RATE = 18;

export const SERVICE_STATES: [ServiceStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["ACTIVE", "Activo", "success"],
  ["INACTIVE", "Inactivo", "warning"],
  ["ARCHIVED", "Archivado", "muted"],
];

export const BILLING_MODES: Record<BillingMode, string> = {
  ONE_TIME: "Único",
  RECURRING: "Recurrente",
  PER_EVENT: "Por evento",
};

export const FREQUENCIES: Record<Frequency, string> = {
  MONTHLY: "mensual",
  QUARTERLY: "trimestral",
  YEARLY: "anual",
};

/** "Recurrente · mensual", "Único"… */
export function billingLabel(service: Pick<Service, "billing_mode" | "recurrence">) {
  const mode = BILLING_MODES[service.billing_mode] ?? service.billing_mode;
  return service.recurrence ? `${mode} · ${FREQUENCIES[service.recurrence.frequency] ?? service.recurrence.frequency}` : mode;
}

// Cómo se lee en el historial cada movimiento que guarda la auditoría.
const HISTORY_LABELS: Record<string, string> = {
  "service.create": "Servicio creado",
  "service.update": "Datos editados",
  "service.price.create": "Nueva versión de precio",
  "quotation.create": "Cotización creada",
  "quotation.update": "Borrador editado",
  "quotation.version": "Nueva versión creada",
  // Movimientos anteriores a que la auditoría guardara el estado de origen y destino.
  "service.activate": "Activado",
  "service.deactivate": "Inactivado",
  "service.archive": "Archivado",
  "quotation.submit": "Enviada a aprobación",
  "quotation.approve": "Aprobada",
  "quotation.reject": "Rechazada",
  "quotation.expire": "Marcada vencida",
  "quotation.cancel": "Cancelada",
};

export function historyLabel(entry: HistoryEntry) {
  return HISTORY_LABELS[entry.action] ?? entry.action;
}

export const QUOTATION_STATES: [QuotationStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["SUBMITTED", "Enviada", "info"],
  ["APPROVED", "Aprobada", "success"],
  ["REJECTED", "Rechazada", "danger"],
  ["EXPIRED", "Vencida", "warning"],
  ["CANCELLED", "Cancelada", "muted"],
];

export function stateOf<S extends string>(states: [S, string, Tone][], status: string): [string, Tone] {
  const found = states.find(([key]) => key === status);
  return found ? [found[1], found[2]] : [status, "neutral"];
}

export function money(value: string | number | null | undefined, currency = "PEN") {
  const amount = Number(value ?? 0);
  const formatted = (Number.isFinite(amount) ? amount : 0).toLocaleString("es-PE", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `${currency === "USD" ? "US$" : "S/"} ${formatted}`;
}

/** Fechas de la API como "05 oct 2026"; las que no traen hora no se corren por la zona horaria. */
export function shortDate(value: string | null | undefined) {
  if (!value) return "—";
  // Las fechas sin hora llegan como "2026-10-20" o "2026-10-20T00:00:00.000000Z".
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:$|T00:00:00)/.exec(value);
  const date = match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleDateString("es-PE", { day: "2-digit", month: "short", year: "numeric" });
}

/** Hoy + n días en formato YYYY-MM-DD (hora local). */
export function isoDateFromNow(days: number) {
  const date = new Date();
  date.setDate(date.getDate() + days);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function customerName(customer: Customer | null | undefined) {
  const party = customer?.party;
  if (!party) return "—";
  if (party.party_type === "ORGANIZATION") {
    return party.organization?.trade_name || party.organization?.legal_name || "Sin nombre";
  }
  return `${party.person?.given_names ?? ""} ${party.person?.family_name ?? ""}`.trim() || "Sin nombre";
}

export function customerDocument(customer: Customer | null | undefined) {
  const party = customer?.party;
  if (!party) return "";
  if (party.party_type === "ORGANIZATION") return `RUC ${party.organization?.tax_identifier || "—"}`;
  return `${party.person?.document_type ?? ""} ${party.person?.document_number ?? ""}`.trim();
}

const FLASH_KEY = "erp-flash";

/** Aviso que debe verse en la pantalla a la que se navega a continuación. */
export function setFlash(text: string) {
  try { window.sessionStorage.setItem(FLASH_KEY, text); } catch { /* sin almacenamiento, sin aviso */ }
}

export function takeFlash() {
  try {
    const text = window.sessionStorage.getItem(FLASH_KEY);
    if (text) window.sessionStorage.removeItem(FLASH_KEY);
    return text;
  } catch {
    return null;
  }
}
