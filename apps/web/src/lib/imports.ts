import type { Tone } from "@/components/ui/proto";

/** Una entidad que la persona puede importar desde Importación masiva. */
export type ImportEntity = {
  key: string;
  label: string;
  endpoint: string;
  template: string;
  columns: string[];
  required: string[];
  hint: string;
  can_import: boolean;
};

export type ImportRowStatus = "created" | "updated" | "skipped" | "error";

export type ImportRow = { row: number; status: ImportRowStatus; message: string | null; values: Record<string, string> };

/** Respuesta de validar (dry_run) o de importar. */
export type ImportResult = {
  imported: number;
  updated: number;
  skipped: number;
  failed: number;
  total: number;
  message?: string;
  dry_run?: boolean;
  rows: ImportRow[];
};

export type ImportJob = {
  id: string;
  entity: string;
  entity_label: string;
  file: string;
  total: number;
  created: number;
  updated: number;
  skipped: number;
  failed: number;
  status: "COMPLETED" | "PARTIAL" | "FAILED";
  status_label: string;
  user_name: string | null;
  at: string;
  details?: ImportRow[];
};

export type ImportsData = { entities: ImportEntity[]; labels: Record<string, string>; states: Record<string, string> };

export const JOB_STATES: [ImportJob["status"], string, Tone][] = [
  ["COMPLETED", "Completada", "success"],
  ["PARTIAL", "Con omisiones", "warning"],
  ["FAILED", "Fallida", "danger"],
];

/** Resultado de una fila: texto y color. */
export const ROW_STATES: Record<ImportRowStatus, [string, Tone]> = {
  created: ["Lista", "success"],
  updated: ["Se actualiza", "info"],
  skipped: ["Se omite", "warning"],
  error: ["Con error", "danger"],
};
