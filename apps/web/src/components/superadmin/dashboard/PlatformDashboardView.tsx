"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { RefreshCw } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { money, shortDate } from "@/lib/commercial";
import { monthName, monthTick } from "@/lib/accounting";
import { dateTimeText } from "@/lib/portal";
import { exportCsv, exportXlsx, type ExportColumn } from "@/lib/export-table";
import { actionLabel, count, limitText, recordPlatformExport, tenantStatus } from "@/lib/platform";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Kpi, Kpis, LoadError, PageHead, Screen, SelectionBar, useRowSelection, useToasts,
  type Column,
} from "@/components/ui/proto";
import type { PlatformAuditRow, PlatformDashboard, PlatformPlanRow, PlatformTrialRow, PlatformUsageRow } from "@/types/platform";

type ExportTable = "trials" | "usage" | "plans" | "audit";
type Toast = ReturnType<typeof useToasts>["toast"];

const statusOf = tenantStatus;
const actionOf = actionLabel;
const trialText = (row: PlatformTrialRow) =>
  row.days_left < 0 ? `Vencida hace ${-row.days_left} ${row.days_left === -1 ? "día" : "días"}`
    : row.days_left === 0 ? "Vence hoy" : `Vence en ${row.days_left} ${row.days_left === 1 ? "día" : "días"}`;

/** Uso frente al límite del plan: número y barra (ámbar desde el 80 %, rojo al llegar al límite). */
function UsageMeter({ used, limit }: { used: number; limit: number | null }) {
  const pct = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  const color = !limit ? "" : pct >= 100 ? "bg-[#E5484D]" : pct >= 80 ? "bg-[#B26E00]" : "bg-[#1B8FC9]";
  return (
    <div className="min-w-[96px]">
      <span className="tabular-nums">{limitText(used, limit)}</span>
      {limit ? (
        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-[var(--app-surface-muted)]" aria-label={`${pct} % del límite`}>
          <div className={`h-full rounded-full ${color}`} style={{ width: `${Math.max(pct, 2)}%` }} />
        </div>
      ) : (
        <span className="app-muted block text-[11px]">Sin límite</span>
      )}
    </div>
  );
}

/** Tabla de una tarjeta con selección y exportar al pie, como los listados. */
function ExportableTable<T>({
  table, title, file, cols, rows, rowKey, exportCols, empty, toast,
}: {
  table: ExportTable;
  title: string;
  file: string;
  cols: Column<T>[];
  rows: T[];
  rowKey: (row: T, index?: number) => string;
  exportCols: ExportColumn<T>[];
  empty: string;
  toast: Toast;
}) {
  const { selection, selectedRows, clear } = useRowSelection(rowKey);
  const [busy, setBusy] = useState(false);

  async function run(format: "xlsx" | "csv", list: T[], selected: boolean) {
    setBusy(true);
    try {
      await recordPlatformExport(table, format, list.length, selected);
      if (format === "xlsx") await exportXlsx(file, title, exportCols, list);
      else exportCsv(file, exportCols, list);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <SelectionBar count={selectedRows.length} busy={busy} onExport={(format) => run(format, selectedRows, true)} onClear={clear} />
      <div className="px-1">
        <DataTable cols={cols} rows={rows} rowKey={rowKey} selection={selection} empty={empty} dense />
      </div>
      {rows.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 border-t border-[color:var(--app-border)] px-3.5 py-3">
          <ExportButtons total={rows.length} busy={busy} onExport={(format) => run(format, rows, false)} />
          <span className="app-muted text-[12px]">{rows.length} {rows.length === 1 ? "registro" : "registros"}</span>
        </div>
      )}
    </>
  );
}

