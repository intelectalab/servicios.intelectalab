"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import {
  AlertTriangle, CheckCircle2, ChevronRight, Clock, Coins, FileSignature, FileText, Filter, Globe, KeyRound, LifeBuoy, Lock, Plug, Plus,
  Receipt, Repeat, ShoppingCart, SquareKanban, Target, Wallet, Wrench, Zap,
} from "lucide-react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { apiFetch } from "@/lib/api";
import { money } from "@/lib/commercial";
import { kfmt } from "@/lib/accounting";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import { Card, ExportButtons, LoadError, Screen, useToasts } from "@/components/ui/proto";
import type { Dashboard, DashboardAttention, DashboardChart, DashboardKpi, DashboardTone } from "@/types/dashboard";

const ICONS: Record<string, typeof Wrench> = {
  funnel: Filter, target: Target, file: FileText, check: CheckCircle2, receipt: Receipt, coins: Coins, wrench: Wrench, life: LifeBuoy,
  kanban: SquareKanban, clock: Clock, bolt: Zap, plug: Plug, lock: KeyRound, repeat: Repeat, globe: Globe, box: ShoppingCart,
  wallet: Wallet, contract: FileSignature,
};

// Verde, rojo y ámbar solo para estados: los indicadores van en el azul del logo salvo lo vencido.
const PILL: Record<DashboardTone, string> = {
  danger: "bg-[var(--p-danger-soft)] text-[color:var(--p-danger-text)]",
  warning: "bg-[var(--p-warning-soft)] text-[color:var(--p-warning-text)]",
  info: "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]",
  success: "bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]",
  neutral: "bg-[var(--p-neutral-soft)] text-[color:var(--p-neutral-text)]",
};
const FILL: Record<DashboardTone, string> = {
  danger: "bg-[#E5484D]", warning: "bg-[#E8A33A]", success: "bg-[var(--p-success)]", info: "bg-[var(--p-chart-1)]", neutral: "bg-[var(--p-muted-text)]",
};
const SHADOW = "shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]";

// El día de quien mira (el servidor trabaja en UTC: de noche en Perú ya sería mañana).
const today = () => new Date().toLocaleDateString("es-PE", { day: "numeric", month: "long", year: "numeric" });
const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

function kpiValue(kpi: DashboardKpi) {
  if (kpi.format === "text") return kpi.text ?? "—";
  if (kpi.value === null || kpi.value === undefined) return "—";
  if (kpi.format === "money") return kfmt(kpi.value);
  if (kpi.format === "percent") return `${kpi.value}%`;
  if (kpi.format === "hours") return `${kpi.value.toFixed(1)} h`;
  return kpi.value.toLocaleString("es-PE");
}

function PlanLock({ text = "No incluido en el plan de tu empresa" }: { text?: string }) {
  return (
    <span className="app-muted inline-flex items-center gap-1.5 text-[12px]" title="Este módulo no está incluido en el plan de tu empresa">
      <Lock size={13} aria-hidden="true" /> {text}
    </span>
  );
}

function KpiCard({ kpi }: { kpi: DashboardKpi }) {
  const Icon = ICONS[kpi.icon ?? ""] ?? FileText;
  const danger = kpi.tone === "r";
  const body = (
    <>
      <span className={`grid h-[46px] w-[46px] shrink-0 place-items-center rounded-xl ${kpi.locked ? "bg-[var(--app-surface-muted)] text-[color:var(--app-muted)]" : danger ? "bg-[var(--p-danger-soft)] text-[#E5484D]" : "bg-[var(--p-brand-soft)] text-[color:var(--p-brand)]"}`}>
        {kpi.locked ? <Lock size={20} /> : <Icon size={21} />}
      </span>
      <span className="grid min-w-0 flex-1 gap-0.5">
        <span className="app-muted text-[10.5px] font-semibold uppercase tracking-[.04em]">{kpi.label}</span>
        {kpi.locked ? (
          <><span className="app-muted text-[21px] font-semibold leading-[1.15]">—</span><PlanLock /></>
        ) : (
          <>
            <span className="text-[21px] font-semibold leading-[1.15] tabular-nums [overflow-wrap:anywhere] sm:text-2xl">{kpiValue(kpi)}</span>
            {kpi.sub && <span className={`text-[11.5px] ${kpi.sub_tone === "danger" ? "text-[color:var(--p-danger-text)]" : "app-muted"}`}>{kpi.sub}</span>}
          </>
        )}
      </span>
    </>
  );
  const box = `app-surface flex min-w-0 items-center gap-2.5 rounded-xl border-l-4 p-4 ${kpi.locked ? "border-l-[color:var(--app-border)]" : danger ? "border-l-[#E5484D]" : "border-l-[#1B8FC9]"} ${SHADOW}`;
  return kpi.href && !kpi.locked
    ? <Link href={kpi.href} className={`${box} transition-[transform,box-shadow] duration-150 hover:-translate-y-px hover:shadow-[0_6px_18px_rgba(8,25,55,.12)]`}>{body}</Link>
    : <div className={box}>{body}</div>;
}

