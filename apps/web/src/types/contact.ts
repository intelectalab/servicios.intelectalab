export interface Contact {
  id: string;
  tenant_id: string;
  party_id: string;
  contact_type: "EMAIL" | "PHONE" | "WHATSAPP" | "OTHER";
  value: string;
  is_primary: boolean;
  created_at: string;
}