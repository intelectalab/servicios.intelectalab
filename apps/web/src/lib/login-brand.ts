"use client";

import { useEffect, useState } from "react";

/** Lo público de la marca de una empresa para su pantalla de ingreso (/e/{nombre-corto}). */
export type LoginBrand = {
  slug: string;
  name: string;
  /** Logo limpio como data URL PNG, o null. */
  logo: string | null;
  logo_width: number | null;
  logo_height: number | null;
  primary_color: string;
  welcome: string | null;
};

/** Enlace de ingreso de una empresa, relativo al sistema. */
export const loginPath = (slug: string | null | undefined) => (slug ? `/e/${slug}` : "/login");

/** Agrega ?empresa= a una ruta de ingreso para que la pantalla siguiente conserve la marca. */
export const withCompany = (path: string, slug: string | null | undefined) =>
  slug ? `${path}${path.includes("?") ? "&" : "?"}empresa=${encodeURIComponent(slug)}` : path;

/**
 * Marca de la empresa por su enlace. Si el servidor web ya la consultó (`initial`, aunque sea null),
 * se usa tal cual y no hay espera ni cambio de colores. Si no, se consulta aquí: `ready` es false
 * mientras tanto. Sin enlace válido queda `brand: null` y la pantalla es la de ingreso general.
 */
export function useLoginBrand(slug: string | null | undefined, initial?: LoginBrand | null) {
  const prefetched = initial !== undefined;
  const [state, setState] = useState<{ slug: string | null; brand: LoginBrand | null }>({ slug: null, brand: null });

  useEffect(() => {
    if (!slug || prefetched) return;
    let cancelled = false;
    const base = process.env.NEXT_PUBLIC_API_URL ?? "";
    fetch(`${base}/v1/public/login-brand/${encodeURIComponent(slug)}`, { headers: { Accept: "application/json" } })
      .then((res) => (res.ok ? (res.json() as Promise<LoginBrand>) : null))
      .catch(() => null)
      .then((brand) => {
        if (!cancelled) setState({ slug, brand });
      });
    return () => { cancelled = true; };
  }, [slug, prefetched]);

  if (prefetched) return { brand: initial ?? null, ready: true };
  return { brand: slug && state.slug === slug ? state.brand : null, ready: !slug || state.slug === slug };
}
