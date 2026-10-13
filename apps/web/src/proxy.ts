import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { SCOPE_COOKIE, TOKEN_COOKIE } from "@/lib/auth";

// La landing ("/") es pública: se ve con o sin sesión.
const AUTH_ROUTES = ["/login", "/forgot-password", "/reset-password"];

// Cada tipo de cuenta tiene su propia consola y no entra a la otra.
const TENANT_PREFIXES = ["/customers", "/suppliers", "/employees", "/actors", "/crm", "/services", "/quotations", "/contracts", "/orders", "/portal-requests", "/contracted-services", "/work-orders", "/exchange-rates", "/audit", "/roles", "/users", "/profile"];
const PLATFORM_PREFIXES = ["/superadmin"];
const TENANT_HOME = "/actors";
// Módulos desconectados: sus pantallas siguen en el repo, pero las reemplaza Clientes y actores.
// Para reconectarlos, vaciar esta lista y devolver sus ítems al menú (AppShell.tsx).
const RETIRED_PREFIXES = ["/customers", "/suppliers", "/employees"];
const PLATFORM_HOME = "/superadmin/plans";

// Dos dominios, como "midominio.com" (página pública) y "app.midominio.com" (el sistema).
// Se activa al definir SITE_URL y APP_URL; sin ellas todo vive en un solo dominio.
const SITE_URL = process.env.SITE_URL?.replace(/\/+$/, "");
const APP_URL = process.env.APP_URL?.replace(/\/+$/, "");
const hostOf = (url?: string) => (url ? new URL(url).host.toLowerCase() : null);
const SITE_HOST = hostOf(SITE_URL);
const APP_HOST = hostOf(APP_URL);
const SPLIT_DOMAINS = Boolean(SITE_HOST && APP_HOST && SITE_HOST !== APP_HOST);

const matches = (pathname: string, routes: string[]) =>
  routes.some((route) => pathname === route || pathname.startsWith(`${route}/`));

export function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl;
  const host = SPLIT_DOMAINS ? request.headers.get("host")?.toLowerCase() : null;

  // En el dominio público solo vive la landing: lo demás se abre en el del sistema.
  if (host === SITE_HOST && pathname !== "/") {
    return NextResponse.redirect(new URL(`${pathname}${search}`, APP_URL));
  }

  const hasToken = Boolean(request.cookies.get(TOKEN_COOKIE)?.value);
  // Solo decide a dónde redirigir; los permisos reales los valida la API.
  const scope = request.cookies.get(SCOPE_COOKIE)?.value;
  const inTenantArea = matches(pathname, TENANT_PREFIXES);
  const inPlatformArea = matches(pathname, PLATFORM_PREFIXES);

  // En el dominio del sistema no hay landing: la raíz lleva al login o a la consola.
  if (host === APP_HOST && pathname === "/") {
    const target = !hasToken ? "/login" : scope === "platform" ? PLATFORM_HOME : TENANT_HOME;
    return NextResponse.redirect(new URL(target, APP_URL));
  }

  if (!hasToken) {
    return inTenantArea || inPlatformArea
      ? NextResponse.redirect(new URL("/login", request.url))
      : NextResponse.next();
  }

  if (matches(pathname, AUTH_ROUTES)) {
    return NextResponse.redirect(new URL(scope === "platform" ? PLATFORM_HOME : TENANT_HOME, request.url));
  }

  if (scope === "platform" && inTenantArea) {
    return NextResponse.redirect(new URL(PLATFORM_HOME, request.url));
  }

  if (scope === "tenant" && inPlatformArea) {
    return NextResponse.redirect(new URL(TENANT_HOME, request.url));
  }

  if (matches(pathname, RETIRED_PREFIXES)) {
    return NextResponse.redirect(new URL(TENANT_HOME, request.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: [
    "/",
    "/blocked/:path*",
    "/login/:path*",
    "/forgot-password/:path*",
    "/reset-password/:path*",
    "/customers/:path*",
    "/suppliers/:path*",
    "/employees/:path*",
    "/actors/:path*",
    "/crm/:path*",
    "/services/:path*",
    "/quotations/:path*",
    "/contracts/:path*",
    "/orders/:path*",
    "/portal-requests/:path*",
    "/contracted-services/:path*",
    "/work-orders/:path*",
    "/exchange-rates/:path*",
    "/audit/:path*",
    "/roles/:path*",
    "/users/:path*",
    "/profile/:path*",
    "/superadmin/:path*",
  ],
};
