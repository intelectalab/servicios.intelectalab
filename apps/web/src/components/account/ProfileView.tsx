"use client";

import { useEffect, useState } from "react";
import { AlertCircle, RefreshCw } from "lucide-react";
import { apiFetch } from "@/lib/api";

type Me = {
  name: string;
  email: string;
  status: string;
  last_login_at: string | null;
  created_at: string | null;
};

const STATUS_LABELS: Record<string, string> = {
  ACTIVE: "Activo",
  INVITED: "Invitado",
  SUSPENDED: "Suspendido",
  DISABLED: "Deshabilitado",
};

function formatDate(value: string | null) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString("es-PE", { dateStyle: "long", timeStyle: "short" });
}

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("");
}

/** Datos de la cuenta de la sesión; lo usan la consola del tenant y la de plataforma. */
export default function ProfileView() {
  const [me, setMe] = useState<Me | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const data = await apiFetch<Me>("/v1/me");
        if (!cancelled) {
          setMe(data);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los datos de tu cuenta.");
      }
    }
    load();
    return () => { cancelled = true; };
  }, [reload]);

  const rows: [string, string][] = me
    ? [
        ["Nombre", me.name],
        ["Correo electrónico", me.email],
        ["Estado", STATUS_LABELS[me.status] ?? me.status],
        ["Último acceso", formatDate(me.last_login_at)],
        ["Cuenta creada", formatDate(me.created_at)],
      ]
    : [];

  return (
    <div className="app-text flex h-full min-h-0 flex-col">
      <div className="app-surface flex h-full min-h-0 flex-col overflow-auto rounded-br-[40px] rounded-tl-[40px] px-8 py-8 shadow-sm">
        <h1 className="text-2xl font-bold tracking-tight">Mi perfil</h1>
        <p className="app-muted mt-1 text-sm">Datos de tu cuenta.</p>

        {error ? (
          <div className="flex flex-col items-center justify-center py-20 text-center">
            <AlertCircle size={32} className="mb-3 text-red-500" />
            <p className="app-muted text-sm">{error}</p>
            <button
              onClick={() => setReload((n) => n + 1)}
              className="mt-4 flex cursor-pointer items-center gap-2 text-sm font-medium text-[var(--app-brand-deep)] hover:underline"
            >
              <RefreshCw size={14} /> Reintentar
            </button>
          </div>
        ) : (
          <section className="app-border mt-6 w-full max-w-xl rounded-2xl border p-6">
            <div className="flex items-center gap-4">
              {me ? (
                <span className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#1B8FC9] text-lg font-semibold text-white">
                  {initials(me.name)}
                </span>
              ) : (
                <span className="h-14 w-14 shrink-0 animate-pulse rounded-full bg-slate-200" />
              )}
              <div className="min-w-0">
                {me ? (
                  <>
                    <h2 className="truncate text-lg font-semibold">{me.name}</h2>
                    <p className="app-muted truncate text-sm">{me.email}</p>
                  </>
                ) : (
                  <div className="space-y-2">
                    <div className="h-4 w-40 animate-pulse rounded bg-slate-200" />
                    <div className="h-3 w-56 animate-pulse rounded bg-slate-200" />
                  </div>
                )}
              </div>
            </div>

            {me && (
              <dl className="app-border mt-6 divide-y divide-[color:var(--app-border)] border-t text-sm">
                {rows.map(([label, value]) => (
                  <div key={label} className="flex items-baseline justify-between gap-6 py-3">
                    <dt className="app-muted shrink-0">{label}</dt>
                    <dd className="min-w-0 truncate text-right font-medium">{value}</dd>
                  </div>
                ))}
              </dl>
            )}
          </section>
        )}
      </div>
    </div>
  );
}
