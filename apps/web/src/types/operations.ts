import type { BillingMode, Frequency, HistoryEntry } from "@/types/commercial";
import type { Customer } from "@/types/customer";

export type ContractedServiceStatus = "PENDING" | "ACTIVE" | "SUSPENDED" | "COMPLETED" | "CANCELLED";

export interface ContractedService {
  id: string;
  status: ContractedServiceStatus;
  status_reason: string | null;
  start_date: string | null;
  end_date: string | null;
  quantity: number;
  price: number;
  recurring: boolean;
  customer: Customer | null;
  service: { id: string; code: string; name: string; billing_mode: BillingMode; frequency: Frequency | null } | null;
  /** null si el contrato queda fuera del alcance de quien mira. */
  contract: { id: string; contract_number: string; status: string } | null;
  owner: { id: string; name: string } | null;
  work_orders_count: number;
  created_at: string;
  lock_version: number;
}

export interface ContractedServiceDetail extends ContractedService {
  work_orders: { id: string; code: string; title: string; status: WorkOrderStatus; scheduled_start: string | null; assignee: string | null }[];
  recurring_instance: { id: string; status: string; frequency: Frequency; next_cycle_date: string | null } | null;
  history: HistoryEntry[];
}

export type WorkOrderStatus = "PLANNED" | "ASSIGNED" | "IN_PROGRESS" | "BLOCKED" | "COMPLETED" | "CANCELLED";
export type WorkOrderPriority = "HIGH" | "NORMAL" | "LOW";
export type ChecklistStatus = "PENDING" | "IN_PROGRESS" | "COMPLETED" | "APPROVED";

export interface WorkOrder {
  id: string;
  code: string;
  title: string;
  status: WorkOrderStatus;
  priority: WorkOrderPriority;
  scheduled_start: string | null;
  scheduled_end: string | null;
  location: string | null;
  customer: Customer | null;
  assignee: { id: string; name: string } | null;
  checklist: { name: string; status: ChecklistStatus; total: number; done: number; required_pending: number } | null;
  blocked_reason: string | null;
  cancel_reason: string | null;
  created_at: string;
  lock_version: number;
}

export interface ChecklistItem {
  id: string;
  position: number;
  label: string;
  required: boolean;
  done: boolean;
  done_by: string | null;
  done_at: string | null;
  evidence_note: string | null;
}

export interface WorkOrderDetail extends WorkOrder {
  description: string | null;
  started_at: string | null;
  completed_at: string | null;
  service: { id: string; code: string; name: string; billing_mode: BillingMode } | null;
  /** null si el contrato o el servicio contratado quedan fuera del alcance de quien mira. */
  contract: { id: string; contract_number: string } | null;
  contracted_service: { id: string; status: ContractedServiceStatus } | null;
  creator: { id: string; name: string } | null;
  checklist_items: ChecklistItem[];
  checklist_review: { by: string | null; at: string; note: string | null } | null;
  hours: number;
  customer_contact?: { address: string | null };
  history: HistoryEntry[];
}

/** Listas de los filtros y del formulario de órdenes de trabajo. */
export interface WorkOrderOptions {
  technicians: { id: string; name: string }[];
  templates: { id: string; name: string; items: number }[];
  contracted_services: { id: string; service: string | null; customer: string | null }[];
}

export type ProjectStatus = "DRAFT" | "PLANNED" | "IN_PROGRESS" | "ON_HOLD" | "COMPLETED" | "CANCELLED";
export type TaskStatus = "TODO" | "IN_PROGRESS" | "BLOCKED" | "DONE" | "CANCELLED";
export type DeliverableStatus = "DRAFT" | "IN_REVIEW" | "RETURNED" | "APPROVED" | "DELIVERED";
export type EntryStatus = "SUBMITTED" | "APPROVED" | "REJECTED";

export interface ProjectMetrics {
  progress: number;
  tasks: number;
  done: number;
  hours: number;
  pending_hours: number;
  labor_cost: number;
  other_cost: number;
  cost: number;
  margin: number;
  margin_pct: number;
}

export interface Project {
  id: string;
  code: string;
  name: string;
  status: ProjectStatus;
  start_date: string | null;
  end_date: string | null;
  customer: Customer | null;
  manager: { id: string; name: string } | null;
  budget_revenue: number;
  budget_cost: number;
  budget_hours: number;
  metrics: ProjectMetrics;
  hold_reason: string | null;
  cancel_reason: string | null;
  created_at: string;
  lock_version: number;
}

