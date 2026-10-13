"use client";

import { useEffect, useState, type ReactNode } from "react";
import { Lock } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { ENTRY_STATES, entryContext, hoursText } from "@/lib/operations";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, Pager, SearchInput, SelectionBar, Who,
  deniedText, useRowSelection, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { PaginatedResponse } from "@/types/customer";
import type { CostEntry, TimeEntry } from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Aviso de una sección que el rol no puede ver: no se esconde, muestra el candado y el permiso. */
export function Locked({ permission }: { permission: string }) {
  return (
    <p className="app-muted flex items-center gap-2 px-5 pb-5 pt-3 text-[13px]">
      <Lock size={14} className="shrink-0" aria-hidden="true" />
      {deniedText(permission)}
    </p>
  );
}

type Review = { id: string; text: string };

type ListProps = {
  /** Filtros fijos de la consulta (p. ej. el proyecto, o "las mías"). */
  fixed: Record<string, string>;
  tableId: string;
  /** Listado completo: buscador, filtros, casillas y exportar. Sin él, la tabla resumida de una ficha. */
  full?: boolean;
  /** Cambia para volver a pedir (alguien registró algo fuera de la tabla). */
  version?: number;
  /** Algo cambió (aprobar, rechazar): quien la usa refresca lo que dependa de ello. */
  onChanged?: () => void;
  /** Cuántos registros hay en total (para el contador de una pestaña). */
  onTotal?: (total: number) => void;
  toast: Toast;
  empty: string;
};

/**
 * Tabla paginada de registros (horas o costos) con aprobar y rechazar por fila. Buscar, ordenar,
 * filtrar por columna y exportar van por la API, sobre todo el listado.
 */