function AttentionRow({ item }: { item: DashboardAttention }) {
  const content = (
    <>
      <span className={`min-w-[34px] rounded-md px-1.5 py-px text-center text-[15px] font-semibold tabular-nums ${PILL[item.tone]}`}>{item.count}</span>
      <span className="min-w-0 flex-1 text-[13px]">{item.text}</span>
      {item.href ? <ChevronRight size={15} className="app-muted shrink-0" /> : <span className="app-muted shrink-0 text-[11px]">Pantalla aún no disponible</span>}
    </>
  );
  const row = "flex items-center gap-2.5 rounded-[9px] bg-[var(--app-surface-muted)] px-2.5 py-2";
  return item.href
    ? <Link href={item.href} className={`${row} transition-colors hover:bg-[var(--p-brand-soft)]`}>{content}</Link>
    : <div className={row}>{content}</div>;
}

const short = (value: number, format?: string) => (format === "money" ? kfmt(value) : value.toLocaleString("es-PE"));

function HBars({ chart }: { chart: DashboardChart }) {
  const rows = chart.rows ?? [];
  const max = Math.max(1, ...rows.map((row) => row.value));
  return (
    <div className="grid gap-2">
      {rows.map((row) => (
        <div key={row.label} className="grid grid-cols-[90px_1fr_auto] items-center gap-2.5 text-[12.5px] sm:grid-cols-[minmax(80px,130px)_1fr_auto]">
          <span className="app-muted truncate" title={row.label}>{row.label}</span>
          <span className="h-2.5 overflow-hidden rounded-[5px] bg-[var(--app-surface-muted)]">
            <span className={`block h-full rounded-[5px] ${FILL[row.tone ?? "info"]}`} style={{ width: `${(row.value / max) * 100}%` }} />
          </span>
          <span className="min-w-[64px] text-right text-[13px] tabular-nums" title={chart.format === "money" ? money(row.value) : undefined}>{short(row.value, chart.format)}</span>
        </div>
      ))}
    </div>
  );
}

function Columns({ chart }: { chart: DashboardChart }) {
  const rows = chart.rows ?? [];
  return (
    <>
      <div className="h-[190px] w-full" role="img" aria-label={chart.label}>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={rows} margin={{ top: 8, right: 4, bottom: 0, left: 0 }} barCategoryGap="34%">
            <CartesianGrid vertical={false} stroke="var(--app-border)" strokeOpacity={0.7} />
            <XAxis dataKey="label" tickLine={false} axisLine={{ stroke: "var(--app-border)" }} tick={{ fill: "var(--app-muted)", fontSize: 11 }} />
            <YAxis width={76} tickFormatter={(value: number) => kfmt(value)} tickLine={false} axisLine={false} tick={{ fill: "var(--app-muted)", fontSize: 11 }} allowDecimals={false} />
            <Tooltip
              cursor={{ fill: "var(--app-surface-muted)", opacity: 0.6 }}
              content={({ active, payload }) => {
                const row = payload?.[0]?.payload as { label: string; value: number } | undefined;
                if (!active || !row) return null;
                return (
                  <div className="app-surface app-text rounded-lg border border-[color:var(--app-border)] px-3 py-2 text-[12.5px] shadow-[0_8px_24px_rgba(8,25,55,.18)]">
                    <p className="font-semibold capitalize">{row.label}</p>
                    <p className="tabular-nums">{money(row.value)}</p>
                  </div>
                );
              }}
            />
            <Bar dataKey="value" name="Facturado" fill="var(--p-chart-1)" radius={[4, 4, 0, 0]} maxBarSize={34} isAnimationActive={false} />
          </BarChart>
        </ResponsiveContainer>
      </div>
      {chart.collected !== null && chart.collected !== undefined && (
        <p className="app-muted mt-2 text-[12.5px]">Cobrado en el mismo periodo: <strong className="app-text tabular-nums">{money(chart.collected)}</strong></p>
      )}
    </>
  );
}

