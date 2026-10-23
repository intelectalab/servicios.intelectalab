"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch } from "@/lib/api";
import { money, shortDate } from "@/lib/commercial";
import { count, PERIODS, subscriptionState } from "@/lib/platform";
import type { ExportColumn } from "@/lib/export-table";
import { Badge, Card, Filters, FilterSelect, LoadError, PageHead, Screen, SearchInput, useToasts } from "@/components/ui/proto";
import { ClientTable, type ClientColumn } from "@/components/superadmin/ClientTable";
import type { PlatformSubscriptionRow } from "@/types/platform";

const plain = (text: string) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
const STATES = ["Vigente", "En prueba", "Prueba vencida", "Vencida", "Reemplazada"];

const COLS: ClientColumn<PlatformSubscriptionRow>[] = [
  { label: "Empresa", sortKey: "tenant", sortValue: (s) => s.tenant_name, render: (s) => <span><strong className="font-medium">{s.tenant_name}</strong><small className="app-muted block">{s.tenant_code}</small></span> },
  { label: "Plan", sortKey: "plan", sortValue: (s) => s.plan, render: (s) => s.plan },
  { label: "Periodo", sortKey: "period", sortValue: (s) => s.billing_period, render: (s) => PERIODS[s.billing_period] ?? s.billing_period },
  { label: "Precio", num: true, sortKey: "price", sortValue: (s) => Number(s.price ?? 0), render: (s) => <span className="whitespace-nowrap">{money(s.price, s.currency)}</span> },
  { label: "Inicio", sortKey: "starts_at", sortValue: (s) => s.starts_at, render: (s) => <span className="whitespace-nowrap">{shortDate(s.starts_at)}</span> },
  { label: "Fin", sortKey: "ends_at", sortValue: (s) => s.ends_at ?? "", render: (s) => <span className="whitespace-nowrap">{shortDate(s.ends_at)}</span> },
  { label: "Estado", sortKey: "state", sortValue: (s) => subscriptionState(s)[0], render: (s) => { const [label, tone] = subscriptionState(s); return <Badge tone={tone}>{label}</Badge>; } },
];

const EXPORT: ExportColumn<PlatformSubscriptionRow>[] = [
  { label: "Empresa", value: (s) => s.tenant_name, width: 28 },
  { label: "Código", value: (s) => s.tenant_code, width: 14 },
  { label: "Plan", value: (s) => s.plan, width: 20 },
  { label: "Periodo", value: (s) => PERIODS[s.billing_period] ?? s.billing_period, width: 12 },
  { label: "Precio", value: (s) => Number(s.price ?? 0), kind: "money", currency: (s) => s.currency, width: 14 },
  { label: "Inicio", value: (s) => shortDate(s.starts_at), kind: "center", width: 14 },
  { label: "Fin", value: (s) => shortDate(s.ends_at), kind: "center", width: 14 },
  {
    label: "Estado", value: (s) => subscriptionState(s)[0], kind: "status", width: 16,
    tone: (s) => { const tone = subscriptionState(s)[1]; return tone === "success" ? "success" : tone === "danger" ? "danger" : tone === "info" ? "info" : "neutral"; },
  },
];

export function SubscriptionsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [rows, setRows] = useState<PlatformSubscriptionRow[] | null>(null);
  const [error, setError] = useState(false);
  const [search, setSearch] = useState("");
  const [state, setState] = useState("");
  const [plan, setPlan] = useState("");
  const [period, setPeriod] = useState("");

  const load = useCallback(async () => {
    setError(false);
    try {
      setRows((await apiFetch<{ data: PlatformSubscriptionRow[] }>("/v1/superadmin/subscriptions")).data);
    } catch {
      setError(true);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- primera carga del listado
    void load();
  }, [load]);

  const plans = useMemo(() => [...new Set((rows ?? []).map((s) => s.plan))].sort(), [rows]);
  const filtered = useMemo(() => {
    const term = plain(search.trim());
    return (rows ?? []).filter((s) =>
      (!state || subscriptionState(s)[0] === state) && (!plan || s.plan === plan) && (!period || s.billing_period === period)
      && (!term || plain(`${s.tenant_name} ${s.tenant_code} ${s.plan}`).includes(term)));
  }, [rows, search, state, plan, period]);

  return (
    <Screen>
      <PageHead title="Suscripciones" />
      <Filters>
        <SearchInput value={search} onChange={setSearch} placeholder="Buscar por empresa, código o plan" />
        <FilterSelect label="Estado" value={state} onChange={setState} options={STATES.map((s) => [s, s])} />
        <FilterSelect label="Plan" value={plan} onChange={setPlan} options={plans.map((p) => [p, p])} />
        <FilterSelect label="Periodo" value={period} onChange={setPeriod} options={Object.entries(PERIODS)} />
        {rows && <span className="app-muted ml-auto text-[12.5px]">{count(filtered.length)} de {count(rows.length)}</span>}
      </Filters>
      <Card flush>
        {error ? (
          <LoadError text="No se pudieron cargar las suscripciones." onRetry={() => void load()} />
        ) : (
          <ClientTable
            table="subscriptions" title="Suscripciones" file="suscripciones" cols={COLS} rows={filtered} rowKey={(s) => s.id} exportCols={EXPORT}
            empty="No hay suscripciones con estos filtros." toast={toast} loading={!rows}
            onRowClick={(s) => router.push(`/superadmin/tenants/${s.tenant_id}`)}
          />
        )}
      </Card>
      {toasts}
    </Screen>
  );
}
