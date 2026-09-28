"use client";

import { useState, useRef, useEffect } from "react";
import {
  Menu, 
  Bell, 
  ChevronDown, 
  User as UserIcon, 
  Settings, 
  LogOut, 
  HelpCircle, 
  Building2,
  Moon,
  Sun,
} from "lucide-react";

export type UserProfile = {
  name: string;
  email?: string;
  role?: string;
  avatarUrl?: string;
};

type HeaderProps = {
  open: boolean;
  currentSection: string;
  onToggle: () => void;
  user?: UserProfile | null;
  onLogout?: () => void;
};

export default function Header({
  open,
  currentSection,
  onToggle,
  user,
  onLogout,
}: HeaderProps) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [darkMode, setDarkMode] = useState(() => {
    if (typeof window === "undefined") return false;
    const savedTheme = window.localStorage.getItem("theme");
    return savedTheme ? savedTheme === "dark" : window.matchMedia("(prefers-color-scheme: dark)").matches;
  });
  const dropdownRef = useRef<HTMLDivElement>(null);

  const userName = user?.name ?? "Usuario";
  const userRole = user?.role ?? "Administrador";
  const userEmail = user?.email ?? "online";
  const initial = userName.charAt(0).toUpperCase();

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

  return (
    <header className="app-surface app-border sticky top-0 z-[100] overflow-visible flex h-20 w-full items-center justify-between border-b px-6 shadow-[0_2px_8px_rgba(15,31,46,0.03)] sm:px-10">
      {/* Sección Izquierda: Toggle y Breadcrumbs con aire generoso */}
      <div className="flex items-center gap-5 min-w-0">
        <button
          onClick={onToggle}
          aria-label={open ? "Cerrar menú" : "Abrir menú"}
          className="app-muted app-hover flex h-10 w-10 shrink-0 items-center justify-center rounded-xl p-2 transition-colors active:scale-95"
        >
          <Menu size={22} />
        </button>

        <div className="app-border h-5 w-[1px] bg-current opacity-40" />

        <div className="flex items-center gap-3 text-sm sm:text-base truncate">
          <span className="app-muted hidden items-center gap-2 font-medium sm:flex">
            <Building2 size={18} />
            ERP SaaS
          </span>
          <span className="app-muted hidden text-lg sm:inline">/</span>
          <span className="app-text truncate font-semibold tracking-tight">
            {currentSection}
          </span>
        </div>
      </div>

      {/* Sección Derecha: Notificaciones y Perfil */}
      <div className="flex items-center gap-4 sm:gap-6 shrink-0">
        <button
          type="button"
          onClick={toggleTheme}
          aria-label="Cambiar tema"
          title="Cambiar tema"
          className="app-muted app-hover flex h-10 w-10 items-center justify-center rounded-xl transition-colors"
        >
          <Moon size={20} className="theme-moon-icon" />
          <Sun size={20} className="theme-sun-icon" />
        </button>
        {/* Notificaciones */}
        <button
          className="app-muted app-hover relative flex h-10 w-10 items-center justify-center rounded-xl transition-colors"
          aria-label="Ver notificaciones"
        >
          <Bell size={21} />
          <span className="absolute right-2.5 top-2.5 h-2.5 w-2.5 rounded-full bg-[#35B6D5] ring-2 ring-[var(--app-surface)]" />
        </button>

        <div className="app-border h-6 w-[1px] bg-current opacity-40" />

        {/* Menú de Usuario con Dropdown */}
        <div className="relative" ref={dropdownRef}>
          <button
            onClick={() => setMenuOpen((prev) => !prev)}
            className="app-hover flex items-center gap-3 rounded-2xl p-1.5 transition-all"
          >
            {/* Avatar destacado */}
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--app-brand-deep)] text-base font-bold text-[var(--app-brand)] shadow-sm">
              {initial}
            </div>

            {/* Datos de usuario en barra */}
            <div className="hidden text-left md:block">
              <div className="flex items-center gap-2">
                <span className="app-text text-sm font-semibold leading-tight">
                  {userName}
                </span>
                <span className="rounded-md bg-[var(--app-brand-soft)] px-2 py-0.5 text-[11px] font-semibold text-[var(--app-brand)] leading-tight">
                  {userRole}
                </span>
              </div>
              <p className="app-muted mt-1 text-xs leading-none">{userEmail}</p>
            </div>

            <ChevronDown
              size={17}
              className={`app-muted transition-transform duration-200 ${
                menuOpen ? "rotate-180" : ""
              }`}
            />
          </button>

          {/* Menú Flotante */}
          {menuOpen && (
            <div className="absolute right-0 top-full z-[110] mt-2 w-64 rounded-2xl border border-[#E1E7EC] bg-white p-1.5 shadow-[0_10px_25px_rgba(15,31,46,0.1)]">
              {/* Tarjeta de usuario interna siempre legible y estructurada */}
              <div className="border-b border-[#F0F4F7] bg-[#F8FAFC] rounded-xl px-3.5 py-3 mb-1">
                <p className="text-sm font-semibold text-[#0F1F2E] truncate">{userName}</p>
                <div className="mt-1.5 flex items-center justify-between gap-2">
                  <span className="rounded-md bg-[#EBF8FA] px-2 py-0.5 text-[11px] font-semibold text-[#006070]">
                    {userRole}
                  </span>
                  <span className="text-xs text-[#8A9BA8] truncate">{userEmail}</span>
                </div>
              </div>

              {/* Enlaces con nowrap para evitar saltos de línea indeseados */}
              <div className="space-y-0.5">
                <button
                  onClick={() => setMenuOpen(false)}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#5C6F81] transition-colors hover:bg-[#F6F8FA] hover:text-[#0F1F2E]"
                >
                  <UserIcon size={18} className="shrink-0" />
                  <span className="whitespace-nowrap">Mi Perfil</span>
                </button>
                <button
                  onClick={() => setMenuOpen(false)}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#5C6F81] transition-colors hover:bg-[#F6F8FA] hover:text-[#0F1F2E]"
                >
                  <Settings size={18} className="shrink-0" />
                  <span className="whitespace-nowrap">Configuración</span>
                </button>
                <button
                  onClick={() => setMenuOpen(false)}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#5C6F81] transition-colors hover:bg-[#F6F8FA] hover:text-[#0F1F2E]"
                >
                  <HelpCircle size={18} className="shrink-0" />
                  <span className="whitespace-nowrap">Centro de Ayuda</span>
                </button>
              </div>

              {/* Botón de cierre */}
              <div className="border-t border-[#F0F4F7] mt-1 pt-1">
                <button
                  onClick={() => {
                    setMenuOpen(false);
                    onLogout?.();
                  }}
                  className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-[#C4453B] transition-colors hover:bg-[#FCEAE8]"
                >
                  <LogOut size={18} className="shrink-0" />
                  <span className="whitespace-nowrap">Cerrar sesión</span>
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}