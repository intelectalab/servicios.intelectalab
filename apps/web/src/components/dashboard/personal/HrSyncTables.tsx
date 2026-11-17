"use client";

import { useEffect, useState, type ReactNode } from "react";
import { Link2, RotateCcw, UserPlus, X } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import { EVENT_LABELS, INBOUND_STATES, OUTBOUND_STATES, RUN_STATES, money } from "@/lib/personal";
import {
  Badge, Btn, Card, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, Pager, Tabs, type Column,
} from "@/components/ui/proto";
import { HrLinkModal } from "@/components/dashboard/personal/HrSyncModals";
import { ConfirmModal, when, type Toast } from "@/components/dashboard/personal/HrSyncShared";
import type { HrInboundEvent, HrOutboundEvent, HrSyncRun, HrUnmatched } from "@/types/personal";

type Page<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number };

/** Un listado paginado de la sincronización, con sus filtros en la consulta. */
function usePaged<T>(path: string, filters: Record<string, string>, reload: number) {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [data, setData] = useState<Page<T> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const query = new URLSearchParams(Object.entries(filters).filter(([, value]) => value)).toString();

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = new URLSearchParams(query);
      params.set("page", String(page));
      params.set("per_page", String(perPage));
      try {
        const next = await apiFetch<Page<T>>(`${path}?${params}`);
        if (!cancelled) { setData(next); setError(""); }
      } catch {
        if (!cancelled) setError("No se pudo cargar el listado.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [path, query, page, perPage, reload, retry]);

  return {
    data, loading, error, query, retry: () => setRetry((n) => n + 1),
    setPage, onPerPage: (size: number) => { setPerPage(size); setPage(1); },
  };
}

/** Exportación de un listado de la sincronización (todas sus páginas, con sus filtros). */
function useSyncExport<T>(toast: Toast, path: string, query: string, file: string, title: string, columns: ExportColumn<T>[]) {
  const [busy, setBusy] = useState(false);
  async function run(format: "xlsx" | "csv") {
    setBusy(true);
    try {
      const rows = await fetchAllPages<T>(path, new URLSearchParams(query));
      await recordExport("hr_sync", format, rows.length, false, `list=${file}`);
      if (format === "xlsx") await exportXlsx(file, title, columns, rows);
      else exportCsv(file, columns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setBusy(false);
    }
  }
  return { busy, run };
}

function PagedCard<T>({
  list, cols, rowKey, empty, exporter, tableId,
}: {
  list: ReturnType<typeof usePaged<T>>;
  cols: Column<T>[];
  rowKey: (row: T) => string;
  empty: string;
  exporter: { busy: boolean; run: (format: "xlsx" | "csv") => void };
  tableId: string;
}) {
  if (list.error) return <Card><LoadError text={list.error} onRetry={list.retry} /></Card>;
  return (
    <Card flush>
      <DataTable tableId={tableId} refreshing={list.loading} cols={cols} rows={list.data?.data ?? []} rowKey={rowKey} loading={list.loading && !list.data} empty={empty} />
      {list.data && (
        <Pager
          loading={list.loading}
          page={list.data.current_page}
          lastPage={list.data.last_page}
          total={list.data.total}
          perPage={list.data.per_page}
          onPage={list.setPage}
          onPerPage={list.onPerPage}
          left={<ExportButtons permission="hr_sync.export" total={list.data.total} busy={exporter.busy} onExport={exporter.run} />}
        />
      )}
    </Card>
  );
}

const muted = (value: ReactNode) => (value === null || value === undefined || value === "" ? <span className="app-muted">—</span> : value);
const eventLabel = (type: string) => EVENT_LABELS[type] ?? type;

// ------------------------------------------------------------------ Bandeja

export const UNMATCHED_STATES: [HrUnmatched["status"], string, "warning" | "success" | "info" | "muted"][] = [
  ["OPEN", "Por resolver", "warning"],
  ["LINKED", "Vinculado", "success"],
  ["CREATED", "Creado en el ERP", "info"],
  ["IGNORED", "Ignorado", "muted"],
];

const UNMATCHED_COLUMNS: ExportColumn<HrUnmatched>[] = [
  { label: "Trabajador en RRHH", value: (row) => row.full_name, width: 32 },
  { label: "Documento", value: (row) => row.document, width: 18, kind: "center" },
  { label: "Motivo", value: (row) => row.reason_label, width: 36 },
  { label: "Correo", value: (row) => row.email ?? "", width: 30 },
  { label: "Costo por hora", value: (row) => (row.hourly_cost !== null ? Number(row.hourly_cost) : ""), width: 16, kind: "money" },
  { label: "Candidatos", value: (row) => row.candidates.map((candidate) => candidate.name).join(", "), width: 36 },
  {
    label: "Estado", value: (row) => stateOf(UNMATCHED_STATES, row.status)[0], width: 18, kind: "status",
    tone: (row) => excelTone(stateOf(UNMATCHED_STATES, row.status)[1]),
  },
  { label: "Resuelto por", value: (row) => row.resolved_by ?? "", width: 24 },
  { label: "Resuelto", value: (row) => (row.resolved_at ? when(row.resolved_at) : ""), width: 22, kind: "center" },
  { label: "Recibido", value: (row) => when(row.created_at), width: 22, kind: "center" },
];

export function HrUnmatchedTable({ toast, reload, onChanged }: { toast: Toast; reload: number; onChanged: () => void }) {
  const [status, setStatus] = useState<HrUnmatched["status"]>("OPEN");
  const list = usePaged<HrUnmatched>("/v1/hr/sync/unmatched", { status }, reload);
  const exporter = useSyncExport(toast, "/v1/hr/sync/unmatched", list.query, "bandeja-rrhh", "Bandeja de trabajadores sin pareja", UNMATCHED_COLUMNS);
  const [linking, setLinking] = useState<HrUnmatched | null>(null);
  const [asking, setAsking] = useState<{ item: HrUnmatched; action: "create" | "ignore" } | null>(null);
  const [busy, setBusy] = useState(false);

  async function resolve(item: HrUnmatched, action: "create" | "ignore") {
    setBusy(true);
    try {
      await apiFetch(`/v1/hr/sync/unmatched/${item.id}/resolve`, { method: "POST", body: JSON.stringify({ action }) });
      toast(action === "create" ? `${item.full_name} creado en el ERP` : `${item.full_name} ignorado`);
      setAsking(null);
      onChanged();
    } catch (err) {
      toast(firstError(err, "No se pudo resolver el caso."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const cols: Column<HrUnmatched>[] = [
    {
      label: "Trabajador en RRHH",
      render: (row) => (
        <div className="min-w-[160px]">
          <strong className="font-semibold">{row.full_name}</strong>
          <small className="app-muted block text-xs">{when(row.created_at)}</small>
        </div>
      ),
    },
    { label: "Documento", render: (row) => <span className="whitespace-nowrap">{muted(row.document)}</span> },
    { label: "Motivo", render: (row) => <span className="block min-w-[150px] text-[12.5px]">{row.reason_label}</span> },
    { label: "Correo", render: (row) => <span className="[overflow-wrap:anywhere]">{muted(row.email)}</span> },
    { label: "Costo por hora", num: true, render: (row) => (row.hourly_cost !== null ? money(Number(row.hourly_cost)) : muted(null)) },
    {
      label: "Candidatos",
      render: (row) => (row.candidates.length
        ? <span className="block min-w-[140px] text-[12.5px]">{row.candidates.map((candidate) => `${candidate.name}${candidate.code ? ` (${candidate.code})` : ""}`).join(", ")}</span>
        : muted(null)),
    },
    status === "OPEN"
      ? {
        label: "Acciones",
        render: (row) => (
          <div className="flex flex-wrap gap-1.5">
            <Btn size="sm" permission="hr_sync.manage" disabled={busy} onClick={() => setLinking(row)}>
              <Link2 size={14} />
              <span>Vincular</span>
            </Btn>
            <Btn size="sm" variant="secondary" permission="hr_sync.manage" disabled={busy} onClick={() => setAsking({ item: row, action: "create" })}>
              <UserPlus size={14} />
              <span>Crear trabajador</span>
            </Btn>
            <Btn size="sm" variant="ghost" permission="hr_sync.manage" disabled={busy} onClick={() => setAsking({ item: row, action: "ignore" })}>
              <X size={14} />
              <span>Ignorar</span>
            </Btn>
          </div>
        ),
      }
      : {
        label: "Resuelto",
        render: (row) => (
          <div className="min-w-[130px] text-[12.5px]">
            <Badge tone={stateOf(UNMATCHED_STATES, row.status)[1]}>{stateOf(UNMATCHED_STATES, row.status)[0]}</Badge>
            <small className="app-muted block text-xs">{row.resolved_by ?? "—"} · {when(row.resolved_at)}</small>
          </div>
        ),
      },
  ];

  return (
    <>
      <Tabs
        tabs={UNMATCHED_STATES.map(([key, label]) => [key, label, key === status && list.data ? list.data.total : null])}
        value={status}
        onChange={(key) => { setStatus(key as HrUnmatched["status"]); list.setPage(1); }}
      />
      <PagedCard
        list={list} cols={cols} rowKey={(row) => row.id} exporter={exporter} tableId={`hr-unmatched-${status.toLowerCase()}`}
        empty={status === "OPEN" ? "No hay trabajadores sin pareja." : "No hay casos en este estado."}
      />
      {linking && (
        <HrLinkModal item={linking} toast={toast} onClose={() => setLinking(null)} onDone={() => { setLinking(null); onChanged(); }} />
      )}
      {asking && (
        <ConfirmModal
          title={asking.action === "create" ? "Crear trabajador" : "Ignorar trabajador"}
          confirmLabel={asking.action === "create" ? "Crear trabajador" : "Ignorar"}
          variant={asking.action === "create" ? "primary" : "danger"}
          busy={busy}
          onClose={() => setAsking(null)}
          onConfirm={() => resolve(asking.item, asking.action)}
        >
          <p><strong className="font-semibold">{asking.item.full_name}</strong>{asking.item.document ? ` · ${asking.item.document}` : ""}</p>
          <p className="app-muted">
            {asking.action === "create"
              ? "Se crea en el ERP con los datos de RRHH y queda vinculado."
              : "No se vincula ni se crea: sus cambios dejarán de llegar a la bandeja."}
          </p>
        </ConfirmModal>
      )}
    </>
  );
}

// ------------------------------------------------------------------ Ejecuciones

const RUN_COLUMNS: ExportColumn<HrSyncRun>[] = [
  { label: "Tipo", value: (row) => row.kind_label, width: 22 },
  {
    label: "Estado", value: (row) => stateOf(RUN_STATES, row.status)[0], width: 16, kind: "status",
    tone: (row) => excelTone(stateOf(RUN_STATES, row.status)[1]),
  },
  { label: "Recibidos", value: (row) => row.received, width: 12, kind: "number" },
  { label: "Aplicados", value: (row) => row.applied, width: 12, kind: "number" },
  { label: "Sin cambios", value: (row) => row.skipped, width: 12, kind: "number" },
  { label: "A la bandeja", value: (row) => row.unmatched, width: 12, kind: "number" },
  { label: "Iniciada por", value: (row) => row.started_by ?? "Sistema", width: 24 },
  { label: "Inicio", value: (row) => when(row.started_at), width: 22, kind: "center" },
  { label: "Fin", value: (row) => (row.finished_at ? when(row.finished_at) : ""), width: 22, kind: "center" },
  { label: "Error", value: (row) => row.error ?? "", width: 40 },
];

export function RunCounts({ run }: { run: Pick<HrSyncRun, "received" | "applied" | "skipped" | "unmatched"> }) {
  return (
    <span className="whitespace-nowrap text-[12.5px] tabular-nums">
      {run.received} recibidos · {run.applied} aplicados · {run.skipped} sin cambios · {run.unmatched} a la bandeja
    </span>
  );
}

export function HrRunsTable({ toast, reload }: { toast: Toast; reload: number }) {
  const list = usePaged<HrSyncRun>("/v1/hr/sync/runs", {}, reload);
  const exporter = useSyncExport(toast, "/v1/hr/sync/runs", list.query, "ejecuciones-rrhh", "Ejecuciones de la sincronización con RRHH", RUN_COLUMNS);
  const cols: Column<HrSyncRun>[] = [
    { label: "Tipo", render: (row) => <span className="whitespace-nowrap font-medium">{row.kind_label}</span> },
    { label: "Estado", render: (row) => <Badge tone={stateOf(RUN_STATES, row.status)[1]}>{stateOf(RUN_STATES, row.status)[0]}</Badge> },
    { label: "Resultado", render: (row) => <RunCounts run={row} /> },
    { label: "Iniciada por", render: (row) => <span className="whitespace-nowrap">{row.started_by ?? "Sistema"}</span> },
    { label: "Inicio", render: (row) => <span className="whitespace-nowrap">{when(row.started_at)}</span> },
    { label: "Fin", render: (row) => <span className="whitespace-nowrap">{when(row.finished_at)}</span> },
    { label: "Error", render: (row) => (row.error ? <span className="block max-w-[260px] text-xs text-[color:var(--p-danger-text)] [overflow-wrap:anywhere]">{row.error}</span> : muted(null)) },
  ];
  return <PagedCard list={list} cols={cols} rowKey={(row) => row.id} exporter={exporter} tableId="hr-sync-runs" empty="Aún no hay ejecuciones." />;
}

// ------------------------------------------------------------------ Mensajes

const INBOUND_COLUMNS: ExportColumn<HrInboundEvent>[] = [
  { label: "Mensaje", value: (row) => row.event_id, width: 30 },
  { label: "Tipo", value: (row) => eventLabel(row.event_type), width: 22 },
  {
    label: "Estado", value: (row) => stateOf(INBOUND_STATES, row.status)[0], width: 16, kind: "status",
    tone: (row) => excelTone(stateOf(INBOUND_STATES, row.status)[1]),
  },
  { label: "Resultado", value: (row) => row.result ?? "", width: 40 },
  { label: "Ocurrió", value: (row) => (row.occurred_at ? when(row.occurred_at) : ""), width: 22, kind: "center" },
  { label: "Recibido", value: (row) => when(row.received_at), width: 22, kind: "center" },
  { label: "Procesado", value: (row) => (row.processed_at ? when(row.processed_at) : ""), width: 22, kind: "center" },
];

const OUTBOUND_COLUMNS: ExportColumn<HrOutboundEvent>[] = [
  { label: "Mensaje", value: (row) => row.event_id, width: 30 },
  { label: "Tipo", value: (row) => eventLabel(row.event_type), width: 22 },
  {
    label: "Estado", value: (row) => stateOf(OUTBOUND_STATES, row.status)[0], width: 16, kind: "status",
    tone: (row) => excelTone(stateOf(OUTBOUND_STATES, row.status)[1]),
  },
  { label: "Intentos", value: (row) => row.attempts, width: 10, kind: "number" },
  { label: "Respuesta", value: (row) => row.response_code ?? "", width: 12, kind: "center" },
  { label: "Error", value: (row) => row.last_error ?? "", width: 40 },
  { label: "Enviado", value: (row) => (row.sent_at ? when(row.sent_at) : ""), width: 22, kind: "center" },
  { label: "Próximo intento", value: (row) => (row.next_attempt_at ? when(row.next_attempt_at) : ""), width: 22, kind: "center" },
  { label: "Creado", value: (row) => when(row.created_at), width: 22, kind: "center" },
];

const eventCell = (eventId: string, type: string) => (
  <div className="min-w-[150px]">
    <span className="font-medium">{eventLabel(type)}</span>
    <code className="app-muted block font-mono text-[11px] [overflow-wrap:anywhere]">{eventId}</code>
  </div>
);

function InboundTable({ toast, reload }: { toast: Toast; reload: number }) {
  const [status, setStatus] = useState("");
  const list = usePaged<HrInboundEvent>("/v1/hr/sync/events", { direction: "in", status }, reload);
  const exporter = useSyncExport(toast, "/v1/hr/sync/events", list.query, "mensajes-recibidos-rrhh", "Mensajes recibidos del SaaS de RRHH", INBOUND_COLUMNS);
  const cols: Column<HrInboundEvent>[] = [
    { label: "Mensaje", render: (row) => eventCell(row.event_id, row.event_type) },
    { label: "Estado", render: (row) => <Badge tone={stateOf(INBOUND_STATES, row.status)[1]}>{stateOf(INBOUND_STATES, row.status)[0]}</Badge> },
    { label: "Resultado", render: (row) => (row.result ? <span className="block max-w-[280px] text-[12.5px] [overflow-wrap:anywhere]">{row.result}</span> : muted(null)) },
    { label: "Ocurrió", render: (row) => <span className="whitespace-nowrap">{when(row.occurred_at)}</span> },
    { label: "Recibido", render: (row) => <span className="whitespace-nowrap">{when(row.received_at)}</span> },
    { label: "Procesado", render: (row) => <span className="whitespace-nowrap">{when(row.processed_at)}</span> },
  ];
  return (
    <>
      <Filters>
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); list.setPage(1); }} options={INBOUND_STATES.map(([key, label]) => [key, label])} />
      </Filters>
      <PagedCard list={list} cols={cols} rowKey={(row) => row.id} exporter={exporter} tableId="hr-events-in" empty="No hay mensajes recibidos con estos filtros." />
    </>
  );
}

function OutboundTable({ toast, reload, onChanged }: { toast: Toast; reload: number; onChanged: () => void }) {
  const [status, setStatus] = useState("");
  const list = usePaged<HrOutboundEvent>("/v1/hr/sync/events", { direction: "out", status }, reload);
  const exporter = useSyncExport(toast, "/v1/hr/sync/events", list.query, "mensajes-enviados-rrhh", "Mensajes enviados al SaaS de RRHH", OUTBOUND_COLUMNS);
  const [busy, setBusy] = useState<string | null>(null);

  async function retry(row: HrOutboundEvent) {
    setBusy(row.id);
    try {
      const result = await apiFetch<{ status: string }>(`/v1/hr/sync/events/${row.id}/retry`, { method: "POST" });
      const [label, tone] = stateOf(OUTBOUND_STATES, result.status);
      toast(`Mensaje reenviado · ${label}`, tone === "danger" ? "danger" : "success");
      onChanged();
    } catch (err) {
      toast(firstError(err, "No se pudo reenviar."), "danger");
    } finally {
      setBusy(null);
    }
  }

  const cols: Column<HrOutboundEvent>[] = [
    { label: "Mensaje", render: (row) => eventCell(row.event_id, row.event_type) },
    { label: "Estado", render: (row) => <Badge tone={stateOf(OUTBOUND_STATES, row.status)[1]}>{stateOf(OUTBOUND_STATES, row.status)[0]}</Badge> },
    { label: "Intentos", num: true, render: (row) => row.attempts },
    { label: "Respuesta", render: (row) => muted(row.response_code) },
    { label: "Error", render: (row) => (row.last_error ? <span className="block max-w-[240px] text-xs [overflow-wrap:anywhere]">{row.last_error}</span> : muted(null)) },
    { label: "Enviado", render: (row) => <span className="whitespace-nowrap">{when(row.sent_at)}</span> },
    { label: "Próximo intento", render: (row) => <span className="whitespace-nowrap">{row.status === "PENDING" || row.status === "RETRY" ? when(row.next_attempt_at) : "—"}</span> },
    {
      label: "Acciones",
      render: (row) => (row.status === "DEAD" ? (
        <Btn size="sm" variant="secondary" permission="hr_sync.manage" disabled={busy !== null} onClick={() => retry(row)}>
          <RotateCcw size={14} />
          <span>{busy === row.id ? "Reenviando…" : "Reenviar"}</span>
        </Btn>
      ) : null),
    },
  ];
  return (
    <>
      <Filters>
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); list.setPage(1); }} options={OUTBOUND_STATES.map(([key, label]) => [key, label])} />
      </Filters>
      <PagedCard list={list} cols={cols} rowKey={(row) => row.id} exporter={exporter} tableId="hr-events-out" empty="No hay mensajes enviados con estos filtros." />
    </>
  );
}

export function HrEventsTables({ toast, reload, onChanged, failedOut }: { toast: Toast; reload: number; onChanged: () => void; failedOut: number }) {
  const [direction, setDirection] = useState<"in" | "out">("in");
  return (
    <>
      <div role="group" aria-label="Mensajes" className="mb-3 inline-flex rounded-[9px] border border-[color:var(--app-border)] p-[3px]">
        {([["in", "Recibidos"], ["out", failedOut ? `Enviados (${failedOut} fallidos)` : "Enviados"]] as const).map(([key, label]) => (
          <button
            key={key}
            type="button"
            aria-pressed={direction === key}
            onClick={() => setDirection(key)}
            className={`h-8 cursor-pointer whitespace-nowrap rounded-[7px] px-3 text-[12.5px] font-medium ${
              direction === key ? "bg-[var(--p-accent,#1B8FC9)] text-[color:var(--p-on-accent,#fff)]" : "app-muted hover:bg-[var(--app-surface-muted)]"
            }`}
          >
            {label}
          </button>
        ))}
      </div>
      {direction === "in" ? <InboundTable toast={toast} reload={reload} /> : <OutboundTable toast={toast} reload={reload} onChanged={onChanged} />}
    </>
  );
}
