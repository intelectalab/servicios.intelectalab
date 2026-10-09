"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Bell, Check, Filter as Funnel, Plus, Target } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, setFlash, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { LEAD_OPEN, LEAD_STATES, firstError, leadName, relativeDay, type ViewMode } from "@/lib/crm";
import { exportCsv, exportXlsx, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Who, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { Kanban, ViewSwitch, useStoredView, type KanbanColumn } from "@/components/ui/kanban";
import { PickSelect } from "@/components/ui/pick-select";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { LeadFormModal, QualifyLeadModal, ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Lead, LeadOptions, LeadStatus, LeadSummary } from "@/types/crm";

// El tablero muestra hasta este número de leads; con más, se acota con los filtros.
const BOARD_LIMIT = 200;

const BOARD_COLUMNS: KanbanColumn[] = [
  { id: "NEW", label: "Nuevos", tone: "info" },
  { id: "CONTACTED", label: "Contactados", tone: "warning" },
  { id: "QUALIFIED", label: "Calificados", tone: "success" },
  { id: "CONVERTED", label: "Convertidos", tone: "success" },
  { id: "DISQUALIFIED", label: "Descartados", tone: "muted" },
];

// Cambios de estado que no piden nada más: estado de destino → ruta y estados desde los que se llega.
const MOVES: Partial<Record<LeadStatus, { path: string; from: LeadStatus[] }>> = {
  CONTACTED: { path: "contact", from: ["NEW"] },
  QUALIFIED: { path: "qualify", from: ["NEW", "CONTACTED"] },
};

/** El puntaje automático llega con la pantalla "Puntuación de leads". */
const SCORE_PENDING = <span className="app-muted" title="Disponible cuando exista la pantalla Puntuación de leads">—</span>;

/** Si la empresa del lead ya está en Clientes y actores (CORE). */
function CoreCell({ lead, fallback = null }: { lead: Lead; fallback?: ReactNode }) {
  if (lead.party_id) return <span title="Enlazado a una identidad de Clientes y actores"><Chip tone="accent">En CORE</Chip></span>;
  if (lead.core_party_id) return <span title="Ya existe en CORE"><Chip tone="warning">Coincidencia</Chip></span>;
  return <>{fallback}</>;
}

const COLUMNS: Column<Lead>[] = [
  {
    label: "Lead", sortKey: "lead", filter: "text",
    render: (lead) => (
      <div className="min-w-[180px]">
        <strong className="font-semibold">{leadName(lead)}</strong>
        <small className="app-muted block text-xs">
          {[lead.lead_number, [lead.organization_name ? lead.person_name : null, lead.position].filter(Boolean).join(", ")].filter(Boolean).join(" · ")}
        </small>
      </div>
    ),
  },
  {
    label: "Correo / teléfono", sortKey: "contact", filter: "text",
    render: (lead) => (
      <div>
        {lead.email || "—"}
        {lead.phone && <small className="app-muted block text-xs">{lead.phone}</small>}
      </div>
    ),
  },
  { label: "Fuente", sortKey: "source", filter: { top: "Fuente" }, render: (lead) => lead.source?.name ?? "—" },
  { label: "Interés", sortKey: "interest", filter: "text", render: (lead) => lead.interest || "—" },
  { label: "Puntaje", num: true, render: () => SCORE_PENDING },
  { label: "CORE", render: (lead) => <CoreCell lead={lead} fallback={<span className="app-muted text-xs">Nuevo</span>} /> },
  { label: "Responsable", sortKey: "owner", filter: { top: "Responsable" }, render: (lead) => <Who name={lead.owner?.name} /> },
  { label: "Creado", sortKey: "created", filter: "date", render: (lead) => <span className="whitespace-nowrap">{shortDate(lead.created_at)}</span> },
  {
    label: "Estado", sortKey: "status", filter: { top: "Estado" },
    render: (lead) => {
      const [label, tone] = stateOf(LEAD_STATES, lead.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Lead>[] = [
  { label: "Lead", value: (lead) => lead.lead_number, width: 16, kind: "center" },
  { label: "Empresa", value: (lead) => lead.organization_name ?? "", width: 34 },
  { label: "Contacto", value: (lead) => lead.person_name ?? "", width: 26 },
  { label: "Cargo", value: (lead) => lead.position ?? "", width: 22 },
  { label: "Correo", value: (lead) => lead.email ?? "", width: 30 },
  { label: "Teléfono", value: (lead) => lead.phone ?? "", width: 16 },
  { label: "Fuente", value: (lead) => lead.source?.name ?? "", width: 18 },
  { label: "Interés", value: (lead) => lead.interest ?? "", width: 26 },
  { label: "CORE", value: (lead) => (lead.party_id ? "En CORE" : lead.core_party_id ? "Coincidencia" : "Nuevo"), width: 14, kind: "center" },
  { label: "Responsable", value: (lead) => lead.owner?.name ?? "", width: 24 },
  { label: "Creado", value: (lead) => shortDate(lead.created_at), width: 15, kind: "center" },
  {
    label: "Estado",
    value: (lead) => stateOf(LEAD_STATES, lead.status)[0],
    width: 24,
    kind: "status",
    tone: (lead) => excelTone(stateOf(LEAD_STATES, lead.status)[1]),
  },
  { label: "Motivo de descarte", value: (lead) => lead.discard_reason ?? "", width: 32 },
];

const leadKey = (lead: Lead) => lead.id;

type TopFilters = { search: string; status: string; sourceId: string; ownerId: string };

/** Filtros de arriba; el tablero reparte por estado, así que ahí el de estado no aplica. */
function withTopFilters(params: URLSearchParams, top: TopFilters, forBoard: boolean) {
  if (top.search) params.set("search", top.search);
  if (top.status && !forBoard) params.set("status", top.status);
  if (top.sourceId) params.set("source_id", top.sourceId);
  if (top.ownerId) params.set("owner_id", top.ownerId);
  return params;
}

export default function LeadsList() {
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("leads", "table");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [sourceId, setSourceId] = useState("");
  const [ownerId, setOwnerId] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(leadKey);

  const [data, setData] = useState<PaginatedResponse<Lead> | null>(null);
  const [summary, setSummary] = useState<LeadSummary | null>(null);
  const [options, setOptions] = useState<LeadOptions>({ sources: [], owners: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [creating, setCreating] = useState(false);
  const [qualifying, setQualifying] = useState<Lead | null>(null);
  const [discarding, setDiscarding] = useState<Lead | null>(null);
  const [busy, setBusy] = useState(false);

  const board = view === "kanban";
  const canManage = !accessLoading && can("leads.manage");
  const canQualify = !accessLoading && can("opportunities.manage");

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
    Promise.all([apiFetch<LeadSummary>("/v1/crm/leads/summary"), apiFetch<LeadOptions>("/v1/crm/leads/options")])
      .then(([nextSummary, nextOptions]) => {
        if (cancelled) return;
        setSummary(nextSummary);
        setOptions(nextOptions);
      })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const size = board ? { per_page: String(BOARD_LIMIT), page: "1" } : { per_page: String(perPage), page: String(page) };
      const params = withTopFilters(new URLSearchParams(size), { search, status, sourceId, ownerId }, board);
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Lead>>(`/v1/crm/leads?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los leads.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [board, page, perPage, search, status, sourceId, ownerId, columnFilters.param, sort, reload]);

  function changeView(mode: ViewMode) {
    setData(null);
    setPage(1);
    setView(mode);
  }

  /** Cambia el estado del lead (contactar, calificar o descartar con su motivo). */
  async function transition(lead: Lead, path: string, done: string, reason?: string) {
    setBusy(true);
    try {
      await apiFetch(`/v1/crm/leads/${lead.id}/${path}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(done);
      setDiscarding(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  /** Mover una tarjeta a otra columna (arrastrando o con "Mover a…"). */
  function moveTo(lead: Lead, target: string) {
    if (target === lead.status) return;
    const label = (state: string) => stateOf(LEAD_STATES, state)[0];
    const open = LEAD_OPEN.includes(lead.status);

    if (target === "CONVERTED" && open) {
      if (canQualify) setQualifying(lead);
      else toast("Tu rol no tiene permiso opportunities.manage", "danger");
    } else if (target === "DISQUALIFIED" && open) {
      setDiscarding(lead);
    } else if (MOVES[target as LeadStatus]?.from.includes(lead.status)) {
      transition(lead, MOVES[target as LeadStatus]!.path, "Lead actualizado");
    } else {
      toast(`Transición no permitida: ${label(lead.status)} → ${label(target)}`, "danger");
    }
  }

  /** Destinos válidos desde el estado del lead, para su "Mover a…". */
  function moveOptions(lead: Lead): [string, string][] {
    const targets = BOARD_COLUMNS.filter((column) =>
      column.id !== lead.status && (column.id === "CONVERTED" ? canQualify : column.id === "DISQUALIFIED" || MOVES[column.id as LeadStatus]?.from.includes(lead.status)));
    return [["", "Mover a…"], ...targets.map((column): [string, string] => [column.id, column.label])];
  }

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Lead[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Lead>("/v1/crm/leads", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status, sourceId, ownerId }, false)), sort)));
      if (format === "xlsx") await exportXlsx("leads", "Leads", EXPORT_COLUMNS, rows);
      else exportCsv("leads", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const count = (state: LeadStatus) => summary?.by_status[state] ?? 0;
  const shown = (value: number) => (summary ? value : "—");
  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Leads"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={() => setCreating(true)} permission="leads.manage">
              <Plus size={16} />
              <span>Nuevo lead</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi label="Total leads" value={shown(summary?.total ?? 0)} icon={<Target size={24} />} />
        <Kpi label="Nuevos sin contactar" value={shown(count("NEW"))} icon={<Bell size={24} />} tone="warning" />
        <Kpi label="Calificados" value={shown(count("QUALIFIED"))} icon={<Check size={24} />} tone="success" />
        <Kpi label="Convertidos" value={shown(count("CONVERTED"))} icon={<Funnel size={24} />} />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar empresa, contacto o correo" />
        {!board && (
          <FilterSelect
            label="Estado"
            value={status}
            onChange={(value) => {
              setStatus(value);
              setPage(1);
            }}
            options={LEAD_STATES.map(([key, label]) => [key, label])}
          />
        )}
        <FilterSelect
          label="Fuente"
          value={sourceId}
          onChange={(value) => {
            setSourceId(value);
            setPage(1);
          }}
          options={options.sources.map((source) => [source.id, source.name])}
        />
        <FilterSelect
          label="Responsable"
          value={ownerId}
          onChange={(value) => {
            setOwnerId(value);
            setPage(1);
          }}
          options={options.owners.map((owner) => [owner.id, owner.name])}
        />
        {!board && (
          <ColumnFilterBar
            filters={columnFilters}
            hasOtherFilters={Boolean(searchInput || status || sourceId || ownerId)}
            onClearAll={() => {
              setSearchInput("");
              setSearch("");
              setStatus("");
              setSourceId("");
              setOwnerId("");
              setPage(1);
            }}
          />
        )}
        <ScopeNote resource="leads" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra los {rows.length} leads más recientes de {data.total}. Usa el buscador o los filtros para acotar.</Notice>
          )}
          <Kanban
            columns={BOARD_COLUMNS}
            cards={rows}
            cardKey={leadKey}
            columnOf={(lead) => lead.status}
            canDrag={(lead) => canManage && LEAD_OPEN.includes(lead.status)}
            onDrop={moveTo}
            loading={loading && !data}
            refreshing={loading}
            renderCard={(lead) => (
              <>
                <Link href={`/crm/leads/${lead.id}`} className="font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline">
                  {leadName(lead)}
                </Link>
                <small className="app-muted">
                  {[lead.organization_name ? lead.person_name : lead.lead_number, lead.position].filter(Boolean).join(" · ") || lead.lead_number}
                </small>
                <div className="flex items-center justify-between gap-1.5 text-[12.5px]">
                  <Chip>{lead.source?.name ?? "Sin fuente"}</Chip>
                  {SCORE_PENDING}
                </div>
                <CoreCell lead={lead} />
                <div className="app-muted flex flex-wrap items-center justify-between gap-1.5 text-xs">
                  <Who name={lead.owner?.name} />
                  <small>{relativeDay(lead.created_at)}</small>
                </div>
                {canManage && LEAD_OPEN.includes(lead.status) && (
                  <PickSelect
                    className="app-muted flex h-[30px] w-full cursor-pointer items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs"
                    ariaLabel={`Mover lead ${leadName(lead)}`}
                    value=""
                    onChange={(target) => moveTo(lead, target)}
                    options={moveOptions(lead)}
                    disabled={busy}
                  />
                )}
              </>
            )}
          />
          <p className="app-muted text-xs">
            Arrastra para cambiar el estado. Soltar en &quot;Convertidos&quot; abre la calificación a oportunidad; en &quot;Descartados&quot; pide motivo.
          </p>
        </>
      ) : (
        <Card flush>
          <SelectionBar count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="crm-leads" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={rows}
            rowKey={leadKey}
            selection={selection}
            onRowClick={(lead) => router.push(`/crm/leads/${lead.id}`)}
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
              left={<ExportButtons total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <LeadFormModal
          sources={options.sources}
          toast={toast}
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            setReload((n) => n + 1);
          }}
        />
      )}
      {qualifying && (
        <QualifyLeadModal
          lead={qualifying}
          toast={toast}
          onClose={() => setQualifying(null)}
          onCreated={(opportunityId) => {
            setFlash("Oportunidad creada");
            router.push(`/crm/opportunities/${opportunityId}`);
          }}
        />
      )}
      {discarding && (
        <ReasonModal
          title="Descartar lead"
          text={leadName(discarding)}
          label="Motivo de descarte"
          confirmLabel="Descartar"
          busy={busy}
          onClose={() => setDiscarding(null)}
          onConfirm={(reason) => transition(discarding, "disqualify", "Lead descartado", reason)}
        />
      )}
      {toasts}
    </Screen>
  );
}
