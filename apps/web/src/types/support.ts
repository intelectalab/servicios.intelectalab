import type { HistoryEntry } from "@/types/commercial";
import type { Customer } from "@/types/customer";

export type TicketStatus = "OPEN" | "TRIAGED" | "ASSIGNED" | "IN_PROGRESS" | "WAITING_CUSTOMER" | "RESOLVED" | "CLOSED" | "REOPENED";
export type TicketPriority = "P1" | "P2" | "P3" | "P4";
export type TicketChannel = "PHONE" | "EMAIL" | "BACKOFFICE" | "PORTAL";
export type TicketCategory = "INCIDENT" | "REQUEST" | "QUESTION";
export type SlaStatus = "ok" | "risk" | "breached" | "paused" | "met" | "none";
export type SlaMode = "BUSINESS" | "24X7";

export type TicketSla = {
  status: SlaStatus;
  started_at: string | null;
  response_due_at: string | null;
  resolution_due_at: string | null;
  first_response_at: string | null;
  resolved_at: string | null;
  paused_at: string | null;
  paused_seconds: number;
};

export type Ticket = {
  id: string;
  number: string;
  subject: string;
  channel: TicketChannel;
  category: TicketCategory;
  priority: TicketPriority | null;
  status: TicketStatus;
  customer: Customer | null;
  assignee: { id: string; name: string } | null;
  sla: TicketSla;
  opened_at: string | null;
  lock_version: number;
};

export type TicketComment = { id: string; author: string | null; author_type: "STAFF" | "PORTAL"; internal: boolean; body: string; at: string | null };

export type SlaPolicy = { priority: TicketPriority; label: string; response_minutes: number; resolution_minutes: number; mode: SlaMode };

export type TicketDetail = Ticket & {
  description: string | null;
  resolution: string | null;
  reopen_reason: string | null;
  closed_at: string | null;
  triaged_at: string | null;
  contract: { id: string; number: string; sla_level: string | null; visible: boolean } | null;
  service: { id: string; code: string; name: string } | null;
  team: string | null;
  creator: string | null;
  policy: (SlaPolicy & { factor: number; applied_response_minutes: number | null; applied_resolution_minutes: number | null }) | null;
  work_order: { id: string; code: string; status: string; visible: boolean } | null;
  claim: { id: string; number: string; status: WarrantyStatus } | null;
  contracted_services: { id: string; service: string | null; contract: string | null }[];
  comments: TicketComment[];
  history: HistoryEntry[];
};

export type TicketSummary = { open: number; breached: number; risk: number; resolved: number };

export type TicketOptions = {
  customers: { id: string; name: string }[];
  contracts: { id: string; number: string; customer_id: string; sla_level: string | null }[];
  services: { id: string; code: string; name: string }[];
  agents: { id: string; name: string }[];
  policies: Record<string, SlaPolicy>;
};

export type WarrantyStatus = "SUBMITTED" | "UNDER_REVIEW" | "APPROVED" | "REJECTED" | "RESOLVED";

export type WarrantyClaim = {
  id: string;
  number: string;
  description: string;
  customer: Customer | null;
  service: { id: string; name: string } | null;
  contracted_service_id: string;
  ticket: { id: string; number: string; visible: boolean } | null;
  coverage: { checked_at: string; valid: boolean; base_date: string | null; base: string | null; until: string | null; policy: string | null } | null;
  status: WarrantyStatus;
  reject_reason: string | null;
  resolution: string | null;
  created_at: string | null;
  lock_version: number;
};

export type WarrantyClaimDetail = WarrantyClaim & {
  contract: string | null;
  creator: string | null;
  reviewer: string | null;
  reviewed_at: string | null;
  resolved_at: string | null;
  history: HistoryEntry[];
};

export type WarrantyOptions = {
  contracted_services: { id: string; customer_id: string; customer: string | null; service: string | null; contract: string | null; warranty_months: number; status: string }[];
  tickets: { id: string; number: string; subject: string; customer_id: string }[];
};

export type SlaSettings = {
  calendar: {
    id: string;
    name: string;
    timezone: string;
    hours: Record<string, { from: string; to: string } | null> | ({ from: string; to: string } | null)[];
    premium_percent: number;
    lock_version: number;
    updated_at: string | null;
    updated_by: string | null;
  };
  policies: SlaPolicy[];
  simulation: { from: string; rows: { priority: TicketPriority; due: string; premium_due: string }[] };
  holidays: { id: string; date: string; name: string }[];
  today: string;
  history: HistoryEntry[];
};
