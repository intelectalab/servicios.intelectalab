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

export type WithholdingType = "DETRACTION" | "RETENTION";
export type WithholdingStatus = "PENDING" | "RECEIVED";
export type WithholdingMode = "AUTO" | "NONE" | "DETRACTION" | "RETENTION";

/** Detracción (SPOT) o retención del IGV de un comprobante. El importe va siempre en soles. */
export interface Withholding {
  type: WithholdingType;
  code: string | null;
  rate: number;
  amount: number;
  status: WithholdingStatus;
  number: string | null;
  date: string | null;
}

export interface DetractionCode {
  code: string;
  name: string;
  rate: number;
}

export type VoidStatus = "PENDING" | "ACCEPTED" | "REJECTED";

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
  withholding: Withholding | null;
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
  /** Cómo decide el borrador su detracción o retención. */
  withholding_mode: WithholdingMode;
  withholding_mode_code: string | null;
  /** En borrador: lo que le correspondería si se emitiera ahora. */
  withholding_preview: { type: WithholdingType; code: string | null; rate: number; amount: number; label: string } | null;
  detraction_codes: DetractionCode[];
  /** Cuenta de detracciones de la empresa en el Banco de la Nación. */
  detraction_account: string | null;
  /** La empresa está emitiendo en contingencia. */
  contingency_mode: boolean;
  void_request: { id: string; status: VoidStatus; ticket: string | null; reason: string; response: string | null; at: string } | null;
  /** null = procede la comunicación de baja; si no, por qué no. */
  void_blocked: string | null;
  reason: string | null;
  fiscal_response: string | null;
  /** Proveedor de facturación electrónica de la empresa; sin él nada se envía a SUNAT. */
  fiscal_provider: { connected: boolean; name: string | null };
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
  withholding: { type: WithholdingType; amount: number; status: WithholdingStatus } | null;
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

export type RecurringStatus = "ACTIVE" | "SUSPENDED" | "EXPIRED" | "CANCELLED";
export type CycleStatus = "PENDING" | "PROCESSING" | "COMPLETED" | "FAILED" | "SKIPPED";

export interface RecurringInstance {
  id: string;
  service_name: string | null;
  customer: Customer | null;
  frequency: string;
  interval_value: number;
  /** Precio pactado por unidad, sin IGV. */
  unit_price: number;
  quantity: number;
  currency: Currency;
  /** Importe de un ciclo, con IGV. */
  amount: number;
  next_cycle_date: string | null;
  /** Activa y con el ciclo vencido: toca procesarlo. */
  due: boolean;
  /** Días que faltan para el ciclo que toca (negativo si ya venció). */
  days_to_next: number | null;
  start_date: string | null;
  end_date: string | null;
  renewal_due: boolean;
  cycles_count: number;
  status: RecurringStatus;
  status_reason: string | null;
  lock_version: number;
}

export interface RecurringCycle {
  id: string;
  period: string;
  idempotency_key: string;
  amount: number;
  status: CycleStatus;
  attempts: number;
  error: string | null;
  billable_event_id: string | null;
  billable_event_status: BillableEventStatus | null;
  processed_at: string | null;
}

export interface RecurringDetail extends RecurringInstance {
  contract: { id: string; contract_number: string } | null;
  contracted_service: { id: string; status: string; visible: boolean } | null;
  single_horizon_days: number;
  terms: { id: string; unit_price: number; currency: Currency; start: string; end: string | null }[];
  cycles: RecurringCycle[];
  history: HistoryEntry[];
}

export interface RecurringSummary {
  due: number;
  alert_days: number;
  alerts_total: number;
  alerts: { id: string; customer: Customer | null; service_name: string | null; end_date: string | null }[];
}

export interface CycleRunSummary {
  created: number;
  skipped_idem: number;
  skipped_status: number;
  failed: number;
  event_ids: string[];
}

export type AccountKind = "BANK" | "CASH" | "DETRACTION";

export interface BankAccount {
  id: string;
  code: string;
  name: string;
  account_type: AccountKind;
  currency: Currency;
  status: "ACTIVE" | "INACTIVE";
  bank_name: string | null;
  account_number: string | null;
  cci: string | null;
  ledger_account: string | null;
  fund_amount: number | null;
  /** Tope por comprobante de un gasto de caja chica (el de la caja o el general). */
  expense_limit: number;
  own_expense_limit: number | null;
  custodian: { id: string; name: string } | null;
  /** Suma de sus movimientos. */
  balance: number;
  /** En soles al tipo de cambio vigente; null si es en dólares y no hay tipo de cambio. */
  balance_pen: number | null;
  pending_count: number;
  lock_version: number;
}

