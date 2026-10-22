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
