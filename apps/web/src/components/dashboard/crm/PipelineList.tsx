"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Check, Filter as Funnel, Plus, Target, TrendingUp } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, excelTone, money, setFlash, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { OPPORTUNITY_STATES, compactMoney, firstError, relativeDay, weighted, type ViewMode } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
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
import { CloseOpportunityModal, OpportunityFormModal } from "@/components/dashboard/crm/CrmModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Opportunity, OpportunityOptions, OpportunitySummary } from "@/types/crm";

// El tablero muestra hasta este número de oportunidades; con más, se acota con los filtros.
const BOARD_LIMIT = 200;

/** Etapa de una abierta, o su estado de cierre. */
function StageCell({ opportunity }: { opportunity: Opportunity }) {
  if (opportunity.status === "OPEN") return <>{opportunity.stage?.name ?? "—"}</>;
  const [label, tone] = stateOf(OPPORTUNITY_STATES, opportunity.status);
  return <Badge tone={tone}>{label}</Badge>;
}

const COLUMNS: Column<Opportunity>[] = [
  {
    label: "Oportunidad", sortKey: "opportunity", filter: "text",
    render: (opportunity) => (
      <div className="min-w-[190px]">
        <strong className="font-semibold">{opportunity.name}</strong>
        <small className="app-muted block text-xs">{[opportunity.opportunity_number, opportunity.source?.name].filter(Boolean).join(" · ")}</small>
      </div>
    ),
  },
  {
    label: "Cliente CORE", sortKey: "customer", filter: "text",
    render: (opportunity) => (opportunity.customer ? customerName(opportunity.customer) : <span className="app-muted text-xs">Por convertir</span>),
  },
  { label: "Etapa", sortKey: "stage", filter: { top: "Etapa" }, render: (opportunity) => <StageCell opportunity={opportunity} /> },
  { label: "Monto", num: true, sortKey: "amount", filter: "number", render: (opportunity) => money(opportunity.expected_value) },
  { label: "Prob.", num: true, sortKey: "probability", filter: "number", render: (opportunity) => `${Number(opportunity.probability)}%` },
  { label: "Ponderado", num: true, sortKey: "weighted", filter: "number", render: (opportunity) => money(weighted(opportunity)) },
  { label: "Cierre", sortKey: "close", filter: "date", render: (opportunity) => <span className="whitespace-nowrap">{shortDate(opportunity.expected_close_date)}</span> },
  { label: "Cotizaciones", num: true, render: (opportunity) => opportunity.quotations_count ?? 0 },
  { label: "Responsable", sortKey: "owner", filter: { top: "Responsable" }, render: (opportunity) => <Who name={opportunity.owner?.name} /> },
];

const EXPORT_COLUMNS: ExportColumn<Opportunity>[] = [
  { label: "Oportunidad", value: (row) => row.opportunity_number, width: 16, kind: "center" },
  { label: "Nombre", value: (row) => row.name, width: 40 },
  { label: "Cliente", value: (row) => (row.customer ? customerName(row.customer) : ""), width: 34 },
  { label: "Fuente", value: (row) => row.source?.name ?? "", width: 18 },
  { label: "Etapa", value: (row) => (row.status === "OPEN" ? row.stage?.name ?? "" : ""), width: 18 },
  { label: "Monto", value: (row) => Number(row.expected_value), width: 16, kind: "money" },
  { label: "Prob. %", value: (row) => Number(row.probability), width: 10, kind: "number" },
  { label: "Ponderado", value: (row) => weighted(row), width: 16, kind: "money" },
  { label: "Cierre", value: (row) => shortDate(row.expected_close_date), width: 15, kind: "center" },
  { label: "Cotizaciones", value: (row) => row.quotations_count ?? 0, width: 13, kind: "number" },
  { label: "Responsable", value: (row) => row.owner?.name ?? "", width: 24 },
  {
    label: "Estado",
    value: (row) => stateOf(OPPORTUNITY_STATES, row.status)[0],
    width: 14,
    kind: "status",
    tone: (row) => excelTone(stateOf(OPPORTUNITY_STATES, row.status)[1]),
  },
  { label: "Evidencia / motivo", value: (row) => row.won_evidence ?? row.loss_reason ?? "", width: 36 },
];

const opportunityKey = (opportunity: Opportunity) => opportunity.id;

/** Columna del tablero en la que cae la oportunidad: su etapa, o Ganadas / Perdidas. */
const boardColumn = (opportunity: Opportunity) => (opportunity.status === "OPEN" ? opportunity.stage_id : opportunity.status);

