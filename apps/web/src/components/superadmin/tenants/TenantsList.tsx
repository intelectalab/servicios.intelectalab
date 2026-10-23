"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { shortDate } from "@/lib/commercial";
import { count, limitText, tenantStatus, TENANT_STATUS } from "@/lib/platform";
import type { ExportColumn } from "@/lib/export-table";
import { Badge, Card, Chip, Filters, FilterSelect, LinkBtn, LoadError, PageHead, Screen, SearchInput, useToasts } from "@/components/ui/proto";
import { ClientTable, type ClientColumn } from "@/components/superadmin/ClientTable";
import type { PlatformTenant } from "@/types/platform";

const plain = (text: string) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

const COLS: ClientColumn<PlatformTenant>[] = [
  {
    label: "Tenant", sortKey: "name", sortValue: (t) => t.name,
    render: (t) => (
      <span>
        <strong className="font-medium">{t.name}</strong>
        <small className="app-muted block">{[t.legal_name, t.ruc && `RUC ${t.ruc}`].filter(Boolean).join(" · ") || t.code}</small>
      </span>
    ),
  },
  {
    label: "Plan", sortKey: "plan", sortValue: (t) => t.subscription?.plan ?? "",
    render: (t) => t.subscription
      ? <span className="whitespace-nowrap">{t.subscription.plan}{t.subscription.trial && <span className="ml-1.5"><Chip tone="info">Prueba</Chip></span>}</span>
      : <span className="app-muted">Sin suscripción</span>,
  },
  {
    label: "Módulos", sortKey: "modules", sortValue: (t) => t.modules.length,
    render: (t) => <span title={t.modules.join(", ")}><Chip tone="success">{t.modules.length} ON</Chip></span>,
  },
  { label: "Usuarios", num: true, sortKey: "users", sortValue: (t) => t.users, render: (t) => limitText(t.users, t.users_limit) },
  { label: "Alta", sortKey: "created_at", sortValue: (t) => t.created_at, render: (t) => <span className="whitespace-nowrap">{shortDate(t.created_at)}</span> },
  {
    label: "Estado", sortKey: "status", sortValue: (t) => tenantStatus(t.status)[0],
    render: (t) => { const [label, tone] = tenantStatus(t.status); return <Badge tone={tone}>{label}</Badge>; },
  },
];

const EXPORT: ExportColumn<PlatformTenant>[] = [
  { label: "Tenant", value: (t) => t.name, width: 28 },
  { label: "Código", value: (t) => t.code, width: 14 },
  { label: "Razón social", value: (t) => t.legal_name ?? "", width: 30 },
  { label: "RUC", value: (t) => t.ruc ?? "", kind: "center", width: 14 },
  { label: "Plan", value: (t) => (t.subscription ? `${t.subscription.plan}${t.subscription.trial ? " (prueba)" : ""}` : "Sin suscripción"), width: 22 },
  { label: "Módulos", value: (t) => t.modules.join(", "), width: 40 },
  { label: "Usuarios", value: (t) => limitText(t.users, t.users_limit), kind: "center", width: 12 },
  { label: "Alta", value: (t) => shortDate(t.created_at), kind: "center", width: 14 },
  { label: "Estado", value: (t) => tenantStatus(t.status)[0], kind: "status", tone: (t) => (t.status === "ACTIVE" ? "success" : t.status === "CLOSED" ? "neutral" : "warning"), width: 16 },
];

export function TenantsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [rows, setRows] = useState<PlatformTenant[] | null>(null);
  const [error, setError] = useState(false);
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [plan, setPlan] = useState("");

  const load = useCallback(async () => {
    setError(false);
    try {
      setRows((await apiFetch<{ data: PlatformTenant[] }>("/v1/superadmin/tenants")).data);
    } catch {
      setError(true);
    }
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- primera carga del listado
    void load();
  }, [load]);

  const plans = useMemo(() => [...new Set((rows ?? []).map((t) => t.subscription?.plan ?? "—"))].sort(), [rows]);

  const filtered = useMemo(() => {
    const term = plain(search.trim());
    return (rows ?? []).filter((t) =>
      (!status || t.status === status)
      && (!plan || (t.subscription?.plan ?? "—") === plan)
      && (!term || plain([t.name, t.legal_name, t.trade_name, t.ruc, t.code].filter(Boolean).join(" ")).includes(term)));
  }, [rows, search, status, plan]);

  return (
    <Screen>
      <PageHead
        title="Tenants"
        actions={<LinkBtn href="/superadmin/tenants/new"><Plus size={16} /><span>Aprovisionar tenant</span></LinkBtn>}
      />
      <Filters>
        <SearchInput value={search} onChange={setSearch} placeholder="Buscar por nombre, razón social, RUC o código" />
        <FilterSelect label="Estado" value={status} onChange={setStatus} options={Object.entries(TENANT_STATUS).map(([key, [label]]) => [key, label])} />
        <FilterSelect label="Plan" value={plan} onChange={setPlan} options={plans.map((name) => [name, name === "—" ? "Sin suscripción" : name])} />
        {rows && <span className="app-muted ml-auto text-[12.5px]">{count(filtered.length)} de {count(rows.length)}</span>}
      </Filters>
      <Card flush>
        {error ? (
          <LoadError text="No se pudo cargar la lista de empresas." onRetry={() => void load()} />
        ) : (
          <ClientTable
            table="tenants" title="Tenants" file="tenants" cols={COLS} rows={filtered} rowKey={(t) => t.id} exportCols={EXPORT}
            empty="No hay empresas con estos filtros." toast={toast} loading={!rows}
            onRowClick={(t) => router.push(`/superadmin/tenants/${t.id}`)}
          />
        )}
      </Card>
      {toasts}
    </Screen>
  );
}
