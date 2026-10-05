"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { Building2, CreditCard, LayoutDashboard, Receipt, ShieldCheck } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import Header, { type UserProfile } from "@/components/layout/Header";
import Sidebar, { type BuiltMenuItem } from "@/components/layout/Sidebar";

const GROUP = "Administración SaaS";
const PLATFORM_NAME = "Plataforma SaaS";
const PROFILE_HREF = "/superadmin/profile";

// La consola de plataforma usa la misma barra lateral y cabecera que el ERP.
// Aquí no hay módulos por plan ni permisos por rol (el acceso lo decide
// is_super_admin), así que "module" y "requiredPermission" son solo de relleno.
const ADMIN_MENU_ITEMS: BuiltMenuItem[] = [
  { href: "/superadmin/dashboard", label: "Dashboard", icon: LayoutDashboard, superModule: GROUP, module: "PLATAFORMA", requiredPermission: "platform" },
  { href: "/superadmin/plans", label: "Planes SaaS", icon: CreditCard, superModule: GROUP, module: "PLATAFORMA", requiredPermission: "platform" },
  { href: "/superadmin/subscriptions", label: "Suscripciones", icon: Receipt, superModule: GROUP, module: "PLATAFORMA", requiredPermission: "platform" },
  { href: "/superadmin/tenants", label: "Tenants / Empresas", icon: Building2, superModule: GROUP, module: "PLATAFORMA", requiredPermission: "platform" },
];

const GROUP_ICONS = { [GROUP]: ShieldCheck };

const SEARCH_SCREENS = [
  ...ADMIN_MENU_ITEMS.map((item) => ({ label: item.label, href: item.href, group: item.superModule })),
  { label: "Mi perfil", href: PROFILE_HREF, group: "Cuenta" },
];

const SIDEBAR_STATE_KEY = "erp-superadmin-sidebar-open";

export default function SuperAdminLayout({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const sidebarPreferenceLoaded = useRef(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
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

    async function loadUserData() {
      try {
        const userResponse = await apiFetch<{ name: string; email: string }>("/v1/me");
        if (!cancelled && userResponse) {
          setCurrentUser({
            name: userResponse.name,
            email: userResponse.email,
            role: "Super Administrador",
          });
        }
      } catch (err) {
        console.error("Error al cargar datos de sesión de superadmin", err);
      }
    }

    loadUserData();
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await apiFetch("/v1/logout", { method: "POST" });
    } catch {
      // Ignorar error de red y limpiar localmente
    } finally {
      clearAuthToken();
      router.push("/login");
    }
  }

  const currentItem = ADMIN_MENU_ITEMS.find((item) => pathname?.startsWith(item.href));
  const onProfile = Boolean(pathname?.startsWith(PROFILE_HREF));
  const currentSection = currentItem?.label ?? (onProfile ? "Mi perfil" : "Administración Global");
  const currentGroup = currentItem?.superModule ?? (onProfile ? "Cuenta" : undefined);

  const sidebar = (compact: boolean) => (
    <Sidebar
      items={ADMIN_MENU_ITEMS}
      compact={compact}
      permissionsLoading={false}
      onNavigate={() => setMobileOpen(false)}
      onLogout={handleLogout}
      loggingOut={loggingOut}
      groupIcons={GROUP_ICONS}
      user={currentUser}
      tenantName={PLATFORM_NAME}
      profileHref={PROFILE_HREF}
    />
  );

  return (
    <div className="app-page flex h-screen min-h-0 overflow-hidden">
      {/* Sidebar desktop */}
      <aside
        className={`app-surface app-border hidden h-full min-h-0 shrink-0 overflow-visible border-r transition-[width] duration-300 md:block ${
          sidebarOpen ? "w-64" : "w-16"
        }`}
      >
        {sidebar(!sidebarOpen)}
      </aside>

      {/* Sidebar mobile (drawer) */}
      {mobileOpen && (
        <div className="fixed inset-0 z-[200] md:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="app-surface absolute inset-y-0 left-0 w-64 shadow-xl">{sidebar(false)}</aside>
        </div>
      )}

      {/* Contenedor Principal */}
      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <Header
          open={sidebarOpen || mobileOpen}
          currentSection={currentSection}
          currentGroup={currentGroup}
          onToggle={() => {
            setSidebarOpen((value) => !value);
            setMobileOpen((value) => !value);
          }}
          user={currentUser}
          onLogout={handleLogout}
          tenantName={PLATFORM_NAME}
          screens={SEARCH_SCREENS}
          profileHref={PROFILE_HREF}
        />

        <main className="min-h-0 flex-1 overflow-y-auto p-6 md:p-10">{children}</main>
      </div>
    </div>
  );
}
