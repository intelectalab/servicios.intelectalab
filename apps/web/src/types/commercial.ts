import type { Customer } from "./customer";

export type ServiceStatus = "DRAFT" | "ACTIVE" | "INACTIVE" | "ARCHIVED";
export type BillingMode = "ONE_TIME" | "RECURRING" | "PER_EVENT";
export type Frequency = "MONTHLY" | "QUARTERLY" | "YEARLY";

/** Movimiento de un registro, tal como lo guarda la auditoría. */
export interface HistoryEntry {
  action: string;
  user: string | null;
  at: string;
  metadata: { from?: string; to?: string; note?: string; amount?: string } & Record<string, unknown>;
}

export interface ServiceCategory {
  id: string;
  name: string;
}

export interface ServicePrice {
  id: string;
  service_id: string;
  currency: string;
  amount: string;
  valid_from: string | null;
  valid_to: string | null;
  active: boolean;
  created_at: string;
}

export interface Service {
  id: string;
  category_id: string | null;
  code: string;
  name: string;
  description: string | null;
  status: ServiceStatus;
  unit: string | null;
  billing_mode: BillingMode;
  warranty_months: number;
  category: ServiceCategory | null;
  current_price: ServicePrice | null;
  recurrence: { frequency: Frequency; interval_value: number } | null;
  /** Solo en el detalle: historial de precios, del más reciente al más antiguo. */
  prices?: ServicePrice[];
  /** Solo en el detalle: cotizaciones que usan el servicio. */
  quotations?: { quotation_id: string; quotation_number: string; status: QuotationStatus; currency: string; unit_price: string }[];
  history?: HistoryEntry[];
}

export type QuotationStatus = "DRAFT" | "SUBMITTED" | "APPROVED" | "REJECTED" | "EXPIRED" | "CANCELLED";

export interface QuotationItem {
  id: string;
  service_id: string | null;
  description: string;
  quantity: string;
  unit_price: string;
  discount: string;
  tax_rate: string;
  tax_amount: string;
  line_total: string;
  service_snapshot: { code: string; name: string; unit?: string | null; billing_mode?: BillingMode; frequency?: Frequency | null } | null;
  service?: Service | null;
}

export interface QuotationApproval {
  id: string;
  decision: "APPROVED" | "REJECTED";
  comments: string | null;
  decided_at: string;
  approver: { id: string; name: string } | null;
}

export interface Quotation {
  id: string;
  customer_id: string;
  quotation_number: string;
  status: QuotationStatus;
  version: number;
  previous_id: string | null;
  currency: string;
  valid_until: string | null;
  subtotal: string;
  tax_total: string;
  discount_total: string;
  total: string;
  notes: string | null;
  approved_at: string | null;
  created_at: string;
  items: QuotationItem[];
  customer: Customer | null;
  creator: { id: string; name: string } | null;
  /** La versión que reemplazó a esta, si existe. */
  superseded_by: { id: string; quotation_number?: string } | null;
  /** Solo en el detalle. */
  previous?: { id: string; quotation_number: string } | null;
  contract?: { id: string; contract_number: string; status: string } | null;
  approvals?: QuotationApproval[];
  history?: HistoryEntry[];
}

export interface QuotationSummary {
  total: number;
  by_status: Partial<Record<QuotationStatus, { count: number; amounts: Record<string, number> }>>;
}
