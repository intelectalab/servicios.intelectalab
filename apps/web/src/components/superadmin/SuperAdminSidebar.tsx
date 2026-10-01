"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { LayoutDashboard, CreditCard, Receipt, Building2, LogOut, Layers, ChevronDown } from "lucide-react";
import { createPortal } from "react-dom";
import { useState, type ComponentType } from "react";

export type AdminMenuItem = {
  href: string;
  label: string;
  icon: ComponentType<{ size?: number; strokeWidth?: number; color?: string }>;
  superModule?: string;
};

const ADMIN_MENU_ITEMS: AdminMenuItem[] = [
  { href: "/superadmin/dashboard", label: "Dashboard", icon: LayoutDashboard, superModule: "Administración SaaS" },
  { href: "/superadmin/plans", label: "Planes SaaS", icon: CreditCard, superModule: "Administración SaaS" },
  { href: "/superadmin/subscriptions", label: "Suscripciones", icon: Receipt, superModule: "Administración SaaS" },
  { href: "/superadmin/tenants", label: "Tenants / Empresas", icon: Building2, superModule: "Administración SaaS" },
];

type SuperAdminSidebarProps = {
  compact?: boolean;
  onLogout?: () => void;
  loggingOut?: boolean;
};

function Logo() {
  return (
    <div className="relative h-9 w-[164px]">
      <Image src="/logo-blanco.png" alt="Creativas" fill priority sizes="164px" className="logo-light object-contain object-left" />
      <Image src="/logo.png" alt="Creativas" fill priority sizes="164px" className="logo-dark object-contain object-left" />
    </div>
  );
}

function CompactLogo() {
  return (
    <div className="relative h-9 w-9 overflow-hidden">
      <Image src="/favicon.jpg" alt="Creativas" fill priority sizes="36px" className="rounded object-contain" />
    </div>
  );
}

export default function SuperAdminSidebar({ compact = false, onLogout, loggingOut = false }: SuperAdminSidebarProps) {
  const pathname = usePathname();
  const [tooltip, setTooltip] = useState<{ label: string; top: number; left: number } | null>(null);
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});

  const toggleGroup = (group: string) => {
    setCollapsedGroups((prev) => ({ ...prev, [group]: !prev[group] }));
  };

  function showTooltip(event: { currentTarget: HTMLAnchorElement }, label: string) {
    const rect = event.currentTarget.getBoundingClientRect();
    setTooltip({ label, top: rect.top + rect.height / 2, left: rect.right + 12 });
  }

  const groupedItems = ADMIN_MENU_ITEMS.reduce((acc, item) => {
    const group = item.superModule || "CORE";
    if (!acc[group]) acc[group] = [];
    acc[group].push(item);
    return acc;
  }, {} as Record<string, AdminMenuItem[]>);

  return (
    <div className="flex h-full flex-col relative w-full bg-[#0F1F2E] text-slate-300 border-r border-slate-800">
      <div className={compact ? "flex justify-center px-2 py-6" : "px-6 py-6"}>
        {compact ? <CompactLogo /> : <Logo />}
      </div>
      
      <nav
        className={`min-h-0 flex-1 overflow-y-auto space-y-4 ${compact ? "px-2" : "px-3"}`}
        aria-label="Navegación SuperAdmin"
        onScroll={() => setTooltip(null)}
      >
        {Object.entries(groupedItems).map(([superModule, groupItems]) => {
          const isCollapsed = collapsedGroups[superModule];

          return (
            <div key={superModule} className="space-y-1">
              {!compact && (
                <button
                  onClick={() => toggleGroup(superModule)}
                  className="w-full px-3 text-[11px] font-extrabold uppercase tracking-wider text-slate-500 hover:text-slate-300 flex items-center justify-between py-1.5 transition-colors cursor-pointer"
                >
                  <span className="flex items-center gap-1.5">
                    <Layers size={13} /> {superModule}
                  </span>
                  <ChevronDown
                    size={14}
                    className={`transition-transform duration-200 ${isCollapsed ? "-rotate-90" : ""}`}
                  />
                </button>
              )}

              {(!isCollapsed || compact) && (
                <div className="space-y-1 pl-1">
                  {groupItems.map(({ href, label, icon: Icon }) => {
                    const active = pathname === href || pathname?.startsWith(`${href}/`);

                    return (
                      <Link
                        key={href}
                        href={href}
                        onMouseEnter={compact ? (event) => showTooltip(event, label) : undefined}
                        onMouseLeave={compact ? () => setTooltip(null) : undefined}
                        onFocus={compact ? (event) => showTooltip(event, label) : undefined}
                        onBlur={compact ? () => setTooltip(null) : undefined}
                        aria-label={compact ? label : undefined}
                        className={`group relative flex items-center justify-between rounded-xl py-2.5 text-xs font-semibold transition-all ${
                          compact ? "justify-center px-0" : "gap-3 px-3"
                        } ${
                          active 
                            ? "bg-[var(--app-brand-deep)] text-white shadow-sm" 
                            : "hover:bg-slate-800 text-slate-300 hover:text-white"
                        }`}
                      >
                        <div className="flex items-center gap-3 min-w-0">
                          <Icon 
                            size={17} 
                            strokeWidth={2} 
                            color={active ? "white" : "#94a3b8"} 
                          />
                          {!compact && (
                            <span className="whitespace-nowrap truncate font-semibold">
                              {label}
                            </span>
                          )}
                        </div>
                      </Link>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </nav>

      {/* Tooltip para sidebar compacto */}
      {compact && tooltip && typeof document !== "undefined" && createPortal(
        <span
          className="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium shadow-lg"
          style={{ top: tooltip.top, left: tooltip.left, backgroundColor: "var(--app-tooltip)", color: "var(--app-tooltip-text)" }}
        >
          <span className="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent" style={{ borderRightColor: "var(--app-tooltip)" }} />
          {tooltip.label}
        </span>,
        document.body,
      )}

      <div className="border-t border-slate-800 p-3">
        <button
          onClick={onLogout}
          disabled={loggingOut}
          aria-label={compact ? "Cerrar sesión" : undefined}
          className={`group relative flex w-full items-center rounded-xl py-2.5 text-xs font-semibold text-rose-400 hover:bg-rose-500/10 transition-colors disabled:opacity-60 cursor-pointer ${
            compact ? "justify-center px-0" : "gap-3 px-3"
          }`}
        >
          <LogOut size={17} />
          {!compact && (loggingOut ? "Cerrando sesión..." : "Cerrar sesión")}
          {compact && (
            <span className="invisible pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-x-2 -translate-y-1/2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium shadow-lg transition-all duration-150 group-hover:visible group-hover:translate-x-0 group-hover:opacity-100" style={{ backgroundColor: "var(--app-tooltip)", color: "var(--app-tooltip-text)" }}>
              <span className="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent" style={{ borderRightColor: "var(--app-tooltip)" }} />
              Cerrar sesión
            </span>
          )}
        </button>
      </div>
    </div>
  );
}