"use client";

import { useState, useRef, useEffect } from "react";
import Link from "next/link";
import {
  Menu,
  ChevronDown,
  User as UserIcon,
  KeyRound,
  RotateCcw,
  LogOut,
  Home,
  Moon,
  Sun,
} from "lucide-react";
import { poppins } from "@/lib/fonts";
import GlobalSearch, { type SearchScreen } from "@/components/layout/GlobalSearch";
import ChangePasswordModal from "@/components/layout/ChangePasswordModal";
import NotificationBell from "@/components/layout/NotificationBell";

export type UserProfile = {
  name: string;
  email?: string;
  role?: string;
  avatarUrl?: string;
};

type HeaderProps = {
  open: boolean;
  currentSection: string;
  /** Grupo del menú al que pertenece la pantalla actual; va en las migas. */
  currentGroup?: string;
  onToggle: () => void;
  user?: UserProfile | null;
  onLogout?: () => void;
  /** Empresa de la sesión; va en las migas y en el menú de usuario. */
  tenantName?: string;
  /** Pantallas disponibles para el buscador global. */
  screens?: SearchScreen[];
  canSearchRecords?: boolean;
  /** Ruta de "Mi perfil y seguridad" (la consola de plataforma usa la suya). */
  profileHref?: string;
  /** Campana de notificaciones: solo en el sistema de la empresa (la consola de plataforma no tiene). */
  notifications?: boolean;
};

// Botón de ícono de la barra: color de la marca, igual en tema claro y oscuro.
const ICON_BUTTON =
  "relative flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-[10px] text-[color:var(--p-brand)] transition-colors hover:bg-[var(--p-brand-soft)] active:scale-95";

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

