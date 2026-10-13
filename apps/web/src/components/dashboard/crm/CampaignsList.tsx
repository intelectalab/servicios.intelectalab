"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Coins, Filter as Funnel, Plus, Send, Users } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, money, setFlash, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { CAMPAIGN_OPEN, CAMPAIGN_STATES, compactMoney, firstError, type ViewMode } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, fetchAllPages, type ExportColumn } from "@/lib/export-table";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { Kanban, ViewSwitch, useStoredView, type KanbanColumn } from "@/components/ui/kanban";
import { PickSelect } from "@/components/ui/pick-select";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { CampaignFormModal } from "@/components/dashboard/crm/CampaignModals";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { PaginatedResponse } from "@/types/customer";
import type { Campaign, CampaignStatus, CampaignSummary } from "@/types/crm";

// El tablero muestra hasta este número de campañas; con más, se acota con el buscador.
const BOARD_LIMIT = 200;

const BOARD_COLUMNS: KanbanColumn[] = [
  { id: "DRAFT", label: "Borrador", tone: "neutral" },
  { id: "ACTIVE", label: "Activas", tone: "success" },
  { id: "COMPLETED", label: "Finalizadas", tone: "muted" },
  { id: "CANCELLED", label: "Canceladas", tone: "danger" },
];

/** Cambios de estado de una campaña: estado de destino → ruta, estados de origen y aviso. */
export const CAMPAIGN_MOVES: Record<Exclude<CampaignStatus, "DRAFT">, { path: string; from: CampaignStatus[]; label: string; done: string }> = {
  ACTIVE: { path: "launch", from: ["DRAFT"], label: "Lanzar", done: "Campaña lanzada" },
  COMPLETED: { path: "complete", from: ["ACTIVE"], label: "Finalizar", done: "Campaña finalizada" },
  CANCELLED: { path: "cancel", from: ["DRAFT", "ACTIVE"], label: "Cancelar", done: "Campaña cancelada" },
};

export const campaignPeriod = (campaign: Pick<Campaign, "starts_at" | "ends_at">) =>
  campaign.starts_at || campaign.ends_at ? `${shortDate(campaign.starts_at)} → ${shortDate(campaign.ends_at)}` : "Sin fechas";

const COLUMNS: Column<Campaign>[] = [
  {
    label: "Campaña", sortKey: "campaign", filter: "text",
    render: (campaign) => (
      <div className="min-w-[180px]">
        <strong className="font-semibold">{campaign.name}</strong>
        <small className="app-muted block text-xs">{[campaign.code, campaign.channel].filter(Boolean).join(" · ")}</small>
      </div>
    ),
  },
  { label: "Vigencia", sortKey: "start", filter: "date", render: (campaign) => <span className="whitespace-nowrap">{campaignPeriod(campaign)}</span> },
  { label: "Miembros", num: true, sortKey: "members", render: (campaign) => campaign.metrics.members },
  { label: "Oportunidades", num: true, sortKey: "opportunities", render: (campaign) => campaign.metrics.opportunities },
  { label: "Ganadas", num: true, sortKey: "won", render: (campaign) => campaign.metrics.won },
  { label: "Ingresos", num: true, sortKey: "revenue", render: (campaign) => money(campaign.metrics.revenue) },
  { label: "Presupuesto", num: true, sortKey: "budget", filter: "number", render: (campaign) => money(campaign.budget) },
  {
    label: "Estado", sortKey: "status", filter: { top: "Estado" },
    render: (campaign) => {
      const [label, tone] = stateOf(CAMPAIGN_STATES, campaign.status);
      return <Badge tone={tone}>{label}</Badge>;
    },
  },
];

