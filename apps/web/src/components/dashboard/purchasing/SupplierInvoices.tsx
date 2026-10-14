"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { SUPPLIER_INVOICE_STATES, SUPPLIER_INVOICE_TABS, docTypeLabel, pctText } from "@/lib/purchasing";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Tabs, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { SupplierInvoiceFormModal, SupplierInvoiceModal } from "@/components/dashboard/purchasing/PurchaseModals";
import type { PaginatedResponse } from "@/types/customer";
import type { CostCenter, SupplierInvoice, SupplierInvoiceOptions, SupplierInvoiceTab } from "@/types/purchasing";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

const invoiceKey = (invoice: SupplierInvoice) => invoice.id;

const EXPORT_COLUMNS: ExportColumn<SupplierInvoice>[] = [
  { label: "Comprobante", value: (invoice) => invoice.doc_number, width: 18, kind: "center" },
  { label: "Tipo", value: (invoice) => docTypeLabel(invoice.doc_type), width: 22 },
  { label: "Concepto", value: (invoice) => invoice.description ?? "", width: 36 },
  { label: "Proveedor", value: (invoice) => invoice.supplier.name, width: 34 },
  { label: "RUC / documento", value: (invoice) => invoice.supplier.tax_id ?? "", width: 18, kind: "center" },
  { label: "Emisión", value: (invoice) => shortDate(invoice.issue_date), width: 15, kind: "center" },
  { label: "Vencimiento", value: (invoice) => shortDate(invoice.due_date), width: 15, kind: "center" },
  { label: "Cuenta de gasto", value: (invoice) => invoice.expense_account_label, width: 36 },
  { label: "Centro de costo", value: (invoice) => invoice.cost_center.name ?? "", width: 28 },
  { label: "Moneda", value: (invoice) => invoice.currency, width: 10, kind: "center" },
  { label: "Valor de venta", value: (invoice) => invoice.subtotal, width: 14, kind: "money", currency: (invoice) => invoice.currency },
  { label: "IGV", value: (invoice) => invoice.tax, width: 13, kind: "money", currency: (invoice) => invoice.currency },
  { label: "Total", value: (invoice) => invoice.total, width: 14, kind: "money", currency: (invoice) => invoice.currency },
  { label: "Detracción (S/)", value: (invoice) => invoice.detraction?.amount ?? "", width: 16, kind: "number" },
  { label: "Retención 4.ª", value: (invoice) => invoice.retention_4ta || "", width: 14, kind: "number" },
  { label: "OC", value: (invoice) => invoice.purchase_order?.order_number ?? "", width: 18, kind: "center" },
  {
    label: "Estado",
    value: (invoice) => stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[0],
    width: 20,
    kind: "status",
    tone: (invoice) => excelTone(stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[1]),
  },
];

type TopFilters = { search: string; tab: SupplierInvoiceTab; costCenter: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.tab !== "ALL") params.set("tab", top.tab);
  if (top.costCenter) params.set("cost_center_id", top.costCenter);
  return params;
}

