import type { Tone } from "@/components/ui/proto";
import type { CredentialAction, CredentialRow, CredentialStatus, CredentialType } from "@/types/credentials";

export const CREDENTIAL_TYPES: [CredentialType, string][] = [
  ["SERVIDOR", "Servidor"],
  ["DNS", "DNS"],
  ["HOSTING", "Hosting"],
  ["CLOUD", "Cloud"],
  ["API_KEY", "API key"],
  ["SMTP", "SMTP"],
  ["CERTIFICADO", "Certificado"],
  ["BASE_DATOS", "Base de datos"],
];

export const CREDENTIAL_STATES: [CredentialStatus, string, Tone][] = [
  ["ACTIVE", "Activa", "success"],
  ["EXPIRED", "Expirada", "warning"],
  ["REVOKED", "Revocada", "muted"],
];

/** Permiso de cada acción del Vault y su nombre (la leyenda "permisos de tu rol" del prototipo). */
export const VAULT_PERMISSIONS: [string, string][] = [
  ["credentials.view", "Ver metadatos"],
  ["credentials.use", "Usar"],
  ["credentials.reveal", "Revelar"],
  ["credentials.manage", "Rotar"],
  ["credentials.delete", "Revocar"],
];

export const ACTION_PERMISSION: Record<CredentialAction, string> = {
  reveal: "credentials.reveal",
  use: "credentials.use",
  rotate: "credentials.manage",
  revoke: "credentials.delete",
};

export const ACCESS_LABELS: Record<string, string> = {
  create: "Alta",
  reveal: "Revelada",
  use: "Usada (copiada)",
  rotate: "Rotada",
  revoke: "Revocada",
};

/** Segundos que se ve un valor revelado. */
export const REVEAL_SECONDS = 20;

/** Tipos que crea el propio sistema (la clave de un webhook): se muestran, pero no se eligen a mano. */
const SYSTEM_TYPES: [string, string][] = [["WEBHOOK", "Clave de webhook"]];

export const typeLabel = (type: string) => [...CREDENTIAL_TYPES, ...SYSTEM_TYPES].find(([key]) => key === type)?.[1] ?? type;

/** Para el filtro del listado: también los tipos del sistema. */
export const CREDENTIAL_FILTER_TYPES: [string, string][] = [...CREDENTIAL_TYPES, ...SYSTEM_TYPES];

/** Fecha en hora de Lima: "Expira el 15/12" vale todo ese día. */
export const limaDate = (value: string) => new Date(value).toLocaleDateString("es-PE", { timeZone: "America/Lima", day: "2-digit", month: "short", year: "numeric" });

/** Fecha (AAAA-MM-DD) de Lima, para un campo de fecha. */
export const limaInputDate = (value: Date | string) => new Date(value).toLocaleDateString("en-CA", { timeZone: "America/Lima" });

/** Vence en menos de 30 días (la etiqueta "Pronto" del prototipo). */
export function expiresSoon(credential: CredentialRow) {
  if (credential.status !== "ACTIVE" || !credential.expires_at) return false;
  return new Date(credential.expires_at).getTime() - Date.now() < 30 * 86_400_000;
}

/** Por qué una acción no se puede hacer con ese estado (null = se puede). */
export function blockedBy(action: CredentialAction, status: CredentialStatus): string | null {
  if (status === "REVOKED") {
    return action === "rotate" ? "Una credencial revocada no se rota: crea una nueva" : action === "revoke" ? "Ya está revocada" : "Credencial revocada";
  }
  if (status === "EXPIRED" && (action === "reveal" || action === "use")) return "Credencial expirada: rótala para volver a usarla";
  return null;
}
