"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Mail, RefreshCw } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { money, shortDate } from "@/lib/commercial";
import { dateTimeText } from "@/lib/portal";
import { count, PERIODS, subscriptionState, tenantStatus, TRANSITION_LABELS } from "@/lib/platform";
import type { ExportColumn } from "@/lib/export-table";
import { Badge, Btn, Card, Chip, Field, INPUT, Kv, LoadError, Modal, PageHead, Screen, Timeline, useToasts } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ClientTable, type ClientColumn } from "@/components/superadmin/ClientTable";
import type { CatalogModule, PlatformPlanOption, PlatformTenantDetail } from "@/types/platform";

type Detail = { tenant: PlatformTenantDetail; transitions: string[]; catalog: CatalogModule[]; plans: PlatformPlanOption[] };
type Sub = PlatformTenantDetail["subscriptions"][number];
type ModuleRow = CatalogModule & { on: boolean; inPlan: boolean; hasPlan: boolean };

function Meter({ label, used, limit, unit = "" }: { label: string; used: number; limit: number | null; unit?: string }) {
  const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  const color = pct >= 100 ? "bg-[#E5484D]" : pct >= 80 ? "bg-[#B26E00]" : "bg-[#1B8FC9]";
  return (
    <div className="border-b border-[color:var(--app-border)] py-2.5 last:border-b-0">
      <div className="flex items-center justify-between gap-3">
        <span>{label}</span>
        <span className="tabular-nums">{limit ? `${count(used)}${unit} / ${count(limit)}${unit}` : `${count(used)}${unit}`}</span>
      </div>
      {limit ? (
        <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-[var(--app-surface-muted)]"><div className={`h-full rounded-full ${color}`} style={{ width: `${Math.max(pct, 2)}%` }} /></div>
      ) : (
        <span className="app-muted text-[11.5px]">Sin límite en el plan</span>
      )}
    </div>
  );
}

const SUB_COLS: ClientColumn<Sub>[] = [
  { label: "Plan", sortKey: "plan", sortValue: (s) => s.plan, render: (s) => s.plan },
  { label: "Periodo", render: (s) => PERIODS[s.billing_period] ?? s.billing_period },
  { label: "Inicio", sortKey: "starts_at", sortValue: (s) => s.starts_at, render: (s) => <span className="whitespace-nowrap">{shortDate(s.starts_at)}</span> },
  { label: "Fin", sortKey: "ends_at", sortValue: (s) => s.ends_at ?? "", render: (s) => <span className="whitespace-nowrap">{shortDate(s.ends_at)}</span> },
  { label: "Estado", render: (s) => { const [label, tone] = subscriptionState(s); return <Badge tone={tone}>{label}</Badge>; } },
];
const SUB_EXPORT: ExportColumn<Sub>[] = [
  { label: "Plan", value: (s) => s.plan, width: 22 },
  { label: "Periodo", value: (s) => PERIODS[s.billing_period] ?? s.billing_period, width: 12 },
  { label: "Inicio", value: (s) => shortDate(s.starts_at), kind: "center", width: 14 },
  { label: "Fin", value: (s) => shortDate(s.ends_at), kind: "center", width: 14 },
  { label: "Estado", value: (s) => subscriptionState(s)[0], width: 16 },
];

