import { apiFetch } from "@/lib/api";
import type { Tone } from "@/components/ui/proto";

/** Estados de una empresa (tenants.status) y su color. */
export const TENANT_STATUS: Record<string, [string, Tone]> = {
  PROVISIONING: ["En alta", "info"],
  ACTIVE: ["Activa", "success"],
  SUSPENDED: ["Suspendida", "warning"],
  MAINTENANCE: ["En mantenimiento", "warning"],
  CLOSED: ["Cerrada", "muted"],
};

export const tenantStatus = (status: string): [string, Tone] => TENANT_STATUS[status] ?? [status, "neutral"];

/** Botón de cada cambio de estado y si es delicado (pide motivo y se ve en rojo). */
export const TRANSITION_LABELS: Record<string, { label: string; danger: boolean }> = {
  ACTIVE: { label: "Activar", danger: false },
  SUSPENDED: { label: "Suspender", danger: true },
  MAINTENANCE: { label: "Poner en mantenimiento", danger: false },
  CLOSED: { label: "Cerrar", danger: true },
};

/** Acciones de la auditoría de plataforma. */
export const PLATFORM_ACTIONS: Record<string, string> = {
  "auth.login": "Inicio de sesión",
  "auth.logout": "Cierre de sesión",
  "auth.name_changed": "Cambio de nombre en Mi perfil",
  "plan.created": "Plan creado",
  "plan.updated": "Plan editado",
  "plan.deactivated": "Plan desactivado",
  "platform.export": "Exportación",
  "platform.access_denied": "Acceso rechazado a la consola",
  "platform.settings_updated": "Configuración de la plataforma actualizada",
  "platform.super_admin_invited": "Super Administrador invitado",
  "platform.super_admin_reinvited": "Invitación de Super Administrador reenviada",
  "platform.super_admin_revoked": "Acceso de Super Administrador quitado",
  "tenant.provisioned": "Empresa aprovisionada",
  "tenant.status_changed": "Estado de la empresa cambiado",
  "tenant.plan_changed": "Plan de la empresa cambiado",
  "tenant.modules_applied": "Módulos del plan aplicados",
  "tenant.invitation_sent": "Invitación al administrador enviada",
  "tenant.subscription_extended": "Pago registrado y vigencia extendida",
};

export const actionLabel = (action: string) => PLATFORM_ACTIONS[action] ?? action;

export const PERIODS: Record<string, string> = { MONTHLY: "Mensual", ANNUAL: "Anual" };

/** Estado de una suscripción vista hoy. */
export function subscriptionState(sub: { status: string; trial: boolean; ends_at: string | null }): [string, Tone] {
  if (sub.status !== "ACTIVE") return ["Reemplazada", "muted"];
  const expired = sub.ends_at ? new Date(sub.ends_at).getTime() < Date.now() : false;
  if (sub.trial) return expired ? ["Prueba vencida", "danger"] : ["En prueba", "info"];
  return expired ? ["Vencida", "danger"] : ["Vigente", "success"];
}

export const count = (value: number) => value.toLocaleString("es-PE");
export const limitText = (used: number, limit: number | null) => (limit ? `${count(used)} / ${count(limit)}` : count(used));

/** Registra en la auditoría de plataforma una exportación de la consola antes de generar el archivo. */
export async function recordPlatformExport(table: string, format: "xlsx" | "csv", rows: number, selection: boolean) {
  await apiFetch("/v1/superadmin/dashboard/exports", { method: "POST", body: JSON.stringify({ table, format, rows, selection }) });
}
