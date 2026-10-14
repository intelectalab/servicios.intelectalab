"use client";

import { useEffect, useMemo, useState } from "react";
import { ChartColumn, Coins, Percent, TrendingUp } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { money } from "@/lib/commercial";
import { kfmt, monthTick, signedMoney } from "@/lib/accounting";
import { Card, Filters, INPUT, Kpi, Kpis, LoadError, PageHead, Screen } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { FinancialStatements, JournalOptions, StatementLine } from "@/types/accounting";

function Pick({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
  return (
    <div className="flex-[1_1_140px] sm:w-[170px] sm:flex-none">
      <PickSelect className={`${INPUT} h-10`} ariaLabel={label} searchable={options.length > 10} value={value} onChange={onChange} options={options} />
    </div>
  );
}

function FinList({ lines }: { lines: StatementLine[] }) {
  return (
    <ul>
      {lines.map((line) => (
        <li key={line.label} className="flex justify-between gap-3 border-b border-[color:color-mix(in_srgb,var(--app-border)_70%,transparent)] py-2 text-[14px] last:border-b-0">
          <span className={line.total ? "font-semibold" : ""}>{line.label}</span>
          <span className={`whitespace-nowrap tabular-nums ${line.total ? "font-semibold" : ""}`}>{signedMoney(line.value)}</span>
        </li>
      ))}
    </ul>
  );
}

/** Barras de ingresos y gastos por mes, como el del prototipo (azul del logo e ingresos; negro cálido y gastos). */
function MonthBars({ months }: { months: FinancialStatements["months"] }) {
  const h = 170;
  const max = Math.max(1, ...months.flatMap((m) => [m.income, m.expenses]));
  const step = Math.pow(10, Math.floor(Math.log10(max)));
  const nice = Math.ceil(max / step) * step;
  const W = Math.max(600, months.length * 110);
  const padL = 60;
  const padB = 24;
  const iw = W - padL - 10;
  const ih = h - padB - 12;
  const gw = iw / Math.max(1, months.length);
  const bw = Math.min(26, gw * 0.3);
  const y = (v: number) => 12 + ih - (v / nice) * ih;

  return (
    <div className="overflow-x-auto">
      <svg viewBox={`0 0 ${W} ${h}`} role="img" aria-label="Ingresos y gastos por mes" className="block h-auto w-full min-w-[280px]">
        {[0, 0.5, 1].map((t) => (
          <g key={t}>
            <line x1={padL} x2={W - 10} y1={y(t * nice)} y2={y(t * nice)} stroke="var(--app-border)" strokeWidth={1} />
            <text x={padL - 6} y={y(t * nice) + 3} textAnchor="end" fontSize="10.5" fill="var(--app-muted)">{kfmt(t * nice)}</text>
          </g>
        ))}
        {months.map((month, index) => {
          const cx = padL + gw * (index + 0.5);
          const income = Math.max(0, month.income);
          const expenses = Math.max(0, month.expenses);
          return (
            <g key={month.period}>
              <rect x={cx - bw - 2} y={y(income)} width={bw} height={Math.max(0, ih + 12 - y(income))} rx={3} fill="var(--p-brand)">
                <title>Ingresos {monthTick(month.period)}: {money(month.income)}</title>
              </rect>
              <rect x={cx + 2} y={y(expenses)} width={bw} height={Math.max(0, ih + 12 - y(expenses))} rx={3} fill="var(--p-neutral-text)">
                <title>Gastos {monthTick(month.period)}: {money(month.expenses)}</title>
              </rect>
              <text x={cx} y={h - 6} textAnchor="middle" fontSize="10.5" fill="var(--app-muted)">{monthTick(month.period)}</text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

export default function Statements() {
  const [options, setOptions] = useState<JournalOptions | null>(null);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [data, setData] = useState<FinancialStatements | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = new URLSearchParams();
      if (from) params.set("from", from);
      if (to) params.set("to", to);
      try {
        const next = await apiFetch<FinancialStatements>(`/v1/financial-statements?${params}`);
        if (cancelled) return;
        setData(next);
        setError("");
        setFrom((current) => current || next.from);
        setTo((current) => current || next.to);
      } catch {
        if (!cancelled) setError("No se pudieron calcular los estados financieros.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [from, to, reload]);

  useEffect(() => {
    let cancelled = false;
    apiFetchLater<JournalOptions>("/v1/journal-entries/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  const periods = useMemo(() => [...new Set([...(options?.periods ?? []), from, to].filter(Boolean))].sort(), [options, from, to]);

  return (
    <Screen>
      <PageHead title="Estados financieros" />

      <Filters>
        <Pick label="Desde" value={from} onChange={setFrom} options={periods.map((p): [string, string] => [p, `Desde ${p}`])} />
        <Pick label="Hasta" value={to} onChange={setTo} options={[...periods].reverse().map((p): [string, string] => [p, `Hasta ${p}`])} />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <div className="h-60 animate-pulse rounded-xl bg-[var(--app-surface-muted)]" aria-busy="true" />
      ) : (
        <div className={loading ? "opacity-60 transition-opacity" : "transition-opacity"}>
          <Kpis>
            <Kpi label="Ingresos del rango" value={kfmt(data.kpis.income)} icon={<TrendingUp size={22} />} />
            <Kpi label="Gastos del rango" value={kfmt(data.kpis.expenses)} icon={<Coins size={22} />} />
            <Kpi label="Resultado" value={kfmt(data.kpis.result)} icon={<ChartColumn size={22} />} tone={data.kpis.result >= 0 ? "success" : "danger"} />
            <Kpi label="Margen" value={data.kpis.margin === null ? "—" : `${data.kpis.margin} %`} icon={<Percent size={22} />} />
          </Kpis>

          <div className="grid grid-cols-1 gap-x-4 lg:grid-cols-2">
            <Card title="Estado de resultados">
              <FinList lines={data.income_statement} />
            </Card>
            <Card title={`Estado de situación financiera (al cierre de ${data.to})`}>
              <FinList lines={data.balance_sheet} />
              <p className={`mt-2 text-[12.5px] ${data.balanced ? "text-[color:var(--p-success-text)]" : "text-[color:var(--p-danger-text)]"}`}>
                {data.balanced ? "Activo = Pasivo + Patrimonio" : "Descuadre: revisar asientos"}
              </p>
            </Card>
          </div>

          <Card title="Ingresos y gastos por mes">
            <div className="app-muted mb-1.5 flex gap-4 text-[12.5px]">
              <span><i className="mr-1.5 inline-block h-2.5 w-2.5 rounded-[3px] bg-[var(--p-brand)] align-[-1px]" />Ingresos</span>
              <span><i className="mr-1.5 inline-block h-2.5 w-2.5 rounded-[3px] bg-[var(--p-neutral-text)] align-[-1px]" />Gastos</span>
            </div>
            <MonthBars months={data.months} />
          </Card>
        </div>
      )}
    </Screen>
  );
}
