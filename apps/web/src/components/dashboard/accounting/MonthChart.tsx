"use client";

import { useState } from "react";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis, type TooltipContentProps } from "recharts";
import type { NameType, ValueType } from "recharts/types/component/DefaultTooltipContent";
import { money } from "@/lib/commercial";
import { kfmt, monthName, monthTick, signedMoney } from "@/lib/accounting";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import { Card, ExportButtons, useToasts } from "@/components/ui/proto";
import type { FinancialStatements } from "@/types/accounting";

type Month = FinancialStatements["months"][number];
type Toast = ReturnType<typeof useToasts>["toast"];


// Dos azules del logo, validados para daltonismo y contraste en claro y oscuro (variables del tema).
const SERIES = [
  { key: "income", label: "Ingresos", color: "var(--p-chart-1)" },
  { key: "expenses", label: "Gastos", color: "var(--p-chart-2)" },
] as const;

const EXPORT: ExportColumn<Month>[] = [
  { label: "Mes", value: (m) => monthName(m.period), width: 18 },
  { label: "Ingresos", value: (m) => m.income, width: 16, kind: "money" },
  { label: "Gastos", value: (m) => m.expenses, width: 16, kind: "money" },
  { label: "Resultado", value: (m) => m.result, width: 16, kind: "money" },
];

/** Recuadro al pasar el cursor o tocar un mes: ingresos, gastos y el resultado del mes. */
function MonthTooltip({ active, payload }: Pick<TooltipContentProps<ValueType, NameType>, "active" | "payload">) {
  const month = payload?.[0]?.payload as Month | undefined;
  if (!active || !month) return null;
  return (
    <div className="app-surface app-text min-w-[190px] rounded-lg border border-[color:var(--app-border)] px-3 py-2 text-[12.5px] shadow-[0_8px_24px_rgba(8,25,55,.18)]">
      <p className="mb-1 font-semibold capitalize">{monthName(month.period)}</p>
      {SERIES.map((series) => (
        <p key={series.key} className="flex items-center justify-between gap-4">
          <span className="app-muted"><i className="mr-1.5 inline-block h-2.5 w-2.5 rounded-[3px] align-[-1px]" style={{ background: series.color }} />{series.label}</span>
          <span className="tabular-nums">{money(month[series.key])}</span>
        </p>
      ))}
      <p className="mt-1 flex items-center justify-between gap-4 border-t border-[color:var(--app-border)] pt-1 font-semibold">
        <span>Resultado</span>
        <span className="tabular-nums">{signedMoney(month.result)}</span>
      </p>
    </div>
  );
}

/** "Ingresos y gastos por mes": gráfico o tabla, con todos los meses del rango elegido. */
export function MonthChart({ months, toast }: { months: Month[]; toast: Toast }) {
  const [view, setView] = useState<"chart" | "table">("chart");
  const [exporting, setExporting] = useState(false);

  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      await recordExport("accounting", format, months.length);
      if (format === "xlsx") await exportXlsx("ingresos-y-gastos-por-mes", "Ingresos y gastos por mes", EXPORT, months);
      else exportCsv("ingresos-y-gastos-por-mes", EXPORT, months);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const toggle = (
    <div role="group" aria-label="Ver como" className="flex rounded-lg border border-[color:var(--app-border)] p-0.5 text-[12px]">
      {([["chart", "Gráfico"], ["table", "Tabla"]] as const).map(([key, label]) => (
        <button
          key={key} type="button" aria-pressed={view === key} onClick={() => setView(key)}
          className={`cursor-pointer rounded-md px-2.5 py-1 font-medium ${view === key ? "bg-[#1B8FC9] text-[#fff]" : "app-muted hover:bg-[var(--app-surface-muted)]"}`}
        >
          {label}
        </button>
      ))}
    </div>
  );

  return (
    <Card title="Ingresos y gastos por mes" actions={toggle} flush>
      {view === "chart" ? (
        <div className="px-5 pb-4 pt-3">
          <div className="app-muted mb-2 flex gap-4 text-[12.5px]">
            {SERIES.map((series) => (
              <span key={series.key}><i className="mr-1.5 inline-block h-2.5 w-2.5 rounded-[3px] align-[-1px]" style={{ background: series.color }} />{series.label}</span>
            ))}
          </div>
          <div className="h-[240px] w-full" role="img" aria-label="Ingresos y gastos por mes">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={months} margin={{ top: 8, right: 8, bottom: 0, left: 0 }} barGap={2} barCategoryGap="28%">
                <CartesianGrid vertical={false} stroke="var(--app-border)" strokeOpacity={0.7} />
                <XAxis dataKey="period" tickFormatter={monthTick} tickLine={false} axisLine={{ stroke: "var(--app-border)" }} tick={{ fill: "var(--app-muted)", fontSize: 11 }} interval="preserveStartEnd" />
                <YAxis width={64} tickFormatter={(value: number) => kfmt(value)} tickLine={false} axisLine={false} tick={{ fill: "var(--app-muted)", fontSize: 11 }} allowDecimals={false} />
                <Tooltip content={(props) => <MonthTooltip active={props.active} payload={props.payload} />} cursor={{ fill: "var(--app-surface-muted)", opacity: 0.6 }} />
                {SERIES.map((series) => (
                  <Bar key={series.key} dataKey={series.key} name={series.label} fill={series.color} radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false} />
                ))}
              </BarChart>
            </ResponsiveContainer>
          </div>
        </div>
      ) : (
        <>
          <div className="overflow-x-auto px-1 pt-1">
            <table className="proto-tbl w-full text-[13px]">
              <thead>
                <tr>
                  {["Mes", "Ingresos", "Gastos", "Resultado"].map((label, index) => (
                    <th key={label} className={`app-muted whitespace-nowrap border-b border-[color:var(--app-border)] px-3.5 pb-2.5 pt-3 text-[10.5px] font-semibold uppercase tracking-[.06em] ${index ? "text-right" : "text-left"}`}>{label}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {months.map((month) => (
                  <tr key={month.period}>
                    <td className="border-b border-[color:var(--app-border)] px-3.5 py-2 capitalize" data-label="Mes">{monthName(month.period)}</td>
                    <td className="border-b border-[color:var(--app-border)] px-3.5 py-2 text-right tabular-nums" data-label="Ingresos">{money(month.income)}</td>
                    <td className="border-b border-[color:var(--app-border)] px-3.5 py-2 text-right tabular-nums" data-label="Gastos">{money(month.expenses)}</td>
                    <td className="border-b border-[color:var(--app-border)] px-3.5 py-2 text-right font-semibold tabular-nums" data-label="Resultado">{signedMoney(month.result)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="border-t border-[color:var(--app-border)] px-3.5 py-3">
            <ExportButtons permission="accounting.export" total={months.length} busy={exporting} onExport={runExport} />
          </div>
        </>
      )}
    </Card>
  );
}
