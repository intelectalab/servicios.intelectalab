"use client";

import { useEffect, useState } from "react";
import { AlertCircle, CalendarDays, Check, Clock, Plus } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { ACTIVITY_TYPES, activityBucket, activityTypeLabel, dateTime, todayRange, type ViewMode } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { useAccess } from "@/lib/access-context";
import {
  Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { Kanban, ViewSwitch, useStoredView, type KanbanColumn } from "@/components/ui/kanban";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ActivityCheck, ActivityFormModal, DueChip, RelatedLink, toggleActivity } from "@/components/dashboard/crm/Activities";
import type { PaginatedResponse } from "@/types/customer";
import type { Activity, ActivitySummary } from "@/types/crm";

// El tablero muestra hasta este número de actividades; con más, se acota con los filtros.
const BOARD_LIMIT = 200;

const BOARD_COLUMNS: KanbanColumn[] = [
  { id: "LATE", label: "Atrasadas", tone: "danger" },
  { id: "TODAY", label: "Hoy", tone: "warning" },
  { id: "NEXT", label: "Próximas", tone: "info" },
  { id: "DONE", label: "Realizadas", tone: "success" },
];

const EXPORT_COLUMNS: ExportColumn<Activity>[] = [
  { label: "Actividad", value: (activity) => activity.subject, width: 40 },
  { label: "Tipo", value: (activity) => activityTypeLabel(activity.activity_type), width: 14, kind: "center" },
  { label: "Relacionado", value: (activity) => activity.related?.name ?? "", width: 36 },
  { label: "Número", value: (activity) => activity.related?.number ?? "", width: 16, kind: "center" },
  { label: "Fecha", value: (activity) => (activity.due_at ? dateTime(activity.due_at) : ""), width: 24, kind: "center" },
  { label: "Responsable", value: (activity) => activity.owner?.name ?? "", width: 24 },
  {
    label: "Estado",
    value: (activity) => (activity.done ? "Realizada" : "Pendiente"),
    width: 16,
    kind: "status",
    tone: (activity) => (activity.done ? "success" : "info"),
  },
  { label: "Realizada el", value: (activity) => (activity.completed_at ? dateTime(activity.completed_at) : ""), width: 24, kind: "center" },
  { label: "Notas", value: (activity) => activity.description ?? "", width: 44 },
];

const activityKey = (activity: Activity) => activity.id;

type TopFilters = { search: string; status: string; type: string };

/** Filtros de arriba; el tablero reparte por estado y fecha, así que ahí el de estado no aplica. */
function withTopFilters(params: URLSearchParams, top: TopFilters, forBoard: boolean) {
  if (top.search) params.set("search", top.search);
  if (top.status && !forBoard) params.set("status", top.status);
  if (top.type) params.set("type", top.type);
  return params;
}

