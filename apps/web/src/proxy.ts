import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { SCOPE_COOKIE, TOKEN_COOKIE } from "@/lib/auth";

// La landing ("/") es pública: se ve con o sin sesión.
const AUTH_ROUTES = ["/login", "/forgot-password", "/reset-password"];

// Cada tipo de cuenta tiene su propia consola y no entra a la otra.
const TENANT_PREFIXES = ["/customers", "/suppliers", "/employees", "/actors", "/services", "/quotations", "/contracts", "/orders", "/portal-requests", "/exchange-rates", "/roles", "/users", "/profile"];
const PLATFORM_PREFIXES = ["/superadmin"];
const TENANT_HOME = "/actors";
// Módulos desconectados: sus pantallas siguen en el repo, pero las reemplaza Clientes y actores.
// Para reconectarlos, vaciar esta lista y devolver sus ítems al menú (AppShell.tsx).
const RETIRED_PREFIXES = ["/customers", "/suppliers", "/employees"];
const PLATFORM_HOME = "/superadmin/plans";

const matches = (pathname: string, routes: string[]) =>
  routes.some((route) => pathname === route || pathname.startsWith(`${route}/`));

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const hasToken = Boolean(request.cookies.get(TOKEN_COOKIE)?.value);
  // Solo decide a dónde redirigir; los permisos reales los valida la API.
  const scope = request.cookies.get(SCOPE_COOKIE)?.value;
  const inTenantArea = matches(pathname, TENANT_PREFIXES);
  const inPlatformArea = matches(pathname, PLATFORM_PREFIXES);

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
    "/login/:path*",
    "/forgot-password/:path*",
    "/reset-password/:path*",
    "/customers/:path*",
    "/suppliers/:path*",
    "/employees/:path*",
    "/actors/:path*",
    "/services/:path*",
    "/quotations/:path*",
    "/contracts/:path*",
    "/orders/:path*",
    "/portal-requests/:path*",
    "/exchange-rates/:path*",
    "/roles/:path*",
    "/users/:path*",
    "/profile/:path*",
    "/superadmin/:path*",
  ],
};
