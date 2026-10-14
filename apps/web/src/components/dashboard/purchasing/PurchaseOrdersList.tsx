"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Clock, Coins, Package, Plus, ReceiptText } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, setFlash, shortDate, stateOf } from "@/lib/commercial";
import { PURCHASE_ORDER_STATES, PURCHASE_ORDER_TABS, kMoney, pctText } from "@/lib/purchasing";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Tabs, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { PurchaseOrderFormModal } from "@/components/dashboard/purchasing/PurchaseModals";
import type { PaginatedResponse } from "@/types/customer";
import type { PurchaseOptions, PurchaseOrder, PurchaseOrderSummary, PurchaseOrderTab } from "@/types/purchasing";

const orderKey = (order: PurchaseOrder) => order.id;

/** Como `Who`, pero el nombre baja de línea: así la tabla cabe en una pantalla de portátil. */
function Requester({ name }: { name: string | null }) {
  if (!name) return <span className="app-muted">—</span>;
  const initials = name.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
  return (
    <span className="flex min-w-[120px] items-center gap-1.5 whitespace-normal">
      <span aria-hidden="true" className="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full bg-[var(--p-brand-soft)] text-[9.5px] font-semibold text-[color:var(--p-brand-soft-text)]">
        {initials}
      </span>
      <span>{name}</span>
    </span>
  );
}

/** Barra de lo recibido de una orden: azul mientras falta, verde al completarse. */
export function ReceptionBar({ percent }: { percent: number }) {
  const value = Math.max(0, Math.min(100, percent));
  return (
    <div className="h-1.5 min-w-[120px] overflow-hidden rounded-full bg-[var(--app-surface-muted)]" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100} aria-label="Recibido">
      <div className={`h-full rounded-full ${value >= 100 ? "bg-[var(--p-success)]" : "bg-[#1B8FC9]"}`} style={{ width: `${value}%` }} />
    </div>
  );
}

const EXPORT_COLUMNS: ExportColumn<PurchaseOrder>[] = [
  { label: "Orden", value: (order) => order.order_number, width: 18, kind: "center" },
  { label: "Descripción", value: (order) => `${order.first_item ?? ""}${order.items_count > 1 ? ` +${order.items_count - 1}` : ""}`, width: 40 },
  { label: "Proveedor", value: (order) => order.supplier.name ?? "", width: 34 },
  { label: "Centro de costo", value: (order) => order.cost_center.name ?? "", width: 28 },
  { label: "Requerida para", value: (order) => (order.needed_by ? shortDate(order.needed_by) : ""), width: 15, kind: "center" },
  { label: "Moneda", value: (order) => order.currency, width: 10, kind: "center" },
  { label: "Subtotal", value: (order) => order.subtotal, width: 14, kind: "money", currency: (order) => order.currency },
  { label: "IGV", value: (order) => order.tax, width: 13, kind: "money", currency: (order) => order.currency },
  { label: "Total", value: (order) => order.total, width: 14, kind: "money", currency: (order) => order.currency },
  { label: "Recibido %", value: (order) => order.received_pct, width: 12, kind: "number" },
  { label: "Facturado", value: (order) => order.invoiced, width: 14, kind: "money", currency: (order) => order.currency },
  { label: "Solicitante", value: (order) => order.creator ?? "", width: 24 },
  {
    label: "Estado",
    value: (order) => stateOf(PURCHASE_ORDER_STATES, order.status)[0],
    width: 18,
    kind: "status",
    tone: (order) => excelTone(stateOf(PURCHASE_ORDER_STATES, order.status)[1]),
  },
];

type TopFilters = { search: string; tab: PurchaseOrderTab; costCenter: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.tab !== "ALL") params.set("tab", top.tab);
  if (top.costCenter) params.set("cost_center_id", top.costCenter);
  return params;
}

