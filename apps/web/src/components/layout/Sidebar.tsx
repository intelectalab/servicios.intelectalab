"use client";

import Image from "next/image";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { LogOut } from "lucide-react";
import type { ComponentType } from "react";

export type MenuItem = {
  href: string;
  label: string;
  requiredPermission: string;
  icon: ComponentType<{ size?: number; strokeWidth?: number; color?: string }>;
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

  return (
    <div className="flex h-full flex-col">
      <div className={compact ? "flex justify-center px-2 py-6" : "px-6 py-6"}>
        {compact ? <CompactLogo /> : <Logo />}
      </div>

      <nav className={compact ? "flex-1 space-y-1 px-2" : "flex-1 space-y-1 px-3"} aria-label="Navegación principal">
        {items.map(({ href, label, icon: Icon }) => {
          const active = pathname === href || pathname?.startsWith(`${href}/`);

          return (
            <Link
              key={href}
              href={href}
              onClick={onNavigate}
              aria-label={compact ? label : undefined}
              className={`group relative flex items-center rounded-xl py-2.5 text-sm font-medium transition-colors ${
                compact ? "justify-center px-0" : "gap-3 px-3"
              } ${
                active ? "text-white" : "app-muted app-hover"
              }`}
              style={active ? { backgroundColor: "var(--app-brand-deep)" } : undefined}
            >
              <Icon size={17} strokeWidth={2} color={active ? "var(--app-brand)" : undefined} />
              {!compact && <span className="whitespace-nowrap">{label}</span>}
              {compact && (
                <span className="invisible pointer-events-none absolute left-full top-1/2 z-50 ml-3 -translate-x-2 -translate-y-1/2 whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium opacity-0 shadow-lg transition-all duration-150 group-hover:visible group-hover:translate-x-0 group-hover:opacity-100" style={{ backgroundColor: "var(--app-tooltip)", color: "var(--app-tooltip-text)" }}>
                  <span className="absolute right-full top-1/2 -translate-y-1/2 border-[5px] border-transparent" style={{ borderRightColor: "var(--app-tooltip)" }} />
                  {label}
                </span>
              )}
            </Link>
          );
        })}
        {!permissionsLoading && items.length === 0 && (
          <p className="app-muted px-3 py-2 text-sm">No hay módulos disponibles.</p>
        )}
      </nav>

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