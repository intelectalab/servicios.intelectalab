"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { LogOut, Lock, Sparkles, X, ChevronDown, Layers, Loader2 } from "lucide-react";
import { createPortal } from "react-dom";
import { useState, type ComponentType } from "react";

export type MenuItem = {
  href: string;
  label: string;
  requiredPermission: string;
  icon: ComponentType<{ size?: number; strokeWidth?: number; color?: string }>;
  locked?: boolean;
  superModule?: string;
};

type SidebarProps = {
  items: MenuItem[];
  compact?: boolean;
  permissionsLoading: boolean;
  onNavigate: () => void;
  onLogout: () => void;
  loggingOut: boolean;
};

function Logo() {
  return (
    <div className="relative h-9 w-[164px]">
      <Image src="/logo.png" alt="Creativas" fill priority sizes="164px" className="logo-light object-contain object-left" />
      <Image src="/logo-blanco.png" alt="Creativas" fill priority sizes="164px" className="logo-dark object-contain object-left" />
    </div>
  );
}

export default function Sidebar({
  items,
  compact = false,
  permissionsLoading,
  onNavigate,
  onLogout,
  loggingOut,
}: SidebarProps) {
  const pathname = usePathname();
  const router = useRouter();
  const [tooltip, setTooltip] = useState<{ label: string; top: number; left: number } | null>(null);
  const [upgradeModalModule, setUpgradeModalModule] = useState<string | null>(null);
  const [collapsedGroups, setCollapsedGroups] = useState<Record<string, boolean>>({});

  const toggleGroup = (group: string) => {
    setCollapsedGroups((prev) => ({ ...prev, [group]: !prev[group] }));
  };

  function showTooltip(event: { currentTarget: HTMLAnchorElement }, label: string, locked?: boolean) {
    const rect = event.currentTarget.getBoundingClientRect();
    const text = locked ? `${label} (Requiere cambio de plan)` : label;
    setTooltip({ label: text, top: rect.top + rect.height / 2, left: rect.right + 12 });
  }

  const groupedItems = items.reduce((acc, item) => {
    const group = item.superModule || "CORE";
    if (!acc[group]) acc[group] = [];
    acc[group].push(item);
    return acc;
  }, {} as Record<string, MenuItem[]>);

  return (
    <div className="flex h-full flex-col relative">
      <div className={compact ? "flex justify-center px-2 py-6" : "px-6 py-6"}>
        {compact ? <CompactLogo /> : <Logo />}
      </div>

      <nav
        className={`min-h-0 flex-1 overflow-y-auto space-y-4 ${compact ? "px-2" : "px-3"}`}
        aria-label="Navegación principal"
        onScroll={() => setTooltip(null)}
      >
        {permissionsLoading ? (
          /* Esqueleto de carga fluido para evitar el parpadeo y los saltos visuales */
          <div className="space-y-4 px-2 pt-2">
            {[1, 2, 3].map((n) => (
              <div key={n} className="space-y-2">
                <div className="h-3 w-20 bg-slate-200 rounded animate-pulse" />
                <div className="h-10 w-full bg-slate-100 rounded-xl animate-pulse" />
              </div>
            ))}
          </div>
        ) : (
          Object.entries(groupedItems).map(([superModule, groupItems]) => {
            const isCollapsed = collapsedGroups[superModule];

            return (
              <div key={superModule} className="space-y-1">
                {!compact && (
                  <button
                    onClick={() => toggleGroup(superModule)}
                    className="w-full px-3 text-[11px] font-extrabold uppercase tracking-wider text-slate-500 hover:text-slate-800 flex items-center justify-between py-1.5 transition-colors cursor-pointer"
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
                    {groupItems.map(({ href, label, icon: Icon, locked }) => {
                      const active = pathname === href || pathname?.startsWith(`${href}/`);

                      return (
                        <Link
                          key={href}
                          href={locked ? "#" : href}
                          onClick={(e) => {
                            if (locked) {
                              e.preventDefault();
                              setUpgradeModalModule(label);
                            } else {
                              onNavigate();
                            }
                          }}
                          onMouseEnter={compact ? (event) => showTooltip(event, label, locked) : undefined}
                          onMouseLeave={compact ? () => setTooltip(null) : undefined}
                          onFocus={compact ? (event) => showTooltip(event, label, locked) : undefined}
                          onBlur={compact ? () => setTooltip(null) : undefined}
                          aria-label={compact ? label : undefined}
                          className={`group relative flex items-center justify-between rounded-xl py-2.5 text-sm font-medium transition-all ${
                            compact ? "justify-center px-0" : "gap-3 px-3"
                          } ${
                            active 
                              ? "text-white shadow-sm" 
                              : locked 
                              ? "opacity-75 cursor-pointer bg-slate-100 text-slate-500 hover:bg-slate-200 border border-slate-300" 
                              : "app-muted app-hover"
                          }`}
                          style={active && !locked ? { backgroundColor: "var(--app-brand-deep)" } : undefined}
                        >
                          <div className="flex items-center gap-3 min-w-0">
                            <Icon 
                              size={17} 
                              strokeWidth={2} 
                              color={active && !locked ? "var(--app-brand)" : locked ? "#475569" : undefined} 
                            />
                            {!compact && (
                              <span className="whitespace-nowrap truncate font-semibold">
                                {label}
                              </span>
                            )}
                          </div>

                          {locked && !compact && (
                            <div className="flex items-center gap-1 shrink-0 bg-blue-100 text-[var(--app-brand-deep)] px-2 py-0.5 rounded-md border border-blue-200 text-[11px] font-bold">
                              <Lock size={12} color="var(--app-brand-deep)" />
                              <span>Plan</span>
                            </div>
                          )}

                          {locked && compact && (
                            <span className="absolute right-1 top-1 h-2.5 w-2.5 rounded-full bg-[var(--app-brand-deep)] ring-2 ring-white" />
                          )}
                        </Link>
                      );
                    })}
                  </div>
                )}
              </div>
            );
          })
        )}

        {!permissionsLoading && items.length === 0 && (
          <p className="app-muted px-3 py-2 text-sm">No hay módulos disponibles.</p>
        )}
      </nav>

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

      {upgradeModalModule && typeof document !== "undefined" && createPortal(
        <div className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
          <div className="w-full max-w-md bg-white rounded-3xl shadow-2xl p-6 space-y-5 text-slate-900 relative animate-in fade-in zoom-in-95 duration-200">
            <button
              onClick={() => setUpgradeModalModule(null)}
              className="absolute right-4 top-4 rounded-full p-1.5 text-slate-400 hover:bg-slate-100 cursor-pointer"
            >
              <X size={18} />
            </button>

            <div className="w-12 h-12 rounded-2xl bg-blue-50 text-[var(--app-brand-deep)] flex items-center justify-center border border-blue-100">
              <Sparkles size={24} />
            </div>

            <div className="space-y-1.5">
              <h3 className="text-lg font-bold">Módulo no disponible en tu plan</h3>
              <p className="text-xs text-slate-500 leading-relaxed">
                El acceso a <strong>{upgradeModalModule}</strong> requiere de un plan superior o la activación de este módulo en tu suscripción empresarial.
              </p>
            </div>

            <div className="flex gap-3 pt-2">
              <button
                onClick={() => setUpgradeModalModule(null)}
                className="flex-1 py-2.5 rounded-xl border border-slate-300 text-xs font-semibold text-slate-600 hover:bg-slate-50 cursor-pointer"
              >
                Cancelar
              </button>
              <button
                onClick={() => {
                  setUpgradeModalModule(null);
                  router.push("/settings/billing");
                }}
                className="flex-1 py-2.5 rounded-xl bg-[var(--app-brand-deep)] text-white text-xs font-bold hover:bg-[var(--app-brand)] cursor-pointer shadow-md shadow-blue-500/20"
              >
                Cambiar de plan
              </button>
            </div>
          </div>
        </div>,
        document.body,
      )}

      <div className={`app-border ${compact ? "border-t p-2" : "border-t p-3"}`}>
        <button
          onClick={onLogout}
          disabled={loggingOut}
          aria-label={compact ? "Cerrar sesión" : undefined}
          className={`app-muted group relative flex w-full items-center rounded-xl py-2.5 text-sm font-medium transition-colors hover:bg-[#FCEAE8] hover:text-[#C4453B] disabled:opacity-60 ${
            compact ? "justify-center px-0" : "gap-3 px-3"
          }`}
        >
          <LogOut size={17} />
          {!compact && (loggingOut ? "Cerrando sesión..." : "Cerrar sesión")}
          {compact && (
            <span className="invisible pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-x-2 -translate-y-1/2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium opacity-0 shadow-lg transition-all duration-150 group-hover:visible group-hover:translate-x-0 group-hover:opacity-100" style={{ backgroundColor: "var(--app-tooltip)", color: "var(--app-tooltip-text)" }}>
              <span className="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent" style={{ borderRightColor: "var(--app-tooltip)" }} />
              Cerrar sesión
            </span>
          )}
        </button>
      </div>
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