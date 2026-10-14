"use client";

import { useEffect, useMemo, useState } from "react";
import { ChartColumn, Coins, Percent, TrendingUp } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { kfmt, monthName, periodChoices, signedMoney } from "@/lib/accounting";
import { Card, Filters, INPUT, Kpi, Kpis, LoadError, PageHead, Screen, useToasts } from "@/components/ui/proto";
import { MonthChart } from "@/components/dashboard/accounting/MonthChart";
import { PickSelect } from "@/components/ui/pick-select";
import type { FinancialStatements, JournalOptions, StatementLine } from "@/types/accounting";

function Pick({ label, value, onChange, options }: { label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
  return (
    <div className="flex-[1_1_140px] sm:w-[230px] sm:flex-none">
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

export default function Statements() {
  const { toast, toasts } = useToasts();
  const [options, setOptions] = useState<JournalOptions | null>(null);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  // Rango con que abrió la pantalla (enero del año hasta el mes en curso): fija los extremos de la lista.
  const [bounds, setBounds] = useState<[string, string] | null>(null);
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
        setBounds((current) => current ?? [next.from, next.to]);
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

  const periods = useMemo(() => periodChoices([...(options?.periods ?? []), ...(bounds ?? [])]), [options, bounds]);

  return (
    <Screen>
      <PageHead title="Estados financieros" />

      <Filters>
        {/* "Desde" no pasa de "Hasta" ni "Hasta" queda antes de "Desde". */}
        <Pick label="Desde" value={from} onChange={setFrom} options={periods.filter((p) => !to || p <= to).map((p): [string, string] => [p, `Desde ${monthName(p)}`])} />
        <Pick label="Hasta" value={to} onChange={setTo} options={[...periods].reverse().filter((p) => !from || p >= from).map((p): [string, string] => [p, `Hasta ${monthName(p)}`])} />
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

          <MonthChart months={data.months} toast={toast} />
        </div>
      )}
      {toasts}
    </Screen>
  );
}
