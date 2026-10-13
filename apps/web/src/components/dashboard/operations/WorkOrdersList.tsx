"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Plus } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, excelTone, setFlash, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, firstError, type ViewMode } from "@/lib/crm";
import {
  PRIORITIES, WORK_ORDER_MOVES, WORK_ORDER_OPEN, WORK_ORDER_STATES, checklistProgress, scheduledText, type WorkOrderMove,
} from "@/lib/operations";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { Kanban, ViewSwitch, useStoredView, type KanbanColumn } from "@/components/ui/kanban";
import { PickSelect } from "@/components/ui/pick-select";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { AssignWorkOrderModal, WorkOrderFormModal } from "@/components/dashboard/operations/WorkOrderModals";
import type { PaginatedResponse } from "@/types/customer";
import type { WorkOrder, WorkOrderOptions } from "@/types/operations";

// El tablero muestra hasta este número de órdenes; con más, se acota con el buscador.
const BOARD_LIMIT = 200;

// Las canceladas no tienen columna: se ven en la tabla.
const BOARD_COLUMNS: KanbanColumn[] = [
  { id: "PLANNED", label: "Planificadas", tone: "neutral" },
  { id: "ASSIGNED", label: "Asignadas", tone: "info" },
  { id: "IN_PROGRESS", label: "En ejecución", tone: "accent" },
  { id: "BLOCKED", label: "Bloqueadas", tone: "warning" },
  { id: "COMPLETED", label: "Completadas", tone: "success" },
];

export function PriorityChip({ priority }: { priority: string }) {
  const [label, tone] = stateOf(PRIORITIES, priority);
  return <Chip tone={tone}>{label}</Chip>;
}

/** Barra de avance del checklist de una orden. */
export function ChecklistBar({ order }: { order: Pick<WorkOrder, "checklist"> }) {
  const percent = checklistProgress(order);
  if (percent === null) return null;

  return (
    <div className="grid gap-1">
      <div className="h-1.5 overflow-hidden rounded-full bg-[var(--app-surface-muted)]" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label="Avance del checklist">
        <div className={`h-full rounded-full ${percent === 100 ? "bg-[var(--p-success)]" : "bg-[#1B8FC9]"}`} style={{ width: `${percent}%` }} />
      </div>
      <small className="app-muted text-xs">Checklist {percent}%</small>
    </div>
  );
}

