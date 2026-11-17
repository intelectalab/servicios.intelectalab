"use client";

import { useEffect, useState } from "react";
import { X } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, shortDate, stateOf } from "@/lib/commercial";
import { LEAVE_STATES, hrDateTime } from "@/lib/personal";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, Pager, SearchInput, SelectionBar,
  applySort, useRowSelection, type Column, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { LeaveActions, leaveMastered, type LeaveAction } from "@/components/dashboard/personal/LeaveModals";
import type { Toast } from "@/components/dashboard/personal/EmployeeModals";
import type { LeaveKpis, LeaveList, LeaveOptions, LeaveRequest } from "@/types/personal";

const leaveKey = (leave: LeaveRequest) => leave.id;

const EXPORT_COLUMNS: ExportColumn<LeaveRequest>[] = [
  { label: "Trabajador", value: (l) => l.employee.name, width: 32 },
  { label: "Tipo", value: (l) => l.type.name, width: 24 },
  { label: "Goce", value: (l) => (l.type.paid ? "Con goce" : "Sin goce"), width: 12, kind: "center" },
  { label: "Desde", value: (l) => shortDate(l.date_from), width: 16, kind: "center" },
  { label: "Hasta", value: (l) => shortDate(l.date_to), width: 16, kind: "center" },
  { label: "Días", value: (l) => l.days, width: 8, kind: "number" },
  { label: "Motivo", value: (l) => l.reason ?? "", width: 36 },
  {
    label: "Estado", value: (l) => stateOf(LEAVE_STATES, l.status)[0], width: 14, kind: "status",
    tone: (l) => excelTone(stateOf(LEAVE_STATES, l.status)[1]),
  },
  { label: "Decidida por", value: (l) => l.decided_by?.name ?? "", width: 24 },
  { label: "Decidida", value: (l) => (l.decided_at ? hrDateTime(l.decided_at) : ""), width: 22, kind: "center" },
  { label: "Nota de la decisión", value: (l) => l.decision_note ?? "", width: 32 },
  { label: "Origen", value: (l) => (l.source === "HR_SAAS" ? "SaaS de RRHH" : "ERP"), width: 14, kind: "center" },
  { label: "Solicitada", value: (l) => hrDateTime(l.created_at), width: 22, kind: "center" },
];

type TopFilters = { search: string; status: string; type: string; mine: boolean; employee: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.type) params.set("leave_type_id", top.type);
  if (top.mine) params.set("mine", "1");
  if (top.employee) params.set("employee_id", top.employee);
  return params;
}

