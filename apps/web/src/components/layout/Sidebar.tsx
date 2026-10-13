"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { LogOut, Lock, Sparkles, X, ChevronRight, Layers, Clock3, UserRound, Building2 } from "lucide-react";
import { createPortal } from "react-dom";
import { useEffect, useMemo, useRef, useState, useSyncExternalStore, type ComponentType } from "react";
import { poppins } from "@/lib/fonts";

type MenuIcon = ComponentType<{ size?: number; strokeWidth?: number; color?: string; className?: string }>;

/** Pantalla ya construida: navega, y se bloquea u oculta según módulo y permiso. */
export type BuiltMenuItem = {
  href: string;
  label: string;
  /** Código del módulo en el catálogo; si la empresa no lo tiene, el ítem sale con candado. Sin él, no depende del plan. */
  module?: string;
  requiredPermission: string;
  icon: MenuIcon;
  locked?: boolean;
  superModule?: string;
  soon?: false;
};

/** Pantalla del prototipo que aún no existe: ocupa su lugar en el menú, sin navegar. */
export type SoonMenuItem = {
  label: string;
  icon: MenuIcon;
  superModule?: string;
  soon: true;
};

export type MenuItem = BuiltMenuItem | SoonMenuItem;

type SidebarProps = {
  items: MenuItem[];
  compact?: boolean;
  permissionsLoading: boolean;
  onNavigate: () => void;
  onLogout: () => void;
  loggingOut: boolean;
  /** Ícono de cada grupo (clave = superModule). */
  groupIcons?: Record<string, MenuIcon>;
  /** Usuario de la sesión, para la tarjeta bajo el logo. */
  user?: { name: string; role?: string } | null;
  /** Empresa de la sesión, bajo la tarjeta de usuario. */
  tenantName?: string;
  /** Ruta de "Mi perfil" (la consola de plataforma usa la suya). */
  profileHref?: string;
};

/**
 * Colores de la barra: el azul del logo (#1B8FC9) oscureciéndose hacia abajo,
 * con texto e indicadores en blanco. En tema oscuro baja de tono y el azul vivo
 * queda para lo activo (variables --side-… de globals.css).
 */
// Ojo: aquí no se usan clases "bg-white…": globals.css las repinta en tema oscuro.
const SIDEBAR_BACKGROUND = "var(--side-bg)";

// Grupos del menú que la persona dejó plegados (true) o desplegados (false). Se recuerda en su
// navegador; todas las barras abiertas (escritorio, teléfono, otra pestaña) leen lo mismo.
const SIDEBAR_GROUPS_KEY = "erp-sidebar-groups";
const SIDEBAR_GROUPS_EVENT = "erp-sidebar-groups-change";

function subscribeToGroups(onChange: () => void) {
  window.addEventListener(SIDEBAR_GROUPS_EVENT, onChange);
  window.addEventListener("storage", onChange);
  return () => {
    window.removeEventListener(SIDEBAR_GROUPS_EVENT, onChange);
    window.removeEventListener("storage", onChange);
  };
}

// Si el navegador no deja guardar, el menú se sigue abriendo y cerrando; solo no se recuerda.
const unsavedGroups: { text: string | null } = { text: null };

function storedGroupsText() {
  if (unsavedGroups.text !== null) return unsavedGroups.text;
  try {
    return window.localStorage.getItem(SIDEBAR_GROUPS_KEY) ?? "{}";
  } catch {
    return "{}";
  }
}

function saveGroups(text: string) {
  try {
    window.localStorage.setItem(SIDEBAR_GROUPS_KEY, text);
    unsavedGroups.text = null;
  } catch {
    unsavedGroups.text = text;
  }
  window.dispatchEvent(new Event(SIDEBAR_GROUPS_EVENT));
}

function parseGroups(text: string): Record<string, boolean> {
  try {
    const stored: unknown = JSON.parse(text);
    if (!stored || typeof stored !== "object" || Array.isArray(stored)) return {};
    return Object.fromEntries(Object.entries(stored).filter(([, collapsed]) => typeof collapsed === "boolean")) as Record<string, boolean>;
  } catch {
    return {};
  }
}

