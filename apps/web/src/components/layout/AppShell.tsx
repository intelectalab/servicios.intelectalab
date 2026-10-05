"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import {
  Bell, BookOpen, BriefcaseBusiness, Calendar, CalendarClock, CalendarDays, ChartColumn, ChartLine, ChartPie,
  Clock, Coins, Crosshair, Eye, FileSignature, FileText, Filter, Folder, Globe, HandCoins, Home, IdCard, Key,
  KeyRound, Landmark, Layers, LifeBuoy, ListChecks, ListTree, Lock, Package, Plug, Receipt, ReceiptText, Repeat,
  Scale, Send, Settings, Shield, ShieldCheck, ShoppingCart, Smartphone, SquareKanban, Target, ToggleLeft,
  TrendingUp, Truck, Upload, UserCog, UserRound, Users, Wallet, Workflow, Wrench, Zap,
} from "lucide-react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
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

  { label: "Leads", icon: Target, superModule: "CRM", soon: true },
  { label: "Pipeline", icon: Filter, superModule: "CRM", soon: true },
  { label: "Actividades", icon: CalendarDays, superModule: "CRM", soon: true },
  { label: "Campañas", icon: Send, superModule: "CRM", soon: true },
  { label: "Puntuación de leads", icon: ChartColumn, superModule: "CRM", soon: true },

  { href: "/customers", label: "Clientes", module: "CLIENTES", requiredPermission: "core.view", icon: Users, superModule: "Comercial" },
  { href: "/suppliers", label: "Proveedores", module: "PROVEEDORES", requiredPermission: "core.view", icon: Truck, superModule: "Comercial" },
  { href: "/employees", label: "Empleados", module: "EMPLEADOS", requiredPermission: "core.view", icon: IdCard, superModule: "Comercial" },
  { href: "/services", label: "Servicios", module: "SERVICIOS", requiredPermission: "services.view", icon: BriefcaseBusiness, superModule: "Comercial" },
  { href: "/quotations", label: "Cotizaciones", module: "COTIZACIONES", requiredPermission: "quotations.view", icon: FileText, superModule: "Comercial" },
  { label: "Contratos", icon: FileSignature, superModule: "Comercial", soon: true },
  { label: "Órdenes", icon: Package, superModule: "Comercial", soon: true },
  { label: "Solicitudes del portal", icon: Globe, superModule: "Comercial", soon: true },

  { label: "Servicios contratados", icon: Layers, superModule: "Operaciones", soon: true },
  { label: "Órdenes de trabajo", icon: Wrench, superModule: "Operaciones", soon: true },
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
  { label: "Monedas y tipo de cambio", icon: TrendingUp, superModule: "Finanzas", soon: true },

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
  { label: "Auditoría", icon: Eye, superModule: "Control", soon: true },

  { label: "Configuración", icon: Settings, superModule: "Administración", soon: true },
  { label: "Módulos y plan", icon: ToggleLeft, superModule: "Administración", soon: true },
  { label: "Usuarios", icon: UserCog, superModule: "Administración", soon: true },
  { label: "Roles y permisos", icon: Key, superModule: "Administración", soon: true },
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

type AccessResponse = {
  modules: string[];
  permissions: string[];
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
  const [modules, setModules] = useState<string[]>([]);
  const [tenantName, setTenantName] = useState<string | undefined>(undefined);
  const [roleName, setRoleName] = useState<string | undefined>(undefined);
  const [permissionsLoading, setPermissionsLoading] = useState(true);
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
          setTenantName(access.tenant?.name);
          setRoleName(access.roles[0]);
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
    item.soon ? item : { ...item, locked: !modules.includes(item.module) },
  ).filter((item) => item.soon || item.locked || permissions.includes(item.requiredPermission));

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

        <main className="min-h-0 flex-1 overflow-y-auto p-6 md:p-10">{children}</main>
      </div>
    </div>
  );
}