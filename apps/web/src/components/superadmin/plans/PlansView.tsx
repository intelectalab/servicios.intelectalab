"use client";

import { useCallback, useEffect, useMemo, useState, type ReactNode } from "react";
import { LayoutGrid, List, Pencil, Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { money } from "@/lib/commercial";
import { count } from "@/lib/platform";
import type { ExportColumn } from "@/lib/export-table";
import { useStoredView } from "@/components/ui/kanban";
import { Badge, Btn, Card, Chip, Filters, Kpi, Kpis, Kv, LoadError, PageHead, Screen, SearchInput, Tabs, useToasts } from "@/components/ui/proto";
import { ClientTable, type ClientColumn } from "@/components/superadmin/ClientTable";
import { SaasPlanDrawer } from "@/components/superadmin/plans/SaasPlanDrawer";
import type { SaasPlan } from "@/types/saas-plan";
import type { CatalogModule, PlatformDashboard } from "@/types/platform";

const plain = (text: string) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
const limit = (value: number | undefined | null, unit = "") => (value ? `${count(value)}${unit}` : "Sin límite");
const price = (value: SaasPlan["monthly_price"], currency?: string) => (value === null || value === undefined ? "Personalizado" : money(value, currency ?? "PEN"));
const usersOf = (plan: SaasPlan) => plan.limits?.users ?? plan.limits?.max_users;

/** Vista Tarjetas / Tabla, como la de los listados con tablero. */
function CardsSwitch({ value, onChange }: { value: "kanban" | "table"; onChange: (mode: "kanban" | "table") => void }) {
  const options: ["kanban" | "table", string, ReactNode][] = [["kanban", "Tarjetas", <LayoutGrid key="c" size={15} />], ["table", "Tabla", <List key="t" size={15} />]];
  return (
    <div role="group" aria-label="Tipo de vista" className="app-surface inline-flex h-[38px] items-center gap-0.5 rounded-[9px] border border-[color:var(--app-border)] p-[3px]">
      {options.map(([mode, label, icon]) => (
        <button
          key={mode} type="button" aria-pressed={value === mode} onClick={() => onChange(mode)}
          className={`inline-flex h-full cursor-pointer items-center gap-1.5 rounded-[7px] px-2.5 text-[12.5px] font-medium transition-colors ${
            value === mode ? "bg-[#1B8FC9] text-[#fff]" : "app-muted hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
          }`}
        >
          {icon}{label}
        </button>
      ))}
    </div>
  );
}

export function PlansView() {
  const { toast, toasts } = useToasts();
  const [plans, setPlans] = useState<SaasPlan[] | null>(null);
  const [dashboard, setDashboard] = useState<PlatformDashboard | null>(null);
  const [catalog, setCatalog] = useState<CatalogModule[]>([]);
  const [error, setError] = useState(false);
  const [search, setSearch] = useState("");
  const [tab, setTab] = useState("all");
  const [view, setView] = useStoredView("erp.view.saas-plans", "kanban");
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [editing, setEditing] = useState<SaasPlan | null>(null);

  const load = useCallback(async () => {
    setError(false);
    try {
      setPlans(await apiFetch<SaasPlan[]>("/v1/superadmin/plans"));
    } catch {
      setError(true);
    }
    // Indicadores y nombres de módulos: después del listado.
    apiFetch<PlatformDashboard>("/v1/superadmin/dashboard").then(setDashboard).catch(() => undefined);
    apiFetch<CatalogModule[]>("/v1/superadmin/modules").then(setCatalog).catch(() => undefined);
  }, []);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- primera carga de los planes
    void load();
  }, [load]);

  const moduleName = useMemo(() => new Map(catalog.map((m) => [m.code, m.name])), [catalog]);
  const tenantsByPlan = useMemo(() => new Map((dashboard?.plans ?? []).map((p) => [p.id, p.tenants])), [dashboard]);
  const modulesOf = useCallback((plan: SaasPlan) => {
    const codes = Object.entries(plan.modules ?? {}).filter(([, on]) => on).map(([code]) => code.toUpperCase());
    // Solo los del catálogo, en su orden.
    return catalog.length ? catalog.filter((m) => m.is_core || codes.includes(m.code)).map((m) => m.code) : codes;
  }, [catalog]);

  const filtered = useMemo(() => {
    const term = plain(search.trim());
    return (plans ?? []).filter((plan) =>
      (tab === "all" || (tab === "active" ? plan.active : !plan.active))
      && (!term || plain(`${plan.name} ${plan.code} ${plan.description ?? ""}`).includes(term)));
  }, [plans, search, tab]);

  const activeCount = (plans ?? []).filter((p) => p.active).length;
  const open = (plan: SaasPlan | null) => { setEditing(plan); setDrawerOpen(true); };

  const cols: ClientColumn<SaasPlan>[] = [
    { label: "Plan", sortKey: "name", sortValue: (p) => p.name, render: (p) => <span><strong className="font-medium">{p.name}</strong><small className="app-muted block">{p.description || p.code}</small></span> },
    { label: "Precio mensual", num: true, sortKey: "monthly", sortValue: (p) => Number(p.monthly_price ?? 0), render: (p) => <span className="whitespace-nowrap">{price(p.monthly_price, p.currency)}</span> },
    { label: "Precio anual", num: true, sortKey: "annual", sortValue: (p) => Number(p.annual_price ?? 0), render: (p) => <span className="whitespace-nowrap">{price(p.annual_price, p.currency)}</span> },
    { label: "Módulos", num: true, sortKey: "modules", sortValue: (p) => modulesOf(p).length, render: (p) => <span title={modulesOf(p).map((c) => moduleName.get(c) ?? c).join(", ")}>{modulesOf(p).length}</span> },
    { label: "Usuarios", num: true, sortKey: "users", sortValue: (p) => usersOf(p) ?? 0, render: (p) => limit(usersOf(p)) },
    { label: "Prueba", sortKey: "trial", sortValue: (p) => (p.has_trial ? p.trial_days ?? 30 : 0), render: (p) => (p.has_trial ? <Chip tone="info">{p.trial_days || 30} días</Chip> : <span className="app-muted">No</span>) },
    { label: "Empresas", num: true, sortKey: "tenants", sortValue: (p) => tenantsByPlan.get(p.id) ?? 0, render: (p) => count(tenantsByPlan.get(p.id) ?? 0) },
    { label: "Estado", sortKey: "active", sortValue: (p) => (p.active ? 0 : 1), render: (p) => <Badge tone={p.active ? "success" : "muted"}>{p.active ? "Activo" : "Inactivo"}</Badge> },
  ];
  const exportCols: ExportColumn<SaasPlan>[] = [
    { label: "Plan", value: (p) => p.name, width: 24 },
    { label: "Código", value: (p) => p.code, width: 14 },
    { label: "Precio mensual", value: (p) => Number(p.monthly_price ?? 0), kind: "money", currency: (p) => p.currency ?? "PEN", width: 16 },
    { label: "Precio anual", value: (p) => Number(p.annual_price ?? 0), kind: "money", currency: (p) => p.currency ?? "PEN", width: 16 },
    { label: "Módulos", value: (p) => modulesOf(p).map((c) => moduleName.get(c) ?? c).join(", "), width: 50 },
    { label: "Usuarios", value: (p) => limit(usersOf(p)), kind: "center", width: 12 },
    { label: "Prueba", value: (p) => (p.has_trial ? `${p.trial_days || 30} días` : "No"), kind: "center", width: 12 },
    { label: "Empresas", value: (p) => tenantsByPlan.get(p.id) ?? 0, kind: "number", width: 12 },
    { label: "Estado", value: (p) => (p.active ? "Activo" : "Inactivo"), kind: "status", tone: (p) => (p.active ? "success" : "neutral"), width: 12 },
  ];

  const revenue = dashboard?.revenue.length ? dashboard.revenue : [{ currency: "PEN", mrr: 0 }];

  return (
    <Screen>
      <PageHead title="Planes SaaS" actions={<Btn onClick={() => open(null)}><Plus size={16} /><span>Nuevo plan</span></Btn>} />

      <Kpis>
        <Kpi label="Planes activos" value={plans ? count(activeCount) : "—"} sub={plans ? `${count(plans.length - activeCount)} inactivos` : undefined} />
        <Kpi label="Tenants activos" tone="success" value={dashboard ? count(dashboard.tenants.by_status.ACTIVE ?? 0) : "—"} sub={dashboard ? `de ${count(dashboard.tenants.total)} empresas` : undefined} />
        <Kpi label="En prueba" tone="warning" value={dashboard ? count(dashboard.subscriptions.trial) : "—"} sub={dashboard ? `${count(dashboard.subscriptions.trial_expired)} ${dashboard.subscriptions.trial_expired === 1 ? "vencida" : "vencidas"} sin pasar a pago` : undefined} />
        <Kpi label="Ingresos mensuales" tone="success" value={dashboard ? revenue.map((r) => money(r.mrr, r.currency)).join(" · ") : "—"} sub="Sin pruebas · anuales ÷ 12" />
      </Kpis>

      <Tabs
        value={tab} onChange={setTab}
        tabs={[["all", "Todos", plans?.length ?? null], ["active", "Activos", plans ? activeCount : null], ["inactive", "Inactivos", plans ? plans.length - activeCount : null]]}
      />
      <Filters>
        <SearchInput value={search} onChange={setSearch} placeholder="Buscar plan por nombre o código" />
        <span className="ml-auto"><CardsSwitch value={view === "table" ? "table" : "kanban"} onChange={setView} /></span>
      </Filters>

      {error ? (
        <Card><LoadError text="No se pudieron cargar los planes." onRetry={() => void load()} /></Card>
      ) : view === "table" ? (
        <Card flush>
          <ClientTable
            table="plans" title="Planes SaaS" file="planes-saas" cols={cols} rows={filtered} rowKey={(p) => p.id} exportCols={exportCols}
            empty="No hay planes con estos filtros." toast={toast} loading={!plans} onRowClick={open}
          />
        </Card>
      ) : !plans ? (
        <div className="grid gap-[18px] sm:grid-cols-2 lg:grid-cols-3">{[0, 1, 2].map((n) => <div key={n} className="app-surface h-64 animate-pulse rounded-xl" />)}</div>
      ) : filtered.length === 0 ? (
        <Card><p className="app-muted py-6 text-center">No hay planes con estos filtros.</p></Card>
      ) : (
        <div className="grid gap-x-[18px] sm:grid-cols-2 lg:grid-cols-3">
          {filtered.map((plan) => (
            <Card
              key={plan.id}
              title={<span className="flex flex-wrap items-center gap-1.5">{plan.name}<Badge tone={plan.active ? "success" : "muted"}>{plan.active ? "Activo" : "Inactivo"}</Badge>{plan.is_public === false && <Chip tone="muted">Oculto</Chip>}</span>}
              actions={<Btn size="sm" variant="ghost" onClick={() => open(plan)} aria-label={`Editar ${plan.name}`}><Pencil size={14} /><span>Editar</span></Btn>}
            >
              {plan.description && <p className="app-muted mb-2 text-[12.5px]">{plan.description}</p>}
              <div className="[&_dl]:sm:grid-cols-[minmax(132px,46%)_1fr]">
              <Kv rows={[
                ["Precio mensual", price(plan.monthly_price, plan.currency)],
                ["Precio anual", price(plan.annual_price, plan.currency)],
                ["Prueba", plan.has_trial ? `${plan.trial_days || 30} días` : "No"],
                ["Usuarios", limit(usersOf(plan))],
                ["Almacenamiento", limit(plan.consumption?.storage_gb, " GB")],
                ["Clientes", limit(plan.limits?.clients)],
                ["Llamadas API/mes", limit(plan.consumption?.api_requests_month)],
                ["Empresas", count(tenantsByPlan.get(plan.id) ?? 0)],
                ["Módulos", <span key="m" className="flex flex-wrap gap-1">{modulesOf(plan).map((code) => <Chip key={code} tone="neutral">{moduleName.get(code) ?? code}</Chip>)}</span>],
              ]} />
              </div>
            </Card>
          ))}
        </div>
      )}

      <SaasPlanDrawer
        isOpen={drawerOpen}
        onClose={() => { setDrawerOpen(false); setEditing(null); }}
        onSuccess={() => { toast(editing ? "Plan actualizado" : "Plan creado"); void load(); }}
        planToEdit={editing}
      />
      {toasts}
    </Screen>
  );
}
