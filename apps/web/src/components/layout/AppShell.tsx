"use client";

import { useState, type ReactNode } from "react";
import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { LayoutDashboard, Users, ShieldCheck, LogOut, Menu, X } from "lucide-react";
import { apiFetch } from "@/lib/api";

const NAVY = "#002347";
const TEAL = "#80CED7";

const NAV_ITEMS = [
  { href: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
  { href: "/customers", label: "Clientes", icon: Users },
  { href: "/permissions", label: "Permisos", icon: ShieldCheck },
];

function Logo() {
  return (
    <div className="relative h-9 w-[164px]">
      <Image src="/logo.png" alt="intelectaLab" fill priority className="object-contain object-left" />
    </div>
  );
}

export default function AppShell({ children }: { children: ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await apiFetch("/v1/logout", { method: "POST" });
    } catch {
      // Si falla la llamada, igual limpiamos la sesión localmente.
    } finally {
      localStorage.removeItem("token");
      router.push("/login");
    }
  }

  const SidebarContent = (
    <div className="flex h-full flex-col">
      <div className="px-6 py-6">
        <Logo />
      </div>

      <nav className="flex-1 space-y-1 px-3">
        {NAV_ITEMS.map(({ href, label, icon: Icon }) => {
          const active = pathname === href || pathname?.startsWith(`${href}/`);
          return (
            <Link
              key={href}
              href={href}
              onClick={() => setMobileOpen(false)}
              className={`flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${
                active ? "text-white" : "text-[#5C6F81] hover:bg-[#F6F8FA] hover:text-[#0F1F2E]"
              }`}
              style={active ? { backgroundColor: NAVY } : undefined}
            >
              <Icon size={17} strokeWidth={2} color={active ? TEAL : undefined} />
              {label}
            </Link>
          );
        })}
      </nav>

      <div className="border-t border-[#E1E7EC] p-3">
        <button
          onClick={handleLogout}
          disabled={loggingOut}
          className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#5C6F81] transition-colors hover:bg-[#FCEAE8] hover:text-[#C4453B] disabled:opacity-60"
        >
          <LogOut size={17} />
          {loggingOut ? "Cerrando sesión…" : "Cerrar sesión"}
        </button>
      </div>
    </div>
  );

  return (
    <div className="flex min-h-screen bg-[#F6F8FA] text-[#0F1F2E]">
      {/* Sidebar desktop */}
      <aside className="hidden w-64 shrink-0 border-r border-[#E1E7EC] bg-white md:block">
        {SidebarContent}
      </aside>

      {/* Sidebar mobile (drawer) */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 md:hidden">
          <div className="absolute inset-0 bg-black/30" onClick={() => setMobileOpen(false)} />
          <aside className="absolute inset-y-0 left-0 w-64 bg-white shadow-xl">{SidebarContent}</aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        {/* Topbar mobile */}
        <header className="flex items-center justify-between border-b border-[#E1E7EC] bg-white px-4 py-3 md:hidden">
          <Logo />
          <button
            onClick={() => setMobileOpen((v) => !v)}
            aria-label="Abrir menú"
            className="text-[#0F1F2E]"
          >
            {mobileOpen ? <X size={22} /> : <Menu size={22} />}
          </button>
        </header>

        <main className="flex-1 p-6 md:p-10">{children}</main>
      </div>
    </div>
  );
}
