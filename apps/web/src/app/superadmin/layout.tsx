"use client";

import { useState, useEffect, useRef, ReactNode } from "react";
import { useRouter, usePathname } from "next/navigation";
import SuperAdminSidebar from "@/components/superadmin/SuperAdminSidebar";
import SuperAdminHeader from "@/components/superadmin/SuperAdminHeader";
import { apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";

const SIDEBAR_STATE_KEY = "erp-superadmin-sidebar-open";

export default function SuperAdminLayout({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const sidebarPreferenceLoaded = useRef(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [currentUser, setCurrentUser] = useState<any>(null);

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
    async function loadUser() {
      try {
        const userResponse = await apiFetch<any>("/v1/me");
        if (userResponse) {
          setCurrentUser({
            name: userResponse.name,
            email: userResponse.email,
            role: "Super Administrador",
          });
        }
      } catch (err) {
        console.error("Error al cargar perfil de superadmin", err);
      }
    }
    loadUser();
  }, []);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await apiFetch("/v1/logout", { method: "POST" });
    } catch {
      // Ignorar error de red
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
  const currentSection = sectionTitles[pathname] ?? "Administración Global";

  return (
    <div className="app-page flex h-screen min-h-0 overflow-hidden">
      {/* 
        AQUÍ ESTABA EL DETALLE: El aside controlaba el ancho global 
        y ahora depende correctamente de la variable `sidebarOpen` 
      */}
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
        <div className="fixed inset-0 z-40 md:hidden">
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
          }}
          user={currentUser}
          onLogout={handleLogout}
        />

        <main className="min-h-0 flex-1 overflow-y-auto">{children}</main>
      </div>
    </div>
  );
}