import type { Tone } from "@/components/ui/proto";

export type FieldType = "number" | "choice" | "boolean";

export type WorkflowCondition = { field: string; op: string; value: string | number | boolean } | null;

export type WorkflowAction = { type: "notify_role" | "notify_owner" | "portal_notice"; role_id?: string; role?: string | null; text: string };

export type WorkflowRule = {
  id: string;
  name: string;
  trigger: string;
  trigger_label: string;
  module_on: boolean;
  condition: WorkflowCondition;
  actions: WorkflowAction[];
  active: boolean;
  runs: number;
  last_run_at: string | null;
  creator: string | null;
  updated_at: string;
};

export type WorkflowCatalog = {
  triggers: {
    key: string;
    label: string;
    available: boolean;
    customer: boolean;
    fields: { key: string; label: string; type: FieldType; options: { value: string; label: string }[] }[];
  }[];
  operators: Record<FieldType, Record<string, string>>;
  actions: Record<WorkflowAction["type"], string>;
};

export type WorkflowsData = {
  rules: WorkflowRule[];
  roles: { id: string; name: string; code: string }[];
  catalog: WorkflowCatalog;
};

export type WorkflowRun = {
  id: string;
  workflow_id: string;
  workflow: string;
  trigger: string;
  trigger_label: string;
  record: string | null;
  matched: boolean | null;
  status: "DONE" | "SKIPPED" | "FAILED";
  result: string | null;
  at: string;
};

export const RUN_STATES: [WorkflowRun["status"], string, Tone][] = [
  ["DONE", "Ejecutada", "success"],
  ["SKIPPED", "Omitida", "muted"],
  ["FAILED", "Falló", "danger"],
];

/** "Prioridad es P1 Crítica", "Monto (S/) mayor que 20000"; sin condición, "Siempre". */
export function conditionText(condition: WorkflowCondition, trigger: string, catalog: WorkflowCatalog) {
  if (!condition) return "Siempre";
  const field = catalog.triggers.find((t) => t.key === trigger)?.fields.find((f) => f.key === condition.field);
  if (!field) return `${condition.field} ${condition.op} ${String(condition.value)}`;
  const op = catalog.operators[field.type]?.[condition.op] ?? condition.op;
  const value = field.type === "boolean"
    ? (condition.value === true || condition.value === "true" ? "Sí" : "No")
    : field.type === "choice"
      ? field.options.find((o) => o.value === String(condition.value))?.label ?? String(condition.value)
      : Number(condition.value).toLocaleString("es-PE");
  return `${field.label} ${op} ${value}`;
}

/**
 * Plantillas del prototipo (sin "crear evento facturable": la OT completada ya lo crea sola).
 * `role` se busca entre los roles de la empresa por su código o su nombre.
 */
export const TEMPLATES: { name: string; trigger: string; condition: WorkflowCondition; actions: (Omit<WorkflowAction, "role_id"> & { roleHint?: string })[] }[] = [
  {
    name: "Ticket crítico: avisar a Gerencia",
    trigger: "ticket.triaged",
    condition: { field: "priority", op: "=", value: "P1" },
    actions: [{ type: "notify_role", roleHint: "GERENCIA", text: "Ticket P1 abierto: requiere atención inmediata" }],
  },
  {
    name: "Cotización aprobada: preparar el contrato",
    trigger: "quotation.approved",
    condition: null,
    actions: [{ type: "notify_owner", text: "Tu cotización fue aprobada. Genera el contrato." }],
  },
  {
    name: "Pago aplicado: avisar al cliente en el portal",
    trigger: "payment.allocated",
    condition: { field: "amount", op: ">", value: 0 },
    actions: [{ type: "portal_notice", text: "Recibimos tu pago. Gracias." }],
  },
  {
    name: "Oportunidad ganada de alto valor: avisar a Gerencia",
    trigger: "opportunity.won",
    condition: { field: "amount", op: ">", value: 20000 },
    actions: [{ type: "notify_role", roleHint: "GERENCIA", text: "Oportunidad ganada de alto valor" }],
  },
  {
    name: "OT completada: avisar a Finanzas",
    trigger: "work_order.completed",
    condition: null,
    actions: [{ type: "notify_role", roleHint: "FINANZAS", text: "OT completada: revisa su evento facturable" }],
  },
];
