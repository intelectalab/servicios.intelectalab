// Personal (kit v1.5, D-44/D-45): lo que devuelve la API en /v1/hr/*.

export type HrMode = "ERP_MASTER" | "HR_SAAS_MASTER";
export type HrSource = "LOCAL" | "HR_SAAS";
export type EmployeeStatus = "ACTIVE" | "INACTIVE" | "TERMINATED";
export type ContractType = "INDEFINIDO" | "PLAZO_FIJO" | "TIEMPO_PARCIAL" | "PRACTICAS";
export type LeaveStatus = "REQUESTED" | "APPROVED" | "REJECTED" | "CANCELLED";
export type PayrollStatus = "GENERATED" | "SENT" | "ACKNOWLEDGED" | "FAILED";

export type Ref = { id: string | null; name: string };

export type HrEmployee = {
  id: string;
  code: string | null;
  name: string;
  party_id: string;
  document_type: string | null;
  document_number: string | null;
  position: Ref | null;
  branch: Ref | null;
  user: { id: string; name: string; email: string } | null;
  hire_date: string | null;
  termination_date: string | null;
  termination_reason: string | null;
  contract_type: ContractType | null;
  contract_label: string | null;
  weekly_hours: number;
  hourly_cost: number | null;
  currency: "PEN" | "USD";
  payroll_external_id: string | null;
  source: HrSource;
  synced_at: string | null;
  status: EmployeeStatus;
  status_label: string;
  /** Ausencia aprobada de hoy, si la hay. */
  on_leave: { type: string; date_from: string; date_to: string } | null;
  lock_version: number;
};

export type HrEmployeeDetail = HrEmployee & {
  leaves: { id: string; date_from: string; date_to: string; days: number; status: LeaveStatus; status_label: string; source: HrSource; type: string }[];
  hours: { period: string; hours: number; cost: number }[];
  history: unknown[];
};

export type HrEmployeeKpis = {
  active: number; on_leave: number; without_cost: number; without_user: number; average_cost: number; pending_leaves: number;
};

export type HrPosition = {
  id: string; code: string; name: string; department: string | null; default_hourly_cost: number | null; currency: string;
  active: boolean; source: HrSource; external_id: string | null; employees: number; lock_version: number;
};

export type HrEmployeeOptions = {
  positions: { id: string; code: string; name: string; default_hourly_cost: number | null; active: boolean; source: HrSource }[];
  branches: { id: string; code: string; name: string }[];
  users: { id: string; name: string; email: string; employee_id: string | null }[];
  people: { id: string; name: string; document_type: string | null; document_number: string | null }[];
  contract_types: { value: ContractType; label: string }[];
  mode: HrMode;
};

export type LeaveType = {
  id: string; code: string; name: string; paid: boolean; requires_approval: boolean; days_per_year: number | null; active: boolean;
};

export type LeaveRequest = {
  id: string;
  employee: { id: string; name: string };
  type: { id: string; name: string; code: string; paid: boolean };
  date_from: string;
  date_to: string;
  days: number;
  reason: string | null;
  status: LeaveStatus;
  status_label: string;
  decided_by: { id: string; name: string } | null;
  decided_at: string | null;
  decision_note: string | null;
  source: HrSource;
  /** Es de la persona que mira (no la puede aprobar ella misma). */
  mine: boolean;
  created_at: string;
  lock_version: number;
  /** Al aprobar: trabajo agendado en esas fechas que hay que reasignar. */
  conflicts?: { type: "work_order" | "agenda"; id: string; label: string }[];
};

export type LeaveKpis = { pending: number; absent_today: number; upcoming: number; days_this_year: number };

export type LeaveOptions = {
  types: LeaveType[];
  employees: { id: string; name: string; mine: boolean; mastered: boolean }[];
  mode: HrMode;
};

export type PayrollExport = {
  id: string; period: string; version: number; destination: "FILE" | "HR_SAAS"; destination_label: string;
  employee_count: number; minutes_total: number; hours_total: number; status: PayrollStatus; status_label: string;
  sent_at: string | null; acknowledged_at: string | null; provider_response: string | null; created_at: string;
  generated_by: string | null; sha256: string;
};

export type PayrollExportDetail = PayrollExport & {
  summary: { rows: number; hires: number; terminations: number; leaves: number; unlinked: string[] };
  by_employee: { name: string; code: string | null; minutes: number; billable_minutes: number }[];
  deliveries: { id: string; event_id: string; status: string; attempts: number; response_code: number | null; last_error: string | null; sent_at: string | null; next_attempt_at: string | null; created_at: string }[];
};

export type PayrollPreview = {
  period: string; destination: "FILE" | "HR_SAAS"; employee_count: number; rows: number; minutes_total: number;
  unlinked: string[]; pending_hours: number; next_version: number; current_month: boolean;
};

export type HrSyncRun = {
  id: string; kind: "INITIAL_MATCH" | "WEBHOOK" | "FULL_RESYNC"; kind_label: string; status: "RUNNING" | "COMPLETED" | "FAILED";
  received: number; applied: number; skipped: number; unmatched: number; error: string | null;
  started_by: string | null; started_at: string; finished_at: string | null;
};

export type HrSyncStatus = {
  mode: HrMode;
  connection: {
    id: string; base_url: string; status: "ACTIVE" | "INACTIVE"; has_token: boolean; has_secret: boolean;
    initial_match_at: string | null; activated_at: string | null; deactivated_at: string | null; last_event_at: string | null; lock_version: number;
  } | null;
  webhook_url: string;
  tenant_ruc: string | null;
  /** El ERP usa el simulador del SaaS de RRHH (solo en desarrollo). */
  simulated: boolean;
  last_run: (Omit<HrSyncRun, "kind_label" | "started_by"> & { started_by: string | null }) | null;
  counts: { unmatched: number; employees_hr: number; employees_local: number; outbound_pending: number; outbound_dead: number; inbound_failed: number };
  max_attempts: number;
};

export type HrUnmatched = {
  id: string; external_id: string; full_name: string; document: string; reason: "NO_MATCH" | "MULTIPLE_MATCH" | "CONFLICT"; reason_label: string;
  status: "OPEN" | "LINKED" | "CREATED" | "IGNORED"; email: string | null; hourly_cost: string | null;
  candidates: { id: string; code: string | null; name: string }[];
  employee_id: string | null; resolved_by: string | null; resolved_at: string | null; created_at: string;
};

export type HrInboundEvent = {
  id: string; event_id: string; event_type: string; status: "RECEIVED" | "APPLIED" | "SKIPPED" | "UNMATCHED" | "FAILED";
  result: string | null; occurred_at: string | null; received_at: string; processed_at: string | null;
};

export type HrOutboundEvent = {
  id: string; event_id: string; event_type: string; status: "PENDING" | "RETRY" | "SENT" | "DEAD"; attempts: number;
  response_code: number | null; last_error: string | null; sent_at: string | null; next_attempt_at: string | null; created_at: string;
};

// --- Listados y fichas de Trabajadores y Ausencias (pantallas /hr/employees y /hr/leaves) ---

export type HrPage<T> = {
  data: T[]; current_page: number; last_page: number; per_page: number; total: number;
};

export type HrEmployeeList = HrPage<HrEmployee> & { kpis: HrEmployeeKpis; mode: HrMode };

export type LeaveList = HrPage<LeaveRequest> & { kpis: LeaveKpis };

/** Movimiento del historial de una ficha (auditoría). */
export type HrHistoryEntry = { action: string; user: string | null; at: string; metadata: Record<string, string | undefined> };
