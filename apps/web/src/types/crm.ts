import type { HistoryEntry } from "./commercial";
import type { Customer } from "./customer";

export type LeadStatus = "NEW" | "CONTACTED" | "QUALIFIED" | "CONVERTED" | "DISQUALIFIED";
export type OpportunityStatus = "OPEN" | "WON" | "LOST";

type Named = { id: string; name: string };

export type ScoreGrade = "A" | "B" | "C" | "D";

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
  /** Puntaje automático (0 a 100), su grado y los criterios que cumple. */
  score: number | null;
  score_grade: ScoreGrade | null;
  score_hits: string[] | null;
  /** Campaña a la que el lead quedó asociado. */
  campaign_id: string | null;
  campaign: Named | null;
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
  /** Campañas activas a las que se puede asociar un lead (vacío sin permiso de ver campañas). */
  campaigns: (Named & { consent_required: boolean })[];
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

export type ActivityType = "CALL" | "MEETING" | "EMAIL" | "DEMO" | "VISIT";

export interface Activity {
  id: string;
  subject: string;
  description: string | null;
  activity_type: ActivityType;
  due_at: string | null;
  completed_at: string | null;
  done: boolean;
  lead_id: string | null;
  opportunity_id: string | null;
  /** Lead u oportunidad al que pertenece; sin nombre si queda fuera del alcance de quien mira. */
  related: { type: "lead" | "opportunity"; id: string; number: string | null; name: string | null } | null;
  owner: Named | null;
  lock_version: number;
}

export interface ActivitySummary {
  late: number;
  today: number;
  upcoming: number;
  done: number;
}

export interface ActivityOptions {
  opportunities: { id: string; number: string; name: string }[];
  leads: { id: string; number: string; name: string | null }[];
  types: { code: ActivityType; label: string }[];
}

export type CampaignStatus = "DRAFT" | "ACTIVE" | "COMPLETED" | "CANCELLED";

export interface CampaignMetrics {
  members: number;
  qualified: number;
  opportunities: number;
  won: number;
  revenue: number;
}

export interface Campaign {
  id: string;
  code: string;
  name: string;
  channel: string | null;
  status: CampaignStatus;
  starts_at: string | null;
  ends_at: string | null;
  budget: number;
  consent_required: boolean;
  cancel_reason: string | null;
  owner: Named | null;
  metrics: CampaignMetrics;
  created_at: string;
  lock_version: number;
}

export interface CampaignMember {
  id: string;
  status: "ADDED" | "EXCLUDED";
  consent: boolean;
  added_at: string;
  lead: { id: string; lead_number: string; name: string | null; person_name: string | null; status: LeadStatus };
}

export interface CampaignDetail extends Campaign {
  members: CampaignMember[];
  /** Miembros que existen pero quedan fuera del alcance de quien mira. */
  hidden_members: number;
  available_leads: { id: string; number: string; name: string | null; person_name: string | null }[];
  history: HistoryEntry[];
}

export interface CampaignSummary {
  active: number;
  members: number;
  opportunities: number;
  revenue: number;
}

export interface ScoringRule {
  id: string;
  label: string;
  condition: string;
  points: number;
  default_points: number;
}

export interface Scoring {
  rules: ScoringRule[];
  limits: { min: number; max: number };
  distribution: { grade: ScoreGrade; total: number }[];
  priority: { id: string; lead_number: string; name: string | null; score: number; score_grade: ScoreGrade }[];
}
