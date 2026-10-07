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
  /** Versión del precio del catálogo con la que se cotizó la línea; solo en el detalle. */
  price_version?: number | null;
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
  /** Tipo de cambio (venta) con el que se emitió; solo en dólares. */
  exchange_rate: string | null;
  /** Origen: vacío o DIRECT (directa), OPPORTUNITY (CRM), PORTAL. */
  source_type: string | null;
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
  /** Orden directa generada desde la cotización. Solo en el detalle. */
  order?: { id: string; order_number: string; status: string } | null;
  /** La empresa permite convertir la cotización en orden sin pasar por contrato. */
  direct_order_enabled?: boolean;
  approvals?: QuotationApproval[];
  history?: HistoryEntry[];
  /** Dirección y correo del cliente, para el PDF. Solo en el detalle. */
  customer_contact?: { address: string | null; email: string | null };
  /** Segregación de funciones: quien la creó no puede aprobarla. Solo en el detalle. */
  own_approval_blocked?: boolean;
}

export type ContractStatus = "DRAFT" | "ACTIVE" | "SUSPENDED" | "TERMINATED" | "EXPIRED";

export interface ContractItem {
  id: string;
  service_id: string | null;
  description: string;
  quantity: string;
  unit_price: string;
  /** Total de la línea con IGV, copiado de la cotización. */
  line_total: string;
  service_snapshot: QuotationItem["service_snapshot"];
}

export interface ContractedService {
  id: string;
  status: string;
  quantity: string;
  recurring: boolean;
  service_code: string;
  service_name: string;
  work_orders_count: number;
  projects_count: number;
}

export interface RecurringInstance {
  id: string;
  status: string;
  frequency: string;
  next_cycle_date: string | null;
  service_name: string;
  amount: string;
}

export interface Contract {
  id: string;
  customer_id: string;
  quotation_id: string | null;
  contract_number: string;
  status: ContractStatus;
  currency: string;
  start_date: string | null;
  end_date: string | null;
  total_value: string;
  terms: string | null;
  sla_level: string;
  created_at: string;
  customer: Customer | null;
  quotation: { id: string; quotation_number: string } | null;
  /** Solo en el detalle. */
  items?: ContractItem[];
  creator?: { id: string; name: string } | null;
  orders?: { id: string; order_number: string; status: string }[];
  contracted_services?: ContractedService[];
  recurring_instances?: RecurringInstance[];
  customer_contact?: { address: string | null };
  history?: HistoryEntry[];
}

export type OrderStatus = "DRAFT" | "CONFIRMED" | "IN_PROGRESS" | "COMPLETED" | "CANCELLED";

export interface OrderItem {
  id: string;
  service_id: string | null;
  description: string;
  quantity: string;
  unit_price: string;
  /** Total de la línea con IGV, copiado de la cotización o del contrato. */
  line_total: string;
  service_snapshot: QuotationItem["service_snapshot"];
  service?: { id: string; unit: string | null } | null;
}

export interface Order {
  id: string;
  customer_id: string;
  contract_id: string | null;
  quotation_id: string | null;
  order_number: string;
  status: OrderStatus;
  order_date: string;
  currency: string;
  total: string;
  notes: string | null;
  created_at: string;
  customer: Customer | null;
  quotation: { id: string; quotation_number: string } | null;
  contract: { id: string; contract_number: string } | null;
  /** Solo en el detalle. */
  items?: OrderItem[];
  creator?: { id: string; name: string } | null;
  customer_contact?: { address: string | null };
  history?: HistoryEntry[];
}

export interface ExchangeRate {
  id: string;
  rate_date: string;
  currency: string;
  buy: string;
  sell: string;
  source: string;
}

export interface ExchangeRateSummary {
  current: ExchangeRate | null;
  variation_30: number | null;
  quotations_usd: number;
  invoices_usd: number;
}

export interface QuotationSummary {
  total: number;
  by_status: Partial<Record<QuotationStatus, { count: number; amounts: Record<string, number> }>>;
}