function ChartCard({ chart }: { chart: DashboardChart }) {
  return (
    <Card
      title={chart.label}
      actions={chart.href && !chart.locked ? <Link href={chart.href} className="text-[12px] font-medium text-[color:var(--p-brand-text)] hover:underline">Ver</Link> : undefined}
    >
      {chart.locked ? (
        <div className="app-muted flex flex-col items-center gap-1.5 py-6 text-center text-[13px]">
          <Lock size={22} aria-hidden="true" />
          <p>Este módulo no está incluido en el plan de tu empresa.</p>
        </div>
      ) : chart.type === "columns" ? <Columns chart={chart} /> : <HBars chart={chart} />}
    </Card>
  );
}

const ATTENTION_EXPORT: ExportColumn<DashboardAttention>[] = [
  { label: "Cantidad", value: (row) => row.count, width: 12, kind: "number" },
  { label: "Pendiente", value: (row) => row.text, width: 56 },
  {
    label: "Prioridad", value: (row) => ({ danger: "Urgente", warning: "Atención", info: "Pendiente", success: "Al día", neutral: "—" })[row.tone], width: 14,
    kind: "status", tone: (row) => row.tone,
  },
];

type Recent = NonNullable<Dashboard["recent"]>[number];
const RECENT_EXPORT: ExportColumn<Recent>[] = [
  { label: "Fecha", value: (row) => dateTime(row.at), width: 22, kind: "center" },
  { label: "Actor", value: (row) => row.actor, width: 26 },
  { label: "Acción", value: (row) => row.action, width: 34 },
  { label: "Entidad", value: (row) => row.entity ?? "", width: 24 },
  { label: "Correlation ID", value: (row) => row.correlation_id ?? "", width: 38 },
];

function initials(name: string) {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? "").join("");
}