export default function SupplierInvoices() {
  const { can } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [tab, setTab] = useState<SupplierInvoiceTab>("ALL");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [costCenter, setCostCenter] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(invoiceKey);

  const [data, setData] = useState<PaginatedResponse<SupplierInvoice> | null>(null);
  const [tabs, setTabs] = useState<Record<SupplierInvoiceTab, number> | null>(null);
  const [centers, setCenters] = useState<CostCenter[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);

  const seesOrders = can("purchases.view");

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
        const response = await apiFetch<PaginatedResponse<SupplierInvoice>>(`/v1/supplier-invoices?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los comprobantes de proveedor.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, tab, costCenter, columnFilters.param, sort, reload]);

  // Los conteos de las pestañas: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<{ tabs: Record<SupplierInvoiceTab, number> }>("/v1/supplier-invoices/summary")
      .then((next) => { if (!cancelled) setTabs(next.tabs); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  // La lista del filtro por centro de costo: una vez, después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<SupplierInvoiceOptions>("/v1/supplier-invoices/options")
      .then((next) => { if (!cancelled) setCenters(next.cost_centers); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  const columns: Column<SupplierInvoice>[] = [
    {
      label: "Comprobante", sortKey: "document", filter: "text",
      render: (invoice) => (
        <div className="min-w-[120px]">
          <strong className="whitespace-nowrap font-semibold tabular-nums">{invoice.doc_number}</strong>
          <small className="app-muted block whitespace-normal text-xs">{docTypeLabel(invoice.doc_type)}{invoice.description ? ` · ${invoice.description}` : ""}</small>
        </div>
      ),
    },
    { label: "Proveedor", sortKey: "supplier", filter: "text", render: (invoice) => invoice.supplier.name },
    {
      label: "Emisión / vence", sortKey: "issue", filter: "date",
      render: (invoice) => (
        <div>
          <span className="whitespace-nowrap">{shortDate(invoice.issue_date)}</span>
          <small className="app-muted block whitespace-normal text-xs">vence {shortDate(invoice.due_date)}</small>
        </div>
      ),
    },
    {
      label: "Gasto", sortKey: "expense", filter: "text",
      render: (invoice) => (
        <small className="block min-w-[110px] whitespace-normal text-xs">
          {invoice.expense_account_label}
          <span className="app-muted block">{invoice.cost_center.name ?? "—"}</span>
        </small>
      ),
    },
    {
      label: "Total", num: true, sortKey: "total", filter: "number",
      render: (invoice) => (
        <div className="min-w-[96px]">
          <span className="whitespace-nowrap">{money(invoice.total, invoice.currency)}</span>
          {invoice.detraction && <small className="app-muted block whitespace-normal text-xs">detracción {pctText(invoice.detraction.pct)} %: {money(invoice.detraction.amount)}</small>}
          {invoice.retention_4ta > 0 && <small className="app-muted block whitespace-normal text-xs">retención 4.ª: {money(invoice.retention_4ta, invoice.currency)}</small>}
        </div>
      ),
    },
    {
      label: "OC", sortKey: "order", filter: "text",
      render: (invoice) =>
        !invoice.purchase_order ? <span className="app-muted">—</span>
          : seesOrders ? (
            <Link href={`/purchase-orders/${invoice.purchase_order.id}`} className={`${LINK} whitespace-nowrap tabular-nums`} onClick={(event) => event.stopPropagation()}>
              {invoice.purchase_order.order_number ?? "—"}
            </Link>
          ) : <span className="whitespace-nowrap tabular-nums">{invoice.purchase_order.order_number ?? "—"}</span>,
    },
    {
      label: "Estado", sortKey: "status",
      render: (invoice) => <Badge tone={stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[1]}>{stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[0]}</Badge>,
    },
  ];

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: SupplierInvoice[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<SupplierInvoice>("/v1/supplier-invoices", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, tab, costCenter })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("payables", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("comprobantes-de-proveedor", "Comprobantes de proveedor", EXPORT_COLUMNS, rows);
      else exportCsv("comprobantes-de-proveedor", EXPORT_COLUMNS, rows);
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
        title="Comprobantes de proveedor"
        actions={
          <Btn onClick={() => setCreating(true)} permission="payables.create">
            <Plus size={16} />
            <span>Registrar comprobante</span>
          </Btn>
        }
      />

      <Tabs
        tabs={SUPPLIER_INVOICE_TABS.map(([key, label]) => [key, label, tabs ? tabs[key] : null])}
        value={tab}
        onChange={(value) => {
          setTab(value as SupplierInvoiceTab);
          setPage(1);
        }}
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar comprobante, proveedor o concepto" />
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
        <ScopeNote resource="payables" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="payables.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="supplier-invoices" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={data?.data ?? []}
            rowKey={invoiceKey}
            selection={selection}
            onRowClick={(invoice) => setOpenId(invoice.id)}
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
              left={<ExportButtons permission="payables.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <SupplierInvoiceFormModal
          onClose={() => setCreating(false)}
          onSaved={(invoice) => {
            setCreating(false);
            toast("Comprobante registrado: pendiente de aprobación", "success", invoice.doc_number);
            setReload((n) => n + 1);
            // Como en el prototipo: registrado, se abre su ficha.
            setOpenId(invoice.id);
          }}
        />
      )}
      {openId && <SupplierInvoiceModal id={openId} toast={toast} onClose={() => setOpenId(null)} onChanged={() => setReload((n) => n + 1)} />}
      {toasts}
    </Screen>
  );
}
