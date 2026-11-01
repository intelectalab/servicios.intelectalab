"use client";

import { useEffect, useState, type ReactNode } from "react";
import { useRouter } from "next/navigation";
import { ArrowDownRight, ArrowUpRight, BarChart3, Clock, Coins, FileText, Filter, LifeBuoy, Repeat, TrendingUp, Wrench } from "lucide-react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { apiFetch } from "@/lib/api";
import { FREQUENCIES, QUOTATION_STATES, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { OPPORTUNITY_STATES, dateTime } from "@/lib/crm";
import { AGING, RECEIVABLE_STATES } from "@/lib/finance";
import { PRIORITIES, PROJECT_STATES, WORK_ORDER_STATES } from "@/lib/operations";
import { PRIORITY_LABELS, TICKET_STATES } from "@/lib/support";
import { kfmt } from "@/lib/accounting";
import {
  DATED, QUOTATION_SOURCES, RECORD_PATHS, SLA_STATES, monthLabel, periodLabel, type ReportKey, type ReportKpi, type ReportPage, type ReportRow, type ReportsIndex,
} from "@/lib/reports";
import { useAccess } from "@/lib/access-context";
import { ReceivableModal } from "@/components/dashboard/finance/Receivables";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, DataTable, ExportButtons, INPUT, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen, SelectionBar, TD, TH,
  applySort, useRowSelection, useToasts, type Column, type SortState, type Tone,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";

type States = [string, string, Tone][];

/** Una columna del reporte: cómo se ve en la tabla y cómo va al Excel. */
type Col = {
  label: string;
  sortKey?: string;
  filter?: "text" | "number" | "date";
  num?: boolean;
  value: (row: ReportRow) => string | number;
  render?: (row: ReportRow) => ReactNode;
  kind?: ExportColumn<ReportRow>["kind"];
  /** Estado: su lista de textos y colores y el campo de la fila (para el color de la celda en Excel). */
  states?: States;
  field?: string;
  width?: number;
};

const str = (value: unknown) => (value === null || value === undefined ? "" : String(value));
const num = (value: unknown) => Number(value ?? 0);
const cur = (row: ReportRow) => str(row.currency) || "PEN";

function status(label: string, field: string, states: States, sortKey?: string): Col {
  return {
    label, sortKey, states, field, width: 16, kind: "status",
    value: (row) => stateOf(states, str(row[field]))[0],
    render: (row) => {
      const [text, tone] = stateOf(states, str(row[field]));
      return <Badge tone={tone}>{text}</Badge>;
    },
  };
}

function amount(label: string, field: string, sortKey?: string, filter = true): Col {
  return {
    label, sortKey, filter: filter ? "number" : undefined, num: true, kind: "money", width: 16,
    value: (row) => num(row[field]),
    render: (row) => <span className="whitespace-nowrap tabular-nums">{money(num(row[field]), cur(row))}</span>,
  };
}

const PRIORITY_STATES: States = PRIORITIES.map(([key, label, tone]) => [key, label, tone]);

const REPORTS: Record<ReportKey, { icon: ReactNode; cols: Col[] }> = {
  ventas: {
    icon: <FileText size={16} />,
    cols: [
      { label: "Número", sortKey: "number", filter: "text", value: (r) => str(r.number), render: (r) => <strong className="whitespace-nowrap font-semibold">{str(r.number)}</strong>, width: 18 },
      { label: "Cliente", sortKey: "customer", filter: "text", value: (r) => str(r.customer), width: 34 },
      { label: "Origen", value: (r) => QUOTATION_SOURCES[str(r.source)] ?? "Directa", width: 16 },
      status("Estado", "status", QUOTATION_STATES, "status"),
      amount("Total", "total", "total"),
      { label: "Fecha", sortKey: "date", filter: "date", kind: "center", value: (r) => shortDate(str(r.date)), width: 14 },
    ],
  },
  pipeline: {
    icon: <Filter size={16} />,
    cols: [
      {
        label: "Oportunidad", sortKey: "opportunity", filter: "text", width: 36,
        value: (r) => `${str(r.number)} · ${str(r.name)}`,
        render: (r) => (
          <div className="min-w-[160px] max-w-[280px]">
            <strong className="font-semibold">{str(r.name)}</strong>
            <small className="app-muted block text-xs">{[r.number, r.customer].filter(Boolean).join(" · ")}</small>
          </div>
        ),
      },
      { label: "Etapa", sortKey: "stage", filter: "text", value: (r) => str(r.stage) || "—", width: 18 },
      status("Estado", "status", OPPORTUNITY_STATES, "status"),
      amount("Monto", "amount", "amount"),
      { label: "Probabilidad", sortKey: "probability", num: true, kind: "number", value: (r) => num(r.probability), render: (r) => `${num(r.probability)} %`, width: 12 },
      { label: "Responsable", sortKey: "owner", filter: "text", value: (r) => str(r.owner) || "Sin asignar", width: 24 },
    ],
  },
  cobranza: {
    icon: <Coins size={16} />,
    cols: [
      { label: "Comprobante", sortKey: "invoice", filter: "text", value: (r) => str(r.invoice), render: (r) => <strong className="whitespace-nowrap font-semibold">{str(r.invoice) || "—"}</strong>, width: 18 },
      { label: "Cliente", sortKey: "customer", filter: "text", value: (r) => str(r.customer), width: 34 },
      { label: "Vence", sortKey: "due", filter: "date", kind: "center", value: (r) => shortDate(str(r.due_date)), width: 14 },
      {
        label: "Antigüedad", width: 16,
        value: (r) => AGING.find(([key]) => key === r.bucket)?.[1] ?? "",
        render: (r) => {
          const label = AGING.find(([key]) => key === r.bucket)?.[1] ?? "—";
          return r.bucket === "CURRENT" ? <span className="app-muted">{label}</span> : <span className="whitespace-nowrap font-semibold text-[color:var(--p-danger-text)]">{label}</span>;
        },
      },
      status("Estado", "status", RECEIVABLE_STATES),
      amount("Saldo", "balance", "balance"),
    ],
  },
  rentabilidad: {
    icon: <TrendingUp size={16} />,
    cols: [
      {
        label: "Proyecto", sortKey: "project", filter: "text", width: 36,
        value: (r) => `${str(r.code)} · ${str(r.name)}`,
        render: (r) => (
          <div className="min-w-[160px] max-w-[280px]">
            <strong className="font-semibold">{str(r.name)}</strong>
            <small className="app-muted block text-xs">{[r.code, r.customer].filter(Boolean).join(" · ")}</small>
          </div>
        ),
      },
      amount("Ingreso", "revenue", "revenue"),
      amount("Costo", "cost", "cost", false),
      amount("Margen", "margin", "margin"),
      {
        label: "Margen %", sortKey: "margin_pct", num: true, kind: "number", width: 12, value: (r) => num(r.margin_pct),
        render: (r) => <span className={`tabular-nums ${num(r.margin_pct) < 20 ? "font-semibold text-[color:var(--p-warning-text)]" : ""}`}>{num(r.margin_pct)} %</span>,
      },
      { label: "Avance", num: true, kind: "number", width: 10, value: (r) => num(r.progress), render: (r) => `${num(r.progress)} %` },
      status("Estado", "status", PROJECT_STATES, "status"),
    ],
  },
  sla: {
    icon: <LifeBuoy size={16} />,
    cols: [
      {
        label: "Ticket", sortKey: "ticket", filter: "text", width: 36,
        value: (r) => `${str(r.number)} · ${str(r.subject)}`,
        render: (r) => (
          <div className="min-w-[160px] max-w-[280px]">
            <strong className="font-semibold">{str(r.number)}</strong>
            <small className="app-muted block text-xs">{str(r.subject)}</small>
          </div>
        ),
      },
      { label: "Cliente", sortKey: "customer", filter: "text", value: (r) => str(r.customer) || "—", width: 30 },
      { label: "Prioridad", sortKey: "priority", value: (r) => (r.priority ? `${r.priority} ${PRIORITY_LABELS[str(r.priority) as keyof typeof PRIORITY_LABELS] ?? ""}` : "—"), width: 14 },
      status("Estado", "status", TICKET_STATES, "status"),
      status("SLA", "sla", SLA_STATES, "sla"),
      { label: "Apertura", sortKey: "opened", filter: "date", kind: "center", value: (r) => (r.opened_at ? dateTime(str(r.opened_at)) : ""), width: 20 },
    ],
  },
  renovaciones: {
    icon: <Repeat size={16} />,
    cols: [
      { label: "Cliente", sortKey: "customer", filter: "text", value: (r) => str(r.customer), width: 34 },
      { label: "Servicio", sortKey: "service", filter: "text", value: (r) => str(r.service) || "—", width: 30 },
      { label: "Frecuencia", value: (r) => FREQUENCIES[str(r.frequency) as keyof typeof FREQUENCIES] ?? str(r.frequency), width: 14 },
      {
        label: "Fin de vigencia", sortKey: "end", filter: "date", kind: "center", width: 16, value: (r) => shortDate(str(r.end_date)),
        render: (r) => {
          const days = r.days_left === null ? null : num(r.days_left);
          return (
            <span className="whitespace-nowrap">
              {shortDate(str(r.end_date))}
              {days !== null && <small className={`block text-xs ${days < 0 ? "text-[color:var(--p-danger-text)]" : days <= 30 ? "text-[color:var(--p-warning-text)]" : "app-muted"}`}>{days < 0 ? `venció hace ${-days} d` : days === 0 ? "vence hoy" : `en ${days} d`}</small>}
            </span>
          );
        },
      },
      amount("Monto por ciclo", "amount", "amount"),
    ],
  },
  operaciones: {
    icon: <Wrench size={16} />,
    cols: [
      {
        label: "OT", sortKey: "order", filter: "text", width: 36,
        value: (r) => `${str(r.code)} · ${str(r.title)}`,
        render: (r) => (
          <div className="min-w-[160px] max-w-[280px]">
            <strong className="font-semibold">{str(r.code)}</strong>
            <small className="app-muted block text-xs">{[r.title, r.customer].filter(Boolean).join(" · ")}</small>
          </div>
        ),
      },
      { label: "Técnico", sortKey: "technician", filter: "text", value: (r) => str(r.technician) || "Sin asignar", width: 24 },
      status("Estado", "status", WORK_ORDER_STATES, "status"),
      status("Prioridad", "priority", PRIORITY_STATES, "priority"),
      { label: "Programada", sortKey: "scheduled", filter: "date", kind: "center", value: (r) => (r.scheduled_at ? dateTime(str(r.scheduled_at)) : "Sin programar"), width: 20 },
    ],
  },
};

/** Barras horizontales (tramos de antigüedad, estados): etiqueta, barra y cifra. */
function HBars({ rows, format }: { rows: { label: string; value: number; tone: Tone }[]; format: "money" | "count" }) {
  const max = Math.max(1, ...rows.map((row) => row.value));
  const fill: Record<Tone, string> = {
    danger: "bg-[#E5484D]", warning: "bg-[#E8A33A]", success: "bg-[var(--p-success)]", info: "bg-[var(--p-chart-1)]",
    accent: "bg-[var(--p-chart-1)]", neutral: "bg-[var(--p-muted-text)]", muted: "bg-[var(--p-muted-text)]",
  };
  return (
    <div className="mb-4 grid gap-2">
      {rows.map((row) => (
        <div key={row.label} className="grid grid-cols-[96px_1fr_auto] items-center gap-2.5 text-[12.5px] sm:grid-cols-[minmax(90px,140px)_1fr_auto]">
          <span className="app-muted truncate" title={row.label}>{row.label}</span>
          <span className="h-2.5 overflow-hidden rounded-[5px] bg-[var(--app-surface-muted)]">
            <span className={`block h-full rounded-[5px] ${fill[row.tone]}`} style={{ width: `${(row.value / max) * 100}%` }} />
          </span>
          <span className="min-w-[64px] text-right text-[13px] tabular-nums" title={format === "money" ? money(row.value) : undefined}>
            {format === "money" ? kfmt(row.value) : row.value}
          </span>
        </div>
      ))}
    </div>
  );
}

function MonthBars({ months }: { months: { key: string; amount: number }[] }) {
  const rows = months.map((month) => ({ label: monthLabel(month.key), value: month.amount }));
  return (
    <div className="mb-4 h-[190px] w-full" role="img" aria-label="Total cotizado por mes">
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
          <Bar dataKey="value" name="Cotizado" fill="var(--p-chart-1)" radius={[4, 4, 0, 0]} maxBarSize={34} isAnimationActive={false} />
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}

/** Gráfico del reporte, como en el prototipo (ventas, cobranza y rentabilidad) y los conteos de SLA y OT. */
function ReportChart({ report, page }: { report: ReportKey; page: ReportPage }) {
  const summary = page.summary;
  if (report === "ventas" && summary?.months) return <><p className="app-muted mb-1 text-xs">Total cotizado por mes (en soles), últimos 6 meses</p><MonthBars months={summary.months} /></>;
  if (report === "cobranza" && summary?.aging) {
    return (
      <>
        <p className="app-muted mb-2 text-xs">Saldo por antigüedad (en soles)</p>
        <HBars format="money" rows={summary.aging.map((bucket, index) => ({ label: bucket.label, value: bucket.amount, tone: index === 0 ? "success" : "danger" }))} />
        {summary.missing_rate && <Notice tone="warn">Hay saldo en dólares y no hay tipo de cambio registrado: ese saldo no entra en el gráfico.</Notice>}
      </>
    );
  }
  if (report === "rentabilidad" && page.data.length) {
    return (
      <>
        <p className="app-muted mb-2 text-xs">Margen por proyecto (en ámbar, menos de 20 %), de esta página</p>
        <HBars format="money" rows={page.data.map((row) => ({ label: str(row.name), value: Math.max(0, num(row.margin)), tone: num(row.margin_pct) < 20 ? "warning" : "success" }))} />
      </>
    );
  }
  if (report === "sla" && summary?.sla) {
    const rows = SLA_STATES.filter(([key]) => summary.sla?.[key]).map(([key, label, tone]) => ({ label, value: summary.sla?.[key] ?? 0, tone }));
    return rows.length ? <><p className="app-muted mb-2 text-xs">Tickets por estado del SLA</p><HBars format="count" rows={rows} /></> : null;
  }
  if (report === "operaciones" && summary?.states) {
    const rows = WORK_ORDER_STATES.filter(([key]) => summary.states?.[key]).map(([key, label, tone]) => ({ label, value: summary.states?.[key] ?? 0, tone }));
    return rows.length ? <><p className="app-muted mb-2 text-xs">Órdenes por estado</p><HBars format="count" rows={rows} /></> : null;
  }
  return null;
}

const rowKey = (row: ReportRow) => str(row.id);

/** Un reporte: rango de fechas, gráfico, tabla con orden y filtros por columna, y exportación. */
const KPI_VALUE: Record<ReportKpi["format"], (value: number) => string> = {
  money: (value) => kfmt(value),
  count: (value) => value.toLocaleString("es-PE"),
  percent: (value) => `${value} %`,
  hours: (value) => `${value.toLocaleString("es-PE")} h`,
};

/** Cambio frente al periodo anterior: en % (en puntos si la cifra ya es un %). En horas, bajar es bueno. */
function KpiDelta({ kpi }: { kpi: ReportKpi }) {
  if (kpi.previous === null || kpi.value === null) return null;
  if (kpi.previous === kpi.value) return <span className="app-muted">igual que el periodo anterior</span>;
  const diff = kpi.format === "percent" ? kpi.value - kpi.previous : kpi.previous ? ((kpi.value - kpi.previous) / Math.abs(kpi.previous)) * 100 : null;
  if (diff === null) return <span className="app-muted">sin datos del periodo anterior</span>;
  const rounded = Math.round(diff);
  if (rounded === 0) return <span className="app-muted">igual que el periodo anterior</span>;
  const good = kpi.format === "hours" ? rounded < 0 : rounded > 0;
  const Arrow = rounded > 0 ? ArrowUpRight : ArrowDownRight;
  return (
    <span
      className={`inline-flex items-center gap-0.5 font-semibold ${good ? "text-[color:var(--p-success-text)]" : "text-[color:var(--p-danger-text)]"}`}
      title={`Periodo anterior: ${KPI_VALUE[kpi.format](kpi.previous)}`}
    >
      <Arrow size={13} aria-hidden="true" />
      {Math.abs(rounded)}{kpi.format === "percent" ? " pts" : " %"}
      <span className="app-muted font-normal">&nbsp;vs anterior</span>
    </span>
  );
}

function ReportKpis({ page }: { page: ReportPage }) {
  if (!page.kpis?.length) return null;
  const compares = page.kpis.some((kpi) => kpi.previous !== null);
  return (
    <>
      {compares && page.period && (
        <p className="app-muted mb-2 text-xs">
          {page.period.custom ? "Rango elegido" : "Lo que va del mes"}: {periodLabel(page.period.from, page.period.to)}, comparado con {periodLabel(page.period.prev_from, page.period.prev_to)}.
        </p>
      )}
      <Kpis row>
        {page.kpis.map((kpi) => (
          <Kpi
            key={kpi.label}
            label={kpi.label}
            value={kpi.value === null ? "—" : <span title={kpi.format === "money" ? money(kpi.value) : undefined}>{KPI_VALUE[kpi.format](kpi.value)}</span>}
            sub={(kpi.previous !== null || kpi.hint) && (
              <span className="grid gap-0.5">
                {kpi.previous !== null && <KpiDelta kpi={kpi} />}
                {kpi.hint && <span>{kpi.hint}</span>}
              </span>
            )}
          />
        ))}
      </Kpis>
    </>
  );
}

function ReportPanel({ report, label, onExported }: { report: ReportKey; label: string; onExported: () => void }) {
  const { toast, toasts } = useToasts();
  const router = useRouter();
  const { can } = useAccess();
  // Cobranza abre la cuenta en una ventana (no tiene página propia); los demás, su ficha.
  const [receivableId, setReceivableId] = useState<string | null>(null);
  const openRecord = (row: ReportRow) => {
    const path = RECORD_PATHS[report];
    if (path) router.push(path(str(row.id)));
    else setReceivableId(str(row.id));
  };
  const def = REPORTS[report];
  const dated = DATED[report];
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [reload, setReload] = useState(0);
  const [data, setData] = useState<ReportPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState(false);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(rowKey);

  const withRange = (params: URLSearchParams) => {
    if (from) params.set("from", from);
    if (to) params.set("to", to);
    return params;
  };

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = withRange(new URLSearchParams({ per_page: String(perPage), page: String(page) }));
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<ReportPage>(`/v1/reports/${report}?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar el reporte.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- withRange solo lee from y to, que ya están en la lista
  }, [report, page, perPage, from, to, columnFilters.param, sort, reload]);

  const exportCols: ExportColumn<ReportRow>[] = def.cols.map((col) => ({
    label: col.label, width: col.width, kind: col.kind, value: col.value,
    currency: col.kind === "money" ? cur : undefined,
    tone: col.states && col.field ? (row) => excelTone(stateOf(col.states ?? [], str(row[col.field ?? ""]))[1]) : undefined,
  }));

  async function runExport(format: "xlsx" | "csv", picked?: ReportRow[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<ReportRow>(`/v1/reports/${report}`, applySort(columnFilters.apply(withRange(new URLSearchParams())), sort)));
      await recordExport("reports", format, rows.length, Boolean(picked), `report=${report}`);
      const name = `reporte-${report}`;
      if (format === "xlsx") await exportXlsx(name, label, exportCols, rows);
      else exportCsv(name, exportCols, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
      onExported();
    } catch {
      toast("No se pudo exportar el reporte.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const cols: Column<ReportRow>[] = def.cols.map((col) => ({
    label: col.label, sortKey: col.sortKey, filter: col.filter, num: col.num,
    render: col.render ?? ((row) => col.value(row) || "—"),
  }));

  return (
    <>
      <Card title={label} flush>
        <div className="px-5 pt-3">
          {dated && (
            <div className="mb-3 flex flex-wrap items-end gap-2.5">
              <label className="grid gap-1 text-[12px]">
                <span className="app-muted">{dated}: desde</span>
                <input className={`${INPUT} w-[160px]`} type="date" value={from} max={to || undefined} onChange={(event) => { setFrom(event.target.value); setPage(1); }} />
              </label>
              <label className="grid gap-1 text-[12px]">
                <span className="app-muted">hasta</span>
                <input className={`${INPUT} w-[160px]`} type="date" value={to} min={from || undefined} onChange={(event) => { setTo(event.target.value); setPage(1); }} />
              </label>
            </div>
          )}
          <div className="mb-2 flex flex-wrap items-center gap-2">
            <ColumnFilterBar
              filters={columnFilters}
              hasOtherFilters={Boolean(from || to)}
              onClearAll={() => {
                setFrom("");
                setTo("");
                setPage(1);
              }}
            />
          </div>
          {data && <ReportKpis page={data} />}
          {data && <ReportChart report={report} page={data} />}
          {data && <p className="app-muted mb-2 text-[12.5px]">{data.total} {data.total === 1 ? "fila" : "filas"} dentro de tu alcance.</p>}
        </div>
        {error ? (
          <div className="px-5 pb-4"><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></div>
        ) : (
          <>
            <SelectionBar permission="reports.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
            <DataTable
              tableId={`report-${report}`} sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
              onFilter={(column) => addColumnFilter(columnFilters, column)}
              onRowClick={openRecord}
              cols={cols}
              rows={data?.data ?? []}
              rowKey={rowKey}
              selection={selection}
              loading={loading && !data}
              empty="No hay datos con estos filtros."
            />
            {data && (
              <Pager
                loading={loading}
                page={data.current_page}
                lastPage={data.last_page}
                total={data.total}
                perPage={data.per_page}
                onPage={setPage}
                onPerPage={(size) => { setPerPage(size); setPage(1); }}
                left={<ExportButtons permission="reports.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
              />
            )}
          </>
        )}
      </Card>
      {receivableId && <ReceivableModal id={receivableId} version={0} seesInvoices={can("billing.view")} onClose={() => setReceivableId(null)} />}
      {toasts}
    </>
  );
}

export default function ReportsView() {
  const [index, setIndex] = useState<ReportsIndex | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [current, setCurrent] = useState<ReportKey | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<ReportsIndex>("/v1/reports")
      .then((data) => {
        if (cancelled) return;
        setIndex(data);
        setError("");
        // El reporte elegido va en la dirección (?r=): al volver de una ficha se abre el mismo.
        setCurrent((selected) => {
          const wanted = selected ?? (new URLSearchParams(window.location.search).get("r") as ReportKey | null);
          return wanted && data.reports.some((report) => report.key === wanted) ? wanted : data.reports[0]?.key ?? null;
        });
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los reportes."); });
    return () => { cancelled = true; };
  }, [reload]);

  const selected = index?.reports.find((report) => report.key === current);

  return (
    <Screen>
      <PageHead title="Reportes" />
      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !index ? (
        <Card><p className="app-muted">Cargando reportes…</p></Card>
      ) : !index.reports.length ? (
        <Card><p className="app-muted">Tu rol no tiene reportes disponibles.</p></Card>
      ) : (
        <div className="grid items-start gap-[18px] lg:grid-cols-[250px_minmax(0,1fr)]">
          <nav aria-label="Reportes" className="app-surface grid gap-0.5 rounded-xl p-2 shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]">
            {index.reports.map((report) => {
              const on = report.key === current;
              return (
                <button
                  key={report.key}
                  type="button"
                  aria-current={on ? "page" : undefined}
                  onClick={() => {
                    setCurrent(report.key);
                    window.history.replaceState(window.history.state, "", `/reports?r=${report.key}`);
                  }}
                  className={`flex cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2.5 text-left text-[13px] font-medium ${
                    on ? "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]" : "app-muted hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
                  }`}
                >
                  <span className="shrink-0">{REPORTS[report.key]?.icon ?? <BarChart3 size={16} />}</span>
                  <span>{report.label}</span>
                </button>
              );
            })}
          </nav>

          <div className="min-w-0">
            {selected && <ReportPanel key={selected.key} report={selected.key} label={selected.label} onExported={() => setReload((n) => n + 1)} />}

            {index.exports.length > 0 && (
              <Card title="Mis exportaciones">
                <div className="overflow-x-auto">
                  <table className="w-full border-collapse text-[13px]">
                    <thead>
                      <tr>
                        <th className={TH}>Reporte</th>
                        <th className={TH}>Formato</th>
                        <th className={`${TH} text-right`}>Filas</th>
                        <th className={TH}>Fecha</th>
                      </tr>
                    </thead>
                    <tbody>
                      {index.exports.map((item) => (
                        <tr key={item.at}>
                          <td className={TD}>{item.label}{item.selection && <small className="app-muted block text-xs">Solo filas marcadas</small>}</td>
                          <td className={TD}>{item.format === "xlsx" ? "Excel" : item.format === "csv" ? "CSV" : "—"}</td>
                          <td className={`${TD} text-right tabular-nums`}>{item.rows}</td>
                          <td className={`${TD} whitespace-nowrap`}><Clock size={12} className="app-muted mr-1 inline align-[-1px]" />{dateTime(item.at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </Card>
            )}
          </div>
        </div>
      )}
    </Screen>
  );
}
