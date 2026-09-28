export type PartyType = "PERSON" | "ORGANIZATION";

export interface Person {
  id: string;
  party_id: string;
  given_names: string;
  family_name: string;
  document_type: string;
  document_number: string;
}

export interface Organization {
  id: string;
  party_id: string;
  legal_name: string;
  trade_name: string | null;
  tax_identifier: string;
}

export interface Party {
  id: string;
  tenant_id: string;
  party_type: PartyType;
  status: string;
  person: Person | null;
  organization: Organization | null;
}

export interface Customer {
  id: string;
  tenant_id: string;
  party_id: string;
  status: string;
  credit_limit: string | number | null;
  payment_terms_days: number | null;
  preferred_currency: "PEN" | "USD";
  party: Party;
  created_at?: string;
}

/** Shape exacta que devuelve Laravel paginate() — sin wrapper "meta". */
export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  next_page_url: string | null;
  prev_page_url: string | null;
}

/** Nombre para mostrar, según si el party es persona natural o empresa. */
export function getCustomerDisplayName(customer: Customer): string {
  const { party } = customer;
  if (party.party_type === "PERSON" && party.person) {
    return `${party.person.given_names} ${party.person.family_name}`.trim();
  }
  if (party.party_type === "ORGANIZATION" && party.organization) {
    return party.organization.legal_name;
  }
  return "—";
}

/** Documento (DNI/RUC según el tipo de party). */
export function getCustomerDocument(customer: Customer): string {
  const { party } = customer;
  if (party.party_type === "PERSON" && party.person) {
    return `${party.person.document_type} ${party.person.document_number}`;
  }
  if (party.party_type === "ORGANIZATION" && party.organization) {
    const taxId = party.organization.tax_identifier;
    return taxId ? `RUC ${taxId}` : "—";
  }
  return "—";
}