/** Pestaña Solicitudes: la bandeja de aprobación, con filtros y exportación al pie. */
export default function LeaveRequestsList({
  toast, options, reload, busy, onAction, onOpen, onKpis, employee, onClearEmployee,
}: {
  toast: Toast;
  options: LeaveOptions | null;
  /** Sube cuando una acción cambió alguna solicitud. */
  reload: number;
  busy: boolean;
  onAction: (leave: LeaveRequest, action: LeaveAction) => void;
  onOpen: (leave: LeaveRequest) => void;
  onKpis: (kpis: LeaveKpis) => void;
  employee: { id: string; name?: string } | null;
  onClearEmployee: () => void;
}) {
  const [exporting, setExporting] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [type, setType] = useState("");
  const [mine, setMine] = useState(false);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [retry, setRetry] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(leaveKey);

  const [data, setData] = useState<LeaveList | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const employeeId = employee?.id ?? "";

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
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, type, mine, employee: employeeId });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<LeaveList>(`/v1/hr/leaves?${params}`);
        if (!cancelled) {
          setData(response);
          onKpis(response.kpis);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las solicitudes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, type, mine, employeeId, columnFilters.param, sort, reload, retry, onKpis]);

  async function runExport(format: "xlsx" | "csv", picked?: LeaveRequest[]) {
    setExporting(true);
    try {
      const top = { search, status, type, mine, employee: employeeId };
      const rows = picked ?? (await fetchAllPages<LeaveRequest>("/v1/hr/leaves", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), top)), sort)));
      await recordExport("leaves", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("ausencias", "Vacaciones y ausencias", EXPORT_COLUMNS, rows);
      else exportCsv("ausencias", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const cols: Column<LeaveRequest>[] = [
    {
      label: "Trabajador", sortKey: "employee", filter: "text",
      render: (l) => (
        <div className="min-w-[160px]">
          <strong className="font-semibold">{l.employee.name}</strong>
          {l.mine && <small className="app-muted block text-xs">Tu solicitud</small>}
        </div>
      ),
    },
    {
      label: "Tipo", sortKey: "type", filter: { top: "Tipo" },
      render: (l) => (
        <span className="inline-flex flex-wrap items-center gap-1">
          {l.type.name}
          {!l.type.paid && <Chip tone="warning">Sin goce</Chip>}
          {l.source === "HR_SAAS" && <Chip tone="accent">RRHH</Chip>}
        </span>
      ),
    },
    {
      label: "Fechas", sortKey: "from", filter: "date",
      render: (l) => (
        <div className="whitespace-nowrap">
          {shortDate(l.date_from)}{l.date_to !== l.date_from && <> – {shortDate(l.date_to)}</>}
          <small className="app-muted block text-xs">{l.days} {l.days === 1 ? "día" : "días"}</small>
        </div>
      ),
    },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (l) => {
        const [label, tone] = stateOf(LEAVE_STATES, l.status);
        return (
          <div className="grid gap-0.5">
            <span><Badge tone={tone}>{label}</Badge></span>
            {l.status === "REJECTED" && l.decision_note && <small className="app-muted block max-w-[240px] text-xs">{l.decision_note}</small>}
          </div>
        );
      },
    },
    { label: "Solicitada", sortKey: "requested", render: (l) => <span className="whitespace-nowrap">{hrDateTime(l.created_at)}</span> },
    {
      label: "Acciones",
      render: (l) => (
        <span onClick={(e) => e.stopPropagation()}>
          <LeaveActions leave={l} mastered={leaveMastered(l, options)} busy={busy} onAction={(action) => onAction(l, action)} />
        </span>
      ),
    },
  ];

  return (
    <>
      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar trabajador o motivo" />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={LEAVE_STATES.map(([key, label]) => [key, label])} />
        <FilterSelect label="Tipo" value={type} onChange={(value) => { setType(value); setPage(1); }} options={(options?.types ?? []).map((t) => [t.id, t.name])} />
        <label className="relative inline-flex h-10 cursor-pointer items-center gap-2 rounded-[9px] border border-[color:var(--app-border)] px-3 text-[13px]">
          <input type="checkbox" checked={mine} onChange={(e) => { setMine(e.target.checked); setPage(1); }} className="h-4 w-4 accent-[color:var(--p-accent,#1B8FC9)]" />
          <span>Solo mías</span>
        </label>
        {employee && (
          <span className="inline-flex h-10 items-center gap-1 rounded-[9px] bg-[var(--p-brand-soft)] pl-3 pr-1 text-[13px] text-[color:var(--p-brand-text)]">
            {employee.name ?? "Un trabajador"}
            <button type="button" onClick={onClearEmployee} aria-label="Ver las de todos" title="Ver las de todos" className="grid h-8 w-8 cursor-pointer place-items-center rounded-md hover:bg-[var(--app-surface-muted)]">
              <X size={14} />
            </button>
          </span>
        )}
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || type || mine)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setType("");
            setMine(false);
            setPage(1);
          }}
        />
        <ScopeNote resource="leaves" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="leaves.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="hr-leaves" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols}
            rows={data?.data ?? []}
            rowKey={leaveKey}
            selection={selection}
            onRowClick={onOpen}
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
              left={<ExportButtons permission="leaves.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}
    </>
  );
}
