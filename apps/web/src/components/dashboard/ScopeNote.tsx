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

// Una actividad es de quien la registró y de quien lleva el lead o la oportunidad a la que pertenece.
const ACTIVITY_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza las actividades que registraste y las de los leads y oportunidades que llevas.",
  TEAM: "Tu rol alcanza tus actividades y las de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza tus actividades y las de tus sedes.",
};

// Un servicio contratado es de su responsable y de quien vendió su contrato.
const CONTRACTED_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza los servicios contratados de los que eres responsable y los de tus contratos.",
  TEAM: "Tu rol alcanza tus servicios contratados y los de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza tus servicios contratados y los de tus sedes.",
};

// Una orden de trabajo es del técnico que la tiene asignada y de quien la creó.
const WORK_ORDER_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza las órdenes de trabajo que tienes asignadas y las que creaste.",
  TEAM: "Tu rol alcanza tus órdenes de trabajo y las de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza tus órdenes de trabajo y las de tus sedes.",
};

// Un proyecto es de quien lo lleva, de quien lo creó y de quien tiene una tarea suya.
const PROJECT_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza los proyectos que llevas, los que creaste y aquellos donde tienes una tarea.",
  TEAM: "Tu rol alcanza tus proyectos y los de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza tus proyectos y los de tus sedes.",
};

// Las horas son de quien las trabajó; un costo, de quien lo registró.
const ENTRY_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza solo lo que registraste tú.",
  TEAM: "Tu rol alcanza lo que registraste tú y lo de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza lo que registraste tú y lo de tus sedes.",
};

// Un evento de agenda es de su responsable y de quien lo agendó.
const AGENDA_HINTS: typeof HINTS = {
  OWN: "Tu rol alcanza los eventos que tienes a tu cargo y los que agendaste.",
  TEAM: "Tu rol alcanza tus eventos y los de las personas de tu equipo.",
  BRANCH: "Tu rol alcanza tus eventos y los de tus sedes.",
};

const hintsOf = (resource: string) =>
  resource === "portal" ? PORTAL_HINTS
    : resource === "agenda" ? AGENDA_HINTS
    : resource === "projects" ? PROJECT_HINTS
    : resource === "time_entries" || resource === "cost_entries" ? ENTRY_HINTS
    : resource === "contracted_services" ? CONTRACTED_HINTS
    : resource === "work_orders" ? WORK_ORDER_HINTS
    : resource === "activities" ? ACTIVITY_HINTS
    : resource === "leads" || resource === "opportunities" ? CRM_HINTS : HINTS;

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
