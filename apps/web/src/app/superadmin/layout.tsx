"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { LayoutDashboard, CreditCard, Receipt, Building2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import SuperAdminSidebar, { type AdminMenuItem } from "@/components/superadmin/SuperAdminSidebar";
import SuperAdminHeader from "@/components/superadmin/SuperAdminHeader";
import { type UserProfile } from "@/components/layout/Header";

const ADMIN_MENU_ITEMS: AdminMenuItem[] = [
  { href: "/superadmin/dashboard", label: "Dashboard", icon: LayoutDashboard, superModule: "Administración SaaS" },
  { href: "/superadmin/plans", label: "Planes SaaS", icon: CreditCard, superModule: "Administración SaaS" },
  { href: "/superadmin/subscriptions", label: "Suscripciones", icon: Receipt, superModule: "Administración SaaS" },
  { href: "/superadmin/tenants", label: "Tenants / Empresas", icon: Building2, superModule: "Administración SaaS" },
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
        const userResponse = await apiFetch<any>("/v1/me");
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

  const sectionTitles: Record<string, string> = {
    "/superadmin/dashboard": "Dashboard",
    "/superadmin/plans": "Planes SaaS",
    "/superadmin/subscriptions": "Suscripciones",
    "/superadmin/tenants": "Tenants / Empresas",
  };
  const currentSection = sectionTitles[pathname ?? ""] ?? "Administración Global";

  return (
    <div className="app-page flex h-screen min-h-0 overflow-hidden">
      {/* Sidebar desktop */}
      <aside
        className={`app-surface app-border hidden h-full min-h-0 shrink-0 overflow-visible border-r transition-[width] duration-300 md:block ${
          sidebarOpen ? "w-64" : "w-16"
        }`}
      >
        <SuperAdminSidebar
          compact={!sidebarOpen}
          onLogout={handleLogout}
          loggingOut={loggingOut}
        />
      </aside>

      {/* Sidebar mobile (drawer) */}
      {mobileOpen && (
        <div className="fixed inset-0 z-[200] md:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="app-surface absolute inset-y-0 left-0 w-64 shadow-xl">
            <SuperAdminSidebar
              compact={false}
              onLogout={handleLogout}
              loggingOut={loggingOut}
            />
          </aside>
        </div>
      )}

      {/* Contenedor Principal */}
      <div className="flex min-h-0 min-w-0 flex-1 flex-col">
        <SuperAdminHeader
          open={sidebarOpen || mobileOpen}
          currentSection={currentSection}
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