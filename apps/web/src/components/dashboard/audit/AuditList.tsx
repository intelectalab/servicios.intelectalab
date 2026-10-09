"use client";

import { useEffect, useState } from "react";
import { ExternalLink, Lock } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, Kv, LinkBtn, LoadError, Modal, PAGE_SIZES, PageHead, Pager, Screen,
  SearchInput, SelectionBar, Who, deniedText, useRowSelection, useToasts, type Column,
  applySort, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";

type AuditData = Record<string, unknown> | unknown[] | null;

/** Un movimiento de la auditoría, tal como lo entrega la API (los textos visibles vienen de ella). */
type AuditEntry = {
  id: string;
  at: string;
  action: string;
  action_label: string;
  entity_type: string | null;
  entity_label: string | null;
  entity_id: string | null;
  user: { id: string; name: string; email: string } | null;
  ip_address: string | null;
  user_agent: string | null;
  correlation_id: string | null;
  before: AuditData;
  after: AuditData;
  metadata: AuditData;
};

type AuditOptions = { entities: { code: string; label: string }[]; users: { id: string; name: string }[] };

// Entidades que tienen ficha: a dónde lleva "Abrir registro" y qué permiso pide verla.
const RECORD_LINKS: Record<string, { path: string; permission: string }> = {
  Quotation: { path: "/quotations", permission: "quotations.view" },
  Contract: { path: "/contracts", permission: "contracts.view" },
  Order: { path: "/orders", permission: "orders.view" },
  Service: { path: "/services", permission: "services.view" },
  Party: { path: "/actors", permission: "core.view" },
  CrmLead: { path: "/crm/leads", permission: "leads.view" },
  CrmOpportunity: { path: "/crm/opportunities", permission: "opportunities.view" },
};

const CODE = "rounded bg-[var(--app-surface-muted)] px-1.5 py-0.5 font-mono text-[12px] [overflow-wrap:anywhere]";
const PRE = "max-h-[320px] overflow-auto whitespace-pre-wrap rounded-[9px] border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3 font-mono text-[12px] leading-relaxed [overflow-wrap:anywhere]";

/** Fecha con segundos: en auditoría importa el orden exacto. */
const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "medium" });
// En la tabla, los identificadores van abreviados; completos, al pasar el cursor y en el detalle.
const shortId = (id: string) => `${id.slice(0, 8)}…`;
const json = (data: AuditData) => (data === null ? "" : JSON.stringify(data, null, 2));
// Una celda de Excel admite hasta 32 767 caracteres.
const cellJson = (data: AuditData) => (data === null ? "" : JSON.stringify(data).slice(0, 30000));

const COLUMNS: Column<AuditEntry>[] = [
  { label: "Fecha", sortKey: "at", filter: "date", render: (entry) => <span className="whitespace-nowrap">{dateTime(entry.at)}</span> },
  {
    label: "Actor", sortKey: "actor", filter: { top: "Usuario" },
    render: (entry) => (entry.user ? <Who name={entry.user.name} /> : <span className="app-muted">Sistema</span>),
  },
  {
    label: "Acción", sortKey: "action", filter: "text",
    render: (entry) => (
      <div className="min-w-[170px]">
        <strong className="block font-semibold">{entry.action_label}</strong>
        <code className={CODE}>{entry.action}</code>
      </div>
    ),
  },
  {
    label: "Entidad", sortKey: "entity", filter: { top: "Entidad" },
    render: (entry) => (
      <div>
        {entry.entity_label ?? "—"}
        {entry.entity_id && <small className="app-muted block whitespace-nowrap font-mono text-[11px]" title={entry.entity_id}>{shortId(entry.entity_id)}</small>}
      </div>
    ),
  },
  { label: "IP", sortKey: "ip", filter: "text", render: (entry) => <span className="whitespace-nowrap font-mono text-[12px]">{entry.ip_address ?? "—"}</span> },
  {
    label: "Correlation", sortKey: "correlation", filter: "text",
    render: (entry) => (entry.correlation_id ? <code className={`${CODE} whitespace-nowrap text-[11px]`} title={entry.correlation_id}>{shortId(entry.correlation_id)}</code> : "—"),
  },
];

const EXPORT_COLUMNS: ExportColumn<AuditEntry>[] = [
  { label: "Fecha", value: (entry) => dateTime(entry.at), width: 24, kind: "center" },
  { label: "Actor", value: (entry) => entry.user?.name ?? "Sistema", width: 26 },
  { label: "Correo", value: (entry) => entry.user?.email ?? "", width: 30 },
  { label: "Acción", value: (entry) => entry.action_label, width: 32 },
  { label: "Código de acción", value: (entry) => entry.action, width: 28 },
  { label: "Entidad", value: (entry) => entry.entity_label ?? "", width: 26 },
  { label: "ID del registro", value: (entry) => entry.entity_id ?? "", width: 38 },
  { label: "IP", value: (entry) => entry.ip_address ?? "", width: 16, kind: "center" },
  { label: "Navegador", value: (entry) => entry.user_agent ?? "", width: 40 },
  { label: "Correlation ID", value: (entry) => entry.correlation_id ?? "", width: 38 },
  { label: "Detalle", value: (entry) => cellJson(entry.metadata), width: 50 },
  { label: "Antes", value: (entry) => cellJson(entry.before), width: 50 },
  { label: "Después", value: (entry) => cellJson(entry.after), width: 50 },
];

const entryKey = (entry: AuditEntry) => entry.id;

