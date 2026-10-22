import type { Tone } from "@/components/ui/proto";
import type { Customer } from "@/types/customer";

/** Secciones del portal (las del prototipo); el rol del portal y los módulos de la empresa deciden cuáles abre. */
export type PortalSection = "home" | "quotes" | "approve" | "services" | "sign" | "requests" | "documents" | "invoices" | "pay" | "support" | "users";

export type PortalRole = "PORTAL_ADMIN" | "PORTAL_FINANCE" | "PORTAL_VIEWER";

export type PortalRoleInfo = { code: PortalRole; name: string; sections: string[] };

export type PortalMe = {
  id: string;
  name: string;
  email: string;
  role: PortalRole;
  role_name: string;
  customer: Customer & { name: string };
  company_name: string;
  tenant: { name: string; code: string; legal_name: string | null } | null;
  company: { legal_name: string | null; tax_identifier: string | null } | null;
  /** La empresa tiene activo el módulo del portal. */
  enabled: boolean;
  sections: PortalSection[];
  roles: PortalRoleInfo[];
};

export type PortalNoticeView = { id: string; body: string; link: string | null; created_at: string; expires_at: string | null };

export type PortalHome = {
  quotations_pending: number | null;
  balance: { currency: string; amount: number }[] | null;
  overdue: boolean;
  open_tickets: number | null;
  renewals: number | null;
  signatures_pending: number | null;
  notices: PortalNoticeView[];
  work_orders: { id: string; code: string; title: string; status: string; scheduled_start: string | null }[];
};

export type PortalQuotationRow = {
  id: string;
  quotation_number: string;
  version: number;
  status: string;
  currency: string;
  total: number;
  valid_until: string | null;
  created_at: string;
};

export type PortalApproval = { decision: "APPROVED" | "REJECTED"; channel: "STAFF" | "PORTAL"; comment: string | null; decided_at: string };

export type PortalQuotation = PortalQuotationRow & {
  exchange_rate: number | null;
  subtotal: number;
  discount_total: number;
  tax_total: number;
  notes: string | null;
  customer: Customer;
  customer_contact: { address: string | null; email: string | null };
  creator: { name: string | null };
  items: {
    id: string; description: string; quantity: number; unit_price: number; discount: number; tax_amount: number; line_total: number;
    service_snapshot: { unit: string | null }; service: { unit: string | null };
  }[];
  expired: boolean;
  can_decide: boolean;
  approvals: PortalApproval[];
};

export type PortalContract = {
  id: string; contract_number: string; start_date: string | null; end_date: string | null; sla_level: string | null; status: string;
  currency: string; total_value: number;
};

export type PortalContractedService = {
  id: string; status: string; service_id: string; service_name: string; contract_number: string;
  start_date: string | null; end_date: string | null; quantity: number;
  recurring: boolean; frequency: string | null; renewal_date: string | null; renewal_soon: boolean; renewal_requested: boolean; can_renew: boolean;
};

export type PortalRequestRow = {
  id: string; request_number: string; request_type: string; subject: string; description: string | null; service_name: string | null;
  status: string; resolution_note: string | null; created_at: string; quotation: { id: string; quotation_number: string } | null;
};

export type PortalDocument = { id: string; title: string; category: string; source: string | null; versions: number; updated_at: string };

export type PortalReceivable = {
  id: string; invoice_id: string; invoice_number: string | null; invoice_type: string; due_date: string | null; overdue: boolean;
  original_amount: number; balance_amount: number; currency: string; status: string; detraction: number | null;
};

export type PortalInvoiceRow = {
  id: string; invoice_number: string | null; invoice_type: string; issue_date: string | null; due_date: string | null;
  currency: string; total: number; balance_due: number; status: string; fiscal_status: string | null;
};

export type PortalPaymentRow = {
  id: string; payment_number: string; paid_at: string | null; payment_method: string; amount: number; currency: string; status: string; refunded_amount: number;
};

export type PortalFinance = { receivables: PortalReceivable[]; invoices: PortalInvoiceRow[]; payments: PortalPaymentRow[]; online_payment: boolean };