const MODULE_COLS: ClientColumn<ModuleRow>[] = [
  { label: "Módulo", sortKey: "name", sortValue: (m) => m.name, render: (m) => <span className="font-medium">{m.name}</span> },
  { label: "En el plan", render: (m) => (m.is_core ? <Chip tone="info">Obligatorio</Chip> : !m.hasPlan ? <span className="app-muted">—</span> : m.inPlan ? "Sí" : "No") },
  { label: "Estado", sortKey: "on", sortValue: (m) => (m.on ? 0 : 1), render: (m) => <Chip tone={m.on ? "success" : "muted"}>{m.on ? "ON" : "OFF"}</Chip> },
  {
    label: "Diferencia", render: (m) => (m.hasPlan && !m.is_core && m.on !== m.inPlan
      ? <Badge tone="warning">{m.on ? "Encendido fuera del plan" : "Apagado aunque el plan lo incluye"}</Badge>
      : <span className="app-muted">—</span>),
  },
];
const MODULE_EXPORT: ExportColumn<ModuleRow>[] = [
  { label: "Módulo", value: (m) => m.name, width: 26 },
  { label: "Código", value: (m) => m.code, width: 24 },
  { label: "En el plan", value: (m) => (m.is_core ? "Obligatorio" : !m.hasPlan ? "—" : m.inPlan ? "Sí" : "No"), kind: "center", width: 14 },
  { label: "Estado", value: (m) => (m.on ? "ON" : "OFF"), kind: "status", tone: (m) => (m.on ? "success" : "neutral"), width: 10 },
];