const COLUMNS: Column<WorkOrder>[] = [
  {
    label: "OT", sortKey: "order", filter: "text",
    render: (order) => (
      <div className="min-w-[180px]">
        <strong className="font-semibold">{order.code}</strong>
        <small className="app-muted block text-xs">{order.title}</small>
      </div>
    ),
  },
  { label: "Cliente", sortKey: "customer", filter: "text", render: (order) => customerName(order.customer) },
  { label: "Programada", sortKey: "scheduled", filter: "date", render: (order) => <span className="whitespace-nowrap">{scheduledText(order.scheduled_start)}</span> },
  { label: "Prioridad", sortKey: "priority", filter: { top: "Prioridad" }, render: (order) => <PriorityChip priority={order.priority} /> },
  { label: "Técnico", sortKey: "technician", filter: { top: "Técnico" }, render: (order) => <Who name={order.assignee?.name} /> },
  {
    label: "Checklist",
    render: (order) => (order.checklist ? <span className="whitespace-nowrap tabular-nums">{order.checklist.done}/{order.checklist.total}</span> : <span className="app-muted">—</span>),
  },
  {
    label: "Estado", sortKey: "status", filter: { top: "Estado" },
    render: (order) => {
      const [label, tone] = stateOf(WORK_ORDER_STATES, order.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<WorkOrder>[] = [
  { label: "OT", value: (order) => order.code, width: 16, kind: "center" },
  { label: "Título", value: (order) => order.title, width: 38 },
  { label: "Cliente", value: (order) => customerName(order.customer), width: 34 },
  { label: "Programada", value: (order) => (order.scheduled_start ? dateTime(order.scheduled_start) : ""), width: 22, kind: "center" },
  { label: "Lugar", value: (order) => order.location ?? "", width: 26 },
  { label: "Prioridad", value: (order) => stateOf(PRIORITIES, order.priority)[0], width: 12, kind: "center" },
  { label: "Técnico", value: (order) => order.assignee?.name ?? "", width: 26 },
  // "2 de 2" y no "2/2": al abrir el CSV, Excel lee "2/2" como una fecha.
  { label: "Checklist", value: (order) => (order.checklist ? `${order.checklist.done} de ${order.checklist.total}` : ""), width: 12, kind: "center" },
  {
    label: "Estado",
    value: (order) => stateOf(WORK_ORDER_STATES, order.status)[0],
    width: 16,
    kind: "status",
    tone: (order) => excelTone(stateOf(WORK_ORDER_STATES, order.status)[1]),
  },
  { label: "Motivo", value: (order) => order.cancel_reason ?? order.blocked_reason ?? "", width: 32 },
];

const orderKey = (order: WorkOrder) => order.id;

type TopFilters = { search: string; status: string; priority: string; technician: string };

function withTopFilters(params: URLSearchParams, top: TopFilters, forBoard: boolean) {
  if (top.search) params.set("search", top.search);
  if (top.priority) params.set("priority", top.priority);
  if (top.technician) params.set("technician", top.technician);
  if (top.status && !forBoard) params.set("status", top.status);
  return params;
}

export default function WorkOrdersList() {
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("work-orders", "kanban");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [priority, setPriority] = useState("");
  const [technician, setTechnician] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(orderKey);

  const [data, setData] = useState<PaginatedResponse<WorkOrder> | null>(null);
  const [options, setOptions] = useState<WorkOrderOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [creating, setCreating] = useState(false);
  const [assigning, setAssigning] = useState<WorkOrder | null>(null);
  const [reasoning, setReasoning] = useState<{ order: WorkOrder; move: WorkOrderMove } | null>(null);
  const [busy, setBusy] = useState(false);

  const board = view === "kanban";
  const canManage = !accessLoading && can("work_orders.manage");
  const canAssign = !accessLoading && can("work_orders.approve");

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

  // Listas de los filtros y de los formularios: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<WorkOrderOptions>("/v1/work-orders/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const size = board ? { per_page: String(BOARD_LIMIT), page: "1" } : { per_page: String(perPage), page: String(page) };
      const params = withTopFilters(new URLSearchParams(size), { search, status, priority, technician }, board);
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<WorkOrder>>(`/v1/work-orders?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las órdenes de trabajo.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [board, page, perPage, search, status, priority, technician, columnFilters.param, sort, reload]);

  function changeView(mode: ViewMode) {
    // Pulsar la vista que ya está activa no cambia nada (si se vaciara la lista, nadie la volvería a pedir).
    if (mode === view) return;
    setData(null);
    setPage(1);
    setView(mode);
  }

  async function transition(order: WorkOrder, move: WorkOrderMove, reason?: string) {
    setBusy(true);
    try {
      await apiFetch(`/v1/work-orders/${order.id}/${move.path}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(move.done);
      setReasoning(null);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
      // También tras un error: si otra persona la cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  /** Mover una tarjeta a otra columna (arrastrando o con "Mover a…"). */
  function moveTo(order: WorkOrder, target: string) {
    if (target === order.status) return;
    const label = (state: string) => stateOf(WORK_ORDER_STATES, state)[0];

    // Pasar a "Asignadas" es asignar: pide técnico (y su permiso).
    if (target === "ASSIGNED" && order.status === "PLANNED") {
      if (canAssign) setAssigning(order);
      else toast("Tu rol no tiene permiso work_orders.approve", "danger");
      return;
    }

    const move = WORK_ORDER_MOVES.find((item) => item.to === target && item.from.includes(order.status));
    if (!move) toast(`Transición no permitida: ${label(order.status)} → ${label(target)}`, "danger");
    else if (!canManage) toast("Tu rol no tiene permiso work_orders.manage", "danger");
    else if (move.reason) setReasoning({ order, move });
    else transition(order, move);
  }

  function moveOptions(order: WorkOrder): [string, string][] {
    const targets = BOARD_COLUMNS.filter((column) =>
      column.id === "ASSIGNED" && order.status === "PLANNED"
        ? canAssign
        : canManage && WORK_ORDER_MOVES.some((move) => move.to === column.id && move.from.includes(order.status)));
    return [["", "Mover a…"], ...targets.map((column): [string, string] => [column.id, column.label])];
  }

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: WorkOrder[]) {
    setExporting(true);
    try {
      const top = { search, status, priority, technician };
      const rows = picked ?? (await fetchAllPages<WorkOrder>("/v1/work-orders", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), top, false)), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("work_orders", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("ordenes-de-trabajo", "Órdenes de trabajo", EXPORT_COLUMNS, rows);
      else exportCsv("ordenes-de-trabajo", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const rows = data?.data ?? [];
  const movable = (order: WorkOrder) => WORK_ORDER_OPEN.includes(order.status) && (canManage || (canAssign && order.status === "PLANNED"));

  return (
    <Screen>
      <PageHead
        title="Órdenes de trabajo"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={() => setCreating(true)} permission="work_orders.create">
              <Plus size={16} />
              <span>Nueva OT</span>
            </Btn>
          </>
        }
      />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar OT, título o cliente" />
        <FilterSelect
          label="Técnico"
          value={technician}
          onChange={(value) => {
            setTechnician(value);
            setPage(1);
          }}
          options={[["none", "Sin asignar"], ...(options?.technicians ?? []).map((user): [string, string] => [user.id, user.name])]}
        />
        <FilterSelect
          label="Prioridad"
          value={priority}
          onChange={(value) => {
            setPriority(value);
            setPage(1);
          }}
          options={PRIORITIES.map(([key, label]) => [key, label])}
        />
        {!board && (
          <>
            <FilterSelect
              label="Estado"
              value={status}
              onChange={(value) => {
                setStatus(value);
                setPage(1);
              }}
              options={WORK_ORDER_STATES.map(([key, label]) => [key, label])}
            />
            <ColumnFilterBar
              filters={columnFilters}
              hasOtherFilters={Boolean(searchInput || status || priority || technician)}
              onClearAll={() => {
                setSearchInput("");
                setSearch("");
                setStatus("");
                setPriority("");
                setTechnician("");
                setPage(1);
              }}
            />
          </>
        )}
        <ScopeNote resource="work_orders" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra las {rows.length} órdenes más recientes de {data.total}. Usa el buscador para acotar.</Notice>
          )}
          <Kanban
            columns={BOARD_COLUMNS}
            cards={rows}
            cardKey={orderKey}
            columnOf={(order) => order.status}
            canDrag={movable}
            onDrop={moveTo}
            loading={loading && !data}
            refreshing={loading}
            renderCard={(order) => (
              <>
                <Link href={`/work-orders/${order.id}`} className="font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline">
                  {order.title}
                </Link>
                <small className="app-muted">{order.code} · {customerName(order.customer)}</small>
                <div className="app-muted flex flex-wrap items-center justify-between gap-1.5 text-xs">
                  <PriorityChip priority={order.priority} />
                  <span>{scheduledText(order.scheduled_start)}</span>
                </div>
                <ChecklistBar order={order} />
                <div className="text-[12.5px]"><Who name={order.assignee?.name} /></div>
                {movable(order) && moveOptions(order).length > 1 && (
                  <PickSelect
                    className="app-muted flex h-[30px] w-full cursor-pointer items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs"
                    ariaLabel={`Mover orden ${order.code}`}
                    value=""
                    onChange={(target) => moveTo(order, target)}
                    options={moveOptions(order)}
                    disabled={busy}
                  />
                )}
              </>
            )}
          />
          <p className="app-muted text-xs">Arrastra una tarjeta para cambiar de estado. Solo se permiten transiciones válidas; las canceladas se ven en la tabla.</p>
        </>
      ) : (
        <Card flush>
          <SelectionBar permission="work_orders.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="work-orders" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={rows}
            rowKey={orderKey}
            selection={selection}
            onRowClick={(order) => router.push(`/work-orders/${order.id}`)}
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
              left={<ExportButtons permission="work_orders.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <WorkOrderFormModal
          options={options}
          // El aviso de "creada" se da en la ficha a la que se navega; aquí solo los errores.
          toast={(text, tone, detail) => { if (tone && tone !== "success") toast(text, tone, detail); }}
          onClose={() => setCreating(false)}
          onSaved={(order) => {
            setFlash("Orden de trabajo creada");
            router.push(`/work-orders/${order.id}`);
          }}
        />
      )}
      {assigning && (
        <AssignWorkOrderModal
          order={assigning}
          technicians={options?.technicians ?? null}
          toast={toast}
          onClose={() => setAssigning(null)}
          onSaved={() => {
            setAssigning(null);
            setReload((n) => n + 1);
          }}
        />
      )}
      {reasoning && (
        <ReasonModal
          title={`${reasoning.move.label} ${reasoning.order.code}`}
          text={reasoning.order.title}
          label={reasoning.move.reason ?? "Motivo"}
          confirmLabel={reasoning.move.label}
          busy={busy}
          onClose={() => setReasoning(null)}
          onConfirm={(reason) => transition(reasoning.order, reasoning.move, reason)}
        />
      )}
      {toasts}
    </Screen>
  );
}
