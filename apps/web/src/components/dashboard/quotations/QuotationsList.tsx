"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Check, Clock, FileText, Plus, X } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { QUOTATION_STATES, customerDocument, customerName, money, shortDate, stateOf } from "@/lib/commercial";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, Chip, DataTable, ExportButtons, Filters, Kpi, Kpis, LoadError, PageHead, Pager, Screen, SearchInput, Tabs,
  btnClass, useToasts, type Column,
} from "@/components/ui/proto";
import type { PaginatedResponse } from "@/types/customer";
import type { Quotation, QuotationStatus, QuotationSummary } from "@/types/commercial";

const PER_PAGE = 12;
const THREE_DAYS = 3 * 864e5;

function expiresSoon(quotation: Quotation) {
  if (quotation.status !== "SUBMITTED" || !quotation.valid_until) return false;
  return new Date(quotation.valid_until).getTime() < Date.now() + THREE_DAYS;
}

const COLUMNS: Column<Quotation>[] = [
  {
    label: "Número",
    render: (quotation) => (
      <div className="min-w-[150px]">
        <strong className="font-semibold">{quotation.quotation_number}</strong>
        <small className="app-muted block text-xs">
          Versión {quotation.version}{quotation.superseded_by ? " · reemplazada" : ""}
        </small>
      </div>
    ),
  },
  { label: "Cliente", render: (quotation) => customerName(quotation.customer) },
  {
    label: "Válida hasta",
    render: (quotation) => (
      <span className="inline-flex flex-wrap items-center gap-1.5">
        {shortDate(quotation.valid_until)}
        {expiresSoon(quotation) && <Chip tone="warning">Por vencer</Chip>}
      </span>
    ),
  },
  { label: "Total", num: true, render: (quotation) => money(quotation.total, quotation.currency) },
  { label: "Responsable", render: (quotation) => quotation.creator?.name ?? "—" },
  {
    label: "Estado",
    render: (quotation) => {
      const [label, tone] = stateOf(QUOTATION_STATES, quotation.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Quotation>[] = [
  { label: "Número", value: (quotation) => quotation.quotation_number, width: 18 },
  { label: "Versión", value: (quotation) => quotation.version, width: 10 },
  { label: "Cliente", value: (quotation) => customerName(quotation.customer), width: 36 },
  { label: "Documento", value: (quotation) => customerDocument(quotation.customer) },
  { label: "Emitida", value: (quotation) => quotation.created_at.slice(0, 10), width: 14 },
  { label: "Válida hasta", value: (quotation) => (quotation.valid_until ?? "").slice(0, 10), width: 14 },
  { label: "Moneda", value: (quotation) => quotation.currency, width: 10 },
  { label: "Subtotal", value: (quotation) => Number(quotation.subtotal), width: 14 },
  { label: "IGV", value: (quotation) => Number(quotation.tax_total), width: 14 },
  { label: "Total", value: (quotation) => Number(quotation.total), width: 14 },
  { label: "Responsable", value: (quotation) => quotation.creator?.name ?? "" },
  { label: "Estado", value: (quotation) => stateOf(QUOTATION_STATES, quotation.status)[0], width: 14 },
];

/** Monto de un estado; si hay más de una moneda se muestran todas. */
function amountOf(summary: QuotationSummary | null, status: QuotationStatus) {
  const amounts = Object.entries(summary?.by_status[status]?.amounts ?? {});
  if (!amounts.length) return money(0);
  return amounts.map(([currency, amount]) => money(amount, currency)).join(" · ");
}

export default function QuotationsList() {
  const router = useRouter();
  const { can } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [tab, setTab] = useState("ALL");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [reload, setReload] = useState(0);

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
    apiFetch<QuotationSummary>("/v1/quotations/summary")
      .then((response) => { if (!cancelled) setSummary(response); })
      .catch(() => { /* los indicadores quedan en blanco; el listado avisa si la API falla */ });
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = new URLSearchParams({ per_page: String(PER_PAGE), page: String(page) });
      if (search) params.set("search", search);
      if (tab !== "ALL") params.set("status", tab);

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
  }, [page, search, tab, reload]);

  /** Exporta todas las filas de la pestaña y búsqueda actuales, no solo la página visible. */
  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const params = new URLSearchParams();
      if (search) params.set("search", search);
      if (tab !== "ALL") params.set("status", tab);
      const rows = await fetchAllPages<Quotation>("/v1/quotations", params);
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
  const canCreate = can("quotations.manage");

  return (
    <Screen>
      <PageHead
        title="Cotizaciones"
        actions={
          canCreate ? (
            <Link href="/quotations/new" className={btnClass("primary")}>
              <Plus size={16} />
              <span>Nueva cotización</span>
            </Link>
          ) : (
            <span className={`${btnClass("primary")} cursor-not-allowed opacity-45`} aria-disabled="true" title="Tu rol no puede crear cotizaciones">
              <Plus size={16} />
              <span>Nueva cotización</span>
            </span>
          )
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
      </Filters>

      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <>
            <DataTable
              cols={COLUMNS}
              rows={data?.data ?? []}
              rowKey={(quotation) => quotation.id}
              onRowClick={(quotation) => router.push(`/quotations/${quotation.id}`)}
              loading={loading && !data}
            />
            {data && (
              <Pager
                page={data.current_page}
                lastPage={data.last_page}
                total={data.total}
                perPage={data.per_page}
                onPage={setPage}
                left={<ExportButtons total={data.total} busy={exporting} onExport={runExport} />}
              />
            )}
          </>
        )}
      </Card>
      {toasts}
    </Screen>
  );
}
