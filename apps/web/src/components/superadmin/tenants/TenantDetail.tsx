"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { ArrowLeft, Calendar, Mail, Minus, Pencil, Plus, RefreshCw } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { money, shortDate } from "@/lib/commercial";
import { dateTimeText } from "@/lib/portal";
import { count, PERIODS, subscriptionState, tenantStatus, TRANSITION_LABELS } from "@/lib/platform";
import { extensionBase } from "@/lib/subscription";
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
  const [openPaymentModal, setOpenPaymentModal] = useState(false);
  const [paymentMonths, setPaymentMonths] = useState<number>(3);
  const [paymentAmount, setPaymentAmount] = useState<string>("0");
  const [paymentEndDate, setPaymentEndDate] = useState<string>("");
  const [paymentMethod, setPaymentMethod] = useState<string>("Transferencia BCP");
  const [paymentRef, setPaymentRef] = useState<string>("");
  const [paymentNotes, setPaymentNotes] = useState<string>("");

  const formatIsoDate = (d: Date) => {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${y}-${m}-${day}`;
  };

  const initPaymentModal = (months = 3) => {
    setFormError("");
    setPaymentMonths(months);
    const effectiveBase = extensionBase(sub?.ends_at);
    effectiveBase.setMonth(effectiveBase.getMonth() + months);
    setPaymentEndDate(formatIsoDate(effectiveBase));

    const price = currentPlan?.monthly_price ? Number(currentPlan.monthly_price) : 0;
    const suggested = months === 12 && currentPlan?.annual_price ? Number(currentPlan.annual_price) : price * months;
    setPaymentAmount(suggested > 0 ? suggested.toFixed(2) : "0.00");
    setPaymentMethod("Transferencia BCP");
    setPaymentRef("");
    setPaymentNotes("");
    setOpenPaymentModal(true);
  };

  const handleMonthsChange = (m: number) => {
    const months = Math.max(1, Math.min(120, m));
    setPaymentMonths(months);
    const effectiveBase = extensionBase(sub?.ends_at);
    effectiveBase.setMonth(effectiveBase.getMonth() + months);
    setPaymentEndDate(formatIsoDate(effectiveBase));

    const price = currentPlan?.monthly_price ? Number(currentPlan.monthly_price) : 0;
    const suggested = months === 12 && currentPlan?.annual_price ? Number(currentPlan.annual_price) : price * months;
    setPaymentAmount(suggested > 0 ? suggested.toFixed(2) : "0.00");
  };

  const handleDateChange = (val: string) => {
    setPaymentEndDate(val);
    if (!val) return;
    const parts = val.split("-");
    const picked = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    const effectiveBase = extensionBase(sub?.ends_at);
    const diffDays = Math.ceil((picked.getTime() - effectiveBase.getTime()) / (1000 * 60 * 60 * 24));
    if (diffDays > 0) {
      const approxMonths = Math.max(1, Math.round(diffDays / 30));
      setPaymentMonths(approxMonths);
      const price = currentPlan?.monthly_price ? Number(currentPlan.monthly_price) : 0;
      const suggested = Math.round((diffDays / 30) * price);
      setPaymentAmount(suggested.toFixed(2));
    }
  };

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
  // El compilador de React ya memoriza estos cálculos.
  const currentPlan = detail?.plans.find((p) => p.id === detail.tenant.subscription?.plan_id);
  const targetPlan = detail?.plans.find((p) => p.id === planId);

  const modules: ModuleRow[] = (detail?.catalog ?? []).map((m) => ({
    ...m,
    on: m.is_core || (tenant?.modules ?? []).includes(m.code),
    inPlan: m.is_core || Boolean(currentPlan?.modules[m.code]),
    hasPlan: Boolean(currentPlan),
  }));

  // Qué cambia al pasar al plan elegido: módulos que se encienden y que se apagan (sin borrar datos).
  const planDiff = (() => {
    if (!targetPlan) return { on: [] as string[], off: [] as string[] };
    const name = (code: string) => detail?.catalog.find((m) => m.code === code)?.name ?? code;
    const target = new Set(Object.keys(targetPlan.modules));
    return {
      on: modules.filter((m) => !m.is_core && !m.on && target.has(m.code)).map((m) => m.name),
      off: modules.filter((m) => !m.is_core && m.on && !target.has(m.code)).map((m) => name(m.code)),
    };
  })();

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
            <Btn variant="primary" disabled={!sub} onClick={() => initPaymentModal(3)}>
              Registrar pago / Extender
            </Btn>
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
            <Card title="Historial de pagos (renovaciones)" flush>
        {tenant.payments && tenant.payments.length > 0 ? (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="border-b border-[color:var(--app-border)] bg-[var(--app-surface-muted)] text-[11.5px] font-semibold text-[color:var(--app-muted)]">
                <tr>
                  <th className="px-4 py-2.5">Fecha</th>
                  <th className="px-4 py-2.5">Monto</th>
                  <th className="px-4 py-2.5">Periodo</th>
                  <th className="px-4 py-2.5">Método / Ref</th>
                  <th className="px-4 py-2.5">Nueva vigencia</th>
                  <th className="px-4 py-2.5">Registrado por</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-[color:var(--app-border)]">
                {tenant.payments.map((p) => (
                  <tr key={p.id} className="hover:bg-[var(--app-surface-muted)]">
                    <td className="whitespace-nowrap px-4 py-2.5 font-medium">{shortDate(p.created_at)}</td>
                    <td className="whitespace-nowrap px-4 py-2.5 font-mono font-bold text-[color:var(--p-success-text)]">
                      {money(p.amount, p.currency)}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2.5">{p.months ? `+${p.months} meses` : "Personalizado"}</td>
                    <td className="px-4 py-2.5">
                      <span className="font-medium">{p.payment_method}</span>
                      {p.reference && <small className="block font-mono text-[11px] text-[color:var(--app-muted)]">Ref: {p.reference}</small>}
                      {p.notes && <small className="block text-[11px] italic text-[color:var(--app-muted)]">{p.notes}</small>}
                    </td>
                    <td className="whitespace-nowrap px-4 py-2.5 font-mono">{shortDate(p.new_ends_at)}</td>
                    <td className="whitespace-nowrap px-4 py-2.5 text-[color:var(--app-muted)]">{p.created_by_name ?? "Superadmin"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="app-muted p-4 text-xs">Sin cobros registrados aún (la empresa inició con periodo de prueba o activación directa).</p>
        )}
      </Card>

      {openPaymentModal && (
        <Modal
          title="Registrar Pago y Extender Vigencia"
          size="md"
          onClose={() => setOpenPaymentModal(false)}
          footer={<>
            <Btn variant="secondary" onClick={() => setOpenPaymentModal(false)}>Cancelar</Btn>
            <Btn
              variant="primary"
              disabled={busy || !paymentEndDate || !paymentAmount}
              onClick={() => void act(async () => {
                await apiFetch(`/v1/superadmin/tenants/${id}/extend-subscription`, {
                  method: "POST",
                  body: JSON.stringify({
                    ends_at: paymentEndDate,
                    amount: parseFloat(paymentAmount) || 0,
                    payment_method: paymentMethod,
                    reference: paymentRef || null,
                    notes: paymentNotes || null,
                    months: paymentMonths,
                  }),
                });
                setOpenPaymentModal(false);
              }, "Pago y vigencia registrados con éxito")}
            >
              Guardar y extender vigencia
            </Btn>
          </>}
        >
          <div className="space-y-4 text-xs">
            {/* Cabecera resumen del plan */}
            <div className="flex items-center justify-between rounded-[10px] border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3.5">
              <div>
                <span className="app-muted block text-[11px] font-medium uppercase tracking-wider">Plan actual</span>
                <div className="mt-0.5 flex items-baseline gap-2">
                  <strong className="text-base font-semibold">{currentPlan?.name ?? sub?.plan}</strong>
                  <span className="font-mono text-xs text-[color:var(--app-muted)]">
                    ({money(currentPlan?.monthly_price ?? sub?.monthly_price ?? 0, currentPlan?.currency ?? sub?.currency ?? "PEN")}/mes)
                  </span>
                </div>
              </div>
              <div className="text-right">
                <span className="app-muted block text-[11px]">Vigencia actual</span>
                <span className="font-mono text-xs font-medium">
                  {sub?.ends_at ? shortDate(sub.ends_at) : "Sin fecha"}
                </span>
              </div>
            </div>

            {/* Atajos de periodo y selector de meses personalizado */}
            <div>
              <div className="mb-2 flex items-center justify-between">
                <label className="text-[12.5px] font-medium text-[color:var(--app-text)]">Periodo a extender</label>
                <div className="flex items-center gap-1.5 text-xs">
                  <span className="app-muted text-[11px]">Personalizado:</span>
                  <button
                    type="button"
                    onClick={() => handleMonthsChange(paymentMonths - 1)}
                    className="flex h-6 w-6 items-center justify-center rounded border border-[color:var(--app-border)] bg-[var(--app-surface)] text-[color:var(--app-text)] hover:bg-[var(--app-surface-muted)] transition cursor-pointer"
                    title="Restar un mes"
                  >
                    <Minus size={12} />
                  </button>
                  <input
                    type="number"
                    min={1}
                    max={120}
                    value={paymentMonths}
                    onChange={(e) => handleMonthsChange(parseInt(e.target.value, 10) || 1)}
                    className="h-6 w-12 rounded border border-[color:var(--app-border)] bg-[var(--app-surface)] text-center font-mono text-xs font-bold"
                  />
                  <span className="text-[11px] text-[color:var(--app-muted)]">meses</span>
                  <button
                    type="button"
                    onClick={() => handleMonthsChange(paymentMonths + 1)}
                    className="flex h-6 w-6 items-center justify-center rounded border border-[color:var(--app-border)] bg-[var(--app-surface)] text-[color:var(--app-text)] hover:bg-[var(--app-surface-muted)] transition cursor-pointer"
                    title="Sumar un mes"
                  >
                    <Plus size={12} />
                  </button>
                </div>
              </div>

              <div className="grid grid-cols-4 gap-2">
                {[
                  { m: 1, label: "+1 Mes", sub: "Mensual" },
                  { m: 3, label: "+3 Meses", sub: "Trimestral" },
                  { m: 6, label: "+6 Meses", sub: "Semestral" },
                  { m: 12, label: "+12 Meses", sub: "Anual" },
                ].map(({ m, label, sub: subText }) => {
                  const active = paymentMonths === m;
                  return (
                    <button
                      key={m}
                      type="button"
                      onClick={() => handleMonthsChange(m)}
                      className={`rounded-[10px] border px-2.5 py-2 text-center transition cursor-pointer ${
                        active
                          ? "border-[var(--p-accent,#1B8FC9)] bg-[var(--p-accent,#1B8FC9)] text-white shadow-xs font-medium"
                          : "border-[color:var(--app-border)] bg-[var(--app-surface)] hover:bg-[var(--app-surface-muted)] text-[color:var(--app-text)]"
                      }`}
                    >
                      <span className="block text-xs font-semibold">{label}</span>
                      <span className={`block text-[10.5px] ${active ? "text-white/80" : "app-muted"}`}>{subText}</span>
                    </button>
                  );
                })}
              </div>
            </div>

            {/* Fecha fin de vigencia con selector de calendario */}
            <Field
              label="Fecha fin de vigencia"
              hint="Calculada según los meses o seleccionable directamente en el calendario para cualquier día exacto."
            >
              <div className="flex items-center gap-2">
                <div className="relative flex-1">
                  <input
                    type="date"
                    value={paymentEndDate}
                    onChange={(e) => handleDateChange(e.target.value)}
                    className={`${INPUT} font-mono cursor-pointer`}
                  />
                </div>
                {paymentEndDate && (
                  <Badge tone="info">
                    <span className="inline-flex items-center gap-1 font-mono text-[11px]">
                      <Calendar size={12} />
                      {shortDate(paymentEndDate)}
                    </span>
                  </Badge>
                )}
              </div>
            </Field>

            {/* Monto editable con icono de edición */}
            <Field
              label="Monto total cobrado"
              hint="Sugerido según el plan. Puedes editarlo libremente si acordaste un descuento o importe específico."
            >
              <div className="relative flex items-center">
                <span className="pointer-events-none absolute left-3 font-mono font-semibold text-xs text-[color:var(--app-muted)]">
                  {sub?.currency ?? "PEN"}
                </span>
                <input
                  type="number"
                  step="0.01"
                  value={paymentAmount}
                  onChange={(e) => setPaymentAmount(e.target.value)}
                  className={`${INPUT} pl-12 pr-20 font-mono text-sm font-semibold`}
                />
                <span className="pointer-events-none absolute right-3 inline-flex items-center gap-1 text-[11px] text-[color:var(--app-muted)]">
                  <Pencil size={11} />
                  Editable
                </span>
              </div>
            </Field>

            {/* Método de pago y Nº de Operación */}
            <div className="grid grid-cols-2 gap-3">
              <Field label="Método de pago" required>
                <select
                  value={paymentMethod}
                  onChange={(e) => setPaymentMethod(e.target.value)}
                  className={INPUT}
                >
                  <option>Transferencia BCP</option>
                  <option>Transferencia BBVA</option>
                  <option>Transferencia Interbank</option>
                  <option>Transferencia Scotiabank</option>
                  <option>Yape / Plin</option>
                  <option>Depósito / Efectivo</option>
                  <option>Tarjeta de crédito</option>
                  <option>Otro</option>
                </select>
              </Field>
              <Field label="Nº de Operación / Ref.">
                <input
                  type="text"
                  placeholder="Ej. OPE-94819284"
                  value={paymentRef}
                  onChange={(e) => setPaymentRef(e.target.value)}
                  className={`${INPUT} font-mono`}
                />
              </Field>
            </div>

            {/* Notas comerciales internas */}
            <Field label="Notas internas (opcional)">
              <textarea
                placeholder="Ej. Pago por 4 meses acordado por WhatsApp con descuento comercial"
                value={paymentNotes}
                onChange={(e) => setPaymentNotes(e.target.value)}
                rows={2}
                className={INPUT}
              />
            </Field>

            {formError && <p className="text-[12.5px] text-[color:var(--p-danger-text)]">{formError}</p>}
          </div>
        </Modal>
      )}

      {toasts}
    </Screen>
  );
}
