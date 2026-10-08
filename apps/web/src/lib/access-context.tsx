"use client";

import { createContext, useContext } from "react";

/** Qué registros alcanza un rol en un recurso: lo propio, su equipo, su sede o toda la empresa. */
export type Scope = "OWN" | "TEAM" | "BRANCH" | "TENANT";

/** Texto de cada alcance, como en la matriz del prototipo. */
export const SCOPE_LABELS: Record<Scope, string> = {
  OWN: "Propios",
  TEAM: "Equipo",
  BRANCH: "Sede",
  TENANT: "Toda la empresa",
};

export type Access = {
  permissions: string[];
  /** Alcance del usuario por recurso (cotizaciones, contratos, órdenes, portal). */
  scopes?: Record<string, Scope>;
  /** Mientras carga /me/access aún no se sabe qué puede hacer el usuario. */
  loading: boolean;
};

const AccessContext = createContext<Access>({ permissions: [], loading: true });

export const AccessProvider = AccessContext.Provider;

/** Permisos del usuario de la sesión; los carga AppShell una sola vez. */
export function useAccess() {
  const access = useContext(AccessContext);
  return {
    ...access,
    can: (permission: string) => access.permissions.includes(permission),
    /** Alcance sobre un recurso; undefined mientras carga o si el recurso no lleva alcance. */
    scopeOf: (resource: string): Scope | undefined => access.scopes?.[resource],
  };
}
