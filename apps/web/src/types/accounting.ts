export type JournalStatus = "POSTED" | "REVERSED";

export type AccountType = "ASSET" | "LIABILITY" | "EQUITY" | "INCOME" | "EXPENSE";

export interface JournalEntry {
  id: string;
  entry_number: string;
  entry_date: string;
  period: string;
  description: string;
  source_type: string;
  source_label: string;
  source_number: string | null;
  /** "1212 D · 40111 H · 7041 H" */
  accounts: string | null;
  amount: number;
  status: JournalStatus;
  reversed_number: string | null;
}

export interface JournalLine {
  id: string;
  account_code: string;
  account_name: string;
  debit: number;
  credit: number;
  note: string | null;
  cost_center: string | null;
  party: string | null;
}

export interface JournalEntryDetail extends JournalEntry {
  reason: string | null;
  created_by: string | null;
  automatic: boolean;
  reversed_by: { id: string; number: string } | null;
  reversal_of: { id: string; number: string } | null;
  lines: JournalLine[];
}

export interface JournalSummary {
  total: number;
  period: string;
  in_period: number;
  automatic: number;
  balanced: boolean;
}

export interface ChartOption {
  code: string;
  name: string;
  type: AccountType;
}

export interface JournalOptions {
  periods: string[];
  sources: { value: string; label: string }[];
  accounts: ChartOption[];
  cost_centers: { id: string; code: string; name: string }[];
}

export interface TrialRow {
  code: string;
  name: string;
  type: AccountType;
  type_label: string;
  debit: number;
  credit: number;
  debtor: number;
  creditor: number;
}

export interface TrialBalance {
  from: string;
  to: string;
  cost_center_id: string | null;
  rows: TrialRow[];
  totals: { debit: number; credit: number; debtor: number; creditor: number };
  balanced: boolean;
}

export interface LedgerLine {
  id: string;
  entry_id: string;
  entry_number: string;
  entry_date: string;
  description: string;
  note: string | null;
  debit: number;
  credit: number;
  running: number;
}

export interface StatementLine {
  label: string;
  value: number;
  total?: boolean;
}

export interface FinancialStatements {
  from: string;
  to: string;
  kpis: { income: number; expenses: number; result: number; margin: number | null };
  income_statement: StatementLine[];
  balance_sheet: StatementLine[];
  balanced: boolean;
  months: { period: string; income: number; expenses: number }[];
}

export interface AccountingAccount {
  id: string;
  code: string;
  name: string;
  type: AccountType;
  type_label: string;
  nature: "D" | "C";
  postable: boolean;
  parent_code: string | null;
  active: boolean;
  system: boolean;
  used: boolean;
  lock_version: number;
}

export interface PostingRule {
  event: string;
  label: string;
  lines: string;
}

export interface AccountingPeriod {
  id: string;
  period: string;
  status: "OPEN" | "CLOSED";
  entries: number;
  unreconciled: number;
  closed_at: string | null;
  closed_by: string | null;
  reason: string | null;
  ended: boolean;
}

export type CostCenterKind = "COST" | "REVENUE";

export interface BudgetHeader {
  id: string;
  name: string;
  year: number;
  status: "DRAFT" | "APPROVED" | "CLOSED";
  approved_by: string | null;
  approved_at: string | null;
  reason: string | null;
  lock_version: number;
}

export interface CostCenterRow {
  id: string;
  code: string;
  name: string;
  kind: CostCenterKind;
  active: boolean;
  branch_id: string | null;
  branch: string | null;
  manager_user_id: string | null;
  manager: string | null;
}

export interface CenterExecution extends CostCenterRow {
  annual: number;
  ytd: number;
  actual: number;
  /** null en los centros de ingresos. */
  committed: number | null;
  pct: number;
  over_ytd: boolean;
}

export interface BudgetExecution {
  year: number;
  budget: BudgetHeader | null;
  kpis: { cost_budget: number; cost_actual: number; committed: number; revenue_pct: number | null };
  centers: CenterExecution[];
}

export interface BudgetLineRow {
  account_code: string;
  account_name: string;
  budgeted: boolean;
  annual: number;
  ytd: number;
  actual: number;
  pct: number | null;
}

export interface CenterBudget {
  center: CostCenterRow & { lock_version: number };
  year: number;
  lines: BudgetLineRow[];
}

export interface BudgetOptions {
  years: number[];
  users: { id: string; name: string }[];
  branches: { id: string; name: string }[];
  accounts: ChartOption[];
}