const EXPORT_COLUMNS: ExportColumn<Campaign>[] = [
  { label: "Código", value: (campaign) => campaign.code, width: 16, kind: "center" },
  { label: "Campaña", value: (campaign) => campaign.name, width: 36 },
  { label: "Canal", value: (campaign) => campaign.channel ?? "", width: 18 },
  { label: "Inicio", value: (campaign) => (campaign.starts_at ? shortDate(campaign.starts_at) : ""), width: 15, kind: "center" },
  { label: "Fin", value: (campaign) => (campaign.ends_at ? shortDate(campaign.ends_at) : ""), width: 15, kind: "center" },
  { label: "Miembros", value: (campaign) => campaign.metrics.members, width: 12, kind: "number" },
  { label: "Calificados", value: (campaign) => campaign.metrics.qualified, width: 12, kind: "number" },
  { label: "Oportunidades", value: (campaign) => campaign.metrics.opportunities, width: 15, kind: "number" },
  { label: "Ganadas", value: (campaign) => campaign.metrics.won, width: 12, kind: "number" },
  { label: "Ingresos", value: (campaign) => campaign.metrics.revenue, width: 16, kind: "money" },
  { label: "Presupuesto", value: (campaign) => campaign.budget, width: 16, kind: "money" },
  { label: "Responsable", value: (campaign) => campaign.owner?.name ?? "", width: 24 },
  {
    label: "Estado",
    value: (campaign) => stateOf(CAMPAIGN_STATES, campaign.status)[0],
    width: 16,
    kind: "status",
    tone: (campaign) => excelTone(stateOf(CAMPAIGN_STATES, campaign.status)[1]),
  },
  { label: "Motivo de cancelación", value: (campaign) => campaign.cancel_reason ?? "", width: 32 },
];

const campaignKey = (campaign: Campaign) => campaign.id;

type TopFilters = { search: string; status: string };

function withTopFilters(params: URLSearchParams, top: TopFilters, forBoard: boolean) {
  if (top.search) params.set("search", top.search);
  if (top.status && !forBoard) params.set("status", top.status);
  return params;
}

