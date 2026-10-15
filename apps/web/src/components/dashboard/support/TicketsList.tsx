"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { AlertTriangle, CheckCircle2, Clock, LifeBuoy, Plus } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, excelTone, setFlash, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, type ViewMode } from "@/lib/crm";
import {
  CATEGORIES, CHANNELS, PRIORITY_FILTER, PRIORITY_LABELS, SLA_FILTER, TICKET_STATES, TICKET_STATUS_FILTER, slaView,
} from "@/lib/support";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Who, applySort, useRowSelection, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { Kanban, ViewSwitch, useStoredView, type KanbanColumn } from "@/components/ui/kanban";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { PriorityTag, SlaCell, useNow } from "@/components/dashboard/support/SupportParts";
import { NewTicketModal } from "@/components/dashboard/support/TicketModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Ticket, TicketOptions, TicketSummary } from "@/types/support";

// El tablero muestra hasta este número de tickets; con más, se acota con el buscador.
const BOARD_LIMIT = 200;

// Las columnas del tablero del prototipo; los cerrados se ven en la lista y los reabiertos van con los asignados.
const BOARD_COLUMNS: KanbanColumn[] = [
  { id: "OPEN", label: "Abiertos", tone: "info" },
  { id: "TRIAGED", label: "Clasificados", tone: "info" },
  { id: "ASSIGNED", label: "Asignados", tone: "accent" },
  { id: "IN_PROGRESS", label: "En atención", tone: "accent" },
  { id: "WAITING_CUSTOMER", label: "Esperando cliente", tone: "warning" },
  { id: "RESOLVED", label: "Resueltos", tone: "success" },
];

const ticketKey = (ticket: Ticket) => ticket.id;

function StatusBadge({ status }: { status: string }) {
  const [label, tone] = stateOf(TICKET_STATES, status);
  return <Badge tone={tone}>{label}</Badge>;
}

function columns(now: number): Column<Ticket>[] {
  return [
    {
      label: "Ticket", sortKey: "ticket", filter: "text",
      render: (ticket) => (
        <div className="min-w-[170px]">
          <strong className="font-semibold">{ticket.subject}</strong>
          <small className="app-muted block text-xs">{ticket.number} · {CHANNELS[ticket.channel] ?? ticket.channel} · {CATEGORIES[ticket.category] ?? ticket.category}</small>
        </div>
      ),
    },
    { label: "Cliente", sortKey: "customer", filter: "text", render: (ticket) => customerName(ticket.customer) },
    { label: "Prioridad", sortKey: "priority", filter: { top: "Prioridad" }, render: (ticket) => <PriorityTag priority={ticket.priority} /> },
    { label: "SLA", sortKey: "sla", filter: { top: "SLA" }, render: (ticket) => <SlaCell sla={ticket.sla} now={now} /> },
    { label: "Asignado", sortKey: "assignee", render: (ticket) => <Who name={ticket.assignee?.name} /> },
    { label: "Estado", sortKey: "status", filter: { top: "Estado" }, render: (ticket) => <StatusBadge status={ticket.status} /> },
  ];
}

const EXPORT_COLUMNS: ExportColumn<Ticket>[] = [
  { label: "Ticket", value: (ticket) => ticket.number, width: 16, kind: "center" },
  { label: "Asunto", value: (ticket) => ticket.subject, width: 40 },
  { label: "Cliente", value: (ticket) => customerName(ticket.customer), width: 32 },
  { label: "Canal", value: (ticket) => CHANNELS[ticket.channel] ?? ticket.channel, width: 13, kind: "center" },
  { label: "Categoría", value: (ticket) => CATEGORIES[ticket.category] ?? ticket.category, width: 15, kind: "center" },
  { label: "Prioridad", value: (ticket) => (ticket.priority ? `${ticket.priority} ${PRIORITY_LABELS[ticket.priority]}` : ""), width: 13, kind: "center" },
  { label: "SLA", value: (ticket) => slaView(ticket.sla).label, width: 28 },
  { label: "Vence", value: (ticket) => (ticket.sla.resolution_due_at ? dateTime(ticket.sla.resolution_due_at) : ""), width: 22, kind: "center" },
  { label: "Asignado", value: (ticket) => ticket.assignee?.name ?? "", width: 26 },
  {
    label: "Estado", value: (ticket) => stateOf(TICKET_STATES, ticket.status)[0], width: 18, kind: "status",
    tone: (ticket) => excelTone(stateOf(TICKET_STATES, ticket.status)[1]),
  },
  { label: "Abierto", value: (ticket) => (ticket.opened_at ? dateTime(ticket.opened_at) : ""), width: 22, kind: "center" },
];

type TopFilters = { search: string; status: string; priority: string; sla: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.priority) params.set("priority", top.priority);
  if (top.sla) params.set("sla", top.sla);
  return params;
}

