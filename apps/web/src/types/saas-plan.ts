export type BillingPeriod = "MONTHLY" | "ANNUAL";

export interface SaasPlan {
  id: string;
  code: string;
  name: string;
  description?: string | null;
  active: boolean;
  display_order?: number;
  is_public?: boolean;
  /** Laravel serializa los decimales como string. */
  monthly_price?: number | string | null;
  annual_price?: number | string | null;
  currency?: string;
  has_trial?: boolean;
  trial_days?: number;
  requires_payment_upfront?: boolean;
  auto_renew_default?: boolean;
  default_billing_period?: BillingPeriod;
  limits?: Record<string, number> | null;
  modules?: Record<string, boolean> | null;
  consumption?: Record<string, number> | null;
  features?: Record<string, boolean> | null;
  support_level?: string;
  support_tickets_month?: number | null;
  support_sla_hours?: number | null;
  support_channels?: Record<string, boolean> | null;
}

/** Plan elegido en la landing, con el periodo y si entra como prueba. */
export type SelectedPlan = Partial<SaasPlan> & {
  billingPeriod: BillingPeriod;
  isTrial: boolean;
};
