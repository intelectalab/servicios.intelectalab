"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import {
  Bell, BookOpen, BriefcaseBusiness, Calendar, CalendarClock, CalendarDays, ChartColumn, ChartLine, ChartPie,
  Clock, Coins, Contact, Crosshair, Eye, FileSignature, FileText, Filter, Folder, Globe, HandCoins, Home, Key,
  KeyRound, Landmark, Layers, LifeBuoy, ListChecks, ListTree, Lock, Package, Plug, Receipt, ReceiptText, Repeat,
  Scale, Send, Settings, Shield, ShieldCheck, ShoppingCart, Smartphone, SquareKanban, Target, ToggleLeft,
  TrendingUp, Upload, UserCog, UserRound, Wallet, Workflow, Wrench, Zap,
} from "lucide-react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import { AccessProvider, type Scope } from "@/lib/access-context";
import Header, { type UserProfile } from "@/components/layout/Header";
import Sidebar, { type MenuItem } from "@/components/layout/Sidebar";

// Menú con los grupos y el orden del prototipo.
//  - Ítems con "href": pantallas ya construidas. "module" es el código del catálogo
//    (module_catalog) y decide si la empresa lo tiene en su plan; "requiredPermission"
//    decide si el rol del usuario lo ve.
//  - Ítems con "soon": pantallas del prototipo que aún no existen. Al construir una,
//    se reemplaza "soon" por su href, module y requiredPermission.
const MENU_ITEMS: MenuItem[] = [
  { label: "Panel de control", icon: Home, superModule: "Inicio", soon: true },

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
  { label: "Proyectos", icon: SquareKanban, superModule: "Operaciones", soon: true },
  { label: "Horas y costos", icon: Clock, superModule: "Operaciones", soon: true },
  { label: "Agenda", icon: Calendar, superModule: "Operaciones", soon: true },
  { label: "App del técnico", icon: Smartphone, superModule: "Operaciones", soon: true },

  { label: "Por facturar", icon: Zap, superModule: "Finanzas", soon: true },
  { label: "Comprobantes", icon: Receipt, superModule: "Finanzas", soon: true },
  { label: "Cuentas por cobrar", icon: Coins, superModule: "Finanzas", soon: true },
  { label: "Cobros", icon: Wallet, superModule: "Finanzas", soon: true },
  { label: "Recurrencia", icon: Repeat, superModule: "Finanzas", soon: true },
  { label: "Bancos y caja", icon: Landmark, superModule: "Finanzas", soon: true },
  { label: "Facturación electrónica", icon: ShieldCheck, superModule: "Finanzas", soon: true },
  { href: "/exchange-rates", label: "Monedas y tipo de cambio", module: "FINANZAS", requiredPermission: "billing.view", icon: TrendingUp, superModule: "Finanzas" },

  { label: "Órdenes de compra", icon: ShoppingCart, superModule: "Compras", soon: true },
  { label: "Comprobantes de proveedor", icon: ReceiptText, superModule: "Compras", soon: true },
  { label: "Cuentas por pagar", icon: HandCoins, superModule: "Compras", soon: true },

  { label: "Libro diario", icon: BookOpen, superModule: "Contabilidad", soon: true },
  { label: "Mayor y balance", icon: Scale, superModule: "Contabilidad", soon: true },
  { label: "Estados financieros", icon: ChartPie, superModule: "Contabilidad", soon: true },
  { label: "Centros de costo y presupuestos", icon: Crosshair, superModule: "Contabilidad", soon: true },
  { label: "Plan de cuentas y periodos", icon: ListTree, superModule: "Contabilidad", soon: true },

  { label: "Tickets y SLA", icon: LifeBuoy, superModule: "Soporte", soon: true },
  { label: "Garantías", icon: Shield, superModule: "Soporte", soon: true },

  { label: "Documentos", icon: Folder, superModule: "Control", soon: true },
  { label: "Credential Vault", icon: KeyRound, superModule: "Control", soon: true },
  { label: "Integraciones", icon: Plug, superModule: "Control", soon: true },
  { label: "Workflows", icon: Workflow, superModule: "Control", soon: true },
  { label: "Reportes", icon: ChartLine, superModule: "Control", soon: true },
  { href: "/audit", label: "Auditoría", requiredPermission: "audit.view", icon: Eye, superModule: "Control" },

  { label: "Configuración", icon: Settings, superModule: "Administración", soon: true },
  { label: "Módulos y plan", icon: ToggleLeft, superModule: "Administración", soon: true },
  { href: "/users", label: "Usuarios", requiredPermission: "users.view", icon: UserCog, superModule: "Administración" },
  { href: "/roles", label: "Roles y permisos", requiredPermission: "users.view", icon: Key, superModule: "Administración" },
  { label: "Seguridad y aislamiento", icon: Lock, superModule: "Administración", soon: true },
  { label: "Usuarios del portal", icon: UserRound, superModule: "Administración", soon: true },
  { label: "Reglas de aprobación", icon: ListChecks, superModule: "Administración", soon: true },
  { label: "Calendario laboral y SLA", icon: CalendarClock, superModule: "Administración", soon: true },
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

// Pantalla a la que se llega al iniciar sesión (la misma de proxy.ts) y el permiso que pide.
const HOME = { href: "/actors", permission: "core.view" };

type AccessResponse = {
  modules: string[];
  permissions: string[];
  scopes?: Record<string, Scope>;
  tenant: { name: string; code: string } | null;
  roles: string[];
};

export default function AppShell({ children }: { children: ReactNode }) {
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
  const [currentUser, setCurrentUser] = useState<UserProfile | null>(null);

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
          setRoleName(access.roles[0]);
          setAccessLoaded(true);
        }
      } catch (err) {
        console.error("Error al cargar datos de sesión", err);
      } finally {
        if (!cancelled) setPermissionsLoading(false);
      }
    }

    loadUserDataAndPermissions();
    return () => {
      cancelled = true;
    };
  }, []);

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
  ).filter((item) => item.soon || item.locked || permissions.includes(item.requiredPermission));

  // Un rol que no abre la pantalla de inicio (p. ej. un técnico, sin Clientes y actores) no se
  // queda ante un listado que no carga: va a la primera pantalla que sí puede abrir.
  const firstScreen = menuWithLockState.find((item) => !item.soon && !item.locked);
  const homeDenied = accessLoaded && pathname === HOME.href && !permissions.includes(HOME.permission);
  const fallback = firstScreen && !firstScreen.soon ? firstScreen.href : "/profile";

  useEffect(() => {
    if (homeDenied) router.replace(fallback);
  }, [homeDenied, fallback, router]);

  // El rol real llega con /me/access; mientras tanto se muestra uno genérico.
  const sessionUser = currentUser ? { ...currentUser, role: roleName ?? currentUser.role } : null;

  // Pantallas a las que el usuario puede entrar, para el buscador global.
  const searchScreens = [
    ...menuWithLockState.flatMap((item) =>
      item.soon || item.locked ? [] : [{ label: item.label, href: item.href, group: item.superModule }],
    ),
    { label: "Mi perfil", href: "/profile", group: "Cuenta" },
  ];

  const currentItem = MENU_ITEMS.find((item) => !item.soon && pathname?.startsWith(item.href));

  return (
    <div className="app-page flex h-screen min-h-0 overflow-hidden">
      {/* Sidebar desktop */}
      <aside
        className={`app-surface app-border hidden h-full min-h-0 shrink-0 overflow-visible border-r transition-[width] duration-300 md:block ${
          sidebarOpen ? "w-64" : "w-16"
        }`}
      >
        <Sidebar
          items={menuWithLockState}
          compact={sidebarOpen === false}
          permissionsLoading={permissionsLoading}
          onNavigate={() => setMobileOpen(false)}
          onLogout={handleLogout}
          loggingOut={loggingOut}
          groupIcons={GROUP_ICONS}
          user={sessionUser}
          tenantName={tenantName}
        />
      </aside>
      {/* Sidebar mobile (drawer) */}
      {mobileOpen && (
        <div className="fixed inset-0 z-[200] md:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="app-surface absolute inset-y-0 left-0 w-64 shadow-xl">
            <Sidebar
              items={menuWithLockState}
              compact={false}
              permissionsLoading={permissionsLoading}
              onNavigate={() => setMobileOpen(false)}
              onLogout={handleLogout}
              loggingOut={loggingOut}
              groupIcons={GROUP_ICONS}
              user={sessionUser}
              tenantName={tenantName}
            />
          </aside>
        </div>
      )}

      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <Header
          open={sidebarOpen || mobileOpen}
          currentSection={currentItem?.label ?? (pathname?.startsWith("/profile") ? "Mi perfil" : "Inicio")}
          currentGroup={currentItem?.superModule ?? (pathname?.startsWith("/profile") ? "Cuenta" : undefined)}
          onToggle={() => {
            setSidebarOpen((value) => !value);
            setMobileOpen((value) => !value);
          }}
          user={sessionUser}
          onLogout={handleLogout}
          tenantName={tenantName}
          screens={searchScreens}
          canSearchRecords={permissions.includes("core.view")}
        />

        <main className="min-h-0 flex-1 overflow-y-auto p-6 md:p-10">
          <AccessProvider value={{ permissions, scopes, loading: permissionsLoading }}>{homeDenied ? null : children}</AccessProvider>
        </main>
      </div>
    </div>
  );
}