export function TenantDetail({ id }: { id: string }) {
  const { toast, toasts } = useToasts();
  const [detail, setDetail] = useState<Detail | null>(null);
  const [error, setError] = useState<"missing" | "failed" | null>(null);
  const [transition, setTransition] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [planId, setPlanId] = useState("");
  const [period, setPeriod] = useState("MONTHLY");
  const [confirmPlan, setConfirmPlan] = useState(false);
  const [confirmModules, setConfirmModules] = useState(false);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState("");

  const load = useCallback(async () => {
    setError(null);
    try {
      const data = await apiFetch<Detail>(`/v1/superadmin/tenants/${id}`);
      setDetail(data);
      setPlanId(data.tenant.subscription?.plan_id ?? "");
    } catch (err) {
      setError(err instanceof ApiError && err.status === 404 ? "missing" : "failed");
    }
  }, [id]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- primera carga de la ficha
    void load();
  }, [load]);

  const tenant = detail?.tenant;
  const currentPlan = detail?.plans.find((p) => p.id === tenant?.subscription?.plan_id);
  const targetPlan = detail?.plans.find((p) => p.id === planId);

  const modules: ModuleRow[] = useMemo(() => (detail?.catalog ?? []).map((m) => ({
    ...m,
    on: m.is_core || (tenant?.modules ?? []).includes(m.code),
    inPlan: m.is_core || Boolean(currentPlan?.modules[m.code]),
    hasPlan: Boolean(currentPlan),
  })), [detail, tenant, currentPlan]);

  // Qué cambia al pasar al plan elegido: módulos que se encienden y que se apagan (sin borrar datos).
  const planDiff = useMemo(() => {
    if (!targetPlan) return { on: [] as string[], off: [] as string[] };
    const name = (code: string) => detail?.catalog.find((m) => m.code === code)?.name ?? code;
    const target = new Set(Object.keys(targetPlan.modules));
    return {
      on: modules.filter((m) => !m.is_core && !m.on && target.has(m.code)).map((m) => m.name),
      off: modules.filter((m) => !m.is_core && m.on && !target.has(m.code)).map((m) => name(m.code)),
    };
  }, [targetPlan, modules, detail]);

  async function act(run: () => Promise<unknown>, done: string) {
    setBusy(true);
    setFormError("");
    try {
      await run();
      toast(done);
      setTransition(null);
      setConfirmPlan(false);
      setConfirmModules(false);
      setReason("");
      await load();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : "No se pudo completar la acción.");
    } finally {
      setBusy(false);
    }
  }

  if (!tenant || !detail) {
    return (
      <Screen>
        <PageHead title="Tenant" />
        <Card>
          {error === "missing" ? <p className="app-muted py-6 text-center">Esa empresa no existe.</p>
            : error ? <LoadError text="No se pudo cargar la empresa." onRetry={() => void load()} />
              : <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" />}
        </Card>
      </Screen>
    );
  }

  const [statusLabel, statusTone] = tenantStatus(tenant.status);
  const sub = tenant.subscription;
  const pendingAdmins = tenant.admins.filter((a) => !a.last_login_at);
  const reasonNeeded = transition !== null && transition !== "ACTIVE";
  const downgradeBlocked = Boolean(targetPlan?.users_limit && tenant.users > (targetPlan.users_limit ?? 0));

  return (
    <Screen>
      <Link href="/superadmin/tenants" className="app-muted mb-2 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
        <ArrowLeft size={15} /> Tenants
      </Link>
      <PageHead
        title={tenant.name}
        showTitle
        sub={[tenant.legal_name, tenant.ruc && `RUC ${tenant.ruc}`, tenant.code].filter(Boolean).join(" · ")}
        actions={detail.transitions.map((to) => {
          const meta = TRANSITION_LABELS[to] ?? { label: to, danger: false };
          return (
            <Btn key={to} variant={meta.danger ? "danger-ghost" : to === "ACTIVE" ? "primary" : "secondary"} onClick={() => { setFormError(""); setReason(""); setTransition(to); }}>
              {meta.label}
            </Btn>
          );
        })}
      />

      <div className="grid gap-x-[18px] lg:grid-cols-2">
        <Card title="Suscripción">
          <Kv rows={[
            ["Estado", <Badge key="s" tone={statusTone}>{statusLabel}</Badge>],
            ["Plan", sub ? <span key="p">{sub.plan}{sub.trial && <span className="ml-1.5"><Chip tone="info">Prueba</Chip></span>}</span> : <span key="p" className="app-muted">Sin suscripción</span>],
            sub ? ["Periodo", PERIODS[sub.billing_period] ?? sub.billing_period] : null,
            sub ? ["Precio", money(sub.billing_period === "ANNUAL" ? sub.annual_price : sub.monthly_price, sub.currency) + (sub.billing_period === "ANNUAL" ? " al año" : " al mes")] : null,
            sub ? ["Vigencia", <span key="v">{shortDate(sub.starts_at)} – {shortDate(sub.ends_at)} <Badge tone={subscriptionState({ status: "ACTIVE", trial: sub.trial, ends_at: sub.ends_at })[1]}>{subscriptionState({ status: "ACTIVE", trial: sub.trial, ends_at: sub.ends_at })[0]}</Badge></span>] : null,
            ["Módulos", <span key="m" className="flex flex-wrap gap-1">{modules.filter((m) => m.on).map((m) => <Chip key={m.code} tone="success">{m.name}</Chip>)}</span>],
          ]} />
          <div className="mt-3 flex flex-wrap items-end gap-2">
            <label className="min-w-[200px] flex-1">
              <span className="mb-1 block text-[12.5px] font-medium">Plan</span>
              <PickSelect value={planId} onChange={setPlanId} ariaLabel="Plan" options={detail.plans.map((p) => [p.id, p.name])} className={INPUT} />
            </label>
            {!sub && (
              <label className="min-w-[140px]">
                <span className="mb-1 block text-[12.5px] font-medium">Periodo</span>
                <PickSelect value={period} onChange={setPeriod} ariaLabel="Periodo" options={Object.entries(PERIODS)} className={INPUT} />
              </label>
            )}
            <Btn variant="secondary" disabled={!planId || planId === sub?.plan_id} onClick={() => { setFormError(""); setConfirmPlan(true); }}>
              {sub ? "Cambiar plan" : "Asignar plan"}
            </Btn>
            <Btn variant="ghost" disabled={!sub} onClick={() => { setFormError(""); setConfirmModules(true); }} title={sub ? undefined : "La empresa no tiene suscripción"}>
              <RefreshCw size={14} /> Aplicar módulos del plan
            </Btn>
          </div>
        </Card>

        <Card title="Uso">
          <Meter label="Usuarios activos" used={tenant.users} limit={tenant.users_limit} />
          <Meter label="Usuarios del portal" used={tenant.portal_users} limit={tenant.portal_users_limit} />
          <Meter label="Clientes" used={tenant.customers} limit={tenant.customers_limit} />
          <Meter label="Almacenamiento (MB)" used={Number(tenant.storage_mb)} limit={tenant.storage_limit_gb ? Number(tenant.storage_limit_gb) * 1024 : null} />
          <div className="flex items-center justify-between gap-3 py-2.5">
            <span>Llamadas API (mes)</span>
            <span className="app-muted text-[12.5px]">Aún no se miden</span>
          </div>
        </Card>

        <Card title="Historial de estado">
          <Timeline
            empty="Sin cambios de estado registrados."
            items={tenant.status_log.map((log, index) => ({
              key: `${log.at}-${index}`,
              head: <>{log.from && <><Badge tone={tenantStatus(log.from)[1]}>{tenantStatus(log.from)[0]}</Badge><span className="app-muted">→</span></>}<Badge tone={tenantStatus(log.to)[1]}>{tenantStatus(log.to)[0]}</Badge></>,
              meta: `${dateTimeText(log.at)}${log.user_name ? ` · ${log.user_name}` : ""}`,
              note: log.reason,
            }))}
          />
        </Card>

        <Card title="Administradores">
          {tenant.admins.length ? (
            <ul>
              {tenant.admins.map((admin) => (
                <li key={admin.id} className="flex flex-wrap items-center gap-2 border-b border-[color:var(--app-border)] py-2.5 last:border-b-0">
                  <span className="min-w-0 flex-1">
                    <strong className="block font-medium">{admin.name}</strong>
                    <small className="app-muted">{admin.email} · {admin.last_login_at ? `Último ingreso ${dateTimeText(admin.last_login_at)}` : "Nunca ha ingresado"}</small>
                  </span>
                  {!admin.last_login_at && (
                    <Btn
                      size="sm" variant="secondary" disabled={busy || tenant.status !== "ACTIVE"}
                      title={tenant.status !== "ACTIVE" ? "Activa la empresa para invitar a su administrador" : undefined}
                      onClick={() => void act(() => apiFetch(`/v1/superadmin/tenants/${id}/admins/${admin.id}/invitation`, { method: "POST" }), `Invitación enviada a ${admin.email}`)}
                    >
                      <Mail size={13} /> Reenviar invitación
                    </Btn>
                  )}
                </li>
              ))}
            </ul>
          ) : (
            <p className="app-muted">La empresa no tiene administradores con el rol de sistema.</p>
          )}
        </Card>
      </div>

      <Card title="Módulos" flush>
        <ClientTable table="tenant_modules" title={`Módulos de ${tenant.name}`} file="modulos-del-tenant" cols={MODULE_COLS} rows={modules} rowKey={(m) => m.code} exportCols={MODULE_EXPORT} empty="Sin módulos." toast={toast} />
      </Card>

      <Card title="Historial de suscripciones" flush>
        <ClientTable table="tenant_subscriptions" title={`Suscripciones de ${tenant.name}`} file="suscripciones-del-tenant" cols={SUB_COLS} rows={tenant.subscriptions} rowKey={(s) => s.id} exportCols={SUB_EXPORT} empty="La empresa no tiene suscripciones." toast={toast} />
      </Card>

      {transition && (
        <Modal
          title={TRANSITION_LABELS[transition]?.label ?? transition}
          size="sm"
          onClose={() => setTransition(null)}
          footer={<>
            <Btn variant="secondary" onClick={() => setTransition(null)}>Cancelar</Btn>
            <Btn
              variant={TRANSITION_LABELS[transition]?.danger ? "danger" : "primary"}
              disabled={busy || (reasonNeeded && !reason.trim())}
              onClick={() => void act(() => apiFetch(`/v1/superadmin/tenants/${id}/transitions`, { method: "POST", body: JSON.stringify({ from: tenant.status, to: transition, reason }) }), "Estado de la empresa actualizado")}
            >
              {TRANSITION_LABELS[transition]?.label ?? transition}
            </Btn>
          </>}
        >
          <p className="mb-3">{tenant.name}: <Badge tone={statusTone}>{statusLabel}</Badge> → <Badge tone={tenantStatus(transition)[1]}>{tenantStatus(transition)[0]}</Badge></p>
          {tenant.status === "PROVISIONING" && transition === "ACTIVE" && pendingAdmins.length > 0 && (
            <p className="mb-3">Se enviará la invitación para crear su contraseña a {pendingAdmins.map((a) => a.email).join(", ")}.</p>
          )}
          {transition !== "ACTIVE" && <p className="mb-3">Sus usuarios no podrán entrar mientras esté {tenantStatus(transition)[0].toLowerCase()}. Los datos se conservan.</p>}
          <Field label="Motivo" required={reasonNeeded}>
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={3} className={INPUT} maxLength={1000} />
          </Field>
          {formError && <p className="mt-2 text-[13px] text-[color:var(--p-danger-text)]">{formError}</p>}
        </Modal>
      )}

      {confirmPlan && targetPlan && (
        <Modal
          title={sub ? "Cambiar plan" : "Asignar plan"}
          size="sm"
          onClose={() => setConfirmPlan(false)}
          footer={<>
            <Btn variant="secondary" onClick={() => setConfirmPlan(false)}>Cancelar</Btn>
            <Btn
              disabled={busy || downgradeBlocked}
              onClick={() => void act(() => apiFetch(`/v1/superadmin/tenants/${id}/plan`, { method: "PUT", body: JSON.stringify({ plan_id: planId, billing_period: period }) }), "Plan actualizado")}
            >
              {sub ? "Cambiar plan" : "Asignar plan"}
            </Btn>
          </>}
        >
          <p className="mb-3">{sub ? `${sub.plan} → ${targetPlan.name}` : `${targetPlan.name}, ${PERIODS[period].toLowerCase()}`}</p>
          {downgradeBlocked && (
            <p className="mb-3 text-[color:var(--p-danger-text)]">La empresa tiene {tenant.users} usuarios activos y el plan permite {targetPlan.users_limit}. Desactiva usuarios antes de bajar de plan.</p>
          )}
          {planDiff.on.length > 0 && <p className="mb-2"><strong className="font-medium">Se encienden:</strong> {planDiff.on.join(", ")}.</p>}
          {planDiff.off.length > 0 && <p className="mb-2"><strong className="font-medium">Se apagan (sus datos se conservan):</strong> {planDiff.off.join(", ")}.</p>}
          {!planDiff.on.length && !planDiff.off.length && <p className="app-muted mb-2">Los módulos no cambian.</p>}
          {formError && <p className="mt-2 text-[13px] text-[color:var(--p-danger-text)]">{formError}</p>}
        </Modal>
      )}

      {confirmModules && currentPlan && (
        <Modal
          title="Aplicar módulos del plan"
          size="sm"
          onClose={() => setConfirmModules(false)}
          footer={<>
            <Btn variant="secondary" onClick={() => setConfirmModules(false)}>Cancelar</Btn>
            <Btn disabled={busy} onClick={() => void act(() => apiFetch(`/v1/superadmin/tenants/${id}/apply-plan-modules`, { method: "POST" }), "Módulos aplicados")}>Aplicar</Btn>
          </>}
        >
          <p className="mb-3">Los módulos de {tenant.name} quedarán como los del plan {currentPlan.name}.</p>
          {(() => {
            const on = modules.filter((m) => !m.is_core && !m.on && m.inPlan).map((m) => m.name);
            const off = modules.filter((m) => !m.is_core && m.on && !m.inPlan).map((m) => m.name);
            return <>
              {on.length > 0 && <p className="mb-2"><strong className="font-medium">Se encienden:</strong> {on.join(", ")}.</p>}
              {off.length > 0 && <p className="mb-2"><strong className="font-medium">Se apagan (sus datos se conservan):</strong> {off.join(", ")}.</p>}
              {!on.length && !off.length && <p className="app-muted mb-2">Ya coinciden con el plan.</p>}
            </>;
          })()}
          {formError && <p className="mt-2 text-[13px] text-[color:var(--p-danger-text)]">{formError}</p>}
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
