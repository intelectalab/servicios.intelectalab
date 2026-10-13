"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Check, Clock, FileText, Plus, X } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { QUOTATION_STATES, customerDocument, customerName, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LinkBtn, LoadError, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Tabs, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";
import type { Quotation, QuotationStatus, QuotationSummary } from "@/types/commercial";

const THREE_DAYS = 3 * 864e5;

function expiresSoon(quotation: Quotation) {
  if (quotation.status !== "SUBMITTED" || !quotation.valid_until) return false;
  return new Date(quotation.valid_until).getTime() < Date.now() + THREE_DAYS;
}

/** Origen de la cotización; sin dato es una cotización directa. */
function sourceOf(quotation: Quotation): [string, "accent" | "neutral"] {
  if (quotation.source_type === "OPPORTUNITY") return ["CRM", "accent"];
  if (quotation.source_type === "PORTAL") return ["Portal", "neutral"];
  return ["Directa", "neutral"];
}

const COLUMNS: Column<Quotation>[] = [
  {
    label: "Número", sortKey: "number", filter: "text",
    render: (quotation) => (
      <div className="min-w-[150px]">
        <strong className="font-semibold">{quotation.quotation_number}</strong>
        <small className="app-muted block text-xs">
          Versión {quotation.version}{quotation.superseded_by ? " · reemplazada" : ""}
        </small>
      </div>
    ),
  },
  { label: "Cliente", sortKey: "customer", filter: "text", render: (quotation) => customerName(quotation.customer) },
  {
    label: "Origen", sortKey: "source", filter: { top: "Origen" },
    render: (quotation) => {
      const [label, tone] = sourceOf(quotation);
      return <Chip tone={tone}>{label}</Chip>;
    },
  },
  {
    label: "Válida hasta", sortKey: "valid_until", filter: "date",
    render: (quotation) => (
      <span className="inline-flex flex-wrap items-center gap-1.5">
        {shortDate(quotation.valid_until)}
        {expiresSoon(quotation) && <Chip tone="warning">Por vencer</Chip>}
      </span>
    ),
  },
  { label: "Total", sortKey: "total", filter: "number", num: true, render: (quotation) => money(quotation.total, quotation.currency) },
  { label: "Responsable", sortKey: "creator", filter: "text", render: (quotation) => <Who name={quotation.creator?.name} /> },
  {
    label: "Estado", sortKey: "status",
    render: (quotation) => {
      const [label, tone] = stateOf(QUOTATION_STATES, quotation.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Quotation>[] = [
  { label: "Número", value: (quotation) => quotation.quotation_number, width: 18, kind: "center" },
  { label: "Versión", value: (quotation) => quotation.version, width: 10, kind: "center" },
  { label: "Cliente", value: (quotation) => customerName(quotation.customer), width: 36 },
  { label: "Documento", value: (quotation) => customerDocument(quotation.customer), width: 20 },
  { label: "Origen", value: (quotation) => sourceOf(quotation)[0], width: 12, kind: "center" },
  { label: "Emitida", value: (quotation) => shortDate(quotation.created_at), width: 15, kind: "center" },
  { label: "Válida hasta", value: (quotation) => (quotation.valid_until ? shortDate(quotation.valid_until) : ""), width: 15, kind: "center" },
  { label: "Moneda", value: (quotation) => quotation.currency, width: 10, kind: "center" },
  { label: "Subtotal", value: (quotation) => Number(quotation.subtotal), width: 15, kind: "money", currency: (quotation) => quotation.currency },
  { label: "IGV", value: (quotation) => Number(quotation.tax_total), width: 15, kind: "money", currency: (quotation) => quotation.currency },
  { label: "Total", value: (quotation) => Number(quotation.total), width: 16, kind: "money", currency: (quotation) => quotation.currency },
  { label: "Responsable", value: (quotation) => quotation.creator?.name ?? "", width: 24 },
  {
    label: "Estado",
    value: (quotation) => stateOf(QUOTATION_STATES, quotation.status)[0],
    width: 14,
    kind: "status",
    tone: (quotation) => excelTone(stateOf(QUOTATION_STATES, quotation.status)[1]),
  },
];

const quotationKey = (quotation: Quotation) => quotation.id;

/** Monto de un estado; si hay más de una moneda se muestran todas. */
function amountOf(summary: QuotationSummary | null, status: QuotationStatus) {
  const amounts = Object.entries(summary?.by_status[status]?.amounts ?? {});
  if (!amounts.length) return money(0);
  return amounts.map(([currency, amount]) => money(amount, currency)).join(" · ");
}

export default function QuotationsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [tab, setTab] = useState("ALL");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [source, setSource] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(quotationKey);

  const [data, setData] = useState<PaginatedResponse<Quotation> | null>(null);
  const [summary, setSummary] = useState<QuotationSummary | null>(null);
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
    apiFetchLater<QuotationSummary>("/v1/quotations/summary")
      .then((response) => { if (!cancelled) setSummary(response); })
      .catch(() => { /* los indicadores quedan en blanco; el listado avisa si la API falla */ });
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = new URLSearchParams({ per_page: String(perPage), page: String(page) });
      if (search) params.set("search", search);
      if (tab !== "ALL") params.set("status", tab);
      if (source) params.set("source", source);
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Quotation>>(`/v1/quotations?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las cotizaciones.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, tab, source, columnFilters.param, sort, reload]);

  /** Exporta las filas marcadas o, sin `picked`, todas las de la pestaña y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Quotation[]) {
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (tab !== "ALL") params.set("status", tab);
      if (source) params.set("source", source);
      const rows = picked ?? (await fetchAllPages<Quotation>("/v1/quotations", applySort(columnFilters.apply(params), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("quotations", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("cotizaciones", "Cotizaciones", EXPORT_COLUMNS, rows);
      else exportCsv("cotizaciones", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const count = (status: QuotationStatus) => summary?.by_status[status]?.count ?? 0;
  const shown = (value: number) => (summary ? value : "—");

  return (
    <Screen>
      <PageHead
        title="Cotizaciones"
        actions={
          <LinkBtn href="/quotations/new" permission="quotations.manage">
            <Plus size={16} />
            <span>Nueva cotización</span>
          </LinkBtn>
        }
      />

      <Kpis>
        <Kpi label="Cotizaciones" value={shown(summary?.total ?? 0)} icon={<FileText size={24} />} />
        <Kpi label="Por aprobar" value={shown(count("SUBMITTED"))} sub={summary && amountOf(summary, "SUBMITTED")} icon={<Clock size={24} />} tone="warning" />
        <Kpi label="Aprobadas" value={shown(count("APPROVED"))} sub={summary && amountOf(summary, "APPROVED")} icon={<Check size={24} />} tone="success" />
        <Kpi label="Rechazadas / vencidas" value={shown(count("REJECTED") + count("EXPIRED"))} icon={<X size={24} />} tone="danger" />
      </Kpis>

      <Tabs
        value={tab}
        onChange={(value) => {
          setTab(value);
          setPage(1);
        }}
        tabs={[
          ["ALL", "Todas", summary ? summary.total : null],
          ["DRAFT", "Borrador", summary ? count("DRAFT") : null],
          ["SUBMITTED", "Por aprobar", summary ? count("SUBMITTED") : null],
          ["APPROVED", "Aprobadas", summary ? count("APPROVED") : null],
          ["REJECTED", "Rechazadas", summary ? count("REJECTED") : null],
          ["EXPIRED", "Vencidas", summary ? count("EXPIRED") : null],
        ]}
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar número o cliente" />
        <FilterSelect
          label="Origen"
          value={source}
          onChange={(value) => {
            setSource(value);
            setPage(1);
          }}
          options={[["DIRECT", "Directa ERP"], ["OPPORTUNITY", "Desde oportunidad CRM"], ["PORTAL", "Solicitud del portal"]]}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || source)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setSource("");
            setPage(1);
          }}
        />
        <ScopeNote resource="quotations" />
      </Filters>

      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <>
            <SelectionBar
              permission="quotations.export"
              count={selectedRows.length}
              busy={exporting}
              onExport={(format) => runExport(format, selectedRows)}
              onClear={clearSelection}
            />
            <DataTable
              tableId="quotations" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
              onFilter={(column) => addColumnFilter(columnFilters, column)}
              cols={COLUMNS}
              rows={data?.data ?? []}
              rowKey={quotationKey}
              selection={selection}
              onRowClick={(quotation) => router.push(`/quotations/${quotation.id}`)}
              loading={loading && !data}
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
                left={<ExportButtons permission="quotations.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
              />
            )}
          </>
        )}
      </Card>
      {toasts}
    </Screen>
  );
}