export interface ProjectTask {
  id: string;
  title: string;
  description: string | null;
  status: TaskStatus;
  priority: WorkOrderPriority;
  estimate_hours: number;
  due_at: string | null;
  assignee: { id: string; name: string } | null;
  required_check: string | null;
  required_check_done: boolean;
  blocked_reason: string | null;
}

export interface Deliverable {
  id: string;
  name: string;
  status: DeliverableStatus;
  due_date: string | null;
  /** Nombre del archivo adjunto o, en los entregables anteriores, el enlace escrito. */
  document_ref: string | null;
  /** Archivo adjunto (última versión); null si no tiene o solo lleva un enlace. */
  document: DeliverableDocument | null;
  review_note: string | null;
  delivered_at: string | null;
}

export interface DeliverableDocument {
  id: string;
  title: string;
  category: string;
  visibility: "INTERNAL" | "PORTAL";
  version: number;
  file_name: string;
  size_bytes: number;
  sha256: string;
  antivirus_status: "PENDING" | "CLEAN" | "INFECTED" | "FAILED";
}

export interface ProjectDetail extends Project {
  /** null si el contrato queda fuera del alcance de quien mira. */
  contract: { id: string; contract_number: string } | null;
  tasks: ProjectTask[];
  /** null si el rol no tiene permiso de ver entregables. */
  deliverables: Deliverable[] | null;
  history: HistoryEntry[];
}

export interface ProjectOptions {
  people: { id: string; name: string }[];
  contracted_services: { id: string; service: string | null; customer: string | null }[];
}

export interface TimeEntry {
  id: string;
  user: { id: string; name: string } | null;
  /** Son las horas de quien mira: no las puede aprobar. */
  own: boolean;
  project: { id: string; code: string; name: string } | null;
  work_order: { id: string; code: string; title: string } | null;
  task: string | null;
  started_at: string;
  ended_at: string;
  hours: number;
  billable: boolean;
  cost: number;
  status: EntryStatus;
  description: string | null;
  reject_reason: string | null;
  lock_version: number;
}

export interface TimeEntryOptions {
  projects: { id: string; code: string; name: string; tasks: { id: string; title: string }[] }[];
  work_orders: { id: string; code: string; title: string }[];
}

export interface TimeSummary {
  mine: number;
  week_hours: number;
  to_approve: number;
  /** null si el rol no ve costos. */
  costs_to_approve: number | null;
}

export interface CostEntry {
  id: string;
  description: string;
  category: string;
  quantity: number;
  unit_cost: number;
  total: number;
  incurred_at: string | null;
  status: EntryStatus;
  reject_reason: string | null;
  project: { id: string; code: string; name: string } | null;
  creator: { id: string; name: string } | null;
  own: boolean;
  lock_version: number;
}

export type AgendaEventType = "MEETING" | "VISIT" | "MAINTENANCE" | "SESSION";
export type AgendaEventStatus = "SCHEDULED" | "DONE" | "CANCELLED";

export interface AgendaEvent {
  id: string;
  title: string;
  event_type: AgendaEventType;
  status: AgendaEventStatus;
  starts_at: string;
  ends_at: string;
  assignee: { id: string; name: string } | null;
  customer: { id: string; name: string | null } | null;
  location: string | null;
  notes: string | null;
  cancel_reason: string | null;
  /** El evento está a cargo de quien mira. */
  own: boolean;
  lock_version: number;
}

/** Orden de trabajo programada, tal como la muestra la agenda. */
export interface AgendaWorkOrder {
  id: string;
  code: string;
  title: string;
  status: WorkOrderStatus;
  starts_at: string;
  ends_at: string;
  assignee: { id: string; name: string } | null;
  customer: string | null;
}

export interface AgendaWeek {
  data: AgendaEvent[];
  /** null si quien mira no puede ver órdenes de trabajo. */
  work_orders: AgendaWorkOrder[] | null;
}

export interface AgendaOptions {
  people: { id: string; name: string }[];
  /** null si quien mira no puede ver clientes. */
  customers: { id: string; name: string }[] | null;
}
