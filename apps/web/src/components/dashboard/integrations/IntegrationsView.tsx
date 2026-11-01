"use client";

import { useEffect, useState } from "react";
import { KeyRound, Mail, MessageCircle, Pencil, Plus, Receipt, Send, Webhook, Wallet } from "lucide-react";
import Link from "next/link";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { CONNECTION_STATES, DELIVERY_STATES, type Delivery, type IntegrationsData, type WebhookEndpoint } from "@/lib/integrations";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, LoadError, PAGE_SIZES, PageHead, Pager, Screen, Tabs,
  applySort, deniedText, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { DeliveryModal, SecretModal, WebhookModal } from "@/components/dashboard/integrations/IntegrationModals";
import type { PaginatedResponse } from "@/types/customer";

type Tab = "connections" | "webhooks" | "queue" | "dead";

const CONNECTION_ICONS: Record<string, React.ReactNode> = {
  fiscal: <Receipt size={18} />, mail: <Mail size={18} />, payments: <Wallet size={18} />, whatsapp: <MessageCircle size={18} />,
};

const EXPORT_COLUMNS: ExportColumn<Delivery>[] = [
  { label: "Fecha", value: (d) => (d.created_at ? dateTime(d.created_at) : ""), width: 22, kind: "center" },
  { label: "Webhook", value: (d) => d.endpoint, width: 26 },
  { label: "Evento", value: (d) => d.event_label, width: 24 },
  { label: "Registro", value: (d) => d.record ?? "", width: 34 },
  { label: "Estado", value: (d) => stateOf(DELIVERY_STATES, d.status)[0], width: 14, kind: "status", tone: (d) => excelTone(stateOf(DELIVERY_STATES, d.status)[1]) },
  { label: "Intentos", value: (d) => d.attempts, width: 10, kind: "number" },
  { label: "Respuesta", value: (d) => d.response_code ?? "", width: 10, kind: "center" },
  { label: "Último error", value: (d) => d.error ?? "", width: 40 },
];

const deliveryKey = (d: Delivery) => d.id;

