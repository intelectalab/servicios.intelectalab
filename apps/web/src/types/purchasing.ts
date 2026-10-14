import type { HistoryEntry } from "@/types/commercial";
import type { Currency, DetractionCode } from "@/types/finance";

export type PurchaseOrderStatus = "DRAFT" | "SUBMITTED" | "APPROVED" | "PARTIALLY_RECEIVED" | "RECEIVED" | "CLOSED" | "REJECTED" | "CANCELLED";
export type PurchaseOrderTab = "ALL" | "DRAFT" | "SUBMITTED" | "RECEIVE" | "RECEIVED" | "CLOSED";

export interface CostCenter {
  id: string;
  code: string;
  name: string;
  active: boolean;
}

export interface SupplierOption {
  id: string;
  name: string;
  tax_id: string | null;
  detraction_account: string | null;
}

export interface ExpenseAccount {
  code: string;
  name: string;
}

export interface PurchaseOptions {
  suppliers: SupplierOption[];
  cost_centers: CostCenter[];
  expense_accounts: ExpenseAccount[];
  default_account: string;
}

export interface PurchaseOrder {
  id: string;
  order_number: string;
  first_item: string | null;
  items_count: number;
  supplier: { id: string; name: string | null };
  cost_center: { id: string; name: string | null };
  currency: Currency;
  subtotal: number;
  tax: number;
  total: number;
  received_pct: number;
  invoiced: number;
  creator: string | null;
  needed_by: string | null;
  status: PurchaseOrderStatus;
  created_at: string;
  lock_version: number;
}

export interface PurchaseOrderItem {
  id: string;
  description: string;
  quantity: number;
  unit_cost: number;
  account_code: string;
  account: string;
  subtotal: number;
}

export interface PurchaseReceipt {
  id: string;
  pct: number;
  note: string | null;
  by: string | null;
  at: string;
}

/** Presupuesto aprobado del centro de costo en el año (Contabilidad); null si no tiene. */
export interface BudgetCheck {
  annual: number;
  actual: number;
  committed: number;
  available: number;
  over: boolean;
}

export interface PurchaseOrderDetail extends Omit<PurchaseOrder, "supplier"> {
  supplier: { id: string; name: string | null; tax_id: string | null; party_id: string | null };
  notes: string | null;
  reason: string | null;
  created_by: string | null;
  approver: string | null;
  approved_at: string | null;
  items: PurchaseOrderItem[];
  receipts: PurchaseReceipt[];
  segregated: boolean;
  budget: BudgetCheck | null;
  /** null: el rol no ve Cuentas por pagar. */
  supplier_invoices: SupplierInvoice[] | null;
  history: HistoryEntry[];
}

export interface PurchaseOrderSummary {
  tabs: Record<PurchaseOrderTab, number>;
  to_approve: number;
  to_approve_amount: number;
  to_receive: number;
  received_uninvoiced: number;
  committed: number;
  missing_rate: boolean;
}

export type SupplierInvoiceStatus = "REGISTERED" | "APPROVED" | "REJECTED" | "VOIDED";
export type SupplierInvoiceTab = "ALL" | "REGISTERED" | "APPROVED" | "REJECTED";
export type SupplierDocType = "FACTURA" | "RECIBO_HONORARIOS";

export interface MatchInfo {
  ok: boolean;
  tone: "neutral" | "success" | "danger";
  label: string;
}

export interface SupplierInvoice {
  id: string;
  doc_type: SupplierDocType;
  doc_number: string;
  description: string | null;
  supplier: { id: string; name: string; tax_id: string | null };
  issue_date: string;
  due_date: string;
  expense_account: string;
  expense_account_label: string;
  cost_center: { id: string; name: string | null };
  currency: Currency;
  subtotal: number;
  tax: number;
  total: number;
  /** Importe en soles. */
  detraction: { code: string; pct: number; amount: number } | null;
  retention_4ta: number;
  purchase_order: { id: string; order_number: string | null } | null;
  sunat_validation: "NOT_CHECKED" | "VALID" | "INVALID";
  status: SupplierInvoiceStatus;
  lock_version: number;
  match?: MatchInfo;
}

export type PayableStatus = "OPEN" | "PARTIAL" | "PAID" | "CANCELLED";

export interface SupplierInvoiceDetail extends SupplierInvoice {
  match: MatchInfo;
  exchange_rate: number | null;
  reason: string | null;
  creator: string | null;
  approver: string | null;
  payable: {
    id: string; status: PayableStatus; balance_amount: number; currency: Currency; detraction_amount: number; detraction_deposited: boolean;
    movements: PayableDetail["movements"];
  } | null;
  /** Su asiento en el libro diario (nace al aprobarlo para pago). */
  journal_entry: { id: string; number: string } | null;
  history: HistoryEntry[];
}

export interface SupplierInvoiceOptions extends PurchaseOptions {
  detraction_codes: DetractionCode[];
  terms_days: number;
  purchase_orders: {
    id: string; order_number: string; supplier_id: string; supplier_name: string | null; cost_center_id: string; currency: Currency;
    subtotal: number; received_pct: number; first_item: string | null; first_account: string | null;
  }[];
}

export interface Payable {
  id: string;
  supplier_invoice: { id: string; doc_number: string | null; description: string | null };
  supplier: { id: string; name: string | null; detraction_account: string | null };
  due_date: string;
  overdue: boolean;
  days_overdue: number;
  currency: Currency;
  exchange_rate: number | null;
  original_amount: number;
  balance_amount: number;
  /** En soles. */
  detraction_amount: number;
  detraction_deposited: boolean;
  status: PayableStatus;
}

export type PayableMovementType = "CHARGE" | "PAYMENT" | "DETRACTION" | "RETENTION_4TA" | "PAYMENT_REVERSAL";

export interface PayableDetail extends Payable {
  movements: { id: string; movement_type: PayableMovementType; amount: number; reference: string | null; at: string }[];
}

export interface PayAccount {
  id: string;
  name: string;
  currency: Currency;
  account_type: "BANK" | "CASH";
  balance: number;
}

export interface PayableSummary {
  outstanding: number;
  open_count: number;
  overdue: number;
  due_soon: number;
  due_soon_count: number;
  detraction_pending: number;
  buckets: { key: string; label: string; amount: number }[];
  /** null: el rol no ve Bancos y caja. */
  accounts: PayAccount[] | null;
  missing_rate: boolean;
}

export interface SupplierPayment {
  id: string;
  payment_number: string;
  payment_method: string;
  reference: string | null;
  supplier: { id: string; name: string | null };
  account_name: string | null;
  paid_at: string;
  amount: number;
  currency: Currency;
  /** En soles. */
  detraction_amount: number;
  detraction_number: string | null;
  status: "PAID" | "REVERSED";
  creator: string | null;
  reversed_at: string | null;
  reversal_reason: string | null;
}

/** Ficha del pago: a qué cuentas se aplicó, sus movimientos de banco o caja, su asiento e historial. */
export interface SupplierPaymentDetail extends SupplierPayment {
  reversed_by: string | null;
  allocations: { payable_id: string; invoice_id: string; doc_number: string; description: string | null; amount: number; payable_status: PayableStatus; balance: number }[];
  movements: { id: string; date: string; description: string; amount: number; type: string; account: string }[];
  journal_entry: { id: string; number: string; status: "POSTED" | "REVERSED" } | null;
  history: HistoryEntry[];
}

export interface SupplierPaymentOptions {
  accounts: PayAccount[];
  methods: string[];
}