type TopFilters = { search: string; entity: string; userId: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.entity) params.set("entity", top.entity);
  if (top.userId) params.set("user_id", top.userId);
  return params;
}

/** El movimiento completo: quién, qué, sobre qué registro, y el antes y el después. */
function AuditEntryModal({ entry, onClose }: { entry: AuditEntry; onClose: () => void }) {
  const link = entry.entity_type && entry.entity_id ? RECORD_LINKS[entry.entity_type] : undefined;

  return (
    <Modal
      title={entry.action_label}
      size="lg"
      onClose={onClose}
      footer={
        <>
          {link && (
            <LinkBtn href={`${link.path}/${entry.entity_id}`} permission={link.permission} variant="secondary">
              <ExternalLink size={15} />
              <span>Abrir registro</span>
            </LinkBtn>
          )}
          <Btn variant="secondary" onClick={onClose}>Cerrar</Btn>
        </>
      }
    >
      <Kv
        rows={[
          ["Actor", entry.user ? <>{entry.user.name} <span className="app-muted">· {entry.user.email}</span></> : "Sistema"],
          ["Acción", <code key="action" className={CODE}>{entry.action}</code>],
          ["Entidad", <>{entry.entity_label ?? "—"}{entry.entity_id && <> · <span className="font-mono text-[12px]">{entry.entity_id}</span></>}</>],
          ["Fecha", dateTime(entry.at)],
          ["Dirección IP", entry.ip_address ?? "—"],
          entry.user_agent ? ["Navegador", <span key="agent" className="text-[12.5px]">{entry.user_agent}</span>] : null,
          ["Correlation ID", entry.correlation_id ? <code className={CODE}>{entry.correlation_id}</code> : "—"],
        ]}
      />

      {entry.metadata !== null && (
        <div className="mt-3">
          <h4 className="mb-1.5 text-[13px] font-semibold">Detalle</h4>
          <pre className={PRE}>{json(entry.metadata)}</pre>
        </div>
      )}

      <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
        {([["Antes", entry.before], ["Después", entry.after]] as const).map(([label, data]) => (
          <div key={label} className="min-w-0">
            <h4 className="mb-1.5 text-[13px] font-semibold">{label}</h4>
            {data === null ? <p className="app-muted text-[12.5px]">Sin datos registrados.</p> : <pre className={PRE}>{json(data)}</pre>}
          </div>
        ))}
      </div>
    </Modal>
  );
}

export default function AuditList() {
  const { toast, toasts } = useToasts();
  const { can, loading: accessLoading } = useAccess();
  // Quien llega por la dirección sin el permiso no pide nada a la API: ve el candado y su motivo.
  const denied = !accessLoading && !can("audit.view");
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [entity, setEntity] = useState("");
  const [userId, setUserId] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(entryKey);

  const [data, setData] = useState<PaginatedResponse<AuditEntry> | null>(null);
  const [options, setOptions] = useState<AuditOptions>({ entities: [], users: [] });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [opened, setOpened] = useState<AuditEntry | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  useEffect(() => {
    if (accessLoading || denied) return;
    let cancelled = false;
    apiFetch<AuditOptions>("/v1/audit/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [reload, accessLoading, denied]);

  useEffect(() => {
    if (accessLoading || denied) return;
    let cancelled = false;

    async function load() {
      setLoading(true);
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, entity, userId });
      applySort(applyFilters(params, columnFilters.param), sort);

      try {
        const response = await apiFetch<PaginatedResponse<AuditEntry>>(`/v1/audit?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar la auditoría.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, entity, userId, columnFilters.param, sort, reload, accessLoading, denied]);

  /** Exporta las filas marcadas o, sin `picked`, todas las del filtro y búsqueda actuales. */
  async function runExport(format: "xlsx" | "csv", picked?: AuditEntry[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<AuditEntry>("/v1/audit", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, entity, userId })), sort)));
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("audit", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("auditoria", "Auditoría", EXPORT_COLUMNS, rows);
      else exportCsv("auditoria", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
      // La exportación recién hecha es un movimiento más del listado.
      setReload((n) => n + 1);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  if (denied) {
    return (
      <Screen>
        <PageHead title="Auditoría" />
        <Card>
          <div className="app-muted flex flex-col items-center gap-2 px-4 py-10 text-center">
            <Lock size={26} aria-hidden="true" />
            <p>{deniedText("audit.view")}</p>
          </div>
        </Card>
      </Screen>
    );
  }

  return (
    <Screen>
      <PageHead title="Auditoría" />

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar acción, entidad, usuario o correlation_id" />
        <FilterSelect
          label="Entidad"
          value={entity}
          onChange={(value) => {
            setEntity(value);
            setPage(1);
          }}
          options={options.entities.map((item) => [item.code, item.label])}
        />
        <FilterSelect
          label="Usuario"
          value={userId}
          onChange={(value) => {
            setUserId(value);
            setPage(1);
          }}
          options={options.users.map((user) => [user.id, user.name])}
        />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || entity || userId)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setEntity("");
            setUserId("");
            setPage(1);
          }}
        />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="audit.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="audit" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={COLUMNS}
            rows={data?.data ?? []}
            rowKey={entryKey}
            selection={selection}
            onRowClick={setOpened}
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
              left={<ExportButtons permission="audit.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {opened && <AuditEntryModal entry={opened} onClose={() => setOpened(null)} />}
      {toasts}
    </Screen>
  );
}
