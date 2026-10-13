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
