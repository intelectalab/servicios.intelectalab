import type { HistoryEntry } from "./commercial";
import type { Customer } from "./customer";

export type LeadStatus = "NEW" | "CONTACTED" | "QUALIFIED" | "CONVERTED" | "DISQUALIFIED";
export type OpportunityStatus = "OPEN" | "WON" | "LOST";

type Named = { id: string; name: string };

export interface Lead {
  id: string;
  lead_number: string;
  organization_name: string | null;
  person_name: string | null;
  position: string | null;
  email: string | null;
  phone: string | null;
  interest: string | null;
  status: LeadStatus;
  discard_reason: string | null;
  source_id: string | null;
  source: Named | null;
  owner_user_id: string | null;
  owner: Named | null;
  /** Identidad de Clientes y actores a la que el lead quedó enlazado. */
  party_id: string | null;
  /** La enlazada o, si no hay, la que coincide por nombre. */
  core_party_id: string | null;
  created_at: string;
  lock_version: number;
}

export interface LeadDetail extends Lead {
  core_party: Named | null;
  opportunity: { id: string; opportunity_number: string; name: string; status: OpportunityStatus } | null;
  history: HistoryEntry[];
}

export interface LeadSummary {
  total: number;
  by_status: Partial<Record<LeadStatus, number>>;
}

export interface LeadOptions {
  sources: (Named & { code: string })[];
  owners: Named[];
}

export interface Stage {
  id: string;
  pipeline_id: string;
  name: string;
  position: number;
  probability: number;
}

export interface Opportunity {
  id: string;
  opportunity_number: string;
  name: string;
  status: OpportunityStatus;
  stage_id: string;
  stage: Pick<Stage, "id" | "name" | "position" | "probability"> | null;
  expected_value: string;
  currency: string;
  probability: string;
  expected_close_date: string | null;
  source_id: string | null;
  source: Named | null;
  owner: Named | null;
  customer_id: string | null;
  customer: Customer | null;
  lead_id: string | null;
  won_at: string | null;
  won_evidence: string | null;
  lost_at: string | null;
  loss_reason: string | null;
  /** Cotizaciones emitidas desde la oportunidad que la persona alcanza. */
  quotations_count?: number;
  /** Ya se convirtió en cliente. */
  converted?: boolean;
  created_at: string;
  lock_version: number;
}

/** Identidad que ya existe y con la que se puede convertir sin duplicar. */
export interface ConversionCandidate {
  id: string;
  name: string;
  document: string;
  is_customer: boolean;
}

export interface OpportunityDetail extends Opportunity {
  lead: { id: string; lead_number: string; organization_name: string | null; person_name: string | null } | null;
  conversion: { id: string; party_id: string | null; party_name: string | null; mode: "CREATE" | "REUSE" | null; converted_at: string } | null;
  conversion_candidates: ConversionCandidate[];
  quotations: { id: string; quotation_number: string; total: string; currency: string; status: string }[];
  history: HistoryEntry[];
}

export interface OpportunitySummary {
  open: { count: number; amount: number; weighted: number };
  won: { count: number; amount: number };
  lost: { count: number };
  /** Ganadas sobre cerradas, en porcentaje; null si aún no se cerró ninguna. */
  close_rate: number | null;
}

export interface OpportunityOptions {
  stages: Stage[];
  sources: (Named & { code: string })[];
  owners: Named[];
}
