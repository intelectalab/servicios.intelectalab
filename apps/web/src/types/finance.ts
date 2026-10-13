import type { HistoryEntry } from "@/types/commercial";
import type { Customer } from "@/types/customer";

export type Currency = "PEN" | "USD";

export type BillableEventStatus = "PENDING" | "INVOICED" | "CANCELLED";
export type BillableEventSource = "WORK_ORDER" | "RECURRING_CYCLE" | "CONTRACT_MILESTONE" | "TIME_ENTRY" | "MANUAL";

export interface BillableEvent {
  id: string;
  description: string;
  source_type: BillableEventSource;
  source_id: string | null;
  idempotency_key: string;
  customer: Customer | null;
  base: number;
  amount: number;
  currency: Currency;
  status: BillableEventStatus;
  cancel_reason: string | null;
  invoice: { id: string; invoice_number: string | null; status: InvoiceStatus } | null;
  contract: { id: string; contract_number: string } | null;
  created_at: string;
  lock_version: number;
}

export interface BillableEventOptions {
  customers: { id: string; name: string; tax_id: string | null; currency: Currency }[];
  contracts: { id: string; contract_number: string; customer_id: string; currency: Currency }[];
}

export type InvoiceType = "INVOICE" | "RECEIPT" | "CREDIT_NOTE" | "OTHER";
export type InvoiceStatus = "DRAFT" | "ISSUED" | "CORRECTED" | "VOID" | "CANCELLED" | "PAID" | "PARTIALLY_PAID" | "OVERDUE";
export type FiscalStatus = "NOT_SENT" | "CONTINGENCY" | "PENDING" | "ACCEPTED" | "REJECTED";

export interface Invoice {
  id: string;
  invoice_number: string | null;
  invoice_type: InvoiceType;
  series: string | null;
  customer: Customer | null;
  customer_name: string | null;
  customer_tax_id: string | null;
  issue_date: string | null;
  due_date: string | null;
  currency: Currency;
  exchange_rate: number | null;
  subtotal: number;
  tax_total: number;
  total: number;
  balance_due: number;
  status: InvoiceStatus;
  fiscal_status: FiscalStatus;
  created_at: string;
  lock_version: number;
}

export type ReceivableStatus = "OPEN" | "PARTIAL" | "PAID" | "CANCELLED";
export type MovementType = "CHARGE" | "PAYMENT" | "REVERSAL" | "CREDIT_NOTE" | "WITHHOLDING" | "VOID";

export interface ReceivableMovement {
  id: string;
  movement_type: MovementType;
  amount: number;
  reference: string | null;
  at: string;
}

export interface InvoiceDetail extends Invoice {
  reason: string | null;
  fiscal_response: string | null;
  issued_at: string | null;
  items: { id: string; description: string; quantity: number; unit_price: number; tax_rate: number; line_total: number; billable_event_id: string | null }[];
  contract: { id: string; contract_number: string } | null;
  related: { id: string; invoice_number: string | null } | null;
  credit_note: { id: string; invoice_number: string | null } | null;
  receivable: {
    id: string;
    due_date: string | null;
    original_amount: number;
    balance_amount: number;
    currency: Currency;
    status: ReceivableStatus;
    overdue: boolean;
    movements: ReceivableMovement[];
  } | null;
  payments: { id: string; payment_number: string; payment_method: string; paid_at: string | null; applied: number }[];
  history: HistoryEntry[];
}

export type InvoiceTab = "ALL" | "DRAFT" | "ISSUED" | "REJECTED" | "CORRECTED";
export type InvoiceSummary = Record<InvoiceTab, number>;

export type AgingBucket = "CURRENT" | "D30" | "D60" | "D90" | "OVER";

export interface Receivable {
  id: string;
  invoice_id: string;
  invoice_number: string | null;
  customer: Customer | null;
  due_date: string | null;
  original_amount: number;
  balance_amount: number;
  currency: Currency;
  status: ReceivableStatus;
  overdue: boolean;
  days_overdue: number;
  bucket: AgingBucket | null;
}

export interface ReceivableDetail extends Receivable {
  movements: ReceivableMovement[];
}

export interface ReceivableSummary {
  outstanding: number;
  overdue: number;
  open_count: number;
  overdue_customers: number;
  aging: { key: AgingBucket; label: string; amount: number }[];
  missing_rate: boolean;
}

export type PaymentStatus = "PENDING" | "CONFIRMED" | "PARTIALLY_ALLOCATED" | "ALLOCATED" | "REFUNDED" | "FAILED" | "CANCELLED";

export interface Payment {
  id: string;
  payment_number: string;
  payment_method: string;
  reference: string | null;
  source: string;
  customer: Customer | null;
  paid_at: string | null;
  amount: number;
  currency: Currency;
  refunded_amount: number;
  allocated: number;
  account_name: string | null;
  status: PaymentStatus;
  reconciled: boolean;
  lock_version: number;
}

export interface Refund {
  id: string;
  payment_id?: string;
  payment_number?: string;
  currency?: Currency;
  amount: number;
  reason: string;
  by: string | null;
  at: string | null;
}

export interface PaymentDetail extends Payment {
  free: number;
  exchange_rate: number | null;
  reconciled_at: string | null;
  creator: string | null;
  allocations: { receivable_id: string; invoice_id: string; invoice_number: string | null; amount: number; at: string }[];
  open_receivables: { id: string; invoice_number: string | null; due_date: string | null; balance_amount: number }[];
  refunds: Refund[];
  history: HistoryEntry[];
}

export interface PaymentOptions {
  customers: { id: string; name: string; currency: Currency }[];
  accounts: { id: string; name: string; currency: Currency; account_type: string }[];
  methods: string[];
}
