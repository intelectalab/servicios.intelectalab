"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import {
  FileSignature, FileText, Folder, Home, KeyRound, Layers, LifeBuoy, Loader2, Lock, LogOut, Moon, Receipt, Send, Sun, ToggleLeft, Users,
  type LucideIcon,
} from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { clearAuthToken } from "@/lib/auth";
import { poppins } from "@/lib/fonts";
import { PORTAL_NAV, type PortalMe, type PortalSection } from "@/lib/portal";
import { Btn, Field, INPUT, LoadError, Modal, useToasts } from "@/components/ui/proto";

const ICONS: Record<PortalSection, LucideIcon> = {
  home: Home, quotes: FileText, approve: FileText, services: Layers, sign: FileSignature, requests: Send,
  documents: Folder, invoices: Receipt, pay: Receipt, support: LifeBuoy, support_write: LifeBuoy, users: Users,
};

type PortalContextValue = {
  me: PortalMe;
  reload: () => void;
  can: (section: PortalSection) => boolean;
  toast: ReturnType<typeof useToasts>["toast"];
};

const PortalContext = createContext<PortalContextValue | null>(null);

/** La cuenta del portal, sus secciones y los avisos (toasts) del portal. */
export function usePortal() {
  const value = useContext(PortalContext);
  if (!value) throw new Error("usePortal fuera del portal");
  return value;
}

/** Iniciales de la empresa (dos palabras), como en la cabecera de los PDF. */
const initials = (name: string) => name.split(/\s+/).filter((word) => /^[\p{L}\d]/u.test(word)).slice(0, 2).map((word) => word[0]).join("").toUpperCase() || "E";

