"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { customerName, excelTone, money, setFlash, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { firstError, type ViewMode } from "@/lib/crm";
import { PROJECT_MOVES, PROJECT_OPEN, PROJECT_STATES, hoursText, projectPeriod, type ProjectMove } from "@/lib/operations";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { Kanban, ViewSwitch, useStoredView, type KanbanColumn, MoveLocked } from "@/components/ui/kanban";
import { PickSelect } from "@/components/ui/pick-select";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { ProjectFormModal } from "@/components/dashboard/operations/ProjectModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Project, ProjectOptions } from "@/types/operations";

// El tablero muestra hasta este número de proyectos; con más, se acota con el buscador.
const BOARD_LIMIT = 200;

// Los cancelados no tienen columna: se ven en la tabla.
const BOARD_COLUMNS: KanbanColumn[] = [
  { id: "DRAFT", label: "Borrador", tone: "neutral" },
  { id: "PLANNED", label: "Planificados", tone: "info" },
  { id: "IN_PROGRESS", label: "En ejecución", tone: "accent" },
  { id: "ON_HOLD", label: "En pausa", tone: "warning" },
  { id: "COMPLETED", label: "Completados", tone: "success" },
];

/** Barra de avance, con su porcentaje. */
export function ProgressBar({ percent, label }: { percent: number; label?: string }) {
  const value = Math.max(0, Math.min(100, percent));
  return (
    <div className="grid min-w-[90px] gap-1">
      <div className="h-1.5 overflow-hidden rounded-full bg-[var(--app-surface-muted)]" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100} aria-label={label ?? "Avance"}>
        <div className={`h-full rounded-full ${value === 100 ? "bg-[var(--p-success)]" : "bg-[#1B8FC9]"}`} style={{ width: `${value}%` }} />
      </div>
      <small className="app-muted text-xs tabular-nums">{value}%</small>
    </div>
  );
}

/** Margen del proyecto: en rojo si es negativo. */
export function Margin({ project }: { project: Pick<Project, "metrics"> }) {
  const { margin, margin_pct: percent } = project.metrics;
  return <span className={`whitespace-nowrap tabular-nums ${margin < 0 ? "text-[color:var(--p-danger-text)]" : ""}`}>{money(margin)} ({percent}%)</span>;
}

