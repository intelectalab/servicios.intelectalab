"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Eye, EyeOff, Globe, Pencil, Smartphone } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { PROFILE_NAME_EVENT } from "@/lib/auth";
import { Btn, Card, Chip, Field, INPUT, Kv, LoadError, PageHead, Screen, useToasts } from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";

type Session = {
  id: number;
  current: boolean;
  mobile: boolean;
  /** "Chrome en Windows"; null si la sesión es anterior a que se guardara este dato. */
  browser: string | null;
  mfa: boolean;
  started_at: string | null;
  last_used_at: string | null;
};

type Preferences = { in_app: boolean; email: boolean; whatsapp: boolean };

type Profile = {
  name: string;
  email: string;
  last_login_at: string | null;
  created_at: string | null;
  status: string;
  is_super_admin: boolean;
  tenant: string | null;
  roles: string[];
  branch: string | null;
  /** Sedes adicionales: lo que la persona ve además de su sede. */
  extra_branches: string[];
  team: string | null;
  mfa: boolean;
  sessions: Session[];
  /** null en la cuenta de plataforma, que no pertenece a ninguna empresa. */
  notification_preferences: Preferences | null;
};

const STATUS_LABELS: Record<string, string> = { ACTIVE: "Activo", INVITED: "Invitado", SUSPENDED: "Suspendido", DISABLED: "Deshabilitado" };

const CHANNELS: { key: keyof Preferences; label: string; hint?: string }[] = [
  { key: "in_app", label: "En la aplicación (campana)" },
  { key: "email", label: "Correo electrónico" },
  { key: "whatsapp", label: "WhatsApp", hint: "Por ejemplo, avisos de OT asignada para técnicos. Aún no disponible: la empresa no tiene proveedor de WhatsApp; tu elección queda guardada." },
];

function dateTime(value: string | null) {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? "—" : date.toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
}

/** "hace 5 min", "hace 3 h", "hace 2 días". */
function ago(value: string | null) {
  if (!value) return "";
  const minutes = Math.max(0, Math.round((Date.now() - new Date(value).getTime()) / 60_000));
  if (minutes < 1) return "hace un momento";
  if (minutes < 60) return `hace ${minutes} min`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `hace ${hours} h`;
  const days = Math.round(hours / 24);
  return `hace ${days} ${days === 1 ? "día" : "días"}`;
}

function initials(name: string) {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? "").join("");
}

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