function SignupsChart({ months }: { months: PlatformDashboard["signups"] }) {
  return (
    <div className="h-[220px] w-full" role="img" aria-label="Altas de empresas por mes">
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={months} margin={{ top: 8, right: 8, bottom: 0, left: 0 }} barCategoryGap="28%">
          <CartesianGrid vertical={false} stroke="var(--app-border)" strokeOpacity={0.7} />
          <XAxis dataKey="month" tickFormatter={monthTick} tickLine={false} axisLine={{ stroke: "var(--app-border)" }} tick={{ fill: "var(--app-muted)", fontSize: 11 }} interval="preserveStartEnd" minTickGap={10} />
          <YAxis width={32} tickLine={false} axisLine={false} tick={{ fill: "var(--app-muted)", fontSize: 11 }} allowDecimals={false} />
          <Tooltip
            cursor={{ fill: "var(--app-surface-muted)", opacity: 0.6 }}
            content={({ active, payload }) => {
              const row = payload?.[0]?.payload as PlatformDashboard["signups"][number] | undefined;
              if (!active || !row) return null;
              return (
                <div className="app-surface app-text rounded-lg border border-[color:var(--app-border)] px-3 py-2 text-[12.5px] shadow-[0_8px_24px_rgba(8,25,55,.18)]">
                  <p className="font-semibold capitalize">{monthName(row.month)}</p>
                  <p className="tabular-nums">{row.tenants} {row.tenants === 1 ? "empresa nueva" : "empresas nuevas"}</p>
                </div>
              );
            }}
          />
          <Bar dataKey="tenants" name="Empresas nuevas" fill="var(--p-chart-1)" radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

function HealthItem({ tone, label, detail }: { tone: "success" | "warning" | "danger" | "neutral"; label: string; detail: ReactNode }) {
  const dot = { success: "bg-[#22A55A]", warning: "bg-[#B26E00]", danger: "bg-[#E5484D]", neutral: "bg-[var(--p-muted-text)]" }[tone];
  return (
    <li className="flex items-start gap-2.5 border-b border-[color:var(--app-border)] py-2.5 last:border-b-0">
      <span className={`mt-[6px] h-2.5 w-2.5 shrink-0 rounded-full ${dot}`} aria-hidden="true" />
      <span className="min-w-0 flex-1 font-medium">{label}</span>
      <small className="app-muted text-right text-[12px]">{detail}</small>
    </li>
  );
}

const PLAN_COLS: Column<PlatformPlanRow>[] = [
  { label: "Plan", render: (p) => <span className="font-medium">{p.name}{!p.active && <span className="ml-1.5"><Chip tone="muted">Inactivo</Chip></span>}</span> },
  { label: "Vigentes", num: true, render: (p) => count(p.tenants) },
  { label: "Pagadas", num: true, render: (p) => count(p.paid) },
  { label: "En prueba", num: true, render: (p) => count(p.trial) },
  { label: "Ingreso mensual", num: true, render: (p) => money(p.mrr, p.currency) },
];

const PLAN_EXPORT: ExportColumn<PlatformPlanRow>[] = [
  { label: "Plan", value: (p) => p.name, width: 24 },
  { label: "Activo", value: (p) => (p.active ? "Sí" : "No"), kind: "center", width: 10 },
  { label: "Vigentes", value: (p) => p.tenants, kind: "number", width: 12 },
  { label: "Pagadas", value: (p) => p.paid, kind: "number", width: 12 },
  { label: "En prueba", value: (p) => p.trial, kind: "number", width: 12 },
  { label: "Ingreso mensual", value: (p) => p.mrr, kind: "money", currency: (p) => p.currency, width: 18 },
];

const TRIAL_COLS: Column<PlatformTrialRow>[] = [
  { label: "Empresa", render: (t) => <span><strong className="font-medium">{t.name}</strong><small className="app-muted block">{t.code}</small></span> },
  { label: "Plan", render: (t) => t.plan },
  { label: "Fin de la prueba", render: (t) => <span className="whitespace-nowrap">{shortDate(t.ends_at)}</span> },
  { label: "Estado", render: (t) => <Badge tone={t.days_left < 0 ? "danger" : "warning"}>{trialText(t)}</Badge> },
];

const TRIAL_EXPORT: ExportColumn<PlatformTrialRow>[] = [
  { label: "Empresa", value: (t) => t.name, width: 30 },
  { label: "Código", value: (t) => t.code, width: 14 },
  { label: "Plan", value: (t) => t.plan, width: 20 },
  { label: "Fin de la prueba", value: (t) => shortDate(t.ends_at), kind: "center", width: 16 },
  { label: "Estado", value: (t) => trialText(t), kind: "status", tone: (t) => (t.days_left < 0 ? "danger" : "warning"), width: 22 },
];

const USAGE_COLS: Column<PlatformUsageRow>[] = [
  { label: "Empresa", render: (u) => <span><strong className="font-medium">{u.name}</strong><small className="app-muted block">{u.code}</small></span> },
  { label: "Plan", render: (u) => (u.plan ? <span>{u.plan}{u.trial && <span className="ml-1.5"><Chip tone="info">Prueba</Chip></span>}</span> : <span className="app-muted">Sin suscripción</span>) },
  { label: "Estado", render: (u) => { const [label, tone] = statusOf(u.status); return <Badge tone={tone}>{label}</Badge>; } },
  { label: "Usuarios", render: (u) => <UsageMeter used={u.users} limit={u.users_limit} /> },
  { label: "Usuarios del portal", render: (u) => <UsageMeter used={u.portal_users} limit={u.portal_users_limit} /> },
  { label: "Clientes", render: (u) => <UsageMeter used={u.customers} limit={u.customers_limit} /> },
  { label: "Almacenamiento", num: true, render: (u) => <span className="whitespace-nowrap">{`${u.storage_mb.toLocaleString("es-PE", { maximumFractionDigits: 2 })} MB`}</span> },
  { label: "Alta", render: (u) => <span className="whitespace-nowrap">{shortDate(u.created_at)}</span> },
];

const USAGE_EXPORT: ExportColumn<PlatformUsageRow>[] = [
  { label: "Empresa", value: (u) => u.name, width: 30 },
  { label: "Código", value: (u) => u.code, width: 14 },
  { label: "Plan", value: (u) => (u.plan ? `${u.plan}${u.trial ? " (prueba)" : ""}` : "Sin suscripción"), width: 22 },
  { label: "Estado", value: (u) => statusOf(u.status)[0], kind: "status", tone: (u) => (u.status === "ACTIVE" ? "success" : "warning"), width: 14 },
  { label: "Usuarios", value: (u) => limitText(u.users, u.users_limit), kind: "center", width: 12 },
  { label: "Usuarios del portal", value: (u) => limitText(u.portal_users, u.portal_users_limit), kind: "center", width: 14 },
  { label: "Clientes", value: (u) => limitText(u.customers, u.customers_limit), kind: "center", width: 12 },
  { label: "Almacenamiento (MB)", value: (u) => u.storage_mb, kind: "number", width: 16 },
  { label: "Alta", value: (u) => shortDate(u.created_at), kind: "center", width: 14 },
];

const AUDIT_COLS: Column<PlatformAuditRow>[] = [
  { label: "Fecha", render: (a) => <span className="tabular-nums">{dateTimeText(a.created_at)}</span> },
  { label: "Acción", render: (a) => actionOf(a.action) },
  { label: "Usuario", render: (a) => a.user_name ?? "—" },
];

const AUDIT_EXPORT: ExportColumn<PlatformAuditRow>[] = [
  { label: "Fecha", value: (a) => dateTimeText(a.created_at), width: 20 },
  { label: "Acción", value: (a) => actionOf(a.action), width: 30 },
  { label: "Código de la acción", value: (a) => a.action, width: 24 },
  { label: "Usuario", value: (a) => a.user_name ?? "—", width: 24 },
];

export function PlatformDashboardView() {
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<PlatformDashboard | null>(null);
  const [error, setError] = useState(false);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    setError(false);
    try {
      setData(await apiFetch<PlatformDashboard>("/v1/superadmin/dashboard"));
    } catch {
      setError(true);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- primera carga del panel
    void load();
  }, [load]);

  const refresh = (
    <Btn variant="secondary" size="sm" onClick={() => void load()} disabled={loading}>
      <RefreshCw size={14} className={loading ? "animate-spin" : ""} />
      <span>Actualizar</span>
    </Btn>
  );

  if (!data) {
    return (
      <Screen>
        <PageHead title="Dashboard" actions={refresh} />
        {error ? (
          <Card><LoadError text="No se pudo cargar el panel de la plataforma." onRetry={() => void load()} /></Card>
        ) : (
          <Kpis row>{[0, 1, 2, 3, 4].map((n) => <div key={n} className="app-surface h-[86px] animate-pulse rounded-xl" />)}</Kpis>
        )}
      </Screen>
    );
  }

  const { tenants, subscriptions: subs, users, health } = data;
  const active = tenants.by_status.ACTIVE ?? 0;
  const otherStatuses = Object.entries(tenants.by_status).filter(([status]) => status !== "ACTIVE");
  const revenue = data.revenue.length ? data.revenue : [{ currency: "PEN", mrr: 0 }];
  const mailTone = health.mail === "log" || health.mail === "array" ? "warning" : "success";
  const jobsTone = health.failed_last_24h > 0 ? "danger" : health.jobs_failed > 0 ? "warning" : "success";
  const outboxTone = health.outbox_failed > 0 ? "danger" : health.outbox_pending > 0 ? "warning" : "success";

  return (
    <Screen>
      <PageHead title="Dashboard" sub={<>Actualizado el {dateTimeText(data.generated_at)}</>} actions={refresh} />

      <Kpis row>
        <Kpi
          label="Empresas" value={count(tenants.total)}
          sub={<>{count(active)} {active === 1 ? "activa" : "activas"}{otherStatuses.map(([status, n]) => ` · ${n} ${statusOf(status)[0].toLowerCase()}`).join("")} · {tenants.new_this_month} {tenants.new_this_month === 1 ? "nueva" : "nuevas"} este mes</>}
        />
        <Kpi
          label="Suscripciones vigentes" tone="success" value={count(subs.current)}
          sub={<>{subs.paid} {subs.paid === 1 ? "pagada" : "pagadas"} · {subs.trial} en prueba{tenants.without_subscription ? ` · ${tenants.without_subscription} sin suscripción` : ""}</>}
        />
        <Kpi
          label="Pruebas por vencer" tone={subs.trial_expired ? "danger" : "warning"} value={count(subs.trial_expiring)}
          sub={<>En 7 días · {subs.trial_expired} {subs.trial_expired === 1 ? "vencida" : "vencidas"} sin pasar a pago</>}
        />
        <Kpi
          label="Ingreso mensual recurrente" tone="success"
          value={revenue.map((r) => money(r.mrr, r.currency)).join(" · ")}
          sub="Sin pruebas · anuales ÷ 12"
        />
        <Kpi
          label="Usuarios activos" value={count(users.tenant_users)}
          sub={<>{count(users.portal_users)} del portal de clientes · {users.super_admins} {users.super_admins === 1 ? "superadmin" : "superadmins"}</>}
        />
      </Kpis>

      <div className="grid gap-x-[18px] lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <Card title="Altas de empresas por mes">
          <SignupsChart months={data.signups} />
        </Card>
        <Card title="Empresas por plan" flush>
          <ExportableTable
            table="plans" title="Empresas por plan" file="empresas-por-plan" cols={PLAN_COLS} rows={data.plans} rowKey={(p) => p.id}
            exportCols={PLAN_EXPORT} empty="No hay planes." toast={toast}
          />
        </Card>
      </div>

      <Card title="Pruebas por vencer" flush>
        <ExportableTable
          table="trials" title="Pruebas por vencer" file="pruebas-por-vencer" cols={TRIAL_COLS} rows={data.trials} rowKey={(t) => t.tenant_id}
          exportCols={TRIAL_EXPORT} empty="Ninguna prueba vence en los próximos 7 días." toast={toast}
        />
      </Card>

      <Card title="Uso frente al plan" flush>
        <ExportableTable
          table="usage" title="Uso frente al plan" file="uso-frente-al-plan" cols={USAGE_COLS} rows={data.usage} rowKey={(u) => u.tenant_id}
          exportCols={USAGE_EXPORT} empty="Aún no hay empresas." toast={toast}
        />
      </Card>

      <div className="grid gap-x-[18px] lg:grid-cols-2">
        <Card title="Salud de la plataforma">
          <ul>
            <HealthItem
              tone={jobsTone} label="Cola de trabajos"
              detail={<>{health.jobs_pending} pendientes · {health.jobs_failed} fallidos{health.failed_last_24h ? ` (${health.failed_last_24h} en 24 h)` : ""}</>}
            />
            <HealthItem tone={outboxTone} label="Eventos por publicar" detail={<>{health.outbox_pending} pendientes · {health.outbox_failed} con error</>} />
            <HealthItem tone={health.dead_letters ? "danger" : "success"} label="Integraciones con error sin resolver" detail={count(health.dead_letters)} />
            <HealthItem tone={health.files_quarantined ? "warning" : "success"} label="Archivos en cuarentena" detail={count(health.files_quarantined)} />
            <HealthItem
              tone={mailTone} label="Correo"
              detail={mailTone === "warning" ? `Modo ${health.mail}: los correos no salen` : `Envío por ${health.mail.toUpperCase()}`}
            />
            <HealthItem tone="neutral" label="Cola configurada" detail={health.queue} />
          </ul>
        </Card>
        <Card title="Actividad reciente de la plataforma" flush>
          <ExportableTable
            table="audit" title="Actividad reciente de la plataforma" file="actividad-de-la-plataforma" cols={AUDIT_COLS} rows={data.audit}
            rowKey={(a, index) => `${a.created_at}-${a.action}-${a.user_name}-${index ?? 0}`} exportCols={AUDIT_EXPORT} empty="Sin acciones todavía." toast={toast}
          />
        </Card>
      </div>
      {toasts}
    </Screen>
  );
}