export default function Header({
  open,
  currentSection,
  currentGroup,
  onToggle,
  user,
  onLogout,
  tenantName,
  screens = [],
  canSearchRecords = false,
  profileHref = "/profile",
  notifications = false,
}: HeaderProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [changingPassword, setChangingPassword] = useState(false);
  const [darkMode, setDarkMode] = useState(() => {
    if (typeof window === "undefined") return false;
    const savedTheme = window.localStorage.getItem("theme");
    return savedTheme ? savedTheme === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
  });
  const dropdownRef = useRef<HTMLDivElement>(null);

  const userName = user?.name ?? "Usuario";
  const userRole = user?.role ?? "Administrador";
  const userEmail = user?.email ?? "online";
  const avatar = (
    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--p-accent,#1B8FC9)] text-[12.5px] font-semibold text-[color:var(--p-on-accent,#fff)]">
      {initials(userName)}
    </span>
  );

  // Cerrar el menú al hacer clic fuera
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setMenuOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    document.documentElement.dataset.theme = darkMode ? "dark" : "light";
  }, [darkMode]);

  function toggleTheme() {
    const nextIsDark = !darkMode;
    setDarkMode(nextIsDark);
    window.localStorage.setItem("theme", nextIsDark ? "dark" : "light");
    document.documentElement.dataset.theme = nextIsDark ? "dark" : "light";
  }

  const menuItem =
    "app-text flex w-full cursor-pointer items-center gap-2.5 px-3.5 py-2.5 text-left text-[13px] transition-colors hover:bg-[var(--app-surface-muted)]";

  return (
    <header
      className={`${poppins.className} app-surface app-border sticky top-0 z-[100] flex h-16 w-full shrink-0 items-center gap-3.5 overflow-visible border-b px-3 sm:px-6`}
    >
      {/* Izquierda: hamburguesa, migas y título de la pantalla */}
      <button onClick={onToggle} aria-label={open ? "Cerrar menú" : "Abrir menú"} className={ICON_BUTTON}>
        <Menu size={21} />
      </button>

      <div className="mr-auto min-w-0">
        <nav aria-label="Ruta" className="app-muted flex items-center gap-[5px] overflow-hidden whitespace-nowrap text-[11.5px]">
          <Home size={13} className="shrink-0" />
          <span className="truncate">{tenantName ?? "ERP SaaS"}</span>
          {currentGroup && (
            <>
              <span aria-hidden="true">·</span>
              <span className="truncate">{currentGroup}</span>
            </>
          )}
        </nav>
        <h1 className="app-text truncate text-[17px] font-semibold leading-tight tracking-[-0.01em]">
          {currentSection}
        </h1>
      </div>

      {/* Derecha: buscador, tema, notificaciones y menú de usuario */}
      <div className="flex shrink-0 items-center gap-1.5 sm:gap-2.5">
        <GlobalSearch screens={screens} canSearchRecords={canSearchRecords} />

        <button type="button" onClick={toggleTheme} aria-label="Cambiar tema" title="Cambiar tema" className={ICON_BUTTON}>
          <Moon size={19} className="theme-moon-icon" />
          <Sun size={19} className="theme-sun-icon" />
        </button>

        {notifications && <NotificationBell className={ICON_BUTTON} />}

        <div className="relative" ref={dropdownRef}>
          <button
            onClick={() => setMenuOpen((prev) => !prev)}
            aria-haspopup="true"
            aria-expanded={menuOpen}
            className="flex cursor-pointer items-center gap-2.5 rounded-xl py-1 pl-2.5 pr-1.5 transition-colors hover:bg-[var(--p-brand-soft)]"
          >
            <span className="hidden text-right md:grid">
              <strong className="app-text text-[13px] font-medium leading-tight">{userName}</strong>
              <small className="app-muted text-[11.5px] leading-tight">{userRole}</small>
            </span>
            {avatar}
            <ChevronDown
              size={15}
              className={`app-muted transition-transform duration-200 ${menuOpen ? "rotate-180" : ""}`}
            />
          </button>

          {menuOpen && (
            <div
              role="menu"
              className="app-surface app-border absolute right-0 top-[calc(100%+8px)] z-[110] w-[290px] overflow-hidden rounded-xl border shadow-[0_20px_50px_rgba(8,25,55,0.28)]"
            >
              <div className="app-surface-muted app-border flex items-center gap-3 border-b p-3.5">
                {avatar}
                <div className="min-w-0">
                  <strong className="app-text block truncate text-[13px] font-semibold">{userName}</strong>
                  <small className="app-muted block truncate text-[11.5px]">{userEmail}</small>
                  <small className="app-muted block truncate text-[11.5px]">
                    {tenantName ? `${tenantName} · ${userRole}` : userRole}
                  </small>
                </div>
              </div>

              <Link href={profileHref} role="menuitem" onClick={() => setMenuOpen(false)} className={menuItem}>
                <UserIcon size={16} className="shrink-0" />
                Mi perfil y seguridad
              </Link>
              <button
                role="menuitem"
                onClick={() => {
                  setMenuOpen(false);
                  setChangingPassword(true);
                }}
                className={menuItem}
              >
                <KeyRound size={16} className="shrink-0" />
                Cambiar contraseña
              </button>
              {/* Cierra esta sesión y vuelve al login para entrar con otra cuenta. */}
              <button
                role="menuitem"
                onClick={() => {
                  setMenuOpen(false);
                  onLogout?.();
                }}
                className={menuItem}
              >
                <RotateCcw size={16} className="shrink-0" />
                Cambiar de cuenta
              </button>

              <button
                role="menuitem"
                onClick={() => {
                  setMenuOpen(false);
                  onLogout?.();
                }}
                className="app-border flex w-full cursor-pointer items-center gap-2.5 border-t px-3.5 py-2.5 text-left text-[13px] text-[color:var(--p-danger-text)] transition-colors hover:bg-[var(--app-surface-muted)]"
              >
                <LogOut size={16} className="shrink-0" />
                Cerrar sesión
              </button>
            </div>
          )}
        </div>
      </div>

      {changingPassword && <ChangePasswordModal onClose={() => setChangingPassword(false)} />}
    </header>
  );
}
