export interface PartyPerson {
  given_names: string;
  family_name: string;
  document_type: string;
  document_number: string;
}

export interface PartyOrganization {
  legal_name: string;
  trade_name?: string | null;
  tax_identifier: string;
}

export interface Party {
  id: string;
  party_type: "PERSON" | "ORGANIZATION";
  person: PartyPerson | null;
  organization: PartyOrganization | null;
}

export interface Supplier {
  id: string;
  tenant_id: string;
  party_id: string;
  status: "ACTIVE" | "INACTIVE";
  party: Party;
  created_at: string;
  updated_at: string;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export function getSupplierDisplayName(supplier: Supplier): string {
  if (!supplier.party) return "—";
  if (supplier.party.party_type === "ORGANIZATION") {
    return supplier.party.organization?.legal_name || "—";
  }
  const person = supplier.party.person;
  if (!person) return "—";
  return `${person.given_names} ${person.family_name}`.trim();
}

export function getSupplierDocument(supplier: Supplier): string {
  if (!supplier.party) return "—";
  if (supplier.party.party_type === "ORGANIZATION") {
    const taxId = supplier.party.organization?.tax_identifier;
    return taxId ? `RUC ${taxId}` : "—";
  }
  const person = supplier.party.person;
  if (!person || !person.document_number) return "—";
  const docType = person.document_type || "DNI";
  return `${docType} ${person.document_number}`;
}