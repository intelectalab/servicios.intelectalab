"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Bell, CheckCheck } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { markAllRead, markRead, onNotificationsChanged, relTime, type AppNotification, type NotificationSummary } from "@/lib/notifications";

// Cada cuánto se mira si hay avisos nuevos (solo con la pestaña a la vista).
const POLL_MS = 60_000;

/**
 * Campana de la cabecera: contador de no leídas ("9+" desde diez, como el prototipo) y las más
 * recientes en un panel. Un clic marca la notificación como leída y abre lo que la originó.
 */
export default function NotificationBell({ className }: { className: string }) {
  const router = useRouter();
  const [summary, setSummary] = useState<NotificationSummary | null>(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  const load = useCallback(() => {
    apiFetch<NotificationSummary>("/v1/me/notifications/summary").then(setSummary).catch(() => undefined);
  }, []);

  useEffect(() => {
    load();
    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") load();
    }, POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === "visible") load();
    };
    document.addEventListener("visibilitychange", onVisible);
    const off = onNotificationsChanged(load);
    return () => {
      window.clearInterval(timer);
      document.removeEventListener("visibilitychange", onVisible);
      off();
    };
  }, [load]);

  useEffect(() => {
    if (!open) return;
    load();
    function onDown(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    }
    function onKey(event: KeyboardEvent) {
      if (event.key === "Escape") setOpen(false);
    }
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open, load]);

  async function openItem(item: AppNotification) {
    setOpen(false);
    if (!item.read) await markRead(item.id).catch(() => undefined);
    if (item.link) router.push(item.link);
  }

  async function readAll() {
    setBusy(true);
    await markAllRead().catch(() => undefined);
    setBusy(false);
  }

  const unread = summary?.unread ?? 0;
  const latest = summary?.latest ?? [];

  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        className={className}
        onClick={() => setOpen((value) => !value)}
        aria-haspopup="true"
        aria-expanded={open}
        aria-label={unread ? `Notificaciones: ${unread} sin leer` : "Notificaciones"}
        title="Notificaciones"
      >
        <Bell size={20} />
        {unread > 0 && (
          <span className="absolute right-1 top-1 grid h-[17px] min-w-[17px] place-items-center rounded-full bg-[#E5484D] px-1 text-[10px] font-semibold leading-none text-[#fff] ring-2 ring-[var(--app-surface)]">
            {unread > 9 ? "9+" : unread}
          </span>
        )}
      </button>

      {open && (
        <div
          role="dialog"
          aria-label="Notificaciones"
          className="app-surface app-border fixed left-3 right-3 top-[60px] z-[110] overflow-hidden rounded-xl border shadow-[0_20px_50px_rgba(8,25,55,0.28)] sm:absolute sm:left-auto sm:right-0 sm:top-[calc(100%+8px)] sm:w-[400px]"
        >
          <div className="app-border flex items-center gap-2 border-b px-3.5 py-3">
            <strong className="app-text text-[13.5px] font-semibold">Notificaciones</strong>
            {unread > 0 && <span className="whitespace-nowrap rounded-full bg-[var(--p-brand-soft)] px-2 text-[11px] font-semibold text-[color:var(--p-brand-soft-text)]">{unread} sin leer</span>}
            <button
              type="button"
              onClick={readAll}
              disabled={busy || unread === 0}
              className="ml-auto inline-flex shrink-0 cursor-pointer items-center gap-1 whitespace-nowrap rounded-md px-1.5 py-1 text-[12px] font-medium text-[color:var(--p-brand-text)] hover:bg-[var(--app-surface-muted)] disabled:cursor-not-allowed disabled:opacity-45"
            >
              <CheckCheck size={14} />
              Marcar todas como leídas
            </button>
          </div>

          {latest.length === 0 ? (
            <p className="app-muted px-4 py-8 text-center text-[13px]">{summary ? "No tienes notificaciones." : "Cargando…"}</p>
          ) : (
            <ul className="max-h-[min(420px,60vh)] overflow-y-auto">
              {latest.map((item) => (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => openItem(item)}
                    className={`app-border flex w-full cursor-pointer gap-2.5 border-b px-3.5 py-3 text-left transition-colors last:border-b-0 hover:bg-[var(--app-surface-muted)] ${item.read ? "" : "bg-[var(--p-brand-soft)]/50"}`}
                  >
                    <span aria-hidden="true" className={`mt-[6px] h-2 w-2 shrink-0 rounded-full ${item.read ? "bg-transparent" : "bg-[#1B8FC9]"}`} />
                    <span className="min-w-0 flex-1">
                      <strong className={`app-text block text-[13px] leading-snug ${item.read ? "font-medium" : "font-semibold"}`}>{item.title}</strong>
                      <span className="app-muted block text-[12.5px] leading-snug [overflow-wrap:anywhere]">{item.body}</span>
                      <small className="app-muted mt-0.5 block text-[11px]">{relTime(item.created_at)}</small>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}

          <Link
            href="/notifications"
            onClick={() => setOpen(false)}
            className="app-border block border-t px-3.5 py-2.5 text-center text-[12.5px] font-medium text-[color:var(--p-brand-text)] hover:bg-[var(--app-surface-muted)]"
          >
            Ver todas
          </Link>
        </div>
      )}
    </div>
  );
}
