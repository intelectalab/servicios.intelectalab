/** Respuesta de GET /v1/dashboard: cada bloque ya viene filtrado por permiso, alcance y plan. */
export type DashboardTone = "info" | "success" | "warning" | "danger" | "neutral";

export type DashboardKpi = {
  key: string;
  label: string;
  href: string | null;
  /** Con el permiso, pero el módulo no está en el plan de la empresa. */
  locked: boolean;
  value?: number | null;
  format?: "money" | "percent" | "number" | "hours" | "text";
  text?: string;
  sub?: string;
  sub_tone?: "danger" | null;
  tone?: string;
  icon?: string;
};

export type DashboardAttention = { key: string; tone: DashboardTone; icon: string; count: number; text: string; href: string | null };

export type DashboardChart = {
  key: string;
  label: string;
  href: string | null;
  locked: boolean;
  type?: "columns" | "bars";
  format?: "money" | "number";
  rows?: { label: string; value: number; tone?: DashboardTone; month?: string }[];
  collected?: number | null;
};

export type Dashboard = {
  hero: { name: string; role: string | null; company: string; date: string; cta: { label: string; href: string } | null };
  kpis: DashboardKpi[];
  attention: DashboardAttention[];
  scope: { label: string; scope: string }[];
  charts: DashboardChart[];
  health: { available: boolean; rows: { name: string; status: string; detail: string | null }[] } | null;
  recent: { id: string; actor: string; action: string; entity: string | null; correlation_id: string | null; at: string }[] | null;
};
