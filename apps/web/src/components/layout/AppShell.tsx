"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import {
  Bell, BookOpen, BriefcaseBusiness, Calendar, CalendarClock, CalendarDays, ChartColumn, ChartLine, ChartPie,
  Clock, Coins, Contact, Crosshair, Eye, FileSignature, FileText, Filter, Folder, Globe, HandCoins, Home, Key,
  KeyRound, Landmark, Layers, LifeBuoy, ListChecks, ListTree, Lock, Package, Plug, Receipt, ReceiptText, Repeat,
  Scale, Send, Settings, Shield, ShieldCheck, ShoppingCart, Smartphone, SquareKanban, Target, ToggleLeft,
  TrendingUp, Upload, UserCog, UserRound, Wallet, Workflow, Wrench, X, Zap,
} from "lucide-react";
import { apiFetch } from "@/lib/api";
import { PROFILE_NAME_EVENT, clearAuthToken } from "@/lib/auth";
import { AccessProvider, type Scope } from "@/lib/access-context";
import { brandVars, loadBranding, subscribeToBranding, type Branding } from "@/lib/branding";
import Header, { type UserProfile } from "@/components/layout/Header";
import Sidebar, { type BuiltMenuItem, type MenuItem } from "@/components/layout/Sidebar";
import { Card, LinkBtn, LoadError, Screen } from "@/components/ui/proto";
import { shortDate } from "@/lib/commercial";
import { RENEWAL_WARNING_DAYS, daysUntil, expiryText } from "@/lib/subscription";