/** Cola de envíos o Fallidos (la misma tabla con otro filtro de estado). */
function DeliveriesTable({ data, onlyDead, onOpen, version }: { data: IntegrationsData; onlyDead: boolean; onOpen: (id: string) => void; version: number }) {
  const { toast, toasts } = useToasts();
  const [status, setStatus] = useState("");
  const [endpointId, setEndpointId] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [rows, setRows] = useState<PaginatedResponse<Delivery> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [exporting, setExporting] = useState(false);
  const columnFilters = useServerColumnFilters(() => setPage(1));

  const params = (p: URLSearchParams) => {
    if (onlyDead) p.set("status", "DEAD");
    else if (status) p.set("status", status);
    if (endpointId) p.set("endpoint_id", endpointId);
    return p;
  };

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const p = params(new URLSearchParams({ per_page: String(perPage), page: String(page) }));
      applySort(applyFilters(p, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<Delivery>>(`/v1/integration-deliveries?${p}`);
        if (!cancelled) { setRows(response); setError(""); }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los envíos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- params solo lee filtros que ya están en la lista
  }, [onlyDead, status, endpointId, page, perPage, sort, columnFilters.param, reload, version]);

  async function runExport(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const all = await fetchAllPages<Delivery>("/v1/integration-deliveries", applySort(columnFilters.apply(params(new URLSearchParams())), sort));
      await recordExport("integrations", format, all.length);
      const name = onlyDead ? "webhooks-fallidos" : "webhooks-envios";
      if (format === "xlsx") await exportXlsx(name, onlyDead ? "Envíos fallidos" : "Cola de envíos", EXPORT_COLUMNS, all);
      else exportCsv(name, EXPORT_COLUMNS, all);
      toast(`${all.length} ${all.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const cols: Column<Delivery>[] = [
    { label: "Fecha", sortKey: "date", filter: "date", render: (d) => <span className="whitespace-nowrap">{d.created_at ? dateTime(d.created_at) : "—"}</span> },
    { label: "Webhook", sortKey: "endpoint", filter: "text", render: (d) => <span className="font-semibold">{d.endpoint}</span> },
    { label: "Evento", render: (d) => <code className="whitespace-nowrap text-[12px]">{d.event_label}</code> },
    { label: "Registro", sortKey: "record", filter: "text", render: (d) => <span className="[overflow-wrap:anywhere]">{d.record ?? "—"}</span> },
    { label: "Estado", sortKey: "status", filter: onlyDead ? undefined : { top: "Estado" }, render: (d) => { const [label, tone] = stateOf(DELIVERY_STATES, d.status); return <Badge tone={tone}>{label}</Badge>; } },
    { label: "Intentos", sortKey: "attempts", num: true, render: (d) => `${d.attempts} / ${data.max_attempts}` },
    {
      label: onlyDead ? "Último error" : "Resultado", sortKey: "error", filter: "text",
      render: (d) => (
        <span className="app-muted text-[12.5px]">
          {d.status === "SENT" ? `HTTP ${d.response_code ?? 200}` : d.error ?? (d.status === "PENDING" ? "Sale en el próximo minuto" : "—")}
          {d.status === "RETRY" && d.next_attempt_at && <small className="block">Próximo intento: {dateTime(d.next_attempt_at)}</small>}
        </span>
      ),
    },
  ];

  return (
    <Card flush>
      <div className="px-5 pt-3">
        <Filters>
          {!onlyDead && <FilterSelect label="Estado" value={status} onChange={(v) => { setStatus(v); setPage(1); }} options={DELIVERY_STATES.map(([k, l]) => [k, l])} />}
          <FilterSelect label="Webhook" value={endpointId} onChange={(v) => { setEndpointId(v); setPage(1); }} options={data.endpoints.map((e) => [e.id, e.name])} />
          <ColumnFilterBar filters={columnFilters} hasOtherFilters={Boolean(status || endpointId)} onClearAll={() => { setStatus(""); setEndpointId(""); setPage(1); }} />
        </Filters>
      </div>
      {error ? (
        <div className="px-5 pb-4"><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></div>
      ) : (
        <>
          <DataTable
            tableId={onlyDead ? "webhook-dead" : "webhook-queue"} sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols} rows={rows?.data ?? []} rowKey={deliveryKey} onRowClick={(d) => onOpen(d.id)}
            loading={loading && !rows}
            empty={onlyDead ? "No hay envíos fallidos." : "Todavía no hay envíos. Salen solos cuando ocurre un evento al que está suscrito un webhook activo."}
          />
          {rows && (
            <Pager
              loading={loading} page={rows.current_page} lastPage={rows.last_page} total={rows.total} perPage={rows.per_page}
              onPage={setPage} onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="integrations.export" total={rows.total} busy={exporting} onExport={runExport} />}
            />
          )}
        </>
      )}
      {toasts}
    </Card>
  );
}

export default function IntegrationsView() {
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("integrations.manage");
  const { toast, toasts } = useToasts();
  const [tab, setTab] = useState<Tab>("connections");
  const [data, setData] = useState<IntegrationsData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState<WebhookEndpoint | "new" | null>(null);
  const [secret, setSecret] = useState<string | null>(null);
  const [openDelivery, setOpenDelivery] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<IntegrationsData>("/v1/integrations")
      .then((d) => { if (!cancelled) { setData(d); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar las integraciones."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function act(endpoint: WebhookEndpoint, action: "toggle" | "rotate" | "test") {
    setBusy(`${endpoint.id}:${action}`);
    try {
      if (action === "toggle") {
        await apiFetch(`/v1/integrations/webhooks/${endpoint.id}/toggle`, { method: "POST", body: JSON.stringify({ active: !endpoint.active }) });
        toast(endpoint.active ? "Webhook desactivado" : "Webhook activado");
      } else if (action === "rotate") {
        const response = await apiFetch<{ secret: string }>(`/v1/integrations/webhooks/${endpoint.id}/rotate`, { method: "POST" });
        setSecret(response.secret);
      } else {
        const result = await apiFetch<Delivery | null>(`/v1/integrations/webhooks/${endpoint.id}/test`, { method: "POST" });
        if (result?.status === "SENT") toast("Prueba entregada", "success", `El otro sistema respondió ${result.response_code ?? 200}.`);
        else toast("La prueba no se pudo entregar", "danger", result?.error ?? undefined);
      }
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(null);
    }
  }

  async function retry(id: string) {
    try {
      await apiFetch(`/v1/integration-deliveries/${id}/retry`, { method: "POST" });
      toast("Envío reprocesado: sale en el próximo minuto");
      setOpenDelivery(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo reprocesar."), "danger");
    }
  }

  const tabs: [string, string, number | null][] = data ? [
    ["connections", "Conexiones", data.connections.length],
    ["webhooks", "Webhooks salientes", data.endpoints.length],
    ["queue", "Cola de envíos", data.counts.PENDING + data.counts.RETRY],
    ["dead", "Fallidos", data.counts.DEAD],
  ] : [];

  return (
    <Screen>
      <PageHead
        title="Integraciones"
        actions={
          <Btn permission="integrations.manage" disabled={!data} onClick={() => { setTab("webhooks"); setEditing("new"); }}>
            <Plus size={16} />
            <span>Nuevo webhook</span>
          </Btn>
        }
      />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <Card><p className="app-muted">Cargando integraciones…</p></Card>
      ) : (
        <>
          <Tabs tabs={tabs} value={tab} onChange={(key) => setTab(key as Tab)} />

          {tab === "connections" && (
            // Formato del prototipo: nombre y estado, proveedor, eventos, credencial y últimas 24 h.
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
              {data.connections.map((connection) => {
                const [label, tone] = CONNECTION_STATES[connection.status];
                const pending = connection.status === "PENDING_PROVIDER";
                return (
                  <section key={connection.key} className="app-surface flex flex-col rounded-xl p-4 shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]">
                    <div className="flex items-start justify-between gap-3">
                      <div className="flex min-w-0 items-center gap-2.5">
                        <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-[var(--p-brand-soft)] text-[color:var(--p-brand)]">{CONNECTION_ICONS[connection.key] ?? <Webhook size={18} />}</span>
                        <strong className="min-w-0 font-semibold [overflow-wrap:anywhere]">{connection.name}</strong>
                      </div>
                      <Badge tone={tone}>{label}</Badge>
                    </div>
                    <p className="app-muted mt-1.5 text-xs">{connection.provider}</p>
                    <dl className="mt-3 grid grid-cols-[96px_1fr] gap-x-2 gap-y-1.5 text-[12.5px]">
                      <dt className="app-muted">Eventos</dt>
                      <dd className="flex min-w-0 flex-wrap gap-1">
                        {connection.events.length ? connection.events.map((event) => <code key={event} className="rounded bg-[var(--app-surface-muted)] px-1.5 py-0.5 text-[11.5px]">{event}</code>) : <span className="app-muted">—</span>}
                      </dd>
                      <dt className="app-muted">Credencial</dt>
                      <dd>{connection.credential ?? <span className="app-muted">—</span>}</dd>
                      <dt className="app-muted">Últimas 24 h</dt>
                      <dd>
                        {connection.calls === null ? <span className="app-muted">—</span> : (
                          <>
                            {connection.calls} {connection.key === "mail" ? (connection.calls === 1 ? "correo" : "correos") : connection.calls === 1 ? "llamada" : "llamadas"}
                            {connection.errors !== null && <> · <span className={connection.errors ? "font-semibold text-[color:var(--p-danger-text)]" : ""}>{connection.errors} {connection.errors === 1 ? "error" : "errores"}</span></>}
                          </>
                        )}
                      </dd>
                    </dl>
                    {connection.note && <p className={`mt-3 text-xs ${pending ? "app-muted" : "text-[color:var(--p-warning-text)]"}`}>{connection.note}</p>}
                    <div className="mt-auto flex flex-wrap gap-1.5 pt-3">
                      {connection.link && <Link href={connection.link.href} className="text-xs font-semibold text-[color:var(--p-brand-text)] hover:underline">Ir a {connection.link.label} →</Link>}
                      {connection.endpoint_id && (
                        <>
                          <Btn size="sm" variant="ghost" onClick={() => setTab("webhooks")}>Configurar</Btn>
                          <Btn size="sm" variant="ghost" onClick={() => setTab("queue")}>Ver envíos</Btn>
                        </>
                      )}
                    </div>
                  </section>
                );
              })}
            </div>
          )}

          {tab === "webhooks" && (
            data.endpoints.length === 0 ? (
              <Card>
                <p className="font-semibold">Todavía no hay webhooks.</p>
                <p className="app-muted mt-1 text-[13px]">Un webhook avisa a otro sistema (contabilidad externa, Make, Zapier…) cuando pasa algo aquí: una OT completada, un comprobante emitido, un pago aplicado.</p>
              </Card>
            ) : (
              <div className="grid gap-3 lg:grid-cols-2">
                {data.endpoints.map((endpoint) => (
                  <section key={endpoint.id} className={`app-surface rounded-xl p-4 shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] ${endpoint.active ? "" : "opacity-70"}`}>
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <strong className="inline-flex items-center gap-1.5 font-semibold"><Webhook size={15} className="text-[color:var(--p-brand)]" />{endpoint.name}</strong>
                        <code className="app-muted mt-0.5 block font-mono text-[12px] [overflow-wrap:anywhere]">{endpoint.url}</code>
                        {endpoint.description && <p className="app-muted mt-1 text-xs">{endpoint.description}</p>}
                      </div>
                      <Badge tone={endpoint.active ? "success" : "muted"}>{endpoint.active ? "Activo" : "Inactivo"}</Badge>
                    </div>
                    <div className="mt-2.5 flex flex-wrap gap-1">
                      {endpoint.event_labels.map((label) => <Chip key={label} tone="info">{label}</Chip>)}
                    </div>
                    <p className="app-muted mt-2.5 text-[12px]">
                      {endpoint.sent} entregados · {endpoint.queued} en cola · <span className={endpoint.dead ? "font-semibold text-[color:var(--p-danger-text)]" : ""}>{endpoint.dead} fallidos</span>
                      {endpoint.last_delivered_at && ` · último ${dateTime(endpoint.last_delivered_at)}`}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-1.5">
                      <Btn size="sm" variant="secondary" permission="integrations.manage" disabled={!endpoint.active || busy !== null} title={endpoint.active ? undefined : "Actívalo para enviarle una prueba"} onClick={() => act(endpoint, "test")}>
                        <Send size={13} /><span>{busy === `${endpoint.id}:test` ? "Enviando…" : "Enviar prueba"}</span>
                      </Btn>
                      <Btn size="sm" variant="ghost" permission="integrations.manage" onClick={() => setEditing(endpoint)}><Pencil size={13} /><span>Editar</span></Btn>
                      <Btn size="sm" variant="ghost" permission="integrations.manage" disabled={busy !== null} onClick={() => act(endpoint, "rotate")}><KeyRound size={13} /><span>Rotar clave</span></Btn>
                      <Btn size="sm" variant={endpoint.active ? "danger-ghost" : "ghost"} permission="integrations.manage" disabled={busy !== null} onClick={() => act(endpoint, "toggle")}>
                        {endpoint.active ? "Desactivar" : "Activar"}
                      </Btn>
                    </div>
                    {!canManage && !accessLoading && <p className="app-muted mt-2 text-xs">{deniedText("integrations.manage")}</p>}
                  </section>
                ))}
              </div>
            )
          )}

          {tab === "queue" && <DeliveriesTable data={data} onlyDead={false} onOpen={setOpenDelivery} version={reload} />}
          {tab === "dead" && <DeliveriesTable data={data} onlyDead onOpen={setOpenDelivery} version={reload} />}
        </>
      )}

      {editing && data && (
        <WebhookModal
          data={data}
          endpoint={editing === "new" ? undefined : editing}
          toast={toast}
          onClose={() => setEditing(null)}
          onSaved={(_, newSecret) => { setEditing(null); if (newSecret) setSecret(newSecret); setReload((n) => n + 1); }}
        />
      )}
      {secret && <SecretModal secret={secret} onClose={() => setSecret(null)} />}
      {openDelivery && <DeliveryModal id={openDelivery} canRetry={canManage} onClose={() => setOpenDelivery(null)} onRetry={() => retry(openDelivery)} />}
      {toasts}
    </Screen>
  );
}
