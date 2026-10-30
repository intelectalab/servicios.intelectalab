"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Eye, EyeOff, Globe, LifeBuoy, Mail, MessageSquare, Pencil, Smartphone } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { PROFILE_NAME_EVENT } from "@/lib/auth";
import { Btn, Card, Chip, Field, INPUT, Kv, LoadError, Modal, PageHead, Screen, useToasts } from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { shortDate } from "@/lib/commercial";

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
  subscription?: {
    plan_name: string;
    starts_at: string;
    ends_at: string | null;
    is_trial: boolean;
  } | null;
  support?: {
    whatsapp: string;
    email: string;
    schedule?: string;
  } | null;
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
  const [showSupportModal, setShowSupportModal] = useState(false);

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
                    profile.tenant && !profile.is_super_admin
                      ? [
                          "Plan de la empresa",
                          profile.subscription ? (
                            <span className="flex items-center gap-2">
                              <strong className="font-medium">{profile.subscription.plan_name}</strong>
                              <Chip tone={profile.subscription.is_trial ? "warning" : "success"}>
                                {profile.subscription.is_trial ? "Prueba gratuita" : "Activo"}
                              </Chip>
                            </span>
                          ) : (
                            <span className="app-muted">Sin plan asignado</span>
                          ),
                        ]
                      : null,
                    profile.tenant && !profile.is_super_admin && profile.subscription?.ends_at
                      ? [
                          "Vigencia",
                          <span className="flex flex-wrap items-center gap-1.5">
                            <span>Hasta el {shortDate(profile.subscription.ends_at)}</span>
                            {(() => {
                              const diffDays = Math.ceil(
                                (new Date(profile.subscription.ends_at).getTime() - Date.now()) / (1000 * 60 * 60 * 24)
                              );
                              if (diffDays < 0) return <span className="font-medium text-[color:var(--p-danger-text)]">(Vencido)</span>;
                              if (diffDays === 0) return <span className="font-medium text-amber-600">(Vence hoy)</span>;
                              if (diffDays <= 7) return <span className="font-medium text-amber-600">({diffDays} días restantes)</span>;
                              return null;
                            })()}
                            <button
                              type="button"
                              onClick={() => setShowSupportModal(true)}
                              className="ml-1 cursor-pointer text-xs font-medium text-[color:var(--p-brand)] hover:underline"
                            >
                              Extender
                            </button>
                          </span>,
                        ]
                      : null,
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

                {/* Banner de soporte / extensión de plan */}
                {profile.tenant && !profile.is_super_admin && (
                  <div className="mt-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 rounded-xl border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3 text-xs">
                    <div className="flex items-start gap-2.5">
                      <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-[var(--p-brand-soft)] text-[color:var(--p-brand)]">
                        <LifeBuoy size={15} />
                      </div>
                      <div>
                        <strong className="block text-xs font-semibold text-[color:var(--app-text)]">
                          ¿Quieres extender o renovar tu plan?
                        </strong>
                        <p className="app-muted mt-0.5 text-[11.5px] leading-relaxed">
                          Contacta con soporte para renovar tu suscripción, agregar meses o consultar opciones de pago.
                        </p>
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => setShowSupportModal(true)}
                      className="cursor-pointer shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3 py-1.5 text-xs font-semibold text-[color:var(--app-text)] shadow-2xs hover:bg-[var(--app-surface-muted)] hover:border-[color:var(--p-brand)] transition"
                    >
                      <LifeBuoy size={13} className="text-[color:var(--p-brand)]" />
                      <span>Contactar con soporte</span>
                    </button>
                  </div>
                )}
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

      {showSupportModal && (() => {
        const whatsappRaw = profile.support?.whatsapp || "+51999999999";
        const whatsappClean = whatsappRaw.replace(/[^0-9]/g, "");
        const supportEmail = profile.support?.email || "soporte@erp-saas.com";

        return (
          <Modal
            title="Extender plan o contactar con soporte"
            size="md"
            onClose={() => setShowSupportModal(false)}
            footer={
              <Btn variant="secondary" onClick={() => setShowSupportModal(false)}>
                Cerrar
              </Btn>
            }
          >
            <div className="space-y-4 text-xs">
              <div className="rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3">
                <span className="app-muted block text-[11px] font-medium uppercase tracking-wider">Tu empresa y plan</span>
                <div className="mt-1 flex items-center justify-between">
                  <span className="text-sm font-semibold">{profile.tenant}</span>
                  <Chip tone={profile.subscription?.is_trial ? "warning" : "success"}>
                    {profile.subscription?.plan_name ?? "Sin plan"}
                  </Chip>
                </div>
                {profile.subscription?.ends_at && (
                  <p className="app-muted mt-1 text-[11.5px]">
                    Vigencia actual: <strong className="text-[color:var(--app-text)]">{shortDate(profile.subscription.ends_at)}</strong>
                  </p>
                )}
              </div>

              <p className="app-muted text-xs leading-relaxed">
                Para coordinar la extensión de tu periodo (1, 3, 6 o 12 meses), registrar transferencias bancarias o consultar sobre planes superiores, ponte en contacto con nosotros:
              </p>

              <div className="grid gap-2.5">
                <a
                  href={`https://wa.me/${whatsappClean}?text=${encodeURIComponent(
                    `Hola Soporte, me comunico desde la empresa ${profile.tenant ?? ""} (${profile.subscription?.plan_name ?? "Plan"}). Quisiera consultar sobre la extensión o renovación de nuestro plan.`
                  )}`}
                  target="_blank"
                  rel="noreferrer"
                  className="flex items-center justify-between rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] p-3 transition hover:border-[color:var(--p-brand)] hover:bg-[var(--app-surface-muted)]"
                >
                  <div className="flex items-center gap-2.5">
                    <div className="flex h-8 w-8 items-center justify-center rounded-md bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                      <MessageSquare size={16} />
                    </div>
                    <div>
                      <strong className="block text-xs font-semibold text-[color:var(--app-text)]">WhatsApp de Soporte</strong>
                      <span className="app-muted text-[11px]">{whatsappRaw}</span>
                    </div>
                  </div>
                  <span className="text-xs font-medium text-[color:var(--p-brand)]">Iniciar chat →</span>
                </a>

                <div className="flex items-center justify-between rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] p-3">
                  <div className="flex items-center gap-2.5">
                    <div className="flex h-8 w-8 items-center justify-center rounded-md bg-sky-50 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300">
                      <Mail size={16} />
                    </div>
                    <div>
                      <strong className="block text-xs font-semibold text-[color:var(--app-text)]">Correo de Soporte y Facturación</strong>
                      <span className="app-muted text-[11px]">{supportEmail}</span>
                    </div>
                  </div>
                  <a
                    href={`mailto:${supportEmail}?subject=${encodeURIComponent(`Solicitud de extensión de plan - ${profile.tenant ?? ""}`)}&body=${encodeURIComponent(`Hola equipo de soporte,\n\nDeseo coordinar la extensión del plan ${profile.subscription?.plan_name ?? ""} para la empresa ${profile.tenant ?? ""}.\n\nSaludos,\n${profile.name}`)}`}
                    className="text-xs font-medium text-[color:var(--p-brand)] hover:underline"
                  >
                    Enviar correo →
                  </a>
                </div>

                {profile.support?.schedule && (
                  <p className="app-muted text-[11px] text-center pt-1">
                    Horario de atención: {profile.support.schedule}
                  </p>
                )}
              </div>
            </div>
          </Modal>
        );
      })()}

      {toasts}
    </Screen>
  );
}
