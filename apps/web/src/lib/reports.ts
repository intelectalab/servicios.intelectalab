import type { Tone } from "@/components/ui/proto";

export type ReportKey = "ventas" | "pipeline" | "cobranza" | "rentabilidad" | "sla" | "renovaciones" | "operaciones";

export type ReportRow = Record<string, string | number | null>;

export type ReportPage = {
  data: ReportRow[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  summary: {
    months?: { key: string; amount: number }[];
    aging?: { key: string; label: string; amount: number }[];
    missing_rate?: boolean;
    sla?: Record<string, number>;
    states?: Record<string, number>;
  } | null;
  kpis: ReportKpi[] | null;
  period: { from: string; to: string; prev_from: string; prev_to: string; custom: boolean } | null;
};

/** Indicador de arriba del reporte; `previous` es la cifra del periodo anterior (si se compara). */
export type ReportKpi = {
  label: string;
  value: number | null;
  format: "money" | "count" | "percent" | "hours";
  previous: number | null;
  hint: string | null;
};

/** Ficha que abre una fila (o null si se abre en una ventana de la propia pantalla). */
export const RECORD_PATHS: Record<ReportKey, ((id: string) => string) | null> = {
  ventas: (id) => `/quotations/${id}`,
  pipeline: (id) => `/crm/opportunities/${id}`,
  cobranza: null,
  rentabilidad: (id) => `/projects/${id}`,
  sla: (id) => `/tickets/${id}`,
  renovaciones: (id) => `/recurring/${id}`,
  operaciones: (id) => `/work-orders/${id}`,
};

/** "1–15 nov. 2026" o "28 oct.–3 nov. 2026". */
export function periodLabel(from: string, to: string) {
  const day = (value: string, withYear: boolean) => {
    const [y, m, d] = value.split("-").map(Number);
    return new Date(y, m - 1, d).toLocaleDateString("es-PE", { day: "numeric", month: "short", ...(withYear ? { year: "numeric" } : {}) });
  };
  return from === to ? day(to, true) : `${day(from, false)} – ${day(to, true)}`;
}

export type ReportsIndex = {
  reports: { key: ReportKey; label: string }[];
  exports: { report: string | null; label: string; format: "xlsx" | "csv" | "pdf" | null; rows: number; selection: boolean; at: string }[];
};

/** Reportes con una fecha propia: se filtran por rango. */
export const DATED: Partial<Record<ReportKey, string>> = {
  ventas: "Fecha de la cotización",
  sla: "Apertura del ticket",
  renovaciones: "Fin de vigencia",
  operaciones: "Fecha programada",
};

export const QUOTATION_SOURCES: Record<string, string> = { OPPORTUNITY: "Oportunidad CRM", PORTAL: "Portal" };

export const SLA_STATES: [string, string, Tone][] = [
  ["breached", "Vencido", "danger"],
  ["risk", "En riesgo", "warning"],
  ["ok", "A tiempo", "success"],
  ["paused", "En pausa", "muted"],
  ["met", "Cumplido", "success"],
  ["none", "Sin SLA", "neutral"],
];

/** "2026-10" → "oct 2026". */
export const monthLabel = (key: string) => {
  const [year, month] = key.split("-").map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString("es-PE", { month: "short", year: "numeric" }).replace(".", "");
};
