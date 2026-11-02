"use client";

import { useEffect, useState } from "react";
import { AlertTriangle, CheckCircle2, ShieldCheck, XCircle } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { dateTime, firstError } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, DataTable, ExportButtons, Field, LoadError, PageHead, Screen, deniedText, useToasts, type Column,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";

type Control = { key: string; label: string; ok: boolean; detail: string };
type AccessEvent = { id: string; action: string; label: string; user: string | null; ip: string | null; mfa: boolean | null; at: string };
type Policy = { require_mfa_all: boolean; session_hours: number; allow_remember: boolean; idle_minutes: number };
type ProbeResult = { resource: string; label: string; result: "BLOCKED" | "LEAK" | "NO_DATA"; detail: string };
type SecurityData = {
  controls: Control[];
  activity: {
    logins: number; logins_without_mfa: number; failed: number; locks: number; locked_now: string[];
    open_sessions: number; open_sessions_without_mfa: number; recent: AccessEvent[];
  };
  policy: Policy;
  idle_options: number[];
  last_probe: { created_at: string; metadata: string; user_name: string | null } | null;
};

const PROBE_CHIP: Record<ProbeResult["result"], [string, "success" | "danger" | "muted"]> = {
  BLOCKED: ["Bloqueado", "success"],
  LEAK: ["FUGA", "danger"],
  NO_DATA: ["Sin datos para probar", "muted"],
};

const EXPORT_COLUMNS: ExportColumn<AccessEvent>[] = [
  { label: "Fecha", value: (e) => dateTime(e.at), width: 22, kind: "center" },
  { label: "Movimiento", value: (e) => e.label, width: 30 },
  { label: "Usuario", value: (e) => e.user ?? "", width: 28 },
  { label: "Con código", value: (e) => (e.mfa === null ? "" : e.mfa ? "Sí" : "No"), width: 12, kind: "center" },
  { label: "IP", value: (e) => e.ip ?? "", width: 18 },
];

function Stat({ label, value, tone }: { label: string; value: number | string; tone?: "warn" }) {
  return (
    <div className="rounded-xl border border-[color:var(--app-border)] px-3 py-2">
      <p className="app-muted text-[10.5px] font-semibold uppercase tracking-[.04em]">{label}</p>
      <p className={`text-lg font-semibold tabular-nums ${tone === "warn" ? "text-[color:var(--p-warning-text)]" : ""}`}>{value}</p>
    </div>
  );
}