export default function ActivitiesList() {
  const { toast, toasts } = useToasts();
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("activities.manage");
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("activities", "table");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [type, setType] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(activityKey);

  const [data, setData] = useState<PaginatedResponse<Activity> | null>(null);
  const [summary, setSummary] = useState<ActivitySummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Activity | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const board = view === "kanban";

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    let cancelled = false;
    // "Hoy" es el día de quien mira: se le dice a la API dónde empieza y dónde termina.
    const [start, end] = todayRange();
    const params = new URLSearchParams({ day_start: start.toISOString(), day_end: end.toISOString() });
    apiFetchLater<ActivitySummary>(`/v1/crm/activities/summary?${params}`)
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const size = board ? { per_page: String(BOARD_LIMIT), page: "1" } : { per_page: String(perPage), page: String(page) };
      const params = withTopFilters(new URLSearchParams(size), { search, status, type }, board);
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Activity>>(`/v1/crm/activities?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las actividades.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [board, page, perPage, search, status, type, columnFilters.param, sort, reload]);

  function changeView(mode: ViewMode) {
    setData(null);
    setPage(1);
    setView(mode);
  }

  async function toggle(activity: Activity) {
    setBusy(activity.id);
    const updated = await toggleActivity(activity, toast);
    setBusy(null);
    if (updated) setReload((n) => n + 1);
  }

  /** Soltar en "Realizadas" completa; sacarla de ahí la reabre. Entre las demás manda la fecha. */
  function moveTo(activity: Activity, target: string) {
    if ((target === "DONE") !== activity.done) toggle(activity);
    else if (target !== "DONE") toast("La columna depende de la fecha: edita la fecha para moverla", "warning");
  }

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Activity[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Activity>("/v1/crm/activities", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, type }, false)), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("activities", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("actividades", "Actividades", EXPORT_COLUMNS, rows);
      else exportCsv("actividades", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const columns: Column<Activity>[] = [
    {
      label: "Actividad", sortKey: "activity", filter: "text",
      render: (activity) => (
        <div className="flex min-w-[220px] items-start gap-2.5">
          <span className="mt-0.5"><ActivityCheck activity={activity} busy={busy === activity.id} onToggle={toggle} /></span>
          <div className="min-w-0">
            <strong className={`font-semibold ${activity.done ? "app-muted line-through" : ""}`}>{activity.subject}</strong>
            {activity.description && <small className="app-muted block text-xs">{activity.description}</small>}
          </div>
        </div>
      ),
    },
    { label: "Tipo", sortKey: "type", filter: { top: "Tipo" }, render: (activity) => <Chip>{activityTypeLabel(activity.activity_type)}</Chip> },
    { label: "Relacionado", sortKey: "related", filter: "text", render: (activity) => <RelatedLink activity={activity} /> },
    {
      label: "Fecha", sortKey: "due", filter: "date",
      render: (activity) => (
        <span className="inline-flex flex-wrap items-center gap-1.5 whitespace-nowrap">
          {activity.due_at ? dateTime(activity.due_at) : "—"}
          <DueChip activity={activity} />
        </span>
      ),
    },
    { label: "Responsable", sortKey: "owner", filter: "text", render: (activity) => <Who name={activity.owner?.name} /> },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (activity) => (activity.done ? <Chip tone="success">Realizada</Chip> : <Chip tone="info">Pendiente</Chip>),
    },
  ];

  const shown = (value: number | undefined) => (summary ? value ?? 0 : "—");
  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Actividades comerciales"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={() => setCreating(true)} permission="activities.manage">
              <Plus size={16} />
              <span>Nueva actividad</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi label="Atrasadas" value={shown(summary?.late)} icon={<AlertCircle size={24} />} tone="danger" />
        <Kpi label="Para hoy" value={shown(summary?.today)} icon={<Clock size={24} />} tone="warning" />
        <Kpi label="Próximas" value={shown(summary?.upcoming)} icon={<CalendarDays size={24} />} />
        <Kpi label="Realizadas" value={shown(summary?.done)} icon={<Check size={24} />} tone="success" />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar actividad" />
        {!board && (
          <FilterSelect
            label="Estado"
            value={status}
            onChange={(value) => {
              setStatus(value);
              setPage(1);
            }}
            options={[["pending", "Pendientes"], ["done", "Realizadas"]]}
          />
        )}
        <FilterSelect
          label="Tipo"
          value={type}
          onChange={(value) => {
            setType(value);
            setPage(1);
          }}
          options={ACTIVITY_TYPES}
        />
        {!board && (
          <ColumnFilterBar
            filters={columnFilters}
            hasOtherFilters={Boolean(searchInput || status || type)}
            onClearAll={() => {
              setSearchInput("");
              setSearch("");
              setStatus("");
              setType("");
              setPage(1);
            }}
          />
        )}
        <ScopeNote resource="activities" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra {rows.length} actividades de {data.total}, empezando por las más antiguas. Usa el buscador o los filtros para acotar.</Notice>
          )}
          <Kanban
            columns={BOARD_COLUMNS}
            cards={rows}
            cardKey={activityKey}
            columnOf={activityBucket}
            canDrag={() => canManage}
            onDrop={moveTo}
            loading={loading && !data}
            refreshing={loading}
            renderCard={(activity) => (
              <>
                <div className="flex items-center justify-between gap-2">
                  <Chip>{activityTypeLabel(activity.activity_type)}</Chip>
                  <small className="app-muted text-xs">{activity.due_at ? dateTime(activity.due_at) : "Sin fecha"}</small>
                </div>
                <button
                  type="button"
                  onClick={() => setEditing(activity)}
                  className={`cursor-pointer text-left font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline ${activity.done ? "app-muted line-through" : ""}`}
                >
                  {activity.subject}
                </button>
                <small className="text-[12.5px]"><RelatedLink activity={activity} /></small>
                <div className="app-muted flex items-center justify-between gap-1.5 text-xs">
                  <Who name={activity.owner?.name} />
                  <ActivityCheck activity={activity} busy={busy === activity.id} onToggle={toggle} />
                </div>
              </>
            )}
          />
          <p className="app-muted text-xs">
            Arrastra a &quot;Realizadas&quot; para completar una actividad, o sácala de ahí para reabrirla. En teléfono, usa la casilla de cada tarjeta.
          </p>
        </>
      ) : (
        <Card flush>
          <SelectionBar permission="activities.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="crm-activities" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns}
            rows={rows}
            rowKey={activityKey}
            selection={selection}
            onRowClick={setEditing}
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
              left={<ExportButtons permission="activities.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <ActivityFormModal
          toast={toast}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            setReload((n) => n + 1);
          }}
        />
      )}
      {editing && (
        <ActivityFormModal
          activity={editing}
          toast={toast}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