/** Mi perfil y seguridad: cuenta, contraseña, sesiones y avisos. Lo usan la consola de la empresa y la de plataforma. */
export default function ProfileView() {
  const { toast, toasts } = useToasts();
  const [profile, setProfile] = useState<Profile | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [show, setShow] = useState(false);
  const [passwordErrors, setPasswordErrors] = useState<Record<string, string>>({});
  const [savingPassword, setSavingPassword] = useState(false);

  // null: mostrando el nombre; texto: editándolo.
  const [editingName, setEditingName] = useState<string | null>(null);
  const [nameError, setNameError] = useState("");
  const [savingName, setSavingName] = useState(false);

  const [closing, setClosing] = useState(false);
  const [preferences, setPreferences] = useState<Preferences | null>(null);
  const [savingPreferences, setSavingPreferences] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Profile>("/v1/me/profile")
      .then((data) => {
        if (cancelled) return;
        setProfile(data);
        setPreferences(data.notification_preferences);
        setError("");
      })
      .catch(() => {
        if (!cancelled) setError("No se pudieron cargar los datos de tu cuenta.");
      });
    return () => { cancelled = true; };
  }, [reload]);

  async function changePassword(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (!current) found.current_password = "Indica tu contraseña actual.";
    if (password.length < 8 || !/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/\d/.test(password)) {
      found.password = "Mínimo 8 caracteres, mayúsculas, minúsculas y un número.";
    }
    setPasswordErrors(found);
    if (Object.keys(found).length) return;

    setSavingPassword(true);
    try {
      await apiFetch("/v1/me/password", { method: "POST", body: JSON.stringify({ current_password: current, password }) });
      setCurrent("");
      setPassword("");
      toast("Contraseña actualizada", "success", "Se cerraron las demás sesiones de tu cuenta.");
      setReload((n) => n + 1);
    } catch (err) {
      setPasswordErrors(err instanceof ApiError && err.errors ? Object.fromEntries(Object.entries(err.errors).map(([field, messages]) => [field, messages[0]])) : {});
      toast(firstError(err, "No se pudo actualizar la contraseña."), "danger");
    } finally {
      setSavingPassword(false);
    }
  }

  async function saveName(event: FormEvent) {
    event.preventDefault();
    const name = (editingName ?? "").trim().replace(/\s+/g, " ");
    if (name.length < 2) {
      setNameError("Escribe tu nombre.");
      return;
    }
    setSavingName(true);
    try {
      const saved = await apiFetch<{ name: string }>("/v1/me/name", { method: "PUT", body: JSON.stringify({ name }) });
      setProfile((current) => (current ? { ...current, name: saved.name } : current));
      window.dispatchEvent(new CustomEvent(PROFILE_NAME_EVENT, { detail: saved.name }));
      setEditingName(null);
      toast("Nombre actualizado");
    } catch (err) {
      setNameError(firstError(err, "No se pudo guardar el nombre."));
    } finally {
      setSavingName(false);
    }
  }

  async function closeOthers() {
    setClosing(true);
    try {
      await apiFetch("/v1/me/sessions/revoke-others", { method: "POST" });
      toast("Se cerraron las demás sesiones");
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudieron cerrar las sesiones."), "danger");
    } finally {
      setClosing(false);
    }
  }

  async function savePreferences(event: FormEvent) {
    event.preventDefault();
    if (!preferences) return;
    setSavingPreferences(true);
    try {
      setPreferences(await apiFetch<Preferences>("/v1/me/notification-preferences", { method: "PUT", body: JSON.stringify(preferences) }));
      toast("Preferencias guardadas");
    } catch (err) {
      toast(firstError(err, "No se pudieron guardar las preferencias."), "danger");
    } finally {
      setSavingPreferences(false);
    }
  }

  if (error) {
    return (
      <Screen>
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
        {toasts}
      </Screen>
    );
  }

  if (!profile) {
    return (
      <Screen>
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const others = profile.sessions.filter((session) => !session.current).length;
  const reveal = (
    <button
      type="button"
      onClick={() => setShow((value) => !value)}
      aria-label={show ? "Ocultar contraseñas" : "Mostrar contraseñas"}
      title={show ? "Ocultar" : "Mostrar"}
      className="app-muted absolute right-1 top-1 grid h-8 w-8 cursor-pointer place-items-center rounded-lg hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
    >
      {show ? <EyeOff size={16} /> : <Eye size={16} />}
    </button>
  );

  return (
    <Screen>
      <PageHead title="Mi perfil y seguridad" />

      <SortableBoard
        storageKey="erp.cards.profile"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["account", "sessions"] },
          { className: "min-w-0 lg:min-h-24", cards: ["password", ...(preferences ? ["notifications"] : [])] },
        ]}
        cards={[
          {
            id: "account",
            node: (
              <Card title="Cuenta">
                <div className="mb-3 flex items-center gap-3.5">
                  <span aria-hidden="true" className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-[#1B8FC9] text-lg font-semibold text-[#fff]">
                    {initials(profile.name)}
                  </span>
                  <div className="min-w-0 flex-1">
                    {editingName === null ? (
                      <div className="flex min-w-0 items-center gap-1.5">
                        <h3 className="truncate text-[17px] font-semibold">{profile.name}</h3>
                        <button
                          type="button"
                          onClick={() => { setEditingName(profile.name); setNameError(""); }}
                          aria-label="Editar nombre"
                          title="Editar nombre"
                          className="app-muted grid h-7 w-7 shrink-0 cursor-pointer place-items-center rounded-lg hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
                        >
                          <Pencil size={14} />
                        </button>
                      </div>
                    ) : (
                      <form onSubmit={saveName} className="grid gap-1.5" noValidate>
                        <Field label="Nombre" required error={nameError}>
                          <input
                            className={INPUT}
                            value={editingName}
                            maxLength={255}
                            onChange={(e) => setEditingName(e.target.value)}
                            onKeyDown={(e) => { if (e.key === "Escape") setEditingName(null); }}
                            autoComplete="name"
                            autoFocus
                          />
                        </Field>
                        <div className="flex gap-1.5">
                          <Btn type="submit" size="sm" disabled={savingName}>{savingName ? "Guardando…" : "Guardar"}</Btn>
                          <Btn type="button" size="sm" variant="ghost" onClick={() => setEditingName(null)}>Cancelar</Btn>
                        </div>
                      </form>
                    )}
                    <p className="app-muted truncate">{profile.email}</p>
                  </div>
                </div>
                <Kv
                  rows={[
                    ["Empresa", profile.tenant ?? "Plataforma SaaS"],
                    ["Rol", profile.is_super_admin ? "SuperAdmin SaaS" : profile.roles.join(", ") || "—"],
                    profile.is_super_admin ? null : [profile.extra_branches.length ? "Sede principal" : "Sede", profile.branch ?? "—"],
                    profile.extra_branches.length ? ["Sedes adicionales", profile.extra_branches.join(", ")] : null,
                    profile.team ? ["Equipo", profile.team] : null,
                    [
                      "Verificación en dos pasos",
                      <span key="mfa" title={profile.mfa ? "Al iniciar sesión se pide un código enviado a tu correo." : undefined}>
                        <Chip tone={profile.mfa ? "success" : "warning"}>{profile.mfa ? "Activa" : "No activa"}</Chip>
                      </span>,
                    ],
                    ["Estado", STATUS_LABELS[profile.status] ?? profile.status],
                    ["Último acceso", dateTime(profile.last_login_at)],
                    ["Cuenta creada", dateTime(profile.created_at)],
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "password",
            node: (
              <Card title="Cambiar contraseña">
                <form onSubmit={changePassword} className="grid gap-3" noValidate>
                  <Field label="Contraseña actual" required error={passwordErrors.current_password}>
                    <span className="relative block">
                      <input
                        className={`${INPUT} pr-10`}
                        type={show ? "text" : "password"}
                        value={current}
                        onChange={(e) => setCurrent(e.target.value)}
                        autoComplete="current-password"
                      />
                      {reveal}
                    </span>
                  </Field>
                  <Field label="Nueva contraseña" required error={passwordErrors.password} hint="Mínimo 8 caracteres, mayúsculas, minúsculas y un número.">
                    <input
                      className={INPUT}
                      type={show ? "text" : "password"}
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      autoComplete="new-password"
                    />
                  </Field>
                  <div className="flex justify-end">
                    <Btn type="submit" disabled={savingPassword}>{savingPassword ? "Actualizando…" : "Actualizar contraseña"}</Btn>
                  </div>
                </form>
              </Card>
            ),
          },
          {
            id: "sessions",
            node: (
              <Card title="Sesiones recientes">
                {profile.sessions.length ? (
                  <ul className="mb-3 grid gap-2.5">
                    {profile.sessions.map((session) => (
                      <li key={session.id} className="flex items-start gap-2.5">
                        <span className="app-muted mt-0.5 shrink-0">{session.mobile ? <Smartphone size={17} /> : <Globe size={17} />}</span>
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-1.5">
                            <strong className="font-semibold">{session.mobile ? "Navegador móvil" : "Navegador de escritorio"}</strong>
                            {session.browser && <span className="app-muted text-[12.5px]">· {session.browser}</span>}
                            {session.current && <Chip tone="accent">Esta sesión</Chip>}
                            {session.mfa && <span title="Entró con verificación en dos pasos"><Chip tone="success">MFA</Chip></span>}
                          </div>
                          <small className="app-muted block text-xs">Inicio {ago(session.started_at)} · {dateTime(session.started_at)}</small>
                        </div>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="app-muted mb-3">Sin sesiones registradas.</p>
                )}
                <Btn
                  variant="secondary"
                  size="sm"
                  onClick={closeOthers}
                  disabled={closing || others === 0}
                  title={others === 0 ? "No tienes otras sesiones abiertas" : undefined}
                >
                  Cerrar las demás sesiones
                </Btn>
              </Card>
            ),
          },
          ...(preferences
            ? [{
                id: "notifications",
                node: (
                  <Card title="Preferencias de notificación">
                    <form onSubmit={savePreferences} className="grid gap-3">
                      {CHANNELS.map((channel) => (
                        <label key={channel.key} className="flex cursor-pointer items-start gap-2.5">
                          <input
                            type="checkbox"
                            className="mt-0.5 h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9]"
                            checked={preferences[channel.key]}
                            onChange={(e) => setPreferences({ ...preferences, [channel.key]: e.target.checked })}
                          />
                          <span>
                            <span className="font-medium">{channel.label}</span>
                            {channel.hint && <small className="app-muted block text-xs">{channel.hint}</small>}
                          </span>
                        </label>
                      ))}
                      <div className="flex justify-end">
                        <Btn type="submit" variant="secondary" disabled={savingPreferences}>Guardar preferencias</Btn>
                      </div>
                    </form>
                  </Card>
                ),
              }]
            : []),
        ]}
      />
      {toasts}
    </Screen>
  );
}
