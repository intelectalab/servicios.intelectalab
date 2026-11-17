"use client";

import { useEffect, useState, type FormEvent } from "react";
import { KeyRound, Power, PowerOff, RefreshCw } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { fieldErrors, firstError } from "@/lib/crm";
import { RUN_STATES } from "@/lib/personal";
import {
  Badge, Btn, Card, Field, INPUT, Kpi, Kpis, Kv, LoadError, Notice, PageHead, Screen, Tabs, useToasts,
} from "@/components/ui/proto";
import { HrSyncSecretModal } from "@/components/dashboard/personal/HrSyncModals";
import { ConfirmModal, CopyLine, RUN_KINDS, when } from "@/components/dashboard/personal/HrSyncShared";
import { HrEventsTables, HrRunsTable, HrUnmatchedTable, RunCounts } from "@/components/dashboard/personal/HrSyncTables";
import type { HrSyncRun, HrSyncStatus } from "@/types/personal";

type Tab = "unmatched" | "runs" | "events";
type Asking = "activate" | "deactivate" | "rotate" | "resync";

export default function HrSyncView() {
  const { toast, toasts } = useToasts();
  const [status, setStatus] = useState<HrSyncStatus | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [tab, setTab] = useState<Tab>("unmatched");
  const [asking, setAsking] = useState<Asking | null>(null);
  const [busy, setBusy] = useState(false);
  const [secret, setSecret] = useState<string | null>(null);

  // Formulario de la conexión
  const [baseUrl, setBaseUrl] = useState("");
  const [token, setToken] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<HrSyncStatus>("/v1/hr/sync/status")
      .then((next) => {
        if (cancelled) return;
        setStatus(next);
        setError("");
        setBaseUrl((current) => current || next.connection?.base_url || "https://");
      })
      .catch(() => { if (!cancelled) setError("No se pudo cargar la sincronización."); });
    return () => { cancelled = true; };
  }, [reload]);

  const refresh = () => setReload((n) => n + 1);
  const connection = status?.connection ?? null;
  const active = connection?.status === "ACTIVE";

  async function saveConnection(event: FormEvent) {
    event.preventDefault();
    const local: Record<string, string> = {};
    if (!/^https:\/\/\S+$/.test(baseUrl.trim())) local.base_url = "La dirección debe empezar con https://";
    if (!connection?.has_token && !token.trim()) local.token = "Pega el token de servicio que te dio el SaaS de RRHH.";
    setErrors(local);
    if (Object.keys(local).length) return;

    setSaving(true);
    try {
      const response = await apiFetch<{ status: HrSyncStatus; secret: string | null }>("/v1/hr/sync/connection", {
        method: "PUT",
        body: JSON.stringify({ base_url: baseUrl.trim(), ...(token.trim() ? { token: token.trim() } : {}) }),
      });
      setStatus(response.status);
      setBaseUrl(response.status.connection?.base_url ?? baseUrl.trim());
      setToken("");
      toast("Conexión guardada");
      if (response.secret) setSecret(response.secret);
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!fields.base_url && !fields.token) toast(firstError(err, "No se pudo guardar la conexión."), "danger");
    } finally {
      setSaving(false);
    }
  }

  async function run(action: Asking) {
    setBusy(true);
    try {
      if (action === "rotate") {
        const response = await apiFetch<{ secret: string }>("/v1/hr/sync/connection/rotate", { method: "POST" });
        setSecret(response.secret);
        toast("Nueva clave de firma generada");
      } else if (action === "activate") {
        const response = await apiFetch<{ run: HrSyncRun; status: HrSyncStatus }>("/v1/hr/sync/activate", { method: "POST" });
        setStatus(response.status);
        toast("Conexión activa: RRHH es el maestro del personal", "success",
          `${response.run.applied} aplicados · ${response.run.unmatched} a la bandeja`);
      } else if (action === "resync") {
        const response = await apiFetch<{ run: HrSyncRun }>("/v1/hr/sync/full-resync", { method: "POST" });
        const failed = response.run.status !== "COMPLETED";
        toast(failed ? "La relectura no terminó" : "Relectura completa", failed ? "danger" : "success",
          failed ? response.run.error ?? undefined : `${response.run.applied} aplicados · ${response.run.unmatched} a la bandeja`);
      } else {
        const next = await apiFetch<HrSyncStatus>("/v1/hr/sync/deactivate", { method: "POST" });
        setStatus(next);
        toast("Conexión desactivada: el personal se edita en el ERP");
      }
      setAsking(null);
      refresh();
    } catch (err) {
      // Activar sin éxito: la conexión sigue desactivada y la ejecución queda en el historial.
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      setAsking(null);
      refresh();
    } finally {
      setBusy(false);
    }
  }

  const lastRun = status?.last_run ?? null;
  const counts = status?.counts;
  const noConnection = "Primero guarda la conexión";

  return (
    <Screen>
      <PageHead
        title="Sincronización con el SaaS de RRHH"
        actions={status && (
          <>
            {active ? (
              <>
                <Btn variant="secondary" permission="hr_sync.approve" disabled={busy} onClick={() => setAsking("resync")}>
                  <RefreshCw size={15} />
                  <span>Releer todo</span>
                </Btn>
                <Btn variant="danger-ghost" permission="hr_sync.approve" disabled={busy} onClick={() => setAsking("deactivate")}>
                  <PowerOff size={15} />
                  <span>Desactivar</span>
                </Btn>
              </>
            ) : (
              <Btn
                permission="hr_sync.approve"
                disabled={busy || !connection}
                title={connection ? undefined : noConnection}
                onClick={() => setAsking("activate")}
              >
                <Power size={15} />
                <span>Activar (carga inicial)</span>
              </Btn>
            )}
          </>
        )}
      />

      {error ? (
        <Card><LoadError text={error} onRetry={refresh} /></Card>
      ) : !status || !counts ? (
        <Card><div className="h-32 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
      ) : (
        <>
          {status.simulated && <Notice>Conectado al simulador del SaaS de RRHH (solo en este entorno de desarrollo).</Notice>}
          {counts.outbound_dead > 0 && (
            <Notice tone="warn">
              {counts.outbound_dead} {counts.outbound_dead === 1 ? "envío fallido" : "envíos fallidos"} tras {status.max_attempts} intentos: revísalos en Mensajes → Enviados.
            </Notice>
          )}

          <Card>
            <div className="flex flex-wrap items-start gap-4">
              <div className="grid gap-1.5">
                <span className="app-muted text-[10.5px] font-semibold uppercase tracking-[.04em]">Modo actual</span>
                <span
                  className={`inline-flex items-center rounded-lg px-3 py-1.5 text-[17px] font-semibold ${
                    active ? "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]" : "bg-[var(--p-neutral-soft)] text-[color:var(--p-neutral-text)]"
                  }`}
                >
                  {active ? "RRHH maestro" : "ERP maestro"}
                </span>
                <small className="app-muted text-xs">
                  {active
                    ? `Activa desde ${when(connection?.activated_at)}`
                    : connection
                      ? connection.deactivated_at ? `Desactivada el ${when(connection.deactivated_at)}` : "Conexión registrada, sin activar"
                      : "Sin conexión"}
                </small>
              </div>
              <div className="min-w-0 flex-[1_1_320px]">
                <Kv
                  rows={[
                    ["Última sincronización", lastRun ? (
                      <span key="run" className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="font-medium">{RUN_KINDS[lastRun.kind] ?? lastRun.kind}</span>
                        <Badge tone={stateOf(RUN_STATES, lastRun.status)[1]}>{stateOf(RUN_STATES, lastRun.status)[0]}</Badge>
                        <span className="app-muted text-[12.5px]">{when(lastRun.finished_at ?? lastRun.started_at)}</span>
                      </span>
                    ) : "Aún no hay"],
                    lastRun ? ["Resultado", <RunCounts key="counts" run={lastRun} />] : null,
                    lastRun?.error ? ["Error", <span key="error" className="text-[color:var(--p-danger-text)] [overflow-wrap:anywhere]">{lastRun.error}</span>] : null,
                    connection?.initial_match_at ? ["Carga inicial", when(connection.initial_match_at)] : null,
                    connection?.last_event_at ? ["Último mensaje de RRHH", when(connection.last_event_at)] : null,
                  ]}
                />
              </div>
            </div>
          </Card>

          <Kpis row>
            <Kpi label="En la bandeja" value={counts.unmatched} tone={counts.unmatched ? "warning" : "brand"} />
            <Kpi label="Trabajadores de RRHH" value={counts.employees_hr} />
            <Kpi label="Trabajadores solo del ERP" value={counts.employees_local} />
            <Kpi label="Envíos pendientes" value={counts.outbound_pending} />
            <Kpi label="Envíos fallidos" value={counts.outbound_dead} tone={counts.outbound_dead ? "danger" : "brand"} />
            <Kpi label="Mensajes con error" value={counts.inbound_failed} tone={counts.inbound_failed ? "danger" : "brand"} />
          </Kpis>

          <Card
            title="Conexión"
            actions={connection && (
              <Btn size="sm" variant="secondary" permission="hr_sync.manage" disabled={busy} onClick={() => setAsking("rotate")}>
                <KeyRound size={14} />
                <span>Nueva clave de firma</span>
              </Btn>
            )}
          >
            <div className="grid gap-5 lg:grid-cols-2">
              <form onSubmit={saveConnection} className="grid content-start gap-3">
                <Field label="Dirección del SaaS de RRHH (https)" required error={errors.base_url} hint={active ? "Para cambiarla, desactiva la conexión." : undefined}>
                  <input
                    className={`${INPUT} font-mono text-[12.5px]`} value={baseUrl} onChange={(e) => setBaseUrl(e.target.value)}
                    maxLength={300} spellCheck={false} disabled={saving}
                  />
                </Field>
                <Field label="Token de servicio" required={!connection?.has_token} error={errors.token}>
                  <input
                    className={INPUT} type="password" value={token} onChange={(e) => setToken(e.target.value)} maxLength={500} autoComplete="new-password"
                    disabled={saving}
                    placeholder={connection?.has_token ? "Guardado en el Credential Vault — escribe uno nuevo para cambiarlo" : "El que te dio el SaaS de RRHH"}
                  />
                </Field>
                <div>
                  <Btn type="submit" permission="hr_sync.manage" disabled={saving}>{saving ? "Guardando…" : connection ? "Guardar cambios" : "Guardar conexión"}</Btn>
                </div>
              </form>
              <div className="grid content-start gap-3">
                <CopyLine label="Dirección del webhook" value={status.webhook_url} />
                <CopyLine label="ID de conexión" value={connection?.id ?? null} hint={connection ? "RRHH lo envía en la cabecera X-Connection-Id." : "Se genera al guardar la conexión."} />
                <CopyLine label="RUC de la empresa" value={status.tenant_ruc} />
                <small className="app-muted text-xs">
                  Clave de firma: {connection?.has_secret ? "guardada en el Credential Vault" : "se genera al guardar la conexión"}. Firma: <code className="font-mono">X-Signature: sha256=HMAC del cuerpo</code>.
                </small>
              </div>
            </div>
          </Card>

          <Tabs
            tabs={[
              ["unmatched", "Bandeja", counts.unmatched],
              ["runs", "Ejecuciones", null],
              ["events", "Mensajes", counts.inbound_failed + counts.outbound_dead || null],
            ]}
            value={tab}
            onChange={(key) => setTab(key as Tab)}
          />
          {tab === "unmatched" && <HrUnmatchedTable toast={toast} reload={reload} onChanged={refresh} />}
          {tab === "runs" && <HrRunsTable toast={toast} reload={reload} />}
          {tab === "events" && <HrEventsTables toast={toast} reload={reload} onChanged={refresh} failedOut={counts.outbound_dead} />}
        </>
      )}

      {asking === "activate" && (
        <ConfirmModal title="Activar la conexión" confirmLabel="Activar" busy={busy} onClose={() => setAsking(null)} onConfirm={() => run("activate")}>
          <p>Se leen todos los trabajadores del SaaS de RRHH y se emparejan por documento con los del ERP. Los dudosos van a la bandeja.</p>
          <p>Desde ese momento RRHH es el maestro: trabajadores, puestos y ausencias se editan allí.</p>
          <p className="app-muted">Si RRHH no responde, la conexión sigue desactivada.</p>
        </ConfirmModal>
      )}
      {asking === "resync" && (
        <ConfirmModal title="Releer todo" confirmLabel="Releer todo" busy={busy} onClose={() => setAsking(null)} onConfirm={() => run("resync")}>
          <p>Se vuelven a leer todos los trabajadores del SaaS de RRHH y se aplican los cambios. Lo dudoso va a la bandeja.</p>
        </ConfirmModal>
      )}
      {asking === "deactivate" && (
        <ConfirmModal title="Desactivar la conexión" confirmLabel="Desactivar" variant="danger" busy={busy} onClose={() => setAsking(null)} onConfirm={() => run("deactivate")}>
          <p>No se borra nada: el personal que llegó de RRHH vuelve a editarse en el ERP y conserva su vínculo.</p>
          <p className="app-muted">Los cambios de RRHH dejan de aplicarse y las horas del mes salen en Excel.</p>
        </ConfirmModal>
      )}
      {asking === "rotate" && (
        <ConfirmModal title="Nueva clave de firma" confirmLabel="Generar clave" variant="danger" busy={busy} onClose={() => setAsking(null)} onConfirm={() => run("rotate")}>
          <p>La clave actual deja de funcionar: los mensajes de RRHH se rechazarán hasta que cargues la nueva allí.</p>
        </ConfirmModal>
      )}
      {secret && (
        <HrSyncSecretModal
          secret={secret}
          webhookUrl={status?.webhook_url ?? ""}
          connectionId={status?.connection?.id ?? null}
          ruc={status?.tenant_ruc ?? null}
          onClose={() => setSecret(null)}
        />
      )}
      {toasts}
    </Screen>
  );
}