type TopFilters = { search: string; stage: string; ownerId: string };

/** Filtros de arriba; "Etapa" vale por una etapa del embudo o por un cierre (ganada, perdida). */
function withTopFilters(params: URLSearchParams, top: TopFilters, forBoard: boolean) {
  if (top.search) params.set("search", top.search);
  if (top.ownerId) params.set("owner_id", top.ownerId);
  if (top.stage && !forBoard) {
    if (top.stage === "WON" || top.stage === "LOST") params.set("status", top.stage);
    else params.set("stage_id", top.stage);
  }
  return params;
}

export default function PipelineList() {
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("pipeline", "kanban");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [stage, setStage] = useState("");
  const [ownerId, setOwnerId] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(opportunityKey);

  const [data, setData] = useState<PaginatedResponse<Opportunity> | null>(null);
  const [summary, setSummary] = useState<OpportunitySummary | null>(null);
  const [options, setOptions] = useState<OpportunityOptions>({ stages: [], sources: [], owners: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [creating, setCreating] = useState(false);
  const [closing, setClosing] = useState<{ opportunity: Opportunity; outcome: "WON" | "LOST" } | null>(null);
  const [busy, setBusy] = useState(false);

  const board = view === "kanban";
  const canManage = !accessLoading && can("opportunities.manage");

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
    // Las etapas van primero: el tablero las necesita para dibujar sus columnas. Los indicadores, al final.
    apiFetch<OpportunityOptions>("/v1/crm/opportunities/options").then((next) => { if (!cancelled) setOptions(next); }).catch(() => undefined);
    apiFetchLater<OpportunitySummary>("/v1/crm/opportunities/summary").then((next) => { if (!cancelled) setSummary(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const size = board ? { per_page: String(BOARD_LIMIT), page: "1" } : { per_page: String(perPage), page: String(page) };
      const params = withTopFilters(new URLSearchParams(size), { search, stage, ownerId }, board);
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Opportunity>>(`/v1/crm/opportunities?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las oportunidades.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [board, page, perPage, search, stage, ownerId, columnFilters.param, sort, reload]);

  function changeView(mode: ViewMode) {
    // Pulsar la vista que ya está activa no cambia nada (si se vaciara la lista, nadie la volvería a pedir).
    if (mode === view) return;
    setData(null);
    setPage(1);
    setView(mode);
  }

  /** Mover una tarjeta a otra columna (arrastrando o con "Mover a…"): otra etapa, o cerrar. */
  async function moveTo(opportunity: Opportunity, target: string) {
    if (opportunity.status !== "OPEN" || target === opportunity.stage_id) return;
    if (target === "WON" || target === "LOST") {
      setClosing({ opportunity, outcome: target });
      return;
    }
    setBusy(true);
    try {
      await apiFetch(`/v1/crm/opportunities/${opportunity.id}/stage`, { method: "POST", body: JSON.stringify({ stage_id: target }) });
      toast("Etapa actualizada");
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo mover la oportunidad."), "danger");
    } finally {
      setBusy(false);
    }
  }

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Opportunity[]) {
    setExporting(true);
    try {
      const params = applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, stage, ownerId }, false)), sort);
      const rows = picked ?? (await fetchAllPages<Opportunity>("/v1/crm/opportunities", params));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("opportunities", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("oportunidades", "Pipeline de oportunidades", EXPORT_COLUMNS, rows);
      else exportCsv("oportunidades", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const rows = useMemo(() => data?.data ?? [], [data]);

  // Columnas del tablero: las etapas (con el monto abierto de cada una) y los dos cierres.
  const boardColumns = useMemo<KanbanColumn[]>(() => [
    ...options.stages.map((item): KanbanColumn => ({
      id: item.id,
      label: item.name,
      tone: "info",
      sub: compactMoney(rows.filter((row) => row.status === "OPEN" && row.stage_id === item.id).reduce((sum, row) => sum + Number(row.expected_value), 0)),
    })),
    { id: "WON", label: "Ganadas", tone: "success" },
    { id: "LOST", label: "Perdidas", tone: "danger" },
  ], [options.stages, rows]);

  const shown = (value: string | number) => (summary ? value : "—");

  return (
    <Screen>
      <PageHead
        title="Pipeline de oportunidades"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={() => setCreating(true)} permission="opportunities.manage">
              <Plus size={16} />
              <span>Nueva oportunidad</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi
          label="Pipeline abierto"
          value={shown(compactMoney(summary?.open.amount ?? 0))}
          sub={summary && `${summary.open.count} ${summary.open.count === 1 ? "oportunidad" : "oportunidades"}`}
          icon={<Funnel size={24} />}
        />
        <Kpi label="Ponderado" value={shown(compactMoney(summary?.open.weighted ?? 0))} icon={<TrendingUp size={24} />} />
        <Kpi
          label="Ganadas"
          value={shown(compactMoney(summary?.won.amount ?? 0))}
          sub={summary && `${summary.won.count} ${summary.won.count === 1 ? "oportunidad" : "oportunidades"}`}
          icon={<Check size={24} />}
          tone="success"
        />
        <Kpi label="Tasa de cierre" value={shown(summary?.close_rate != null ? `${summary.close_rate}%` : "—")} icon={<Target size={24} />} />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar oportunidad o cliente" />
        {!board && (
          <FilterSelect
            label="Etapa"
            value={stage}
            onChange={(value) => {
              setStage(value);
              setPage(1);
            }}
            options={[...options.stages.map((item): [string, string] => [item.id, item.name]), ["WON", "Ganada"], ["LOST", "Perdida"]]}
          />
        )}
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
            hasOtherFilters={Boolean(searchInput || stage || ownerId)}
            onClearAll={() => {
              setSearchInput("");
              setSearch("");
              setStage("");
              setOwnerId("");
              setPage(1);
            }}
          />
        )}
        <ScopeNote resource="opportunities" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra las {rows.length} oportunidades más recientes de {data.total}. Usa el buscador o los filtros para acotar.</Notice>
          )}
          <Kanban
            columns={boardColumns}
            cards={rows}
            cardKey={opportunityKey}
            columnOf={boardColumn}
            canDrag={(opportunity) => canManage && opportunity.status === "OPEN"}
            onDrop={moveTo}
            loading={(loading && !data) || !options.stages.length}
            refreshing={loading}
            renderCard={(opportunity) => (
              <>
                <Link href={`/crm/opportunities/${opportunity.id}`} className="font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline">
                  {opportunity.name}
                </Link>
                <div className="app-muted flex items-center justify-between gap-1.5 text-[12.5px]">
                  <strong className="app-text text-[15px] font-semibold tabular-nums">{money(opportunity.expected_value)}</strong>
                  <span>{Number(opportunity.probability)}%</span>
                </div>
                <div className="app-muted flex flex-wrap items-center justify-between gap-1.5 text-xs">
                  <Who name={opportunity.owner?.name} />
                  <small>
                    {opportunity.status === "OPEN"
                      ? `Cierre ${relativeDay(opportunity.expected_close_date)}`
                      : shortDate(opportunity.won_at ?? opportunity.lost_at ?? opportunity.expected_close_date)}
                  </small>
                </div>
                {canManage && opportunity.status === "OPEN" && (
                  <PickSelect
                    className="app-muted flex h-[30px] w-full cursor-pointer items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs"
                    ariaLabel={`Mover oportunidad ${opportunity.name}`}
                    value=""
                    onChange={(target) => moveTo(opportunity, target)}
                    options={[
                      ["", "Mover a…"],
                      ...options.stages.filter((item) => item.id !== opportunity.stage_id).map((item): [string, string] => [item.id, item.name]),
                      ["WON", "Ganada"],
                      ["LOST", "Perdida"],
                    ]}
                    disabled={busy}
                  />
                )}
                {opportunity.status === "WON" && !opportunity.converted && (
                  <span><Chip tone="warning">Pendiente de conversión</Chip></span>
                )}
              </>
            )}
          />
        </>
      ) : (
        <Card flush>
          <SelectionBar permission="opportunities.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="crm-opportunities" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={rows}
            rowKey={opportunityKey}
            selection={selection}
            onRowClick={(opportunity) => router.push(`/crm/opportunities/${opportunity.id}`)}
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
              left={<ExportButtons permission="opportunities.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <OpportunityFormModal
          sources={options.sources}
          toast={toast}
          onClose={() => setCreating(false)}
          onSaved={(saved) => {
            setFlash("Oportunidad creada");
            router.push(`/crm/opportunities/${saved.id}`);
          }}
        />
      )}
      {closing && (
        <CloseOpportunityModal
          opportunity={closing.opportunity}
          outcome={closing.outcome}
          toast={toast}
          onClose={() => setClosing(null)}
          onClosed={(updated) => {
            setClosing(null);
            // Ganada y sin convertir: el siguiente paso es convertirla en cliente, en su ficha.
            if (updated.status === "WON" && !updated.conversion) router.push(`/crm/opportunities/${updated.id}?convert=1`);
            else setReload((n) => n + 1);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