export default function SecurityView() {
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("tenant.manage");
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<SecurityData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [probe, setProbe] = useState<{ results: ProbeResult[]; at: string } | null>(null);
  const [probing, setProbing] = useState(false);
  const [policy, setPolicy] = useState<Policy | null>(null);
  const [saving, setSaving] = useState(false);
  const [policyError, setPolicyError] = useState("");
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<SecurityData>("/v1/security")
      .then((response) => { if (!cancelled) { setData(response); setPolicy(response.policy); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar Seguridad y aislamiento."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function runProbe() {
    setProbing(true);
    try {
      setProbe(await apiFetch<{ results: ProbeResult[]; at: string }>("/v1/security/isolation-probe", { method: "POST" }));
    } catch (err) {
      toast(firstError(err, "No se pudo ejecutar la prueba."), "danger");
    } finally {
      setProbing(false);
    }
  }

  async function savePolicy() {
    if (!policy) return;
    setSaving(true);
    setPolicyError("");
    try {
      const response = await apiFetch<SecurityData>("/v1/security/policy", { method: "PUT", body: JSON.stringify(policy) });
      setData(response);
      setPolicy(response.policy);
      toast("Política guardada");
    } catch (err) {
      setPolicyError(firstError(err, "No se pudo guardar la política."));
    } finally {
      setSaving(false);
    }
  }

  async function runExport(format: "xlsx" | "csv") {
    if (!data) return;
    setExporting(true);
    try {
      await recordExport("audit", format, data.activity.recent.length);
      if (format === "xlsx") await exportXlsx("actividad-de-acceso", "Actividad de acceso", EXPORT_COLUMNS, data.activity.recent);
      else exportCsv("actividad-de-acceso", EXPORT_COLUMNS, data.activity.recent);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const lastProbe = data?.last_probe ? (JSON.parse(data.last_probe.metadata || "{}") as { note?: string }) : null;
  const eventCols: Column<AccessEvent>[] = [
    { label: "Fecha", render: (e) => <span className="whitespace-nowrap">{dateTime(e.at)}</span> },
    {
      label: "Movimiento",
      render: (e) => (
        <span className="inline-flex flex-wrap items-center gap-1.5">
          <span className={["auth.login_failed", "auth.account_locked", "auth.session_idle"].includes(e.action) ? "text-[color:var(--p-warning-text)]" : ""}>{e.label}</span>
          {e.mfa === false && <Chip tone="warning">sin código</Chip>}
        </span>
      ),
    },
    { label: "Usuario", render: (e) => e.user ?? "—" },
    { label: "IP", render: (e) => <code className="text-[12px]">{e.ip ?? "—"}</code> },
  ];

  return (
    <Screen>
      <PageHead title="Seguridad y aislamiento" />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data || !policy ? (
        <Card><p className="app-muted">Cargando…</p></Card>
      ) : (
        <div className="grid gap-x-[18px] lg:grid-cols-2">
          <div className="min-w-0">
            <Card title="Prueba de aislamiento entre empresas">
              <p className="text-[13px]">
                Intenta leer, desde tu empresa, registros reales de otras empresas de la plataforma usando sus identificadores. La base debe negarlos.
              </p>
              <div className="mt-3 flex flex-wrap items-center gap-2">
                <Btn onClick={runProbe} disabled={probing}>
                  <ShieldCheck size={15} />
                  <span>{probing ? "Probando…" : "Probar aislamiento"}</span>
                </Btn>
                {data.last_probe && !probe && (
                  <small className="app-muted text-[12px]">Última: {dateTime(data.last_probe.created_at)} · {data.last_probe.user_name ?? "—"} · {lastProbe?.note}</small>
                )}
              </div>
              {probe && (
                <ul className="mt-3 grid gap-1.5">
                  {probe.results.map((r) => (
                    <li key={r.resource} className="flex flex-wrap items-center gap-2 rounded-lg border border-[color:var(--app-border)] px-3 py-2 text-[13px]">
                      <Chip tone={PROBE_CHIP[r.result][1]}>{PROBE_CHIP[r.result][0]}</Chip>
                      <strong className="font-semibold">{r.label}</strong>
                      <small className="app-muted w-full text-[12px]">{r.detail}</small>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card title="Controles de seguridad">
              <ul className="grid gap-2">
                {data.controls.map((c) => (
                  <li key={c.key} className="flex items-start gap-2 text-[13px]">
                    {c.ok
                      ? <CheckCircle2 size={17} className="mt-0.5 shrink-0 text-[color:var(--p-success)]" aria-label="Activo" />
                      : <AlertTriangle size={17} className="mt-0.5 shrink-0 text-[color:var(--p-warning-text)]" aria-label="Revisar" />}
                    <span className="min-w-0">
                      <strong className="block font-semibold">{c.label}</strong>
                      <small className="app-muted block text-[12px]">{c.detail}</small>
                    </span>
                  </li>
                ))}
              </ul>
            </Card>
          </div>

          <div className="min-w-0">
            <Card title="Actividad de acceso (últimos 7 días)" flush>
              <div className="grid grid-cols-2 gap-2 px-5 pt-3 sm:grid-cols-3">
                <Stat label="Ingresos" value={data.activity.logins} />
                <Stat label="Ingresos sin código" value={data.activity.logins_without_mfa} tone={data.activity.logins_without_mfa ? "warn" : undefined} />
                <Stat label="Contraseñas incorrectas" value={data.activity.failed} tone={data.activity.failed ? "warn" : undefined} />
                <Stat label="Bloqueos" value={data.activity.locks} tone={data.activity.locks ? "warn" : undefined} />
                <Stat label="Sesiones abiertas" value={data.activity.open_sessions} />
                <Stat label="Abiertas sin código" value={data.activity.open_sessions_without_mfa} tone={data.activity.open_sessions_without_mfa ? "warn" : undefined} />
              </div>
              {data.activity.locked_now.length > 0 && (
                <p className="flex items-center gap-1.5 px-5 pt-3 text-[12.5px] text-[color:var(--p-danger-text)]">
                  <XCircle size={14} /> Bloqueadas ahora: {data.activity.locked_now.join(", ")}
                </p>
              )}
              <div className="mt-3">
                <DataTable tableId="security-access" cols={eventCols} rows={data.activity.recent} rowKey={(e) => e.id} empty="Sin movimientos de acceso." />
              </div>
              <div className="px-5 py-3">
                <ExportButtons permission="audit.export" total={data.activity.recent.length} busy={exporting} onExport={runExport} />
              </div>
            </Card>

            <Card title="Política de la empresa">
              <div className="grid gap-3 text-[13px]">
                <label className="flex items-start gap-2">
                  <input type="checkbox" className="mt-1" checked={policy.require_mfa_all} disabled={!canManage}
                    onChange={(e) => setPolicy({ ...policy, require_mfa_all: e.target.checked })} />
                  <span>
                    <strong className="block font-semibold">Código de verificación para todos</strong>
                    <span className="app-muted text-[12px]">Cada usuario recibe un código en su correo al ingresar, no solo el administrador.</span>
                  </span>
                </label>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Field label="Duración de la sesión">
                    <PickSelect value={String(policy.session_hours)} disabled={!canManage} ariaLabel="Duración de la sesión" className="h-10 w-full"
                      options={[1, 2, 4, 6, 8, 10, 12].map((h) => [String(h), `${h} ${h === 1 ? "hora" : "horas"}`])}
                      onChange={(value) => setPolicy({ ...policy, session_hours: Number(value) })} />
                  </Field>
                  <Field label="Cerrar por inactividad">
                    <PickSelect value={String(policy.idle_minutes)} disabled={!canManage} ariaLabel="Cerrar por inactividad" className="h-10 w-full"
                      options={data.idle_options.map((m) => [String(m), m === 0 ? "No" : m < 60 ? `Tras ${m} minutos` : `Tras ${m / 60} ${m === 60 ? "hora" : "horas"}`])}
                      onChange={(value) => setPolicy({ ...policy, idle_minutes: Number(value) })} />
                  </Field>
                </div>
                <label className="flex items-start gap-2">
                  <input type="checkbox" className="mt-1" checked={policy.allow_remember} disabled={!canManage}
                    onChange={(e) => setPolicy({ ...policy, allow_remember: e.target.checked })} />
                  <span>
                    <strong className="block font-semibold">Permitir &quot;Recordarme&quot;</strong>
                    <span className="app-muted text-[12px]">La sesión dura 30 días en ese navegador. Sin permitirlo, dura lo elegido arriba.</span>
                  </span>
                </label>
                <p className="app-muted text-[12px]">La duración aplica a las sesiones nuevas; la inactividad, también a las abiertas.</p>
                {!canManage && <p className="app-muted text-[12px]">{deniedText("tenant.manage")}</p>}
                {policyError && <p className="text-[12.5px] font-medium text-[color:var(--p-danger-text)]">{policyError}</p>}
                <div className="flex justify-end">
                  <Btn permission="tenant.manage" onClick={savePolicy} disabled={saving}>{saving ? "Guardando…" : "Guardar política"}</Btn>
                </div>
              </div>
            </Card>
          </div>
        </div>
      )}
      {toasts}
    </Screen>
  );
}
