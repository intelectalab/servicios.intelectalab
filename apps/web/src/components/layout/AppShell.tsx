"use client";

import { useEffect, useRef, useState, type ReactNode } from "react";
import { usePathname, useRouter } from "next/navigation";
import { BriefcaseBusiness, FileText, Users } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import Header from "@/components/layout/Header";
import Sidebar, { type MenuItem } from "@/components/layout/Sidebar";

const MENU_ITEMS: MenuItem[] = [
  { href: "/customers", label: "Clientes", requiredPermission: "core.view", icon: Users },
  { href: "/services", label: "Servicios", requiredPermission: "services.view", icon: BriefcaseBusiness },
  { href: "/quotations", label: "Cotizaciones", requiredPermission: "quotations.view", icon: FileText },
];
const SIDEBAR_STATE_KEY = "erp-sidebar-open";

type PermissionsResponse = { permissions: string[] };

export default function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const sidebarPreferenceLoaded = useRef(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [permissions, setPermissions] = useState<string[]>([]);
  const [permissionsLoading, setPermissionsLoading] = useState(true);

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

    async function loadPermissions() {
      try {
        const response = await apiFetch<PermissionsResponse>("/v1/me/permissions");
        if (!cancelled) setPermissions(response.permissions);
      } finally {
        if (!cancelled) setPermissionsLoading(false);
      }
    }

    loadPermissions();
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

  const visibleMenu = MENU_ITEMS.filter((item) => permissions.includes(item.requiredPermission));

  return (
    <div className="app-page flex h-screen min-h-0 overflow-hidden">
      {/* Sidebar desktop */}
      <aside
        className={`app-surface app-border hidden h-full min-h-0 shrink-0 overflow-visible border-r transition-[width] duration-300 md:block ${
          sidebarOpen ? "w-64" : "w-16"
        }`}
      >
        <Sidebar
          items={visibleMenu}
          compact={sidebarOpen === false}
          permissionsLoading={permissionsLoading}
          onNavigate={() => setMobileOpen(false)}
          onLogout={handleLogout}
          loggingOut={loggingOut}
        />
      </aside>
      {/* Sidebar mobile (drawer) */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="app-surface absolute inset-y-0 left-0 w-64 shadow-xl">
            <Sidebar
              items={visibleMenu}
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
        />

        <main className="min-h-0 flex-1 overflow-hidden p-6 md:p-10">{children}</main>
      </div>
    </div>
  );
}
