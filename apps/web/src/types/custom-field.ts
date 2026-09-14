export interface CustomFieldDefinition {
  id: string;
  tenant_id: string;
  entity_type: string;
  field_key: string;
  label: string;
  field_type: "text" | "number" | "date" | "boolean";
}

export interface CustomFieldValue {
  id: string;
  tenant_id: string;
  entity_type: string;
  entity_id: string;
  field_id: string;
  value: string;
  definition: CustomFieldDefinition;
}