// Los nombres largos del menú no se cortan con "…": pasan a la línea siguiente.
const WRAP = "min-w-0 leading-snug [overflow-wrap:anywhere]";

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

function Logo() {
  return (
    <div className="relative h-9 w-[164px]">
      <Image src="/logo-blanco.png" alt="Creativas" fill priority sizes="164px" className="object-contain object-left" />
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
  groupIcons = {},
  user,
  tenantName,
  profileHref = "/profile",
}: SidebarProps) {
  const pathname = usePathname();
  const router = useRouter();
  const [tooltip, setTooltip] = useState<{ label: string; top: number; left: number } | null>(null);
  const [upgradeModalModule, setUpgradeModalModule] = useState<string | null>(null);
  // Modo íconos: grupo cuyo panel de pantallas está abierto junto a su ícono.
  const [flyout, setFlyout] = useState<{ group: string; left: number; top?: number; bottom?: number } | null>(null);
  const flyoutPanel = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!flyout) return;
    const close = (event: MouseEvent) => {
      const target = event.target as HTMLElement;
      if (!flyoutPanel.current?.contains(target) && !target.closest("[data-group-icon]")) setFlyout(null);
    };
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") setFlyout(null); };
    document.addEventListener("mousedown", close);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", close);
      document.removeEventListener("keydown", onKey);
    };
  }, [flyout]);

  // Al desplegar la barra, el panel del modo íconos ya no aplica.
  const openFlyout = compact ? flyout : null;

  function toggleFlyout(event: { currentTarget: HTMLElement }, group: string) {
    if (flyout?.group === group) {
      setFlyout(null);
      return;
    }
    const rect = event.currentTarget.getBoundingClientRect();
    // En la mitad inferior de la pantalla el panel crece hacia arriba para no salirse.
    const below = rect.top < window.innerHeight / 2;
    setTooltip(null);
    setFlyout({
      group,
      left: rect.right + 10,
      ...(below ? { top: Math.max(8, rect.top - 6) } : { bottom: Math.max(8, window.innerHeight - rect.bottom - 6) }),
    });
  }
  // Los grupos que todavía no tienen ninguna pantalla construida arrancan plegados.
  const [defaultCollapsed] = useState<Record<string, boolean>>(() => {
    const builtGroups = new Set(items.filter((item) => !item.soon).map((item) => item.superModule || "CORE"));
    const initial: Record<string, boolean> = {};
    for (const item of items) {
      const group = item.superModule || "CORE";
      if (!builtGroups.has(group)) initial[group] = true;
    }
    return initial;
  });
  // Lo que la persona abrió o cerró a mano manda sobre lo anterior. En el servidor no hay nada
  // guardado: se dibuja lo de siempre y el navegador lo ajusta al cargar.
  const storedText = useSyncExternalStore(subscribeToGroups, storedGroupsText, () => "{}");
  const collapsedGroups = useMemo(() => ({ ...defaultCollapsed, ...parseGroups(storedText) }), [defaultCollapsed, storedText]);

  const toggleGroup = (group: string) => {
    saveGroups(JSON.stringify({ ...parseGroups(storedGroupsText()), [group]: !collapsedGroups[group] }));
  };

  function showTooltip(event: { currentTarget: HTMLElement }, label: string, locked?: boolean) {
    const rect = event.currentTarget.getBoundingClientRect();
    const text = locked ? `${label} (Requiere cambio de plan)` : label;
    setTooltip({ label: text, top: rect.top + rect.height / 2, left: rect.right + 12 });
  }

  const isActive = (href: string) => pathname === href || Boolean(pathname?.startsWith(`${href}/`));

  const groupedItems = items.reduce((acc, item) => {
    const group = item.superModule || "CORE";
    if (!acc[group]) acc[group] = [];
    acc[group].push(item);
    return acc;
  }, {} as Record<string, MenuItem[]>);

  return (
    <div
      className={`${poppins.className} relative flex h-full flex-col text-white`}
      style={{ background: SIDEBAR_BACKGROUND }}
    >
      <div className={`flex h-16 shrink-0 items-center ${compact ? "justify-center px-2" : "px-5"}`}>
        {compact ? <CompactLogo /> : <Logo />}
      </div>

      {!compact && user && (
        <div className="relative mx-3.5 mb-2 mt-1 flex items-center gap-2.5 rounded-[10px] bg-[rgb(255_255_255/0.09)] px-3 py-2.5">
          <span className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 border-white/70 bg-[rgb(255_255_255/0.15)] text-[12px] font-semibold">
            {initials(user.name)}
            <span
              title="Sesión activa"
              className="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-[color:var(--side-ring)] bg-[#3DDC84]"
            />
          </span>
          <div className="min-w-0">
            <strong className="block truncate text-[13px] font-semibold">{user.name}</strong>
            {user.role && <small className="block truncate text-[11.5px] text-white/60">{user.role}</small>}
          </div>
        </div>
      )}

      {!compact && tenantName && (
        <div
          title="Empresa (tenant) resuelta a partir de tu cuenta"
          className="mx-4 mb-1.5 flex items-center gap-1.5 text-[11.5px] text-white/60"
        >
          <Building2 size={13} className="shrink-0" />
          <span className="truncate">{tenantName}</span>
        </div>
      )}

      {!compact && (
        <div className="px-[22px] pb-1.5 pt-2.5 text-[10.5px] font-semibold uppercase tracking-[0.1em] text-white/55">
          Menú
        </div>
      )}

      <nav
        className={`min-h-0 flex-1 overflow-y-auto pb-2.5 [scrollbar-color:rgba(255,255,255,.2)_transparent] [scrollbar-width:thin] ${
          compact ? "space-y-1 px-2 pt-1" : ""
        }`}
        aria-label="Navegación principal"
        onScroll={() => { setTooltip(null); setFlyout(null); }}
      >
        {permissionsLoading ? (
          /* Esqueleto de carga fluido para evitar el parpadeo y los saltos visuales */
          <div className={`space-y-3 pt-2 ${compact ? "" : "px-[22px]"}`}>
            {[1, 2, 3, 4].map((n) => (
              <div key={n} className="h-8 w-full animate-pulse rounded-lg bg-[rgb(255_255_255/0.15)]" />
            ))}
          </div>
        ) : (
          Object.entries(groupedItems).map(([superModule, groupItems]) => {
            const isCollapsed = collapsedGroups[superModule];
            const GroupIcon = groupIcons[superModule] ?? Layers;
            const hasActive = groupItems.some((item) => !item.soon && isActive(item.href));

            // Modo íconos: un ícono por grupo, los mismos que con la barra desplegada.
            // Al pulsarlo se abre al lado el panel con las pantallas de ese grupo.
            if (compact) {
              const open = openFlyout?.group === superModule;
              return (
                <button
                  key={superModule}
                  type="button"
                  data-group-icon
                  onClick={(event) => toggleFlyout(event, superModule)}
                  onMouseEnter={(event) => { if (!openFlyout) showTooltip(event, superModule); }}
                  onMouseLeave={() => setTooltip(null)}
                  onFocus={(event) => { if (!openFlyout) showTooltip(event, superModule); }}
                  onBlur={() => setTooltip(null)}
                  aria-label={superModule}
                  aria-haspopup="menu"
                  aria-expanded={open}
                  className={`relative flex h-10 w-full cursor-pointer items-center justify-center rounded-lg transition-colors ${
                    hasActive || open ? "bg-[var(--side-group-active)] text-white" : "text-white/85 hover:bg-[rgb(255_255_255/0.10)] hover:text-white"
                  }`}
                >
                  {hasActive && <span className="absolute inset-y-1.5 left-0 w-[3px] rounded-r bg-[#fff]" />}
                  <GroupIcon size={18} strokeWidth={2} />
                </button>
              );
            }

            return (
              <div key={superModule}>
                <button
                  onClick={() => toggleGroup(superModule)}
                  aria-expanded={!isCollapsed}
                  className={`flex w-full cursor-pointer items-center gap-3 px-[22px] py-2.5 text-left text-[13.5px] font-medium transition-colors hover:bg-[rgb(255_255_255/0.07)] hover:text-white ${
                    hasActive ? "text-white" : "text-white/85"
                  }`}
                >
                  <GroupIcon size={18} strokeWidth={2} className="shrink-0 opacity-90" />
                  <span className={WRAP}>{superModule}</span>
                  <ChevronRight
                    size={15}
                    className={`ml-auto shrink-0 transition-transform duration-200 ${isCollapsed ? "" : "rotate-90"}`}
                  />
                </button>

                {!isCollapsed && (
                  <div className="bg-black/[0.14] py-1">
                    {groupItems.map((item) => {
                      if (item.soon) {
                        return (
                          <div
                            key={`soon-${item.label}`}
                            aria-disabled="true"
                            title="Próximamente"
                            className="flex cursor-default items-center gap-2 py-2 pl-[52px] pr-[18px] text-[12.8px] text-white/45"
                          >
                            <span className={WRAP}>{item.label}</span>
                            <Clock3 size={12} aria-label="Próximamente" className="ml-auto shrink-0" />
                          </div>
                        );
                      }

                      const { href, label, locked } = item;
                      const active = isActive(href);

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
                          aria-current={active ? "page" : undefined}
                          className={`relative flex items-center gap-2 py-2 pl-[52px] pr-[18px] text-[12.8px] transition-colors ${
                            active
                              ? "bg-[var(--side-active)] font-semibold text-white"
                              : locked
                              ? "text-white/60 hover:text-white"
                              : "text-white/80 hover:text-white"
                          }`}
                        >
                          {active && <span className="absolute inset-y-0 left-0 w-1 bg-[#fff]" />}
                          <span className={WRAP}>{label}</span>

                          {locked && (
                            <span className="ml-auto flex shrink-0 items-center gap-1 rounded-[5px] bg-[rgb(255_255_255/0.15)] px-1.5 text-[10px] font-semibold leading-[18px] text-white">
                              <Lock size={10} />
                              Plan
                            </span>
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
          <p className="px-[22px] py-2 text-sm text-white/70">No hay módulos disponibles.</p>
        )}
      </nav>

      {openFlyout && typeof document !== "undefined" && createPortal(
        <div
          ref={flyoutPanel}
          role="menu"
          aria-label={openFlyout.group}
          className={`${poppins.className} fixed z-50 flex max-h-[80vh] w-[236px] flex-col overflow-hidden rounded-[10px] text-white shadow-[0_14px_34px_rgba(8,25,55,.35)]`}
          style={{ left: openFlyout.left, top: openFlyout.top, bottom: openFlyout.bottom, background: "var(--side-flyout)" }}
        >
          <div className="px-4 pb-1.5 pt-3 text-[10.5px] font-semibold uppercase tracking-[0.1em] text-white/60">{openFlyout.group}</div>
          <div className="min-h-0 flex-1 overflow-y-auto pb-1.5 [scrollbar-color:rgba(255,255,255,.2)_transparent] [scrollbar-width:thin]">
            {(groupedItems[openFlyout.group] ?? []).map((item) => {
              if (item.soon) {
                return (
                  <div
                    key={`soon-${item.label}`}
                    aria-disabled="true"
                    title="Próximamente"
                    className="flex cursor-default items-center gap-2 px-4 py-2 text-[12.8px] text-white/45"
                  >
                    <span className={WRAP}>{item.label}</span>
                    <Clock3 size={12} aria-label="Próximamente" className="ml-auto shrink-0" />
                  </div>
                );
              }

              const { href, label, locked } = item;
              const active = isActive(href);

              return (
                <Link
                  key={href}
                  href={locked ? "#" : href}
                  role="menuitem"
                  onClick={(e) => {
                    setFlyout(null);
                    if (locked) {
                      e.preventDefault();
                      setUpgradeModalModule(label);
                    } else {
                      onNavigate();
                    }
                  }}
                  aria-current={active ? "page" : undefined}
                  className={`relative flex items-center gap-2 px-4 py-2 text-[12.8px] transition-colors ${
                    active
                      ? "bg-[var(--side-active)] font-semibold text-white"
                      : locked
                      ? "text-white/60 hover:bg-[rgb(255_255_255/0.07)] hover:text-white"
                      : "text-white/85 hover:bg-[rgb(255_255_255/0.07)] hover:text-white"
                  }`}
                >
                  {active && <span className="absolute inset-y-0 left-0 w-1 bg-[#fff]" />}
                  <span className={WRAP}>{label}</span>
                  {locked && (
                    <span className="ml-auto flex shrink-0 items-center gap-1 rounded-[5px] bg-[rgb(255_255_255/0.15)] px-1.5 text-[10px] font-semibold leading-[18px] text-white">
                      <Lock size={10} />
                      Plan
                    </span>
                  )}
                </Link>
              );
            })}
          </div>
        </div>,
        document.body,
      )}

      {compact && tooltip && !openFlyout && typeof document !== "undefined" && createPortal(
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
        <div role="dialog" aria-modal="true" aria-label="Módulo no disponible en tu plan" className="fixed inset-0 z-[200] flex items-center justify-center bg-slate-900/50 backdrop-blur-xs p-4">
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

      <div className={`shrink-0 border-t border-white/[0.12] ${compact ? "p-2" : "pb-2.5 pt-1"}`}>
        {!compact && (
          <div className="px-[22px] pb-1.5 pt-2.5 text-[10.5px] font-semibold uppercase tracking-[0.1em] text-white/55">
            Cuenta
          </div>
        )}
        <Link
          href={profileHref}
          onClick={onNavigate}
          aria-label={compact ? "Mi perfil" : undefined}
          aria-current={isActive(profileHref) ? "page" : undefined}
          className={`group relative flex w-full items-center text-[13.5px] font-medium transition-colors hover:text-white ${
            compact ? "h-10 justify-center rounded-lg" : "gap-3 px-[22px] py-2"
          } ${
            isActive(profileHref)
              ? "bg-[var(--side-active)] text-white"
              : "text-white/85 hover:bg-[rgb(255_255_255/0.07)]"
          }`}
        >
          {isActive(profileHref) && !compact && <span className="absolute inset-y-0 left-0 w-1 bg-[#fff]" />}
          <UserRound size={18} className="shrink-0 opacity-90" />
          {!compact && "Mi perfil"}
          {compact && (
            <span className="invisible pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-x-2 -translate-y-1/2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium opacity-0 shadow-lg transition-all duration-150 group-hover:visible group-hover:translate-x-0 group-hover:opacity-100" style={{ backgroundColor: "var(--app-tooltip)", color: "var(--app-tooltip-text)" }}>
              <span className="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent" style={{ borderRightColor: "var(--app-tooltip)" }} />
              Mi perfil
            </span>
          )}
        </Link>
        <button
          onClick={onLogout}
          disabled={loggingOut}
          aria-label={compact ? "Salir" : undefined}
          className={`group relative flex w-full cursor-pointer items-center text-[13.5px] font-medium text-white/85 transition-colors hover:bg-[rgb(255_255_255/0.07)] hover:text-white disabled:opacity-60 ${
            compact ? "h-10 justify-center rounded-lg" : "gap-3 px-[22px] py-2"
          }`}
        >
          <LogOut size={18} className="shrink-0 opacity-90" />
          {!compact && (loggingOut ? "Saliendo..." : "Salir")}
          {compact && (
            <span className="invisible pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-x-2 -translate-y-1/2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium opacity-0 shadow-lg transition-all duration-150 group-hover:visible group-hover:translate-x-0 group-hover:opacity-100" style={{ backgroundColor: "var(--app-tooltip)", color: "var(--app-tooltip-text)" }}>
              <span className="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent" style={{ borderRightColor: "var(--app-tooltip)" }} />
              Salir
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
