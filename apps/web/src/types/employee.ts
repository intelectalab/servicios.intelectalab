export interface PartyPerson {
  given_names: string;
  family_name: string;
  document_type: string;
  document_number: string;
}

export interface Party {
  id: string;
  party_type: "PERSON";
  person: PartyPerson | null;
  organization: null;
}

export interface UserRef {
  id: string;
  name: string;
  email: string;
}

export interface Employee {
  id: string;
  tenant_id: string;
  person_party_id: string;
  user_id: string | null;
  employee_code: string;
  position: string | null;
  status: "ACTIVE" | "INACTIVE";
  party: Party;
  user?: UserRef | null;
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

export function getEmployeeDisplayName(employee: Employee): string {
  if (!employee.party || !employee.party.person) return "—";
  const person = employee.party.person;
  return `${person.given_names} ${person.family_name}`.trim();
}

export function getEmployeeDocument(employee: Employee): string {
  if (!employee.party || !employee.party.person) return "—";
  const person = employee.party.person;
  if (!person.document_number) return "—";
  const docType = person.document_type || "DNI";
  return `${docType} ${person.document_number}`;
}