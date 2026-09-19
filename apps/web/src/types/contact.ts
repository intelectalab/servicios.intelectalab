export interface Contact {
  id: string;
  tenant_id: string;
  party_id: string;
  contact_name?: string | null;
  contact_type: "EMAIL" | "PHONE" | "WHATSAPP" | "ADDRESS" | "OTHER";
  value: string;
  country_code?: string | null;
  is_primary: boolean;
  created_at?: string;
}