function EntriesList<T extends { id: string; status: string }>({
  fixed, tableId, full = false, version = 0, onChanged, onTotal, toast, empty,
  path, resource, columns, exportColumns, exportName, exportTitle, searchPlaceholder, describe, selfNote,
}: ListProps & {
  path: string;
  /** Recurso de sus permisos: `<recurso>.approve` y `<recurso>.export`. */
  resource: string;
  columns: Column<T>[];
  exportColumns: ExportColumn<T>[];
  exportName: string;
  exportTitle: string;
  searchPlaceholder: string;
  describe: (row: T) => string;
  /** Motivo por el que quien mira no puede revisar esa fila (es suya), si aplica. */
  selfNote: (row: T) => string | undefined;
}) {
  const { can, loading: accessLoading } = useAccess();
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection((row: T) => row.id);

  const [data, setData] = useState<PaginatedResponse<T> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [rejecting, setRejecting] = useState<Review | null>(null);

  const fixedKey = JSON.stringify(fixed);
  const canApprove = !accessLoading && can(`${resource}.approve`);
  // El estado ya viene fijado cuando la tabla es "por aprobar".
  const statusFixed = "status" in fixed;

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  function query(base: Record<string, string>) {
    const params = new URLSearchParams({ ...base, ...(JSON.parse(fixedKey) as Record<string, string>) });
    if (search) params.set("search", search);
    if (status && !statusFixed) params.set("status", status);
    return applySort(applyFilters(params, columnFilters.param), sort);
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      // En una ficha la tabla es secundaria: sale después de la ficha.
      const read = full ? apiFetch : apiFetchLater;
      try {
        const response = await read<PaginatedResponse<T>>(`${path}?${query({ per_page: String(perPage), page: String(page) })}`);
        if (!cancelled) {
          setData(response);
          setError("");
          onTotal?.(response.total);
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError && err.status === 403 ? "denied" : "No se pudo cargar el listado.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [path, fixedKey, full, page, perPage, search, status, columnFilters.param, sort, reload, version]);

  async function review(id: string, action: "approve" | "reject", reason?: string) {
    setBusy(true);
    try {
      await apiFetch(`${path}/${id}/${action}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(action === "approve" ? "Aprobado" : "Rechazado");
      setRejecting(null);
      onChanged?.();
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
      // También tras un error: si otra persona lo cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  async function runExport(format: "xlsx" | "csv", picked?: T[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<T>(path, query({})));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport(resource, format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx(exportName, exportTitle, exportColumns, rows);
      else exportCsv(exportName, exportColumns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const actions: Column<T> = {
    label: "Acciones",
    render: (row) => {
      if (row.status !== "SUBMITTED") return null;
      const own = selfNote(row);
      return (
        // Las acciones no abren nada de la fila.
        <div className="flex flex-wrap gap-1.5" onClick={(event) => event.stopPropagation()}>
          <Btn size="sm" variant="secondary" permission={`${resource}.approve`} disabled={busy || Boolean(canApprove && own)} title={canApprove ? own : undefined} onClick={() => review(row.id, "approve")}>
            Aprobar
          </Btn>
          <Btn size="sm" variant="danger-ghost" permission={`${resource}.approve`} disabled={busy || Boolean(canApprove && own)} title={canApprove ? own : undefined} onClick={() => setRejecting({ id: row.id, text: describe(row) })}>
            Rechazar
          </Btn>
        </div>
      );
    },
  };

  if (error === "denied") return <Locked permission={`${resource}.view`} />;
  if (error) return <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />;

  const rows = data?.data ?? [];
  // El listado completo lleva su propia tarjeta; en una ficha, la tarjeta la pone quien lo usa.
  const Frame = full ? FullFrame : BareFrame;

  return (
    <>
      {full && (
        <>
          <Filters>
            <SearchInput value={searchInput} onChange={setSearchInput} placeholder={searchPlaceholder} />
            {!statusFixed && (
              <FilterSelect
                label="Estado"
                value={status}
                onChange={(value) => {
                  setStatus(value);
                  setPage(1);
                }}
                options={ENTRY_STATES.map(([key, label]) => [key, label])}
              />
            )}
            <ColumnFilterBar
              filters={columnFilters}
              hasOtherFilters={Boolean(searchInput || status)}
              onClearAll={() => {
                setSearchInput("");
                setSearch("");
                setStatus("");
                setPage(1);
              }}
            />
            <ScopeNote resource={resource} />
          </Filters>
        </>
      )}
      <Frame>
      {full && <SelectionBar permission={`${resource}.export`} count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />}
      <DataTable
        tableId={full ? tableId : undefined}
        dense={!full}
        sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
        onFilter={full ? (column) => addColumnFilter(columnFilters, column) : undefined}
        cols={[...columns, actions]}
        rows={rows}
        rowKey={(row) => row.id}
        selection={full ? selection : undefined}
        loading={loading && !data}
        empty={empty}
      />
      {data && (full || data.total > data.per_page) && (
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
          left={full ? <ExportButtons permission={`${resource}.export`} total={data.total} busy={exporting} onExport={(format) => runExport(format)} /> : undefined}
        />
      )}
      </Frame>
      {rejecting && (
        <ReasonModal
          title="Rechazar"
          text={rejecting.text}
          label="Motivo del rechazo"
          confirmLabel="Rechazar"
          busy={busy}
          onClose={() => setRejecting(null)}
          onConfirm={(reason) => review(rejecting.id, "reject", reason)}
        />
      )}
    </>
  );
}

const FullFrame = ({ children }: { children: ReactNode }) => <Card flush>{children}</Card>;
const BareFrame = ({ children }: { children: ReactNode }) => <>{children}</>;

function StatusCell({ status, reason }: { status: string; reason: string | null }): ReactNode {
  const [label, tone] = stateOf(ENTRY_STATES, status);
  return (
    <span title={reason ?? undefined}>
      <Badge tone={tone}>{label}</Badge>
      {reason && <small className="app-muted mt-0.5 block max-w-[220px] text-xs">{reason}</small>}
    </span>
  );
}

const TIME_COLUMNS: Column<TimeEntry>[] = [
  { label: "Persona", sortKey: "person", filter: "text", render: (entry) => <Who name={entry.user?.name} /> },
  {
    label: "Contexto", sortKey: "context", filter: "text",
    render: (entry) => (
      <div className="min-w-[160px]">
        <span>{entryContext(entry)}</span>
        {(entry.task || entry.description) && <small className="app-muted block text-xs">{[entry.task, entry.description].filter(Boolean).join(" · ")}</small>}
      </div>
    ),
  },
  { label: "Fecha", sortKey: "date", filter: "date", render: (entry) => <span className="sm:whitespace-nowrap">{dateTime(entry.started_at)}</span> },
  { label: "Horas", num: true, sortKey: "hours", filter: "number", render: (entry) => <span className="tabular-nums">{entry.hours.toFixed(1)}</span> },
  { label: "Costo", num: true, render: (entry) => <span className="whitespace-nowrap tabular-nums">{money(entry.cost)}</span> },
  { label: "Facturable", render: (entry) => <Chip tone={entry.billable ? "accent" : "neutral"}>{entry.billable ? "Sí" : "No"}</Chip> },
  { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (entry) => <StatusCell status={entry.status} reason={entry.reject_reason} /> },
];

// Dentro de la ficha de un proyecto el contexto es siempre ese proyecto: se muestra la tarea, como en el prototipo.
const TIME_TASK: Column<TimeEntry> = {
  label: "Tarea",
  render: (entry) => (
    <div className="max-w-[280px]">
      <span>{entry.task ?? "—"}</span>
      {entry.description && <small className="app-muted block text-xs">{entry.description}</small>}
    </div>
  ),
};
const PROJECT_TIME_COLUMNS = TIME_COLUMNS.map((column) => (column.label === "Contexto" ? TIME_TASK : column));

const TIME_EXPORT: ExportColumn<TimeEntry>[] = [
  { label: "Persona", value: (entry) => entry.user?.name ?? "", width: 26 },
  { label: "Proyecto u orden", value: (entry) => entryContext(entry), width: 40 },
  { label: "Tarea", value: (entry) => entry.task ?? "", width: 26 },
  { label: "Inicio", value: (entry) => dateTime(entry.started_at), width: 22, kind: "center" },
  { label: "Fin", value: (entry) => dateTime(entry.ended_at), width: 22, kind: "center" },
  { label: "Horas", value: (entry) => entry.hours, width: 10, kind: "number" },
  { label: "Costo", value: (entry) => entry.cost, width: 14, kind: "money" },
  { label: "Facturable", value: (entry) => (entry.billable ? "Sí" : "No"), width: 12, kind: "center" },
  { label: "Descripción", value: (entry) => entry.description ?? "", width: 34 },
  { label: "Estado", value: (entry) => stateOf(ENTRY_STATES, entry.status)[0], width: 14, kind: "status", tone: (entry) => excelTone(stateOf(ENTRY_STATES, entry.status)[1]) },
  { label: "Motivo del rechazo", value: (entry) => entry.reject_reason ?? "", width: 32 },
];

/** Registros de horas, con aprobar y rechazar. Nadie aprueba las suyas. */
export function TimeEntriesList(props: ListProps) {
  return (
    <EntriesList<TimeEntry>
      {...props}
      path="/v1/time-entries"
      resource="time_entries"
      columns={props.fixed.project_id ? PROJECT_TIME_COLUMNS : TIME_COLUMNS}
      exportColumns={TIME_EXPORT}
      exportName="horas"
      exportTitle="Horas"
      searchPlaceholder="Buscar persona, proyecto u orden"
      describe={(entry) => `${entry.user?.name ?? "—"} · ${entryContext(entry)} · ${hoursText(entry.hours)}`}
      selfNote={(entry) => (entry.own ? "No puedes aprobar tus propias horas: las revisa otra persona." : undefined)}
    />
  );
}

const COST_COLUMNS: Column<CostEntry>[] = [
  {
    label: "Costo", sortKey: "cost", filter: "text",
    render: (cost) => (
      <div className="min-w-[160px]">
        <strong className="font-semibold">{cost.description}</strong>
        <small className="app-muted block text-xs">{[cost.category, cost.creator?.name].filter(Boolean).join(" · ")}</small>
      </div>
    ),
  },
  { label: "Proyecto", sortKey: "project", filter: "text", render: (cost) => (cost.project ? `${cost.project.code} · ${cost.project.name}` : "—") },
  { label: "Fecha", sortKey: "date", filter: "date", render: (cost) => <span className="whitespace-nowrap">{shortDate(cost.incurred_at)}</span> },
  { label: "Cant.", num: true, render: (cost) => <span className="tabular-nums">{cost.quantity}</span> },
  { label: "Costo unit.", num: true, render: (cost) => <span className="whitespace-nowrap tabular-nums">{money(cost.unit_cost)}</span> },
  { label: "Total", num: true, sortKey: "total", filter: "number", render: (cost) => <strong className="whitespace-nowrap font-semibold tabular-nums">{money(cost.total)}</strong> },
  { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (cost) => <StatusCell status={cost.status} reason={cost.reject_reason} /> },
];

const PROJECT_COST_COLUMNS = COST_COLUMNS.filter((column) => column.label !== "Proyecto");

const COST_EXPORT: ExportColumn<CostEntry>[] = [
  { label: "Descripción", value: (cost) => cost.description, width: 38 },
  { label: "Categoría", value: (cost) => cost.category, width: 18 },
  { label: "Proyecto", value: (cost) => (cost.project ? `${cost.project.code} · ${cost.project.name}` : ""), width: 38 },
  { label: "Fecha", value: (cost) => (cost.incurred_at ? shortDate(cost.incurred_at) : ""), width: 15, kind: "center" },
  { label: "Cantidad", value: (cost) => cost.quantity, width: 11, kind: "number" },
  { label: "Costo unitario", value: (cost) => cost.unit_cost, width: 16, kind: "money" },
  { label: "Total", value: (cost) => cost.total, width: 15, kind: "money" },
  { label: "Registrado por", value: (cost) => cost.creator?.name ?? "", width: 26 },
  { label: "Estado", value: (cost) => stateOf(ENTRY_STATES, cost.status)[0], width: 14, kind: "status", tone: (cost) => excelTone(stateOf(ENTRY_STATES, cost.status)[1]) },
  { label: "Motivo del rechazo", value: (cost) => cost.reject_reason ?? "", width: 32 },
];

/** Costos directos, con aprobar y rechazar. */
export function CostEntriesList(props: ListProps) {
  return (
    <EntriesList<CostEntry>
      {...props}
      path="/v1/cost-entries"
      resource="cost_entries"
      columns={props.fixed.project_id ? PROJECT_COST_COLUMNS : COST_COLUMNS}
      exportColumns={COST_EXPORT}
      exportName="costos"
      exportTitle="Costos"
      searchPlaceholder="Buscar costo o proyecto"
      describe={(cost) => `${cost.description} · ${money(cost.total)}`}
      // Que quien lo registró no lo apruebe depende de la regla de la empresa: lo decide la API.
      selfNote={() => undefined}
    />
  );
}