/** Panel de control (P-DASH-01): qué ocurre, qué requiere atención y qué resultados hay, según el rol. */
export default function DashboardView() {
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [exporting, setExporting] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Dashboard>("/v1/dashboard")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el panel."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function exportList<T>(key: string, name: string, title: string, columns: ExportColumn<T>[], rows: T[], format: "xlsx" | "csv", audit = false) {
    setExporting(key);
    try {
      // La actividad sale de la auditoría: exige su permiso de exportar y queda registrada.
      if (audit) await recordExport("audit", format, rows.length, true);
      if (format === "xlsx") await exportXlsx(name, title, columns, rows);
      else exportCsv(name, columns, rows);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(null);
    }
  }

  if (error) return <Screen><Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card></Screen>;

  if (!data) {
    return (
      <Screen>
        <div className="mb-5 h-[112px] animate-pulse rounded-[14px] bg-[var(--app-surface-muted)]" aria-busy="true" />
        <div className="grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-3">
          {[0, 1, 2, 3].map((n) => <div key={n} className="h-[86px] animate-pulse rounded-xl bg-[var(--app-surface-muted)]" />)}
        </div>
      </Screen>
    );
  }

  const { hero } = data;
  const charts: ReactNode = data.charts.map((chart) => <ChartCard key={chart.key} chart={chart} />);

  return (
    <Screen>
      <h1 className="sr-only">Panel de control</h1>

      <section
        className={`relative mb-5 flex flex-wrap items-center gap-5 overflow-hidden rounded-[14px] px-5 py-5 text-[#fff] sm:px-7 sm:py-[26px] ${SHADOW}`}
        style={{ background: "radial-gradient(circle at 85% 30%, rgb(255 255 255 / .14), transparent 45%), var(--side-bg)" }}
      >
        <span aria-hidden="true" className="pointer-events-none absolute -right-[60px] -top-[60px] h-[260px] w-[260px] rounded-full border-[40px] border-white/5" />
        <div className="relative z-[1] min-w-0 flex-[1_1_320px]">
          <h2 className="text-[19px] font-semibold sm:text-[22px]">Hola, {hero.name.split(" ")[0]}</h2>
          <p className="mt-1 text-[13px] opacity-90">
            {[hero.role, hero.company].filter(Boolean).join(" · ")} · datos al {today()}
          </p>
        </div>
        {hero.cta && (
          <div className="relative z-[1] flex flex-wrap gap-2">
            <Link href={hero.cta.href} className="inline-flex h-[38px] items-center gap-[7px] whitespace-nowrap rounded-[9px] bg-[#fff] px-4 text-[13px] font-semibold text-[#0F5A82] transition-colors hover:bg-[#E3F2FA]">
              <Plus size={16} /> {hero.cta.label}
            </Link>
          </div>
        )}
      </section>

      <div className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-3">
        {data.kpis.length ? data.kpis.map((kpi) => <KpiCard key={kpi.key} kpi={kpi} />) : <p className="app-muted">Tu rol no tiene indicadores asignados.</p>}
      </div>

      <div className="grid items-start gap-[18px] xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <Card title={<span className="inline-flex items-center gap-2"><AlertTriangle size={17} className="text-[color:var(--p-warning-text)]" /> Requiere tu atención</span>} flush>
          <div className="px-5 pb-4 pt-2">
            {data.attention.length ? (
              <ul className="grid gap-1">
                {data.attention.map((item) => <li key={item.key}><AttentionRow item={item} /></li>)}
              </ul>
            ) : (
              <div className="app-muted flex flex-col items-center gap-1.5 py-6 text-center">
                <CheckCircle2 size={24} className="text-[color:var(--p-success)]" />
                <p>Nada pendiente dentro de tu alcance.</p>
              </div>
            )}
            {data.scope.length > 0 && (
              <p className="app-muted mt-2.5 text-[12px]">
                Tu alcance: {data.scope.map((item, index) => (
                  <span key={item.label}>{index > 0 && " · "}{item.label}: <b className="app-text font-semibold">{item.scope}</b></span>
                ))}
              </p>
            )}
          </div>
          {data.attention.length > 0 && (
            <div className="border-t border-[color:var(--app-border)] px-3.5 py-2.5">
              <ExportButtons
                total={data.attention.length} busy={exporting === "attention"}
                onExport={(format) => exportList("attention", "requiere-tu-atencion", "Requiere tu atención", ATTENTION_EXPORT, data.attention, format)}
              />
            </div>
          )}
        </Card>
        <div className="grid min-w-0">{charts}</div>
      </div>

      {data.health && (
        <Card title="Qué está funcionando">
          <ul className="grid">
            {data.health.rows.map((row) => (
              <li key={row.name} className="grid grid-cols-[10px_1fr_auto] items-center gap-2.5 border-b border-[color:var(--app-border)] py-2 last:border-b-0">
                <span className={`h-[9px] w-[9px] rounded-full ${row.status === "ACTIVE" ? "bg-[var(--p-success)]" : row.status === "ERROR" ? "bg-[#E5484D]" : "bg-[var(--p-muted-text)]"}`} />
                <span>{row.name}</span>
                <small className="app-muted text-[12px]">{row.detail ?? row.status}</small>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {data.recent && (
        <Card title="Actividad reciente (auditoría)" actions={<Link href="/audit" className="text-[12px] font-medium text-[color:var(--p-brand-text)] hover:underline">Ver todo</Link>} flush>
          <div className="px-5 pb-2 pt-2">
            {data.recent.length ? (
              <ul className="grid">
                {data.recent.map((row) => (
                  <li key={row.id} className="flex gap-2.5 border-b border-[color:var(--app-border)] py-2 last:border-b-0">
                    <span aria-hidden="true" className="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full bg-[var(--p-brand-soft)] text-[10px] font-semibold text-[color:var(--p-brand-soft-text)]">{initials(row.actor)}</span>
                    <div className="min-w-0 text-[13px]">
                      <b className="font-semibold">{row.actor}</b> <span className="app-muted">{row.action}</span>
                      {row.entity && <> <code className="rounded bg-[var(--app-surface-muted)] px-1.5 py-0.5 font-mono text-[11.5px]">{row.entity}</code></>}
                      <small className="app-muted block text-[11.5px] [overflow-wrap:anywhere]">{dateTime(row.at)}{row.correlation_id ? ` · ${row.correlation_id}` : ""}</small>
                    </div>
                  </li>
                ))}
              </ul>
            ) : <p className="app-muted py-4">Sin actividad.</p>}
          </div>
          {data.recent.length > 0 && (
            <div className="border-t border-[color:var(--app-border)] px-3.5 py-2.5">
              <ExportButtons
                permission="audit.export" total={data.recent.length} busy={exporting === "recent"}
                onExport={(format) => exportList("recent", "actividad-reciente", "Actividad reciente", RECENT_EXPORT, data.recent ?? [], format, true)}
              />
            </div>
          )}
        </Card>
      )}
      {toasts}
    </Screen>
  );
}
