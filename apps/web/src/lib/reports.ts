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
};

export type ReportsIndex = {
  reports: { key: ReportKey; label: string }[];
  exports: { report: string | null; label: string; format: "xlsx" | "csv" | null; rows: number; selection: boolean; at: string }[];
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