// Menú con los grupos y el orden del prototipo.
//  - Ítems con "href": pantallas ya construidas. "module" es el código del catálogo
//    (module_catalog) y decide si la empresa lo tiene en su plan; "requiredPermission"
//    decide si el rol del usuario lo ve.
//  - Ítems con "soon": pantallas del prototipo que aún no existen. Al construir una,
//    se reemplaza "soon" por su href, module y requiredPermission.
const MENU_ITEMS: MenuItem[] = [
  // Sin permiso propio: cada bloque del panel se muestra según los permisos de la persona.
  { href: "/dashboard", label: "Panel de control", requiredPermission: "", icon: Home, superModule: "Inicio" },

  { href: "/crm/leads", label: "Leads", module: "CRM", requiredPermission: "leads.view", icon: Target, superModule: "CRM" },
  { href: "/crm/opportunities", label: "Pipeline", module: "CRM", requiredPermission: "opportunities.view", icon: Filter, superModule: "CRM" },
  { href: "/crm/activities", label: "Actividades", module: "CRM", requiredPermission: "activities.view", icon: CalendarDays, superModule: "CRM" },
  { href: "/crm/campaigns", label: "Campañas", module: "CRM", requiredPermission: "campaigns.view", icon: Send, superModule: "CRM" },
  { href: "/crm/scoring", label: "Puntuación de leads", module: "CRM", requiredPermission: "leads.view", icon: ChartColumn, superModule: "CRM" },

  // Clientes, Proveedores y Empleados están desconectados (2026-10-08): sus pantallas siguen
  // en el repo, pero no salen en el menú y sus rutas llevan a Clientes y actores (proxy.ts).
  { href: "/actors", label: "Clientes y actores", module: "CLIENTES", requiredPermission: "core.view", icon: Contact, superModule: "Comercial" },
  { href: "/services", label: "Catálogo de servicios", module: "SERVICIOS", requiredPermission: "services.view", icon: BriefcaseBusiness, superModule: "Comercial" },
  { href: "/quotations", label: "Cotizaciones", module: "COTIZACIONES", requiredPermission: "quotations.view", icon: FileText, superModule: "Comercial" },
  { href: "/contracts", label: "Contratos", module: "CONTRATOS", requiredPermission: "contracts.view", icon: FileSignature, superModule: "Comercial" },
  { href: "/orders", label: "Órdenes", module: "CONTRATOS", requiredPermission: "orders.view", icon: Package, superModule: "Comercial" },
  { href: "/portal-requests", label: "Solicitudes del portal", module: "COTIZACIONES", requiredPermission: "portal.view", icon: Globe, superModule: "Comercial" },

  { href: "/contracted-services", label: "Servicios contratados", module: "ORDENES_TRABAJO", requiredPermission: "contracted_services.view", icon: Layers, superModule: "Operaciones" },
  { href: "/work-orders", label: "Órdenes de trabajo", module: "ORDENES_TRABAJO", requiredPermission: "work_orders.view", icon: Wrench, superModule: "Operaciones" },
  // No está en el prototipo (solo trae plantillas de ejemplo): la pidió ella el 2026-10-14.
  { href: "/checklist-templates", label: "Plantillas de checklist", module: "ORDENES_TRABAJO", requiredPermission: "checklists.view", icon: ListChecks, superModule: "Operaciones" },
  { href: "/projects", label: "Proyectos", module: "PROYECTOS", requiredPermission: "projects.view", icon: SquareKanban, superModule: "Operaciones" },
  { href: "/timesheets", label: "Horas y costos", module: "TIMESHEETS", requiredPermission: "time_entries.view", icon: Clock, superModule: "Operaciones" },
  { href: "/agenda", label: "Agenda", module: "AGENDA", requiredPermission: "agenda.view", icon: Calendar, superModule: "Operaciones" },
  { href: "/campo", module: "ORDENES_TRABAJO", requiredPermission: "work_orders.view", label: "App del técnico", icon: Smartphone, superModule: "Operaciones" },

  { href: "/billable-events", label: "Por facturar", module: "FINANZAS", requiredPermission: "billing.view", icon: Zap, superModule: "Finanzas" },
  { href: "/invoices", label: "Comprobantes", module: "FINANZAS", requiredPermission: "billing.view", icon: Receipt, superModule: "Finanzas" },
  { href: "/receivables", label: "Cuentas por cobrar", module: "FINANZAS", requiredPermission: "receivables.view", icon: Coins, superModule: "Finanzas" },
  { href: "/payments", label: "Cobros", module: "FINANZAS", requiredPermission: "payments.view", icon: Wallet, superModule: "Finanzas" },
  { href: "/recurring", label: "Recurrencia", module: "FINANZAS", requiredPermission: "recurring.view", icon: Repeat, superModule: "Finanzas" },
  { href: "/banks", label: "Bancos y caja", module: "FINANZAS", requiredPermission: "treasury.view", icon: Landmark, superModule: "Finanzas" },
  { href: "/fiscal", label: "Facturación electrónica", module: "FINANZAS", requiredPermission: "fiscal.view", icon: ShieldCheck, superModule: "Finanzas" },
  { href: "/exchange-rates", label: "Monedas y tipo de cambio", module: "FINANZAS", requiredPermission: "fiscal.view", icon: TrendingUp, superModule: "Finanzas" },

  { href: "/purchase-orders", label: "Órdenes de compra", module: "COMPRAS", requiredPermission: "purchases.view", icon: ShoppingCart, superModule: "Compras" },
  { href: "/supplier-invoices", label: "Comprobantes de proveedor", module: "COMPRAS", requiredPermission: "payables.view", icon: ReceiptText, superModule: "Compras" },
  { href: "/payables", label: "Cuentas por pagar", module: "COMPRAS", requiredPermission: "payables.view", icon: HandCoins, superModule: "Compras" },

  { href: "/journal", label: "Libro diario", module: "CONTABILIDAD", requiredPermission: "accounting.view", icon: BookOpen, superModule: "Contabilidad" },
  { href: "/ledger", label: "Mayor y balance", module: "CONTABILIDAD", requiredPermission: "accounting.view", icon: Scale, superModule: "Contabilidad" },
  { href: "/financial-statements", label: "Estados financieros", module: "CONTABILIDAD", requiredPermission: "accounting.view", icon: ChartPie, superModule: "Contabilidad" },
  { href: "/budgets", label: "Centros de costo y presupuestos", module: "CONTABILIDAD", requiredPermission: "budgets.view", icon: Crosshair, superModule: "Contabilidad" },
  { href: "/chart-of-accounts", label: "Plan de cuentas y periodos", module: "CONTABILIDAD", requiredPermission: "accounting.view", icon: ListTree, superModule: "Contabilidad" },

  { href: "/tickets", label: "Tickets y SLA", module: "SOPORTE", requiredPermission: "tickets.view", icon: LifeBuoy, superModule: "Soporte" },
  { href: "/warranty-claims", label: "Garantías", module: "SOPORTE", requiredPermission: "warranty.view", icon: Shield, superModule: "Soporte" },

  { href: "/documents", label: "Documentos", module: "DOCUMENTOS", requiredPermission: "documents.view", icon: Folder, superModule: "Control" },
  { href: "/credentials", label: "Credential Vault", requiredPermission: "credentials.view", icon: KeyRound, superModule: "Control" },
  { label: "Integraciones", icon: Plug, superModule: "Control", soon: true },
  { href: "/workflows", label: "Workflows", requiredPermission: "workflows.view", icon: Workflow, superModule: "Control" },
  { href: "/reports", label: "Reportes", requiredPermission: "reports.view", icon: ChartLine, superModule: "Control" },
  { href: "/audit", label: "Auditoría", requiredPermission: "audit.view", icon: Eye, superModule: "Control" },

  { href: "/settings", label: "Configuración", requiredPermission: "tenant.manage", icon: Settings, superModule: "Administración" },
  { label: "Módulos y plan", icon: ToggleLeft, superModule: "Administración", soon: true },
  { href: "/users", label: "Usuarios", requiredPermission: "users.view", icon: UserCog, superModule: "Administración" },
  { href: "/roles", label: "Roles y permisos", requiredPermission: "users.view", icon: Key, superModule: "Administración" },
  { label: "Seguridad y aislamiento", icon: Lock, superModule: "Administración", soon: true },
  { href: "/portal-users", label: "Usuarios del portal", module: "PORTAL", requiredPermission: "portal_users.view", icon: UserRound, superModule: "Administración" },
  { label: "Reglas de aprobación", icon: ListChecks, superModule: "Administración", soon: true },
  // Del módulo Soporte: sin él, el calendario no se usa (sale con el candado del plan).
  { href: "/sla-calendar", label: "Calendario laboral y SLA", module: "SOPORTE", requiredPermission: "sla.view", icon: CalendarClock, superModule: "Administración" },
  { label: "Notificaciones", icon: Bell, superModule: "Administración", soon: true },
  { label: "Importación masiva", icon: Upload, superModule: "Administración", soon: true },
];
// Ícono de cada grupo del menú, como en el prototipo.
const GROUP_ICONS = {
  Inicio: Home,
  CRM: Target,
  Comercial: BriefcaseBusiness,
  Operaciones: Wrench,
  Finanzas: Coins,
  Compras: ShoppingCart,
  Contabilidad: BookOpen,
  Soporte: LifeBuoy,
  Control: ShieldCheck,
  Administración: Settings,
};

