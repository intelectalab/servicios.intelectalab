"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { BriefcaseBusiness, FileText, IdCard, Truck, Users } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import Header, { type UserProfile } from "@/components/layout/Header";
import Sidebar, { type MenuItem } from "@/components/layout/Sidebar";

// "module" es el código del catálogo (module_catalog): decide si la empresa lo
// tiene en su plan. "requiredPermission" decide si el rol del usuario lo ve.
const MENU_ITEMS: MenuItem[] = [
  { href: "/customers", label: "Clientes", module: "CLIENTES", requiredPermission: "core.view", icon: Users, superModule: "CORE" },
  { href: "/suppliers", label: "Proveedores", module: "PROVEEDORES", requiredPermission: "core.view", icon: Truck, superModule: "CORE" },
  { href: "/employees", label: "Empleados", module: "EMPLEADOS", requiredPermission: "core.view", icon: IdCard, superModule: "CORE" },
  { href: "/services", label: "Servicios", module: "SERVICIOS", requiredPermission: "services.view", icon: BriefcaseBusiness, superModule: "OPERACIONES" },
  { href: "/quotations", label: "Cotizaciones", module: "COTIZACIONES", requiredPermission: "quotations.view", icon: FileText, superModule: "VENTAS" },
];
const SIDEBAR_STATE_KEY = "erp-sidebar-open";

type AccessResponse = { modules: string[]; permissions: string[] };

export default function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const sidebarPreferenceLoaded = useRef(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [modules, setModules] = useState<string[]>([]);
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
            role: "Administrador",
          });
        }

        const access = await apiFetch<AccessResponse>("/v1/me/access");
        if (!cancelled) {
          setModules(access.modules);
          setPermissions(access.permissions);
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
  const menuWithLockState = MENU_ITEMS.map((item) => ({
    ...item,
    locked: !modules.includes(item.module),
  })).filter((item) => item.locked || permissions.includes(item.requiredPermission));

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
            />
          </aside>
        </div>
      )}

      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <Header
          open={sidebarOpen || mobileOpen}
          currentSection={MENU_ITEMS.find((item) => pathname?.startsWith(item.href))?.label ?? "Inicio"}
          onToggle={() => {
            setSidebarOpen((value) => !value);
            setMobileOpen((value) => !value);
          }}
          user={currentUser}
          onLogout={handleLogout}
        />

        <main className="min-h-0 flex-1 overflow-y-auto p-6 md:p-10">{children}</main>
      </div>
    </div>
  );
}