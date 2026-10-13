"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { FISCAL_STATES, INVOICE_STATES, INVOICE_TYPES, invoiceCustomer, taxIdText, typeLabel } from "@/lib/finance";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Tabs, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";
import type { Invoice, InvoiceSummary, InvoiceTab } from "@/types/finance";

const invoiceKey = (invoice: Invoice) => invoice.id;

const TABS: [InvoiceTab, string][] = [
  ["ALL", "Todos"],
  ["DRAFT", "Borradores"],
  ["ISSUED", "Emitidos"],
  ["REJECTED", "Rechazados SUNAT"],
  ["CORRECTED", "Corregidos"],
];

const EXPORT_COLUMNS: ExportColumn<Invoice>[] = [
  { label: "Comprobante", value: (invoice) => invoice.invoice_number ?? "Borrador", width: 20, kind: "center" },
  { label: "Tipo", value: (invoice) => typeLabel(invoice.invoice_type), width: 18 },
  { label: "Cliente", value: (invoice) => invoiceCustomer(invoice), width: 34 },
  { label: "RUC / documento", value: (invoice) => invoice.customer_tax_id ?? "", width: 18, kind: "center" },
  { label: "Emisión", value: (invoice) => (invoice.issue_date ? shortDate(invoice.issue_date) : ""), width: 15, kind: "center" },
  { label: "Vencimiento", value: (invoice) => (invoice.due_date ? shortDate(invoice.due_date) : ""), width: 15, kind: "center" },
  { label: "Moneda", value: (invoice) => invoice.currency, width: 10, kind: "center" },
  { label: "Op. gravada", value: (invoice) => invoice.subtotal, width: 14, kind: "money", currency: (invoice) => invoice.currency },
  { label: "IGV", value: (invoice) => invoice.tax_total, width: 13, kind: "money", currency: (invoice) => invoice.currency },
  { label: "Total", value: (invoice) => invoice.total, width: 14, kind: "money", currency: (invoice) => invoice.currency },
  { label: "Saldo", value: (invoice) => invoice.balance_due, width: 14, kind: "money", currency: (invoice) => invoice.currency },
  {
    label: "SUNAT",
    value: (invoice) => stateOf(FISCAL_STATES, invoice.fiscal_status)[0],
    width: 15,
    kind: "status",
    tone: (invoice) => excelTone(stateOf(FISCAL_STATES, invoice.fiscal_status)[1]),
  },
  {
    label: "Estado",
    value: (invoice) => stateOf(INVOICE_STATES, invoice.status)[0],
    width: 15,
    kind: "status",
    tone: (invoice) => excelTone(stateOf(INVOICE_STATES, invoice.status)[1]),
  },
];

type TopFilters = { search: string; tab: InvoiceTab; type: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.tab !== "ALL") params.set("tab", top.tab);
  if (top.type) params.set("type", top.type);
  return params;
}

const COLUMNS: Column<Invoice>[] = [
  {
    label: "Comprobante", sortKey: "invoice", filter: "text",
    render: (invoice) => (
      <div className="whitespace-nowrap">
        <strong className="font-semibold tabular-nums">{invoice.invoice_number ?? "Borrador"}</strong>
        <small className="app-muted block text-xs">{typeLabel(invoice.invoice_type)}</small>
      </div>
    ),
  },
  {
    label: "Cliente", sortKey: "customer", filter: "text",
    render: (invoice) => (
      <div className="min-w-[160px]">
        {invoiceCustomer(invoice)}
        <small className="app-muted block text-xs">{taxIdText(invoice.customer_tax_id)}</small>
      </div>
    ),
  },
  { label: "Emisión", sortKey: "issue", filter: "date", render: (invoice) => <span className="whitespace-nowrap">{shortDate(invoice.issue_date)}</span> },
  { label: "Total", num: true, sortKey: "total", filter: "number", render: (invoice) => money(invoice.total, invoice.currency) },
  {
    label: "SUNAT", sortKey: "fiscal",
    render: (invoice) => <Badge tone={stateOf(FISCAL_STATES, invoice.fiscal_status)[1]}>{stateOf(FISCAL_STATES, invoice.fiscal_status)[0]}</Badge>,
  },
  {
    label: "Estado", sortKey: "status",
    render: (invoice) => <Badge tone={stateOf(INVOICE_STATES, invoice.status)[1]}>{stateOf(INVOICE_STATES, invoice.status)[0]}</Badge>,
  },
];

export default function InvoicesList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [tab, setTab] = useState<InvoiceTab>("ALL");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(invoiceKey);

  const [data, setData] = useState<PaginatedResponse<Invoice> | null>(null);
  const [summary, setSummary] = useState<InvoiceSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, tab, type });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Invoice>>(`/v1/invoices?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los comprobantes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, tab, type, columnFilters.param, sort, reload]);

  // Los conteos de las pestañas: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<InvoiceSummary>("/v1/invoices/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Invoice[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Invoice>("/v1/invoices", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, tab, type })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("billing", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("comprobantes", "Comprobantes", EXPORT_COLUMNS, rows);
      else exportCsv("comprobantes", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead title="Comprobantes electrónicos" />

      <Tabs
        tabs={TABS.map(([key, label]) => [key, label, summary ? summary[key] : null])}
        value={tab}
        onChange={(value) => {
          setTab(value as InvoiceTab);
          setPage(1);
        }}
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar número o cliente" />
        <FilterSelect
          label="Tipo"
          value={type}
          onChange={(value) => {
            setType(value);
            setPage(1);
          }}
          options={INVOICE_TYPES}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || type)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setType("");
            setPage(1);
          }}
        />
        <ScopeNote resource="billing" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="billing.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="invoices" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={data?.data ?? []}
            rowKey={invoiceKey}
            selection={selection}
            onRowClick={(invoice) => router.push(`/invoices/${invoice.id}`)}
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
              left={<ExportButtons permission="billing.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}
      {toasts}
    </Screen>
  );
}