/** Título de cada pantalla del portal (el .p-h1 del prototipo). */
export function PortalTitle({ children, actions }: { children: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
      <h1 className="flex flex-wrap items-center gap-2.5 text-[20px] font-semibold leading-tight text-[color:var(--p-brand-soft-text)] sm:text-[22px]">{children}</h1>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/** "Volver" de una ficha del portal (el .p-back del prototipo). */
export function PortalBack({ href, children }: { href: string; children: ReactNode }) {
  return (
    <Link href={href} className="mb-2 inline-flex items-center gap-1 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:underline">
      ← {children}
    </Link>
  );
}

/** Pantalla cuando el rol del portal no abre la sección (por su dirección). */
export function SectionGuard({ section, children }: { section: PortalSection; children: ReactNode }) {
  const { me, can } = usePortal();
  if (can(section)) return <>{children}</>;
  return (
    <div className="app-surface mx-auto mt-6 grid max-w-[520px] justify-items-center gap-2 rounded-xl px-6 py-10 text-center shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]">
      <span className="grid h-12 w-12 place-items-center rounded-xl bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]"><Lock size={22} /></span>
      <h2 className="text-lg font-semibold">Sin acceso</h2>
      <p className="app-muted text-[13.5px]">Tu rol de portal ({me.role_name}) no incluye esta sección.</p>
      <Link href="/portal" className="mt-2 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:underline">Ir al inicio</Link>
    </div>
  );
}

export default function PortalShell({ children }: { children: ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { toast, toasts } = useToasts();
  const [me, setMe] = useState<PortalMe | null>(null);
  const [error, setError] = useState("");
  const [reloadKey, setReloadKey] = useState(0);
  const [password, setPassword] = useState(false);
  const [leaving, setLeaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalMe>("/v1/portal/me")
      .then((data) => {
        if (!cancelled) {
          setMe(data);
          setError("");
        }
      })
      .catch((err) => {
        if (cancelled) return;
        // Una sesión que no es del portal (o una cuenta desactivada) vuelve al inicio de sesión.
        if (err instanceof ApiError && err.status === 403 && (err.body as { code?: string } | undefined)?.code === "NOT_PORTAL_USER") {
          clearAuthToken();
          router.replace("/login");
          return;
        }
        setError(err instanceof ApiError && err.status === 0 ? err.message : "No se pudo cargar tu portal.");
      });
    return () => { cancelled = true; };
  }, [reloadKey, router]);

  // En el teléfono la barra se desliza de lado: la sección abierta siempre queda a la vista.
  useEffect(() => {
    document.querySelector('nav[aria-label="Portal"] a[aria-current="page"]')?.scrollIntoView({ block: "nearest", inline: "nearest" });
  }, [pathname, me]);

  const reload = useCallback(() => setReloadKey((n) => n + 1), []);
  const can = useCallback((section: PortalSection) => Boolean(me?.sections.includes(section)), [me]);

  /** Claro u oscuro, como en el sistema: se recuerda en este navegador. */
  function toggleTheme() {
    const next = document.documentElement.dataset.theme === "dark" ? "light" : "dark";
    document.documentElement.dataset.theme = next;
    try {
      window.localStorage.setItem("theme", next);
    } catch {
      // Sin almacenamiento, el tema vale hasta recargar.
    }
  }

  async function logout() {
    setLeaving(true);
    try {
      await apiFetch("/v1/logout", { method: "POST" });
    } catch {
      // Aunque falle, la sesión se cierra en este navegador.
    } finally {
      clearAuthToken();
      router.replace("/login");
    }
  }

  if (!me) {
    return (
      <div className={`${poppins.className} app-text grid min-h-screen place-items-center bg-[var(--background)] p-6 text-sm`}>
        {error ? (
          <div className="app-surface w-full max-w-[420px] rounded-xl"><LoadError text={error} onRetry={reload} /></div>
        ) : (
          <span className="app-muted inline-flex items-center gap-2"><Loader2 size={16} className="animate-spin" /> Cargando tu portal…</span>
        )}
      </div>
    );
  }

  const nav = me.enabled ? PORTAL_NAV.filter(([, , section]) => me.sections.includes(section)) : [];
  const active = (href: string) => (href === "/portal" ? pathname === "/portal" : pathname === href || pathname.startsWith(`${href}/`));

  return (
    <PortalContext.Provider value={{ me, reload, can, toast }}>
      <div className={`${poppins.className} app-text flex min-h-screen w-full flex-col bg-[var(--background)] text-sm leading-[1.45]`}>
        <header className="flex min-h-16 flex-wrap items-center gap-x-4 gap-y-2 border-b-[3px] border-[color:var(--portal-line)] bg-[image:var(--portal-top)] px-3 py-2 text-[#fff] sm:px-5">
          <div className="flex min-w-0 items-center gap-2.5">
            {/* La marca es la de la empresa (sus iniciales mientras no tenga logo guardado), no la de la plataforma. */}
            <span aria-hidden="true" className="grid h-9 w-9 shrink-0 place-items-center rounded-[10px] bg-[#fff] text-[13px] font-bold text-[#0F5A82]">
              {initials(me.company_name)}
            </span>
            <div className="min-w-0 leading-tight">
              <strong className="block truncate text-[14.5px] font-semibold">{me.company_name}</strong>
              <small className="block text-[11.5px] text-[rgb(255_255_255/0.72)]">Portal de clientes</small>
            </div>
          </div>

          {/* Los nueve enlaces no caben junto a la marca y la cuenta en un portátil: van en una segunda fila salvo en pantallas muy anchas. */}
          <nav aria-label="Portal" className="order-3 flex w-full gap-0.5 overflow-x-auto [scrollbar-width:none] 2xl:order-none 2xl:w-auto 2xl:flex-1">
            {nav.map(([href, label, section]) => {
              const Icon = ICONS[section];
              const on = active(href);
              return (
                <Link
                  key={href}
                  href={href}
                  aria-current={on ? "page" : undefined}
                  className={`flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-[7px] px-2.5 py-2 text-[13px] transition-colors ${
                    on ? "bg-[#fff] font-semibold text-[#0F5A82]" : "font-medium text-[rgb(255_255_255/0.85)] hover:bg-[rgb(255_255_255/0.12)] hover:text-[#fff]"
                  }`}
                >
                  <Icon size={15} aria-hidden="true" />
                  <span>{label}</span>
                </Link>
              );
            })}
          </nav>

          <div className="ml-auto flex items-center gap-2.5 text-[13px]">
            <span className="hidden min-w-0 text-right leading-tight sm:block">
              <span className="block max-w-[320px] truncate font-medium">{me.name}</span>
              <small className="block max-w-[320px] truncate text-[11.5px] text-[rgb(255_255_255/0.72)]">{me.customer.name} · {me.role_name}</small>
            </span>
            <button
              type="button"
              onClick={toggleTheme}
              aria-label="Cambiar tema"
              title="Cambiar tema"
              className="grid h-[30px] w-[30px] cursor-pointer place-items-center rounded-[7px] border border-[rgb(255_255_255/0.22)] bg-[rgb(255_255_255/0.12)] hover:bg-[rgb(255_255_255/0.2)]"
            >
              <Moon size={15} className="theme-moon-icon" aria-hidden="true" />
              <Sun size={15} className="theme-sun-icon" aria-hidden="true" />
            </button>
            <button
              type="button"
              onClick={() => setPassword(true)}
              className="inline-flex h-[30px] cursor-pointer items-center gap-1.5 rounded-[7px] border border-[rgb(255_255_255/0.22)] bg-[rgb(255_255_255/0.12)] px-2.5 text-xs font-medium hover:bg-[rgb(255_255_255/0.2)]"
            >
              <KeyRound size={14} aria-hidden="true" /><span>Contraseña</span>
            </button>
            <button
              type="button"
              onClick={logout}
              disabled={leaving}
              className="inline-flex h-[30px] cursor-pointer items-center gap-1.5 rounded-[7px] border border-[rgb(255_255_255/0.22)] bg-[rgb(255_255_255/0.12)] px-2.5 text-xs font-medium hover:bg-[rgb(255_255_255/0.2)] disabled:opacity-60"
            >
              <LogOut size={14} aria-hidden="true" /><span>Salir</span>
            </button>
          </div>
        </header>

        <main className="mx-auto w-full max-w-[1080px] px-4 pb-12 pt-5 sm:px-5">
          {me.enabled ? children : (
            <div className="app-surface mx-auto mt-6 grid max-w-[520px] justify-items-center gap-2 rounded-xl px-6 py-10 text-center shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]">
              <span className="grid h-12 w-12 place-items-center rounded-xl bg-[var(--p-neutral-soft)] text-[color:var(--p-neutral-text)]"><ToggleLeft size={24} /></span>
              <h2 className="text-lg font-semibold">Portal desactivado</h2>
              <p className="app-muted text-[13.5px]">La empresa desactivó el portal. Contacta a tu ejecutivo.</p>
            </div>
          )}
        </main>
        <footer className="app-muted mt-auto px-4 pb-4 text-center text-[11.5px]">Desarrollado por GSCREATIVAS</footer>
      </div>

      {password && <PasswordModal onClose={() => setPassword(false)} onDone={() => { setPassword(false); toast("Tu contraseña se actualizó correctamente."); }} />}
      {toasts}
    </PortalContext.Provider>
  );
}

function PasswordModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [confirm, setConfirm] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    if (next !== confirm) {
      setErrors({ confirm: "Las contraseñas no coinciden." });
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      await apiFetch("/v1/portal/me/password", { method: "POST", body: JSON.stringify({ current_password: current, password: next }) });
      onDone();
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      } else {
        setErrors({ password: err instanceof Error ? err.message : "No se pudo cambiar la contraseña." });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Cambiar contraseña"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy || !current || !next || !confirm}>{busy ? "Guardando…" : "Guardar"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <Field label="Contraseña actual" required error={errors.current_password}>
          <input type="password" className={INPUT} value={current} onChange={(e) => setCurrent(e.target.value)} autoComplete="current-password" autoFocus />
        </Field>
        <Field label="Nueva contraseña" required error={errors.password}>
          <input type="password" className={INPUT} value={next} onChange={(e) => setNext(e.target.value)} autoComplete="new-password" />
        </Field>
        <Field label="Repite la nueva contraseña" required error={errors.confirm}>
          <input type="password" className={INPUT} value={confirm} onChange={(e) => setConfirm(e.target.value)} autoComplete="new-password" />
        </Field>
        <p className="app-muted text-xs">Al menos 8 caracteres, con mayúsculas, minúsculas y números. Tus otras sesiones se cerrarán.</p>
      </div>
    </Modal>
  );
}