const COLUMNS: Column<PurchaseOrder>[] = [
  {
    label: "Orden", sortKey: "order", filter: "text",
    render: (order) => (
      <div className="min-w-[130px]">
        <strong className="whitespace-nowrap font-semibold tabular-nums">{order.order_number}</strong>
        <small className="app-muted block whitespace-normal text-xs">{order.first_item ?? ""}{order.items_count > 1 ? ` +${order.items_count - 1}` : ""}</small>
      </div>
    ),
  },
  { label: "Proveedor", sortKey: "supplier", filter: "text", render: (order) => order.supplier.name ?? "—" },
  { label: "Centro de costo", sortKey: "cost_center", filter: { top: "Centro de costo" }, render: (order) => <span className="block min-w-[110px] whitespace-normal text-[12.5px]">{order.cost_center.name ?? "—"}</span> },
  { label: "Total", num: true, sortKey: "total", filter: "number", render: (order) => <span className="whitespace-nowrap">{money(order.total, order.currency)}</span> },
  {
    label: "Recepción / facturado", sortKey: "received", filter: "number",
    render: (order) => (
      <div className="grid gap-1">
        <ReceptionBar percent={order.received_pct} />
        <small className="app-muted whitespace-normal text-xs">{pctText(order.received_pct)} % recibido · {money(order.invoiced, order.currency)} facturado</small>
      </div>
    ),
  },
  { label: "Solicitante", sortKey: "creator", filter: "text", render: (order) => <Requester name={order.creator} /> },
  {
    label: "Estado", sortKey: "status",
    render: (order) => <Badge tone={stateOf(PURCHASE_ORDER_STATES, order.status)[1]}>{stateOf(PURCHASE_ORDER_STATES, order.status)[0]}</Badge>,
  },
];

export default function PurchaseOrdersList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [tab, setTab] = useState<PurchaseOrderTab>("ALL");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [costCenter, setCostCenter] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(orderKey);

  const [data, setData] = useState<PaginatedResponse<PurchaseOrder> | null>(null);
  const [summary, setSummary] = useState<PurchaseOrderSummary | null>(null);
  const [centers, setCenters] = useState<PurchaseOptions["cost_centers"]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, tab, costCenter });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<PurchaseOrder>>(`/v1/purchase-orders?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las órdenes de compra.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, tab, costCenter, columnFilters.param, sort, reload]);

  // Indicadores y conteos de las pestañas: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<PurchaseOrderSummary>("/v1/purchase-orders/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  // La lista del filtro por centro de costo: una vez, después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<PurchaseOptions>("/v1/purchase-orders/options")
      .then((next) => { if (!cancelled) setCenters(next.cost_centers); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: PurchaseOrder[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<PurchaseOrder>("/v1/purchase-orders", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, tab, costCenter })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("purchases", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("ordenes-de-compra", "Órdenes de compra", EXPORT_COLUMNS, rows);
      else exportCsv("ordenes-de-compra", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead
        title="Órdenes de compra"
        actions={
          <Btn onClick={() => setCreating(true)} permission="purchases.create">
            <Plus size={16} />
            <span>Nueva orden de compra</span>
          </Btn>
        }
      />

      <Kpis>
        <Kpi label="Por aprobar" value={summary?.to_approve ?? "—"} sub={summary ? kMoney(summary.to_approve_amount) : undefined} icon={<Clock size={22} />} tone="warning" />
        <Kpi label="Por recibir" value={summary?.to_receive ?? "—"} icon={<Package size={22} />} />
        <Kpi label="Recibidas sin facturar" value={summary?.received_uninvoiced ?? "—"} icon={<ReceiptText size={22} />} />
        <Kpi label="Comprometido (aprobado)" value={summary ? kMoney(summary.committed) : "—"} icon={<Coins size={22} />} tone="success" />
      </Kpis>
      {summary?.missing_rate && <Notice tone="warn">Hay órdenes en dólares y ningún tipo de cambio registrado: no entran en estos importes.</Notice>}

      <Tabs
        tabs={PURCHASE_ORDER_TABS.map(([key, label]) => [key, label, summary ? summary.tabs[key] : null])}
        value={tab}
        onChange={(value) => {
          setTab(value as PurchaseOrderTab);
          setPage(1);
        }}
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar OC, proveedor o descripción" />
        <FilterSelect
          label="Centro de costo"
          value={costCenter}
          onChange={(value) => {
            setCostCenter(value);
            setPage(1);
          }}
          options={centers.map((center): [string, string] => [center.id, center.code])}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || costCenter)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setCostCenter("");
            setPage(1);
          }}
        />
        <ScopeNote resource="purchases" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="purchases.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="purchase-orders" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={data?.data ?? []}
            rowKey={orderKey}
            selection={selection}
            onRowClick={(order) => router.push(`/purchase-orders/${order.id}`)}
            loading={loading && !data}
            empty="No hay registros con estos filtros."
          />
          {data && (
            <Pager
              loading={loading}
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={(size) => {
                setPerPage(size);
                setPage(1);
              }}
              left={<ExportButtons permission="purchases.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <PurchaseOrderFormModal
          onClose={() => setCreating(false)}
          onSaved={(order, submitted) => {
            // Como en el prototipo: guardada, se abre su ficha.
            setFlash(submitted ? "Orden de compra guardada y enviada a aprobación" : "Orden de compra guardada");
            router.push(`/purchase-orders/${order.id}`);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