const SIDEBAR_STATE_KEY = "erp-sidebar-open";

// Pantalla a la que se llega al iniciar sesión (la misma de proxy.ts): el panel, que no pide permiso.
const HOME = { href: "/dashboard", permission: "" };

/** Quien ejecuta órdenes de trabajo sin planificarlas ni asignarlas: al entrar va a la App del técnico. */
const isTechnician = (permissions: string[], modules: string[]) =>
  modules.includes("ORDENES_TRABAJO") && permissions.includes("work_orders.manage") && !permissions.includes("work_orders.approve");

type AccessSubscription = {
  id: string;
  plan_id: string;
  plan_name: string;
  plan_code: string;
  starts_at: string;
  ends_at: string | null;
  is_trial: boolean;
};

type AccessResponse = {
  modules: string[];
  permissions: string[];
  scopes?: Record<string, Scope>;
  tenant: { name: string; code: string } | null;
  roles: string[];
  subscription?: AccessSubscription | null;
};

export default function AppShell({ children, plansUrl = "/#plans" }: { children: ReactNode; plansUrl?: string }) {
  const pathname = usePathname();
  const router = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const sidebarPreferenceLoaded = useRef(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [scopes, setScopes] = useState<Record<string, Scope>>({});
  const [modules, setModules] = useState<string[]>([]);
  const [tenantName, setTenantName] = useState<string | undefined>(undefined);
  const [roleName, setRoleName] = useState<string | undefined>(undefined);
  const [permissionsLoading, setPermissionsLoading] = useState(true);
  const [accessLoaded, setAccessLoaded] = useState(false);
  const [accessFailed, setAccessFailed] = useState(false);
  const [accessAttempt, setAccessAttempt] = useState(0);
  const [currentUser, setCurrentUser] = useState<UserProfile | null>(null);
  const [subscription, setSubscription] = useState<AccessSubscription | null>(null);
  const [bannerDismissed, setBannerDismissed] = useState(false);

  useEffect(() => {
    const frameId = window.requestAnimationFrame(() => {
      const stored = window.localStorage.getItem(SIDEBAR_STATE_KEY);
      if (stored !== null) setSidebarOpen(stored === "true");
      sidebarPreferenceLoaded.current = true;
    });

    return () => window.cancelAnimationFrame(frameId);
  }, []);

  useEffect(() => {
    if (!sidebarPreferenceLoaded.current) return;
    window.localStorage.setItem(SIDEBAR_STATE_KEY, String(sidebarOpen));
  }, [sidebarOpen]);

  useEffect(() => {
    let cancelled = false;

    async function loadUserDataAndPermissions() {
      setPermissionsLoading(true);
      setAccessFailed(false);
      try {
        const userResponse = await apiFetch<{ name: string; email: string }>("/v1/me");
        if (!cancelled && userResponse) {
          setCurrentUser({
            name: userResponse.name,
            email: userResponse.email,
            role: "Usuario",
          });
        }

        const access = await apiFetch<AccessResponse>("/v1/me/access");
        if (!cancelled) {
          setModules(access.modules);
          setPermissions(access.permissions);
          setScopes(access.scopes ?? {});
          setTenantName(access.tenant?.name);
          setSubscription(access.subscription ?? null);
          setRoleName(access.roles[0]);
          setAccessLoaded(true);
          // Al entrar al sistema, quien solo ejecuta órdenes de trabajo va a su app; si después
          // abre el panel desde el menú, se queda en él (el armazón ya está montado).
          if (window.location.pathname === HOME.href && isTechnician(access.permissions, access.modules)) router.replace("/campo");
        }
      } catch (err) {
        console.error("Error al cargar datos de sesión", err);
        // Sin la sesión no se sabe qué puede abrir: se ofrece reintentar en vez de quedarse cargando.
        if (!cancelled) setAccessFailed(true);
      } finally {
        if (!cancelled) setPermissionsLoading(false);
      }
    }

    loadUserDataAndPermissions();
    // Mi perfil avisa cuando la persona cambia su nombre: la cabecera lo toma sin recargar.
    const renamed = (event: Event) => {
      const name = (event as CustomEvent<string>).detail;
      setCurrentUser((current) => (current ? { ...current, name } : current));
    };
    window.addEventListener(PROFILE_NAME_EVENT, renamed);
    return () => {
      cancelled = true;
      window.removeEventListener(PROFILE_NAME_EVENT, renamed);
    };
  }, [accessAttempt, router]);

  const [brand, setBrand] = useState<Branding | null>(null);
  useEffect(() => {
    if (!accessLoaded) return;
    let cancelled = false;
    loadBranding().then((data) => { if (!cancelled) setBrand(data); });
    const unsubscribe = subscribeToBranding((next) => { if (!cancelled) setBrand(next); });
    return () => {
      cancelled = true;
      unsubscribe();
    };
  }, [accessLoaded]);

  // La barra lateral y el sistema usan el color principal de la empresa (si personalizó su marca).
  useEffect(() => {
    if (!brand?.customized) return;
    const vars = brandVars(brand.primary_color);
    const body = document.body;
    body.classList.add("tenant-brand");
    Object.entries(vars).forEach(([name, value]) => body.style.setProperty(name, value));
    return () => {
      body.classList.remove("tenant-brand");
      Object.keys(vars).forEach((name) => body.style.removeProperty(name));
    };
  }, [brand]);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await apiFetch("/v1/logout", { method: "POST" });
    } catch {
      // Si falla la llamada, igual limpiamos la sesión localmente.
    } finally {
      clearAuthToken();
      router.push("/login");
    }
  }

  // Dos preguntas distintas por ítem:
  //  - la empresa no tiene el módulo en su plan → se muestra con candado y aviso de plan;
  //  - lo tiene, pero el rol del usuario no lo incluye → se oculta.
  const menuWithLockState = MENU_ITEMS.map((item) =>
    // Sin "module" la pantalla es de administración de la empresa: no depende del plan.
    item.soon ? item : { ...item, locked: item.module !== undefined && !modules.includes(item.module) },
  ).filter((item) => item.soon || item.locked || !item.requiredPermission || permissions.includes(item.requiredPermission));

  // Un rol que no abre la pantalla de inicio (p. ej. un técnico, sin Clientes y actores) no se
  // queda ante un listado que no carga: va a la primera pantalla que sí puede abrir.
  const firstScreen = menuWithLockState.find((item) => !item.soon && !item.locked);
  const homeDenied = accessLoaded && pathname === HOME.href && Boolean(HOME.permission) && !permissions.includes(HOME.permission);
  const fallback = firstScreen && !firstScreen.soon ? firstScreen.href : "/profile";

  useEffect(() => {
    if (homeDenied) router.replace(fallback);
  }, [homeDenied, fallback, router]);

  // Una pantalla abierta por su dirección sin permiso (o fuera del plan) no se monta: sus
  // lecturas fallarían con "No se pudo cargar…". Se dice por qué y adónde ir.
  const routeItem = MENU_ITEMS.find(
    (item): item is BuiltMenuItem => !item.soon && (pathname === item.href || Boolean(pathname?.startsWith(`${item.href}/`))),
  );
  const routeDenied = accessLoaded && routeItem && !homeDenied
    ? routeItem.module !== undefined && !modules.includes(routeItem.module)
      ? "plan"
      : routeItem.requiredPermission && !permissions.includes(routeItem.requiredPermission) ? "role" : null
    : null;

  // El rol real llega con /me/access; mientras tanto se muestra uno genérico.
  const sessionUser = currentUser ? { ...currentUser, role: roleName ?? currentUser.role } : null;

  // Pantallas a las que el usuario puede entrar, para el buscador global.
  const searchScreens = [
    ...menuWithLockState.flatMap((item) =>
      item.soon || item.locked ? [] : [{ label: item.label, href: item.href, group: item.superModule }],
    ),
    { label: "Mi perfil", href: "/profile", group: "Cuenta" },
    { label: "Notificaciones", href: "/notifications", group: "Cuenta" },
  ];

  const currentItem = MENU_ITEMS.find((item) => !item.soon && pathname?.startsWith(item.href));
  const currentSection = currentItem?.label ?? (pathname?.startsWith("/profile") ? "Mi perfil" : pathname?.startsWith("/notifications") ? "Notificaciones" : "Inicio");
  const pageTitle = tenantName ? `${currentSection} · ${tenantName}` : `${currentSection} · ERP SaaS`;

  useEffect(() => {
    document.title = pageTitle;
  }, [pageTitle, pathname]);

  // Días de vigencia para el aviso de renovación: solo a quien administra la empresa (Configuración).
  const renewalDays = !bannerDismissed && subscription?.ends_at && permissions.includes("tenant.manage")
    ? daysUntil(subscription.ends_at)
    : null;
  const showRenewal = renewalDays !== null && renewalDays <= RENEWAL_WARNING_DAYS;

  return (
    <div className="app-page flex h-screen min-h-0 overflow-hidden">
      <title>{pageTitle}</title>
      {/* Sidebar desktop */}
      <aside
        className={`app-surface app-border hidden h-full min-h-0 shrink-0 overflow-visible border-r transition-[width] duration-300 md:block ${
          sidebarOpen ? "w-64" : "w-16"
        }`}
      >
        <Sidebar
          items={menuWithLockState}
          plansUrl={plansUrl}
          compact={sidebarOpen === false}
          permissionsLoading={permissionsLoading}
          onNavigate={() => setMobileOpen(false)}
          onLogout={handleLogout}
          loggingOut={loggingOut}
          groupIcons={GROUP_ICONS}
          user={sessionUser}
          tenantName={tenantName}
          tenantLogo={brand?.logo}
          tenantCustomized={brand?.customized}
        />
      </aside>
      {/* Sidebar mobile (drawer) */}
      {mobileOpen && (
        <div className="fixed inset-0 z-[200] md:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="app-surface absolute inset-y-0 left-0 w-64 shadow-xl">
            <Sidebar
              items={menuWithLockState}
              plansUrl={plansUrl}
              compact={false}
              permissionsLoading={permissionsLoading}
              onNavigate={() => setMobileOpen(false)}
              onLogout={handleLogout}
              loggingOut={loggingOut}
              groupIcons={GROUP_ICONS}
              user={sessionUser}
              tenantName={tenantName}
              tenantLogo={brand?.logo}
              tenantCustomized={brand?.customized}
            />
          </aside>
        </div>
      )}

      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <Header
          open={sidebarOpen || mobileOpen}
          currentSection={currentSection}
          currentGroup={currentItem?.superModule ?? (pathname?.startsWith("/profile") || pathname?.startsWith("/notifications") ? "Cuenta" : undefined)}
          onToggle={() => {
            setSidebarOpen((value) => !value);
            setMobileOpen((value) => !value);
          }}
          user={sessionUser}
          onLogout={handleLogout}
          tenantName={tenantName}
          screens={searchScreens}
          canSearchRecords={permissions.includes("core.view")}
          notifications
        />

        {/* Vigencia: a quien administra la empresa, cuando faltan 7 días o menos (o ya venció). Colores de aviso del sistema. */}
        {showRenewal && renewalDays !== null && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[color:var(--p-notice-line)] bg-[var(--p-notice-bg)] px-4 py-2 text-xs text-[color:var(--p-notice-text)]">
            <div className="flex items-center gap-2">
              <span className="rounded bg-[var(--p-warning-soft)] px-1.5 py-0.5 text-[10.5px] font-semibold text-[color:var(--p-warning-text)]">
                {subscription?.is_trial ? "Periodo de prueba" : "Aviso de suscripción"}
              </span>
              <span>
                Tu periodo {subscription?.is_trial ? "de prueba" : "de suscripción"}{" "}
                <strong className="font-semibold">{expiryText(renewalDays)}</strong>{" "}
                ({shortDate(subscription?.ends_at ?? null)}).
              </span>
            </div>
            <div className="flex items-center gap-2">
              <Link
                href="/profile"
                className="rounded-md border border-[color:var(--p-notice-line)] bg-[var(--app-surface)] px-2.5 py-1 text-xs font-medium text-[color:var(--p-notice-text)] transition hover:bg-[var(--app-surface-muted)]"
              >
                Extender plan
              </Link>
              <button
                type="button"
                onClick={() => setBannerDismissed(true)}
                className="cursor-pointer rounded p-1 transition hover:bg-[var(--p-warning-soft)]"
                aria-label="Cerrar aviso"
              >
                <X size={14} />
              </button>
            </div>
          </div>
        )}

        <main className="min-h-0 flex-1 overflow-y-auto p-6 md:p-10">
          <AccessProvider value={{ permissions, scopes, loading: permissionsLoading }}>
            {accessFailed ? (
              <Screen>
                <Card>
                  <LoadError text="No se pudo cargar tu sesión: el servidor no respondió." onRetry={() => setAccessAttempt((n) => n + 1)} />
                </Card>
              </Screen>
            ) : homeDenied ? null : routeDenied && routeItem ? (
              <Screen>
                <Card>
                  <div className="flex flex-col items-center px-4 py-10 text-center">
                    <Lock size={28} className="app-muted mb-2" aria-hidden="true" />
                    <p className="font-semibold">{routeItem.label}</p>
                    <p className="app-muted mt-1 max-w-[52ch]">
                      {routeDenied === "plan"
                        ? "Esta pantalla no está incluida en el plan de tu empresa."
                        : "Tu rol no tiene permiso para abrir esta pantalla. Si la necesitas, pídeselo al administrador de tu empresa."}
                    </p>
                    <div className="mt-3">
                      <LinkBtn href={fallback} variant="secondary" size="sm">
                        Ir a {firstScreen && !firstScreen.soon ? firstScreen.label : "Mi perfil"}
                      </LinkBtn>
                    </div>
                  </div>
                </Card>
              </Screen>
            ) : children}
          </AccessProvider>
        </main>
      </div>
    </div>
  );
}