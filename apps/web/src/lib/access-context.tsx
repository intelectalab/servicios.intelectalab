"use client";

import { createContext, useContext } from "react";

export type Access = {
  permissions: string[];
  /** Mientras carga /me/access aún no se sabe qué puede hacer el usuario. */
  loading: boolean;
};

const AccessContext = createContext<Access>({ permissions: [], loading: true });

export const AccessProvider = AccessContext.Provider;

/** Permisos del usuario de la sesión; los carga AppShell una sola vez. */
export function useAccess() {
  const access = useContext(AccessContext);
  return { ...access, can: (permission: string) => access.permissions.includes(permission) };
}
