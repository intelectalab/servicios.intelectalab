import type { HistoryEntry } from "./commercial";

export type ActorRole = "CUSTOMER" | "SUPPLIER" | "CONTACT" | "EMPLOYEE";
export type ActorKind = "PERSON" | "ORGANIZATION";

/** Una identidad (persona u organización) con sus roles de cliente, proveedor y empleado. */
export interface Actor {
  id: string;
  kind: ActorKind;
  /** Inactivo si se dio de baja o si ninguno de sus roles sigue activo. */
  status: "ACTIVE" | "INACTIVE";
  name: string;
  person: { given_names: string; family_name: string; document_type: string | null; document_number: string | null } | null;
  organization: { legal_name: string; trade_name: string | null; tax_identifier: string | null } | null;
  roles: ActorRole[];
  /** Roles dados de baja por separado en Clientes, Proveedores o Empleados. */
  inactive_roles: ActorRole[];
  customer_id: string | null;
  supplier_id: string | null;
  employee_id: string | null;
  email: string | null;
  phone: string | null;
  /** Prefijo del país del teléfono principal ("+51"). */
  phone_country_code: string | null;
  sector: string | null;
  sla_level: string | null;
  position: string | null;
  /** Organización de la que esta persona es contacto (rol Contacto). */
  contact_of: { id: string; name: string } | null;
  employee_code: string | null;
  user_name: string | null;
  created_at: string | null;
  /** Valores de las columnas personalizadas (id de la columna → valor); llegan con el listado. */
  custom_values?: Record<string, string | null>;
}

export interface ActorContact {
  id: string;
  contact_name: string | null;
  contact_type: string;
  value: string;
  country_code: string | null;
  is_primary: boolean;
}

/** Vista 360: la identidad y lo que cada área tiene de ella. */
export interface ActorDetail extends Actor {
  address: string | null;
  customer: { id: string; status: string; credit_limit: string | null; payment_terms_days: number | null; preferred_currency: string; retention_agent?: boolean } | null;
  supplier?: { id: string; status: string; detraction_account: string | null } | null;
  contacts: ActorContact[];
  /** Personas con rol Contacto enlazadas a esta organización. */
  contact_people: { id: string; name: string; position: string | null; email: string | null; phone: string | null; phone_country_code: string | null; status: string }[];
  finance: { outstanding: number; overdue: number; open_documents: number } | null;
  quotations: { id: string; quotation_number: string; created_at: string; total: string; currency: string; status: string }[];
  contracts: { id: string; contract_number: string; start_date: string | null; end_date: string | null; total_value: string; currency: string; status: string }[];
  work_orders: { id: string; code: string; description: string | null; scheduled_start: string | null; status: string; technician: string | null }[];
  invoices: { id: string; invoice_number: string | null; issue_date: string | null; total: string; currency: string; status: string }[];
  tickets: { id: string; ticket_number: string; subject: string; priority: string; status: string }[];
  documents: { id: string; title: string; category: string; version: number; status: string; visibility: "INTERNAL" | "PORTAL" }[];
  leads: { id: string; lead_number: string; person_name: string | null; organization_name: string | null; status: string }[];
  opportunities: { id: string; opportunity_number: string; name: string; status: string }[];
  history: HistoryEntry[];
}

/** Coincidencia que devuelve la API al intentar crear una identidad que ya existe. */
export interface ActorDuplicate {
  id: string;
  name: string;
  kind: ActorKind;
  roles: ActorRole[];
}