const COLUMNS: Column<Project>[] = [
  {
    label: "Proyecto", sortKey: "project", filter: "text",
    render: (project) => (
      <div className="min-w-[180px]">
        <strong className="font-semibold">{project.name}</strong>
        <small className="app-muted block text-xs">{project.code}</small>
      </div>
    ),
  },
  { label: "Cliente", sortKey: "customer", filter: "text", render: (project) => customerName(project.customer) },
  { label: "Responsable", sortKey: "manager", filter: "text", render: (project) => <Who name={project.manager?.name} /> },
  { label: "Vigencia", sortKey: "start", filter: "date", render: (project) => <span className="sm:whitespace-nowrap">{projectPeriod(project)}</span> },
  { label: "Avance", sortKey: "progress", render: (project) => <ProgressBar percent={project.metrics.progress} /> },
  { label: "Tareas", num: true, render: (project) => <span className="tabular-nums">{project.metrics.done}/{project.metrics.tasks}</span> },
  { label: "Horas", num: true, render: (project) => <span className="whitespace-nowrap tabular-nums">{hoursText(project.metrics.hours)}</span> },
  { label: "Margen", num: true, sortKey: "margin", render: (project) => <Margin project={project} /> },
  {
    label: "Estado", sortKey: "status", filter: { top: "Estado" },
    render: (project) => {
      const [label, tone] = stateOf(PROJECT_STATES, project.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Project>[] = [
  { label: "Código", value: (project) => project.code, width: 16, kind: "center" },
  { label: "Proyecto", value: (project) => project.name, width: 38 },
  { label: "Cliente", value: (project) => customerName(project.customer), width: 34 },
  { label: "Responsable", value: (project) => project.manager?.name ?? "", width: 26 },
  { label: "Inicio", value: (project) => (project.start_date ? shortDate(project.start_date) : ""), width: 15, kind: "center" },
  { label: "Fin", value: (project) => (project.end_date ? shortDate(project.end_date) : ""), width: 15, kind: "center" },
  { label: "Avance %", value: (project) => project.metrics.progress, width: 11, kind: "number" },
  { label: "Tareas", value: (project) => project.metrics.tasks, width: 10, kind: "number" },
  { label: "Tareas hechas", value: (project) => project.metrics.done, width: 14, kind: "number" },
  { label: "Horas aprobadas", value: (project) => project.metrics.hours, width: 16, kind: "number" },
  { label: "Ingreso", value: (project) => project.budget_revenue, width: 15, kind: "money" },
  { label: "Costo real", value: (project) => project.metrics.cost, width: 15, kind: "money" },
  { label: "Margen", value: (project) => project.metrics.margin, width: 15, kind: "money" },
  {
    label: "Estado",
    value: (project) => stateOf(PROJECT_STATES, project.status)[0],
    width: 16,
    kind: "status",
    tone: (project) => excelTone(stateOf(PROJECT_STATES, project.status)[1]),
  },
  { label: "Motivo", value: (project) => project.cancel_reason ?? project.hold_reason ?? "", width: 32 },
];

const projectKey = (project: Project) => project.id;

type TopFilters = { search: string; status: string };

function withTopFilters(params: URLSearchParams, top: TopFilters, forBoard: boolean) {
  if (top.search) params.set("search", top.search);
  if (top.status && !forBoard) params.set("status", top.status);
  return params;
}

export default function ProjectsList() {
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("projects", "kanban");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(projectKey);

  const [data, setData] = useState<PaginatedResponse<Project> | null>(null);
  const [options, setOptions] = useState<ProjectOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [creating, setCreating] = useState(false);
  const [reasoning, setReasoning] = useState<{ project: Project; move: ProjectMove } | null>(null);
  const [busy, setBusy] = useState(false);

  const board = view === "kanban";
  const allowed = (move: ProjectMove) => !accessLoading && can(move.permission);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

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
      const size = board ? { per_page: String(BOARD_LIMIT), page: "1" } : { per_page: String(perPage), page: String(page) };
      const params = withTopFilters(new URLSearchParams(size), { search, status }, board);
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Project>>(`/v1/projects?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los proyectos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [board, page, perPage, search, status, columnFilters.param, sort, reload]);

  function changeView(mode: ViewMode) {
    // Pulsar la vista que ya está activa no cambia nada (si se vaciara la lista, nadie la volvería a pedir).
    if (mode === view) return;
    setData(null);
    setPage(1);
    setView(mode);
  }

  /** Abre "Nuevo proyecto"; las listas del formulario se piden al abrirlo. */
  function openCreate() {
    setCreating(true);
    if (!options) apiFetch<ProjectOptions>("/v1/projects/options").then(setOptions).catch(() => setOptions({ people: [], contracted_services: [] }));
  }

  async function transition(project: Project, move: ProjectMove, reason?: string) {
    setBusy(true);
    try {
      await apiFetch(`/v1/projects/${project.id}/${move.path}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(move.done);
      setReasoning(null);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
      // También tras un error: si otra persona lo cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  /** Mover una tarjeta a otra columna (arrastrando o con "Mover a…"). */
  function moveTo(project: Project, target: string) {
    if (target === project.status) return;
    const label = (state: string) => stateOf(PROJECT_STATES, state)[0];
    const move = PROJECT_MOVES.find((item) => item.to === target && item.from.includes(project.status));

    if (!move) toast(`Transición no permitida: ${label(project.status)} → ${label(target)}`, "danger");
    else if (!allowed(move)) toast(`Tu rol no tiene permiso ${move.permission}`, "danger");
    else if (move.reason) setReasoning({ project, move });
    else transition(project, move);
  }

  const movesOf = (project: Project) => PROJECT_MOVES.filter((move) => move.to !== "CANCELLED" && move.from.includes(project.status) && allowed(move));

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Project[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Project>("/v1/projects", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status }, false)), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("projects", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("proyectos", "Proyectos", EXPORT_COLUMNS, rows);
      else exportCsv("proyectos", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Proyectos"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={openCreate} permission="projects.create">
              <Plus size={16} />
              <span>Nuevo proyecto</span>
            </Btn>
          </>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar proyecto o cliente" />
        {!board && (
          <>
            <FilterSelect
              label="Estado"
              value={status}
              onChange={(value) => {
                setStatus(value);
                setPage(1);
              }}
              options={PROJECT_STATES.map(([key, label]) => [key, label])}
            />
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
          </>
        )}
        <ScopeNote resource="projects" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra los {rows.length} proyectos más recientes de {data.total}. Usa el buscador para acotar.</Notice>
          )}
          <Kanban
            columns={BOARD_COLUMNS}
            cards={rows}
            cardKey={projectKey}
            columnOf={(project) => project.status}
            canDrag={(project) => PROJECT_OPEN.includes(project.status) && movesOf(project).length > 0}
            onDrop={moveTo}
            loading={loading && !data}
            refreshing={loading}
            renderCard={(project) => (
              <>
                <Link href={`/projects/${project.id}`} className="font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline">
                  {project.name}
                </Link>
                <small className="app-muted">{project.code} · {customerName(project.customer)}</small>
                <ProgressBar percent={project.metrics.progress} />
                <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-[12.5px]">
                  <span><b className="font-semibold">{project.metrics.done}/{project.metrics.tasks}</b> tareas</span>
                  <span><b className="font-semibold">{hoursText(project.metrics.hours)}</b> aprobadas</span>
                </div>
                <div className="text-[12.5px]">Margen actual: <strong className="font-semibold"><Margin project={project} /></strong></div>
                <div className="text-[12.5px]"><Who name={project.manager?.name} /></div>
                {movesOf(project).length > 0 && (
                  <PickSelect
                    className="app-muted flex h-[30px] w-full cursor-pointer items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs"
                    ariaLabel={`Mover proyecto ${project.code}`}
                    value=""
                    onChange={(target) => moveTo(project, target)}
                    options={[["", "Mover a…"], ...movesOf(project).map((move): [string, string] => [move.to, stateOf(PROJECT_STATES, move.to)[0]])]}
                    disabled={busy}
                  />
                )}
                {movesOf(project).length === 0 && PROJECT_MOVES.some((move) => move.to !== "CANCELLED" && move.from.includes(project.status)) && <MoveLocked permission="projects.manage" />}
              </>
            )}
          />
          <p className="app-muted text-xs">Arrastra una tarjeta para cambiar de estado. Solo se permiten transiciones válidas; los cancelados se ven en la tabla.</p>
        </>
      ) : (
        <Card flush>
          <SelectionBar permission="projects.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="projects" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={rows}
            rowKey={projectKey}
            selection={selection}
            onRowClick={(project) => router.push(`/projects/${project.id}`)}
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
              left={<ExportButtons permission="projects.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <ProjectFormModal
          options={options}
          // El aviso de "creado" se da en la ficha a la que se navega; aquí solo los errores.
          toast={(text, tone, detail) => { if (tone && tone !== "success") toast(text, tone, detail); }}
          onClose={() => setCreating(false)}
          onSaved={(project) => {
            setFlash("Proyecto creado");
            router.push(`/projects/${project.id}`);
          }}
        />
      )}
      {reasoning && (
        <ReasonModal
          title={`${reasoning.move.label} ${reasoning.project.code}`}
          text={reasoning.project.name}
          label={reasoning.move.reason ?? "Motivo"}
          confirmLabel={reasoning.move.label}
          busy={busy}
          onClose={() => setReasoning(null)}
          onConfirm={(reason) => transition(reasoning.project, reasoning.move, reason)}
        />
      )}
      {toasts}
    </Screen>
  );
}