export type PortalStatement = {
  customer: Customer;
  customer_name: string;
  date: string;
  rows: {
    invoice_number: string | null; invoice_type: string; issue_date: string | null; due_date: string | null;
    original_amount: number; balance_amount: number; currency: string; status: string; overdue: boolean;
  }[];
  totals: { currency: string; original: number; balance: number }[];
};

export type PortalAttention = "RECEIVED" | "IN_TIME" | "DELAYED" | "ON_TIME";

export type PortalTicketRow = { id: string; ticket_number: string; subject: string; status: string; attention: PortalAttention; created_at: string };

export type PortalTicket = PortalTicketRow & {
  resolution: string | null;
  comments: { id: string; body: string; from_customer: boolean; mine: boolean; author: string | null; created_at: string }[];
};

export type PortalUserStatus = "INVITED" | "ACTIVE" | "INACTIVE";

export type PortalUserRow = {
  id: string; user_id: string; name: string; email: string; customer_id: string; customer_name: string | null;
  role: PortalRole; role_name: string; status: PortalUserStatus;
  invited_at: string | null; invite_expires_at: string | null; activated_at: string | null; last_login_at: string | null; created_at: string;
  lock_version: number; is_me?: boolean;
};

export type PortalNotice = {
  id: string; customer_id: string | null; customer_name: string | null; body: string; link: string | null; expires_at: string | null;
  created_at: string; author: string | null; status: "ACTIVE" | "EXPIRED" | "WITHDRAWN"; withdrawn_at: string | null;
};

/** Barra del portal: ruta, texto y sección (el orden y los textos del prototipo). */
export const PORTAL_NAV: [string, string, PortalSection][] = [
  ["/portal", "Inicio", "home"],
  ["/portal/quotations", "Cotizaciones", "quotes"],
  ["/portal/services", "Mis servicios", "services"],
  ["/portal/signatures", "Firmas", "sign"],
  ["/portal/requests", "Solicitudes", "requests"],
  ["/portal/documents", "Documentos", "documents"],
  ["/portal/invoices", "Facturas y pagos", "invoices"],
  ["/portal/support", "Soporte", "support"],
  ["/portal/users", "Usuarios", "users"],
];

export const PORTAL_USER_STATES: [PortalUserStatus, string, Tone][] = [
  ["ACTIVE", "Activo", "success"],
  ["INVITED", "Invitado", "warning"],
  ["INACTIVE", "Desactivado", "muted"],
];

export const PORTAL_REQUEST_STATES: [string, string, Tone][] = [
  ["SUBMITTED", "Recibida", "info"],
  ["IN_REVIEW", "En revisión", "warning"],
  ["QUOTED", "Cotizada", "success"],
  ["REJECTED", "Rechazada", "danger"],
];

export const NOTICE_STATES: [PortalNotice["status"], string, Tone][] = [
  ["ACTIVE", "Vigente", "success"],
  ["EXPIRED", "Vencido", "muted"],
  ["WITHDRAWN", "Retirado", "neutral"],
];

/** Lo que el cliente ve de la atención de su ticket (como en el prototipo). */
export const ATTENTION: Record<PortalAttention, [string, Tone]> = {
  RECEIVED: ["Recibido", "neutral"],
  IN_TIME: ["En plazo", "success"],
  ON_TIME: ["Resuelto a tiempo", "success"],
  DELAYED: ["Demorado", "warning"],
};

/** "hace 3 h", "hace 2 días"… como el relTime del prototipo. */
export function relTime(value: string | null | undefined) {
  if (!value) return "—";
  const minutes = Math.round((Date.now() - new Date(value).getTime()) / 60000);
  if (minutes < 1) return "ahora";
  if (minutes < 60) return `hace ${minutes} min`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `hace ${hours} h`;
  const days = Math.round(hours / 24);
  if (days < 30) return `hace ${days} ${days === 1 ? "día" : "días"}`;
  const months = Math.round(days / 30);
  return `hace ${months} ${months === 1 ? "mes" : "meses"}`;
}

/** Fecha y hora cortas (dd/mm/aaaa hh:mm). */
export function dateTimeText(value: string | null | undefined) {
  if (!value) return "—";
  return new Date(value).toLocaleString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: false });
}

/** Correo con los primeros dos caracteres a la vista, como en la verificación del prototipo. */
export const maskEmail = (email: string) => email.replace(/^(.{2}).+@/, "$1•••@");