export interface BankAccountsResponse {
  data: BankAccount[];
  custodians: { id: string; name: string }[];
}

export interface TreasurySummary {
  total_pen: number;
  missing_rate: boolean;
  unreconciled: number;
  unreconciled_amount: number;
  statements: number;
  cash_count: number;
  cash_balance: number;
  cash_fund: number;
}

export interface BankMovement {
  id: string;
  date: string;
  description: string;
  op_number: string | null;
  ref_type: string;
  amount: number;
  running: number | null;
  reconciled: boolean;
}

export type StatementLineStatus = "UNMATCHED" | "MATCHED" | "CREATED" | "IGNORED";

export interface StatementLine {
  id: string;
  date: string;
  description: string;
  amount: number;
  op_number: string | null;
  status: StatementLineStatus;
  rule: string | null;
  movement_description: string | null;
  candidates: { id: string; date: string; description: string }[];
}

export interface Reconciliation {
  account: { id: string; name: string; currency: Currency; account_type: AccountKind };
  book_balance: number;
  pending_count: number;
  pending_amount: number;
  pending: BankMovement[];
  statements_count: number;
  /** Extractos importados en la cuenta, del más reciente al más antiguo. */
  statements: { id: string; file_name: string; lines_count: number; imported_at: string }[];
  statement: {
    id: string;
    file_name: string;
    imported_at: string;
    imported_by: string | null;
    /** Se guardó el archivo tal como se importó (los extractos anteriores a esto no lo tienen). */
    has_file: boolean;
    duplicates_count: number;
    resolved_pct: number;
    unmatched: number;
    lines: StatementLine[];
  } | null;
}

export interface CashExpense {
  id: string;
  expense_number: string;
  expense_date: string;
  description: string;
  receipt: string | null;
  cost_center: string | null;
  expense_account: string | null;
  amount: number;
  /** Clasificación vigente en códigos, para corregirla. */
  cost_center_id: string | null;
  expense_account_code: string | null;
  /** Veces que se corrigió su centro o cuenta. */
  corrections: number;
}

export interface CashCount {
  id: string;
  book: number;
  counted: number;
  difference: number;
  note: string | null;
  by: string | null;
  at: string;
}

export interface FiscalSummary {
  accepted: number;
  pending: number;
  rejected: number;
  contingency: number;
  voids: number;
}

export interface FiscalSeriesRow {
  key: "INVOICE" | "RECEIPT" | "CREDIT_NOTE_INVOICE" | "CREDIT_NOTE_RECEIPT";
  label: string;
  series: string;
  contingency_series: string | null;
  last_number: number;
  contingency_last_number: number | null;
}

export interface FiscalSettings {
  provider: { connected: boolean; name: string | null; code: string | null; available: { code: string; name: string }[]; supports_void: boolean };
  tax_identifier: string | null;
  legal_name: string | null;
  environment: "BETA" | "PRODUCTION";
  mode: "ONLINE" | "CONTINGENCY";
  series: FiscalSeriesRow[];
  detraction: { enabled: boolean; code: string; account: string | null; threshold: number; retention_rate: number; codes: DetractionCode[] };
  pending: {
    detractions: { count: number; amount: number };
    retentions: { count: number; amount: number };
    invoices: { id: string; invoice_number: string | null; customer_name: string; label: string; amount: number }[];
  };
}

export interface FiscalContingency {
  mode: "ONLINE" | "CONTINGENCY";
  provider: { connected: boolean; name: string | null };
  pending_count: number;
  invoices: { id: string; invoice_number: string | null; customer_name: string; issued_at: string | null; total: number; currency: Currency; fiscal_status: FiscalStatus }[];
  log: HistoryEntry[];
}

export interface FiscalVoid {
  id: string;
  invoice_id: string;
  invoice_number: string;
  ticket: string | null;
  reason: string;
  status: VoidStatus;
  response: string | null;
  by: string | null;
  at: string;
}

export interface PaymentOptions {
  customers: { id: string; name: string; currency: Currency }[];
  accounts: { id: string; name: string; currency: Currency; account_type: string }[];
  methods: string[];
}