export default function CampaignsList() {
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [exporting, setExporting] = useState(false);

  const [view, setView] = useStoredView("campaigns", "table");
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(campaignKey);

  const [data, setData] = useState<PaginatedResponse<Campaign> | null>(null);
  const [summary, setSummary] = useState<CampaignSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [creating, setCreating] = useState(false);
  const [cancelling, setCancelling] = useState<Campaign | null>(null);
  const [busy, setBusy] = useState(false);

  const board = view === "kanban";
  const canManage = !accessLoading && can("campaigns.manage");

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
    apiFetchLater<CampaignSummary>("/v1/crm/campaigns/summary")
      .then((next) => { if (!cancelled) setSummary(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      const size = board ? { per_page: String(BOARD_LIMIT), page: "1" } : { per_page: String(perPage), page: String(page) };
      const params = withTopFilters(new URLSearchParams(size), { search, status }, board);
      if (!board) applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<Campaign>>(`/v1/crm/campaigns?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar las campañas.");
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

  async function transition(campaign: Campaign, target: Exclude<CampaignStatus, "DRAFT">, reason?: string) {
    const move = CAMPAIGN_MOVES[target];
    setBusy(true);
    try {
      await apiFetch(`/v1/crm/campaigns/${campaign.id}/${move.path}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(move.done);
      setCancelling(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  /** Mover una tarjeta a otra columna (arrastrando o con "Mover a…"). */
  function moveTo(campaign: Campaign, target: string) {
    if (target === campaign.status) return;
    const label = (state: string) => stateOf(CAMPAIGN_STATES, state)[0];
    const move = target === "DRAFT" ? undefined : CAMPAIGN_MOVES[target as Exclude<CampaignStatus, "DRAFT">];

    if (!move?.from.includes(campaign.status)) toast(`Transición no permitida: ${label(campaign.status)} → ${label(target)}`, "danger");
    else if (target === "CANCELLED") setCancelling(campaign);
    else transition(campaign, target as Exclude<CampaignStatus, "DRAFT">);
  }

  function moveOptions(campaign: Campaign): [string, string][] {
    const targets = BOARD_COLUMNS.filter((column) => column.id !== "DRAFT" && CAMPAIGN_MOVES[column.id as Exclude<CampaignStatus, "DRAFT">].from.includes(campaign.status));
    return [["", "Mover a…"], ...targets.map((column): [string, string] => [column.id, column.label])];
  }

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: Campaign[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<Campaign>("/v1/crm/campaigns", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, status }, false)), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("campaigns", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("campanas", "Campañas", EXPORT_COLUMNS, rows);
      else exportCsv("campanas", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const shown = (value: number | string | undefined) => (summary ? value ?? 0 : "—");
  const rows = data?.data ?? [];

  return (
    <Screen>
      <PageHead
        title="Campañas"
        actions={
          <>
            <ViewSwitch value={view} onChange={changeView} />
            <Btn onClick={() => setCreating(true)} permission="campaigns.manage">
              <Plus size={16} />
              <span>Nueva campaña</span>
            </Btn>
          </>
        }
      />

      <Kpis>
        <Kpi label="Campañas activas" value={shown(summary?.active)} icon={<Send size={24} />} />
        <Kpi label="Miembros" value={shown(summary?.members)} icon={<Users size={24} />} />
        <Kpi label="Oportunidades generadas" value={shown(summary?.opportunities)} icon={<Funnel size={24} />} tone="warning" />
        <Kpi label="Ingresos atribuidos" value={shown(summary ? compactMoney(summary.revenue) : undefined)} icon={<Coins size={24} />} tone="success" />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar campaña" />
        {!board && (
          <>
            <FilterSelect
              label="Estado"
              value={status}
              onChange={(value) => {
                setStatus(value);
                setPage(1);
              }}
              options={CAMPAIGN_STATES.map(([key, label]) => [key, label])}
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
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : board ? (
        <>
          {data && data.total > rows.length && (
            <Notice>El tablero muestra las {rows.length} campañas más recientes de {data.total}. Usa el buscador para acotar.</Notice>
          )}
          <Kanban
            columns={BOARD_COLUMNS}
            cards={rows}
            cardKey={campaignKey}
            columnOf={(campaign) => campaign.status}
            canDrag={(campaign) => canManage && CAMPAIGN_OPEN.includes(campaign.status)}
            onDrop={moveTo}
            loading={loading && !data}
            refreshing={loading}
            renderCard={(campaign) => (
              <>
                <Link href={`/crm/campaigns/${campaign.id}`} className="font-semibold leading-snug hover:text-[color:var(--p-brand-text)] hover:underline">
                  {campaign.name}
                </Link>
                <small className="app-muted">{[campaign.channel, campaignPeriod(campaign)].filter(Boolean).join(" · ")}</small>
                <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-[12.5px]">
                  <span><b className="font-semibold">{campaign.metrics.members}</b> miembros</span>
                  <span><b className="font-semibold">{campaign.metrics.opportunities}</b> opor.</span>
                  <span><b className="font-semibold">{campaign.metrics.won}</b> ganadas</span>
                </div>
                <div className="app-muted flex flex-wrap items-center justify-between gap-1.5 text-xs">
                  <span>Ingresos {compactMoney(campaign.metrics.revenue)}</span>
                  <span>Ppto. {compactMoney(campaign.budget)}</span>
                </div>
                {canManage && CAMPAIGN_OPEN.includes(campaign.status) && (
                  <PickSelect
                    className="app-muted flex h-[30px] w-full cursor-pointer items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs"
                    ariaLabel={`Mover campaña ${campaign.name}`}
                    value=""
                    onChange={(target) => moveTo(campaign, target)}
                    options={moveOptions(campaign)}
                    disabled={busy}
                  />
                )}
              </>
            )}
          />
          <p className="app-muted text-xs">Arrastra para cambiar el estado. Soltar en &quot;Canceladas&quot; pide motivo.</p>
        </>
      ) : (
        <Card flush>
          <SelectionBar permission="campaigns.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="crm-campaigns" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={rows}
            rowKey={campaignKey}
            selection={selection}
            onRowClick={(campaign) => router.push(`/crm/campaigns/${campaign.id}`)}
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
              left={<ExportButtons permission="campaigns.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {creating && (
        <CampaignFormModal
          // El aviso de "creada" se da en la ficha a la que se navega; aquí solo los errores.
          toast={(text, tone, detail) => { if (tone && tone !== "success") toast(text, tone, detail); }}
          onClose={() => setCreating(false)}
          onSaved={(campaign) => {
            setFlash("Campaña creada");
            router.push(`/crm/campaigns/${campaign.id}`);
          }}
        />
      )}
      {cancelling && (
        <ReasonModal
          title="Cancelar campaña"
          text={cancelling.name}
          label="Motivo de cancelación"
          confirmLabel="Cancelar campaña"
          busy={busy}
          onClose={() => setCancelling(null)}
          onConfirm={(reason) => transition(cancelling, "CANCELLED", reason)}
        />
      )}
      {toasts}
    </Screen>
  );
}
