import type { Tone } from "@/components/ui/proto";
import type { Actor, ActorRole } from "@/types/actor";

/** Campos personalizados de la tabla unificada (independientes de los de Clientes, Proveedores y Empleados). */
export const ACTOR_ENTITY = "Party";

export const ROLE_LABELS: Record<ActorRole, string> = { CUSTOMER: "Cliente", SUPPLIER: "Proveedor", EMPLOYEE: "Empleado" };

export const ROLE_TONES: Record<ActorRole, Tone> = { CUSTOMER: "accent", SUPPLIER: "warning", EMPLOYEE: "success" };

export const ACTOR_STATES: ["ACTIVE" | "INACTIVE", string, Tone][] = [
  ["ACTIVE", "Activo", "success"],
  ["INACTIVE", "Inactivo", "muted"],
];

export const DOCUMENT_TYPES: [string, string][] = [["DNI", "DNI"], ["CE", "Carné de extranjería"], ["PASSPORT", "Pasaporte"]];

export const kindLabel = (actor: Pick<Actor, "kind">) => (actor.kind === "ORGANIZATION" ? "Organización" : "Persona");

/** "RUC 20123456789" o "DNI 44556677". */
export function actorDocument(actor: Pick<Actor, "kind" | "person" | "organization">) {
  if (actor.kind === "ORGANIZATION") return `RUC ${actor.organization?.tax_identifier || "—"}`;
  return `${actor.person?.document_type || "DNI"} ${actor.person?.document_number || "—"}`;
}

/** Línea pequeña bajo el nombre: razón social, o cargo o correo de la persona. */
export function actorSubtitle(actor: Actor) {
  if (actor.kind === "ORGANIZATION") return actor.organization?.legal_name ?? "";
  return actor.position || actor.email || "";
}

/** Columna "Sector / relación": sector de la organización, o cargo o usuario de la persona. */
export function actorRelation(actor: Actor) {
  if (actor.kind === "ORGANIZATION") return actor.sector || "—";
  return actor.position || (actor.user_name ? `Usuario: ${actor.user_name}` : "—");
}

export const rolesText = (actor: Pick<Actor, "roles">) => actor.roles.map((role) => ROLE_LABELS[role]).join(", ");