export default function TicketsList() {
  const router = useRouter();
  const { toast, toasts } = useToasts();
  const now = useNow();
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("tickets", "table");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [priority, setPriority] = useState("");
  const [sla, setSla] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(ticketKey);

  const [data, setData] = useState<PaginatedResponse<Ticket> | null>(null);
  const [summary, setSummary] = useState<TicketSummary | null>(null);
  const [options, setOptions] = useState<TicketOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);

  const board = view === "kanban";

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
      const params = withTopFilters(new URLSearchParams(size), { search, status, priority, sla });
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<Ticket>>(`/v1/tickets?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los tickets.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [board, page, perPage, search, status, priority, sla, columnFilters.param, sort, reload]);

  // Indicadores y listas del formulario: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<TicketSummary>("/v1/tickets/summary").then((next) => { if (!cancelled) setSummary(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  function openNew() {
    setCreating(true);
    if (!options) apiFetch<TicketOptions>("/v1/tickets/options").then(setOptions).catch(() => toast("No se pudieron cargar las listas del formulario.", "danger"));
  }

  function changeView(mode: ViewMode) {
    if (mode === view) return;
    setData(null);
    setPage(1);
    setView(mode);
  }

  async function runExport(format: "xlsx" | "csv", picked?: Ticket[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Ticket>("/v1/tickets", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, priority, sla })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("tickets", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("tickets", "Tickets y SLA", EXPORT_COLUMNS, rows);
      else exportCsv("tickets", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const rows = data?.data ?? [];
  const kpi = (value: number | undefined) => (summary ? value : "…");

  return (
    <Screen>
      <PageHead
        title="Tickets y SLA"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={openNew} permission="tickets.create">
              <Plus size={16} />
              <span>Nuevo ticket</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi label="Tickets abiertos" value={kpi(summary?.open)} icon={<LifeBuoy size={22} />} />
        <Kpi label="SLA vencido" value={kpi(summary?.breached)} icon={<AlertTriangle size={22} />} tone="danger" />
        <Kpi label="En riesgo" value={kpi(summary?.risk)} icon={<Clock size={22} />} tone="warning" />
        <Kpi label="Resueltos" value={kpi(summary?.resolved)} icon={<CheckCircle2 size={22} />} tone="success" />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar ticket, asunto o cliente" />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={TICKET_STATUS_FILTER} />
        <FilterSelect label="Prioridad" value={priority} onChange={(value) => { setPriority(value); setPage(1); }} options={PRIORITY_FILTER} />
        <FilterSelect label="SLA" value={sla} onChange={(value) => { setSla(value); setPage(1); }} options={SLA_FILTER} />
        {!board && (
          <ColumnFilterBar
            filters={columnFilters}
            hasOtherFilters={Boolean(searchInput || status || priority || sla)}
            onClearAll={() => {
              setSearchInput("");
              setSearch("");
              setStatus("");
              setPriority("");
              setSla("");
              setPage(1);
            }}
          />
        )}
        <ScopeNote resource="tickets" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra los {rows.length} tickets más urgentes de {data.total}. Usa el buscador para acotar.</Notice>
          )}
          <Kanban
            columns={BOARD_COLUMNS}
            cards={rows}
            cardKey={ticketKey}
            columnOf={(ticket) => (ticket.status === "REOPENED" ? "ASSIGNED" : ticket.status)}
            loading={loading && !data}
            refreshing={loading}
            narrow
            renderCard={(ticket) => (
              <>
                <Link href={`/tickets/${ticket.id}`} className="font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline">
                  {ticket.subject}
                </Link>
                <small className="app-muted">{ticket.number} · {customerName(ticket.customer)}</small>
                <div className="flex flex-wrap items-center gap-1.5">
                  <PriorityTag priority={ticket.priority} />
                  {ticket.status === "REOPENED" && <Chip tone="danger">Reabierto</Chip>}
                </div>
                <SlaCell sla={ticket.sla} now={now} />
                <div className="text-[12.5px]"><Who name={ticket.assignee?.name} /></div>
              </>
            )}
          />
          <p className="app-muted text-xs">Los cambios de estado se hacen desde la ficha del ticket; los cerrados se ven en la lista.</p>
        </>
      ) : (
        <Card flush>
          <SelectionBar permission="tickets.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="tickets" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={columns(now)}
            rows={rows}
            rowKey={ticketKey}
            selection={selection}
            onRowClick={(ticket) => router.push(`/tickets/${ticket.id}`)}
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
              left={<ExportButtons permission="tickets.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <NewTicketModal
          options={options}
          toast={(text, tone, detail) => { if (tone && tone !== "success") toast(text, tone, detail); }}
          onClose={() => setCreating(false)}
          onSaved={(ticket) => {
            setFlash("Ticket creado");
            router.push(`/tickets/${ticket.id}`);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
