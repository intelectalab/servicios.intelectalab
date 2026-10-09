"use client";

import { SCOPE_LABELS, useAccess, type Scope } from "@/lib/access-context";
import { Chip } from "@/components/ui/proto";

const HINTS: Record<Exclude<Scope, "TENANT">, string> = {
  OWN: "Tu rol alcanza solo lo propio: lo que tú registraste y lo que nació de tus cotizaciones.",
  TEAM: "Tu rol alcanza lo propio y lo de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza lo propio y lo de tus sedes.",
};

const PORTAL_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza las solicitudes que nadie tomó todavía y las que tomaste tú.",
  TEAM: "Tu rol alcanza las solicitudes que nadie tomó todavía y las que tomó tu equipo.",
  BRANCH: "Tu rol alcanza las solicitudes que nadie tomó todavía, las que tomaste tú y las de tus sedes.",
};

// En el CRM lo propio es lo que la persona lleva como responsable.
const CRM_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza solo lo propio: lo que llevas como responsable.",
  TEAM: "Tu rol alcanza lo que llevas tú y lo que llevan las personas de tu equipo.",
  BRANCH: "Tu rol alcanza lo que llevas tú y lo de tus sedes.",
};

const hintsOf = (resource: string) =>
  resource === "portal" ? PORTAL_HINTS : resource === "leads" || resource === "opportunities" ? CRM_HINTS : HINTS;

/**
 * Avisa en un listado que no se está viendo toda la empresa, sino lo que alcanza el rol
 * (se define en Roles y permisos). Con alcance de toda la empresa no muestra nada.
 */
export function ScopeNote({ resource }: { resource: string }) {
  const scope = useAccess().scopeOf(resource);
  if (!scope || scope === "TENANT") return null;

  return (
    <span className="ml-auto" title={hintsOf(resource)[scope]}>
      <Chip tone="accent">Alcance: {SCOPE_LABELS[scope]}</Chip>
    </span>
  );
}
