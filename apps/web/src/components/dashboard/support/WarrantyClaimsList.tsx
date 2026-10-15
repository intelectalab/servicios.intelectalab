"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { customerName, excelTone, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime } from "@/lib/crm";
import { COVERAGE_FILTER, WARRANTY_STATES } from "@/lib/support";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen, SearchInput, SelectionBar,
  applySort, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import {
  ClaimActions, CoverageCell, WarrantyClaimDetailModal, WarrantyClaimFormModal, useClaimActions, type ClaimAction,
} from "@/components/dashboard/support/WarrantyModals";
import type { PaginatedResponse } from "@/types/customer";
import type { WarrantyClaim } from "@/types/support";

const claimKey = (claim: WarrantyClaim) => claim.id;
const LINK = "text-[color:var(--p-brand-text)] hover:underline";

const EXPORT_COLUMNS: ExportColumn<WarrantyClaim>[] = [
  { label: "Reclamo", value: (claim) => claim.number, width: 16, kind: "center" },
  { label: "Descripción", value: (claim) => claim.description, width: 44 },
  { label: "Cliente", value: (claim) => customerName(claim.customer), width: 32 },
  { label: "Servicio", value: (claim) => claim.service?.name ?? "", width: 30 },
  { label: "Ticket", value: (claim) => claim.ticket?.number ?? "", width: 16, kind: "center" },
  { label: "Cobertura", value: (claim) => (claim.coverage ? (claim.coverage.valid ? "Dentro de garantía" : "Fuera de cobertura") : "Sin validar"), width: 20, kind: "center" },
  { label: "Política", value: (claim) => claim.coverage?.policy ?? "", width: 36 },
  { label: "Cubre hasta", value: (claim) => (claim.coverage?.until ? shortDate(claim.coverage.until) : ""), width: 16, kind: "center" },
  {
    label: "Estado", value: (claim) => stateOf(WARRANTY_STATES, claim.status)[0], width: 16, kind: "status",
    tone: (claim) => excelTone(stateOf(WARRANTY_STATES, claim.status)[1]),
  },
  { label: "Motivo / resolución", value: (claim) => claim.reject_reason ?? claim.resolution ?? "", width: 36 },
  { label: "Registrado", value: (claim) => (claim.created_at ? dateTime(claim.created_at) : ""), width: 22, kind: "center" },
];

type TopFilters = { search: string; status: string; coverage: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.coverage) params.set("coverage", top.coverage);
  return params;
}

export default function WarrantyClaimsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [coverage, setCoverage] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(claimKey);

  const [data, setData] = useState<PaginatedResponse<WarrantyClaim> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);
  const [open, setOpen] = useState<string | null>(null);

  const { busy, start, reasonModal } = useClaimActions(toast, () => setReload((n) => n + 1));

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  // La ficha se abre también desde un enlace (?claim=), p. ej. desde el ticket.
  useEffect(() => {
    async function fromLink() {
      const claim = new URLSearchParams(window.location.search).get("claim");
      if (claim) setOpen(claim);
    }
    fromLink();
  }, []);

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, coverage });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<WarrantyClaim>>(`/v1/warranty-claims?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los reclamos de garantía.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, coverage, columnFilters.param, sort, reload]);

  async function runExport(format: "xlsx" | "csv", picked?: WarrantyClaim[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<WarrantyClaim>("/v1/warranty-claims", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, coverage })), sort)));
      await recordExport("warranty", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("reclamos-de-garantia", "Reclamos de garantía", EXPORT_COLUMNS, rows);
      else exportCsv("reclamos-de-garantia", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  // Los botones de una fila no abren la ficha.
  const act = (claim: WarrantyClaim, action: ClaimAction) => start(claim, action);

  const cols: Column<WarrantyClaim>[] = [
    {
      label: "Reclamo", sortKey: "claim", filter: "text",
      render: (claim) => (
        <div className="min-w-[200px] max-w-[340px]">
          <strong className="font-semibold">{claim.number}</strong>
          <small className="app-muted block text-xs">{claim.description}</small>
        </div>
      ),
    },
    {
      label: "Cliente / servicio", sortKey: "customer", filter: "text",
      render: (claim) => (
        <div className="min-w-[160px]">
          {customerName(claim.customer)}
          <small className="app-muted block text-xs">{claim.service?.name ?? "—"}</small>
        </div>
      ),
    },
    {
      label: "Ticket", sortKey: "ticket",
      render: (claim) => claim.ticket
        ? (claim.ticket.visible
          ? <Link href={`/tickets/${claim.ticket.id}`} className={`${LINK} whitespace-nowrap`} onClick={(e) => e.stopPropagation()}>{claim.ticket.number}</Link>
          : <span className="whitespace-nowrap">{claim.ticket.number}</span>)
        : <span className="app-muted">—</span>,
    },
    {
      label: "Cobertura", sortKey: "coverage", filter: { top: "Cobertura" },
      render: (claim) => (claim.coverage ? <CoverageCell claim={claim} /> : (
        <span onClick={(e) => e.stopPropagation()}>
          <ClaimActions claim={claim} busy={busy} onAction={(action) => act(claim, action)} />
        </span>
      )),
    },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (claim) => {
        const [label, tone] = stateOf(WARRANTY_STATES, claim.status);
        return (
          <div className="grid gap-0.5">
            <span><Badge tone={tone}>{label}</Badge></span>
            {claim.reject_reason && <small className="app-muted block text-xs">{claim.reject_reason}</small>}
          </div>
        );
      },
    },
    {
      label: "Acciones",
      render: (claim) => (claim.coverage ? (
        <span onClick={(e) => e.stopPropagation()}>
          <ClaimActions claim={claim} busy={busy} onAction={(action) => act(claim, action)} />
        </span>
      ) : null),
    },
  ];

  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Reclamos de garantía"
        actions={
          <Btn onClick={() => setCreating(true)} permission="warranty.create">
            <Plus size={16} />
            <span>Nuevo reclamo</span>
          </Btn>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar reclamo, cliente o servicio" />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={WARRANTY_STATES.map(([key, label]) => [key, label])} />
        <FilterSelect label="Cobertura" value={coverage} onChange={(value) => { setCoverage(value); setPage(1); }} options={COVERAGE_FILTER} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || coverage)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setCoverage("");
            setPage(1);
          }}
        />
        <ScopeNote resource="warranty" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="warranty.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="warranty-claims" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols}
            rows={rows}
            rowKey={claimKey}
            selection={selection}
            onRowClick={(claim) => setOpen(claim.id)}
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
              onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="warranty.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <WarrantyClaimFormModal
          toast={toast}
          onClose={() => setCreating(false)}
          onSaved={(claim) => {
            setCreating(false);
            toast(`Reclamo ${claim.number} registrado · cobertura validada`);
            setReload((n) => n + 1);
            setOpen(claim.id);
          }}
        />
      )}
      {open && (
        <WarrantyClaimDetailModal
          claimId={open}
          toast={toast}
          onClose={() => {
            setOpen(null);
            if (window.location.search.includes("claim=")) router.replace("/warranty-claims");
          }}
          onChanged={() => setReload((n) => n + 1)}
        />
      )}
      {reasonModal}
      {toasts}
    </Screen>
  );
}
