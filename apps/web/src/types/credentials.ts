export type CredentialType = "SERVIDOR" | "DNS" | "HOSTING" | "CLOUD" | "API_KEY" | "SMTP" | "CERTIFICADO" | "BASE_DATOS" | "WEBHOOK";
export type CredentialStatus = "ACTIVE" | "EXPIRED" | "REVOKED";

/** Datos generales de una credencial: el valor nunca viene en el listado ni en la ficha. */
export type CredentialRow = {
  id: string;
  name: string;
  type: CredentialType;
  owner_label: string | null;
  status: CredentialStatus;
  version: number;
  rotated_at: string | null;
  expires_at: string | null;
  creator: string | null;
  created_at: string | null;
};

export type CredentialAction = "reveal" | "use" | "rotate" | "revoke";

export type CredentialHistory = {
  versions: { version: number; reason: string | null; created_at: string; user: string | null }[];
  accesses: { action: "create" | CredentialAction; reason: string | null; success: boolean; created_at: string; user: string | null }[];
};
