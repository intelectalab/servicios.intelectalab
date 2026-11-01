import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";
import { SCOPE_COOKIE, TOKEN_COOKIE } from "@/lib/auth";

// La landing ("/") es pública: se ve con o sin sesión.
// "/e/{empresa}": ingreso con la marca de una empresa.
const AUTH_ROUTES = ["/login", "/forgot-password", "/reset-password", "/e"];

// Cada tipo de cuenta tiene su propia consola y no entra a la otra.
const TENANT_PREFIXES = ["/dashboard", "/notifications", "/customers", "/suppliers", "/employees", "/actors", "/crm", "/services", "/quotations", "/contracts", "/orders", "/portal-requests", "/contracted-services", "/work-orders", "/checklist-templates", "/projects", "/timesheets", "/agenda", "/campo", "/billable-events", "/invoices", "/receivables", "/payments", "/recurring", "/banks", "/fiscal", "/exchange-rates", "/purchase-orders", "/supplier-invoices", "/payables", "/journal", "/ledger", "/financial-statements", "/budgets", "/chart-of-accounts", "/tickets", "/warranty-claims", "/sla-calendar", "/documents", "/credentials", "/reports", "/audit", "/roles", "/users", "/portal-users", "/settings", "/profile"];
const PLATFORM_PREFIXES = ["/superadmin"];
// Portal de clientes: experiencia aparte. "/portal" no coincide con "/portal-requests" ni "/portal-users".
const PORTAL_PREFIXES = ["/portal"];
// Activar la invitación al portal: sin sesión, como el inicio de sesión.
const PORTAL_PUBLIC = ["/portal/activate"];
const PORTAL_HOME = "/portal";
const TENANT_HOME = "/dashboard";
// Módulos desconectados: sus pantallas siguen en el repo, pero las reemplaza Clientes y actores.
// Para reconectarlos, vaciar esta lista y devolver sus ítems al menú (AppShell.tsx).
const RETIRED_PREFIXES = ["/customers", "/suppliers", "/employees"];
const PLATFORM_HOME = "/superadmin/dashboard";

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
  if (SPLIT_DOMAINS && host === SITE_HOST && pathname !== "/") {
    return NextResponse.redirect(new URL(`${pathname}${search}`, APP_URL));
  }

  const hasToken = Boolean(request.cookies.get(TOKEN_COOKIE)?.value);
  // Solo decide a dónde redirigir; los permisos reales los valida la API.
  const scope = request.cookies.get(SCOPE_COOKIE)?.value;
  const inTenantArea = matches(pathname, TENANT_PREFIXES);
  const inPlatformArea = matches(pathname, PLATFORM_PREFIXES);
  const inPortalPublic = matches(pathname, PORTAL_PUBLIC);
  const inPortalArea = matches(pathname, PORTAL_PREFIXES) && !inPortalPublic;
  const home = scope === "platform" ? PLATFORM_HOME : scope === "portal" ? PORTAL_HOME : TENANT_HOME;

  // En el dominio del sistema no hay landing: la raíz lleva al login o a la consola.
  if (SPLIT_DOMAINS && host === APP_HOST && pathname === "/") {
    const target = !hasToken ? "/login" : home;
    return NextResponse.redirect(new URL(target, APP_URL));
  }

  if (!hasToken) {
    return inTenantArea || inPlatformArea || inPortalArea
      ? NextResponse.redirect(new URL("/login", request.url))
      : NextResponse.next();
  }

  if (matches(pathname, AUTH_ROUTES)) {
    return NextResponse.redirect(new URL(home, request.url));
  }

  // Una cuenta del portal solo entra a su portal; las del sistema y la plataforma, no al portal.
  if (scope === "portal" && (inTenantArea || inPlatformArea)) {
    return NextResponse.redirect(new URL(PORTAL_HOME, request.url));
  }
  if (scope !== "portal" && inPortalArea) {
    return NextResponse.redirect(new URL(home, request.url));
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
    "/e/:path*",
    "/dashboard/:path*",
    "/notifications/:path*",
    "/settings/:path*",
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
    "/checklist-templates/:path*",
    "/projects/:path*",
    "/timesheets/:path*",
    "/agenda/:path*",
    "/campo/:path*",
    "/billable-events/:path*",
    "/invoices/:path*",
    "/receivables/:path*",
    "/payments/:path*",
    "/recurring/:path*",
    "/banks/:path*",
    "/fiscal/:path*",
    "/exchange-rates/:path*",
    "/purchase-orders/:path*",
    "/supplier-invoices/:path*",
    "/payables/:path*",
    "/journal/:path*",
    "/ledger/:path*",
    "/financial-statements/:path*",
    "/budgets/:path*",
    "/chart-of-accounts/:path*",
    "/tickets/:path*",
    "/warranty-claims/:path*",
    "/documents/:path*",
    "/credentials/:path*",
    "/reports/:path*",
    "/sla-calendar/:path*",
    "/audit/:path*",
    "/roles/:path*",
    "/users/:path*",
    "/portal-users/:path*",
    "/portal/:path*",
    "/portal",
    "/profile/:path*",
    "/superadmin/:path*",
  ],
};
