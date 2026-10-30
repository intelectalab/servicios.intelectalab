/** Panel de la consola de plataforma: solo totales de plataforma (GET /v1/superadmin/dashboard). */
export type PlatformDashboard = {
  generated_at: string;
  tenants: {
    total: number;
    by_status: Record<string, number>;
    new_this_month: number;
    new_last_month: number;
    without_subscription: number;
  };
  subscriptions: {
    current: number;
    paid: number;
    trial: number;
    trial_expiring: number;
    trial_expired: number;
    paid_expired: number;
  };
  revenue: { currency: string; mrr: number }[];
  plans: PlatformPlanRow[];
  signups: { month: string; tenants: number }[];
  users: { tenant_users: number; portal_users: number; super_admins: number };
  trials: PlatformTrialRow[];
  usage: PlatformUsageRow[];
  health: {
    jobs_pending: number;
    jobs_failed: number;
    failed_last_24h: number;
    outbox_pending: number;
    outbox_failed: number;
    dead_letters: number;
    files_quarantined: number;
    mail: string;
    queue: string;
  };
  audit: PlatformAuditRow[];
};

export type PlatformPlanRow = {
  id: string;
  code: string;
  name: string;
  active: boolean;
  currency: string;
  tenants: number;
  paid: number;
  trial: number;
  mrr: number;
};

export type PlatformTrialRow = {
  tenant_id: string;
  code: string;
  name: string;
  plan: string;
  ends_at: string;
  days_left: number;
};

export type PlatformUsageRow = {
  tenant_id: string;
  code: string;
  name: string;
  status: string;
  plan: string | null;
  trial: boolean | null;
  created_at: string;
  users: number;
  users_limit: number | null;
  portal_users: number;
  portal_users_limit: number | null;
  customers: number;
  customers_limit: number | null;
  storage_mb: number;
};

export type PlatformAuditRow = {
  created_at: string;
  action: string;
  entity_type: string | null;
  user_name: string | null;
};

export type CatalogModule = {
  code: string;
  name: string;
  description: string | null;
  category: string;
  is_core: boolean;
  own_screens: boolean;
  provided_by: string[];
};

export type PlatformSubscription = {
  id: string;
  plan_id: string;
  plan: string;
  plan_code: string;
  currency: string;
  monthly_price: number | string | null;
  annual_price: number | string | null;
  starts_at: string;
  ends_at: string | null;
  trial: boolean;
  billing_period: string;
};

export type PlatformTenant = {
  id: string;
  code: string;
  name: string;
  legal_name: string | null;
  trade_name: string | null;
  ruc: string | null;
  status: string;
  created_at: string;
  subscription: PlatformSubscription | null;
  modules: string[];
  users: number;
  users_limit: number | null;
  portal_users: number;
  portal_users_limit: number | null;
  customers: number;
  customers_limit: number | null;
  storage_mb: number;
  storage_limit_gb: number | null;
};

export type SubscriptionPayment = {
  id: string;
  amount: number;
  currency: string;
  payment_method: string;
  reference: string | null;
  notes: string | null;
  months: number | null;
  previous_ends_at: string | null;
  new_ends_at: string;
  created_at: string;
  created_by_name?: string | null;
};

export type PlatformTenantDetail = PlatformTenant & {
  subscriptions: { id: string; plan: string; status: string; starts_at: string; ends_at: string | null; trial: boolean; billing_period: string }[];
  status_log: { from: string | null; to: string; reason: string | null; at: string; user_name: string | null }[];
  admins: { id: string; name: string; email: string; status: string; last_login_at: string | null }[];
  payments?: SubscriptionPayment[];
};

export type PlatformPlanOption = {
  id: string;
  code: string;
  name: string;
  currency: string;
  monthly_price: number | string | null;
  annual_price: number | string | null;
  has_trial: boolean;
  trial_days: number | null;
  default_billing_period: string;
  modules: Record<string, boolean>;
  users_limit: number | null;
};

export type PlatformSubscriptionRow = {
  id: string;
  tenant_id: string;
  tenant_code: string;
  tenant_name: string;
  tenant_status: string;
  plan: string;
  currency: string;
  status: string;
  starts_at: string;
  ends_at: string | null;
  trial: boolean;
  billing_period: string;
  price: number | string | null;
};

export type PlatformAuditEntry = {
  id: string;
  created_at: string;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  ip_address: string | null;
  user_agent: string | null;
  before_data: Record<string, unknown> | null;
  after_data: Record<string, unknown> | null;
  metadata: Record<string, unknown> | null;
  user_id: string | null;
  user_name: string | null;
  user_email: string | null;
};
