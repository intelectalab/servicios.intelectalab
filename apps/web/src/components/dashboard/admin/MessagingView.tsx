"use client";

import { useEffect, useState } from "react";
import { AlertTriangle, CheckCheck, FileText, MailOpen, Send } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  AUDIENCES, MESSAGE_STATES, SAMPLE, render, type MessageTemplate, type MessagingData, type OutgoingMessage,
} from "@/lib/messaging";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, Kpi, Kpis, Kv, LoadError, Modal, Notice, PAGE_SIZES,
  PageHead, Pager, Screen, SearchInput, Tabs, applySort, deniedText, useToasts, type Column, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import type { PaginatedResponse } from "@/types/customer";

const audienceLabel = (audience: string) => AUDIENCES.find(([key]) => key === audience)?.[1] ?? audience;

const TEMPLATE_EXPORT: ExportColumn<MessageTemplate>[] = [
  { label: "Plantilla", value: (t) => t.label, width: 36 },
  { label: "Evento", value: (t) => t.event, width: 30 },
  { label: "Canal", value: () => "Correo", width: 12, kind: "center" },
  { label: "Destinatario", value: (t) => audienceLabel(t.audience), width: 16 },
  { label: "Asunto", value: (t) => t.subject, width: 40 },
  { label: "Personalizada", value: (t) => (t.customized ? "Sí" : "No"), width: 14, kind: "center" },
  { label: "Activa", value: (t) => (t.active ? "Sí" : "No"), width: 10, kind: "center" },
];

const MESSAGE_EXPORT: ExportColumn<OutgoingMessage>[] = [
  { label: "Fecha", value: (m) => dateTime(m.at), width: 22, kind: "center" },
  { label: "Canal", value: (m) => (m.channel === "EMAIL" ? "Correo" : "WhatsApp"), width: 12, kind: "center" },
  { label: "Destinatario", value: (m) => audienceLabel(m.audience), width: 16 },
  { label: "Para", value: (m) => [m.to_name, m.to].filter(Boolean).join(" · "), width: 40 },
  { label: "Asunto", value: (m) => m.subject, width: 44 },
  { label: "Evento", value: (m) => m.event_label, width: 30 },
  { label: "Estado", value: (m) => stateOf(MESSAGE_STATES, m.status)[0], width: 14, kind: "status", tone: (m) => excelTone(stateOf(MESSAGE_STATES, m.status)[1]) },
  { label: "Abierto", value: (m) => (m.opened_at ? dateTime(m.opened_at) : ""), width: 22, kind: "center" },
  { label: "Motivo", value: (m) => m.error ?? "", width: 40 },
];

function TemplateModal({ template, canManage, onClose, onSaved }: {
  template: MessageTemplate; canManage: boolean; onClose: () => void; onSaved: (data: MessagingData, message: string) => void;
}) {
  const [subject, setSubject] = useState(template.subject);
  const [body, setBody] = useState(template.body);
  const [active, setActive] = useState(template.active);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const sample = { ...SAMPLE, ...(template.audience === "INTERNAL" ? {} : { numero: template.event.includes("ticket") ? "TCK-2026-0031" : template.event.includes("invoice") ? "F001-00000123" : "COT-2026-0012" }) };

  async function save() {
    setBusy(true);
    setError("");
    try {
      const data = await apiFetch<MessagingData>(`/v1/message-templates/${template.event}`, {
        method: "PUT",
        body: JSON.stringify({ subject, body, active }),
        headers: template.lock_version > 0 ? { "If-Match": String(template.lock_version) } : {},
      });
      onSaved(data, "Plantilla guardada");
    } catch (err) {
      setError(firstError(err, "No se pudo guardar la plantilla."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={template.label}
      size="lg"
      onClose={onClose}
      footer={
        <div className="flex flex-wrap justify-end gap-2">
          <Btn variant="ghost" onClick={() => { setSubject(template.default_subject); setBody(template.default_body); }} disabled={!canManage || busy}>
            Texto del sistema
          </Btn>
          <Btn variant="ghost" onClick={onClose} disabled={busy}>Cancelar</Btn>
          <Btn permission="messaging.manage" onClick={save} disabled={busy}>{busy ? "Guardando…" : "Guardar"}</Btn>
        </div>
      }
    >
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Chip tone="info">Correo</Chip>
        <Chip tone={template.audience === "CUSTOMER" ? "accent" : "neutral"}>{audienceLabel(template.audience)}</Chip>
        <code className="app-muted text-[12px]">{template.event}</code>
      </div>
      <div className="grid gap-3">
        <Field label="Asunto" required>
          <input value={subject} onChange={(e) => setSubject(e.target.value)} disabled={!canManage} maxLength={200}
            className="app-input h-10 w-full rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3" />
        </Field>
        <Field label="Mensaje" required hint="Deja una línea en blanco entre párrafos.">
          <textarea value={body} onChange={(e) => setBody(e.target.value)} disabled={!canManage} rows={7} maxLength={5000}
            className="app-input w-full rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3 py-2 text-[13px]" />
        </Field>
        <div className="flex flex-wrap items-center gap-1.5 text-[12px]">
          <span className="app-muted">Variables:</span>
          {template.variables.map((variable) => (
            <button key={variable} type="button" disabled={!canManage} onClick={() => setBody((current) => `${current}{${variable}}`)}
              className="rounded-md bg-[var(--app-surface-muted)] px-1.5 py-0.5 font-mono hover:bg-[var(--p-brand-soft)] disabled:cursor-default">
              {`{${variable}}`}
            </button>
          ))}
        </div>
        <label className="flex items-center gap-2 text-[13px]">
          <input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} disabled={!canManage} />
          <span>Activa: se envía este correo cuando ocurre el evento</span>
        </label>
        <div className="rounded-xl border border-[color:var(--app-border)] p-3">
          <p className="app-muted mb-1 text-[11px] font-semibold uppercase tracking-[.04em]">Vista previa</p>
          <p className="font-semibold">{render(subject, sample)}</p>
          <p className="mt-1 whitespace-pre-line text-[13px]">{render(body, sample)}</p>
        </div>
        {!canManage && <p className="app-muted text-[12px]">{deniedText("messaging.manage")}</p>}
        {error && <p className="text-[12.5px] font-medium text-[color:var(--p-danger-text)]">{error}</p>}
      </div>
    </Modal>
  );
}

export default function MessagingView() {
  const { can, loading: accessLoading } = useAccess();
  const canManage = !accessLoading && can("messaging.manage");
  const { toast, toasts } = useToasts();
  const [data, setData] = useState<MessagingData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [tab, setTab] = useState("TPL");
  const [editing, setEditing] = useState<MessageTemplate | null>(null);
  const [exportingTemplates, setExportingTemplates] = useState(false);

  // Bitácora
  const [log, setLog] = useState<PaginatedResponse<OutgoingMessage> | null>(null);
  const [logLoading, setLogLoading] = useState(true);
  const [logError, setLogError] = useState("");
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [audienceFilter, setAudienceFilter] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [sort, setSort] = useState<SortState>(null);
  const [exporting, setExporting] = useState(false);
  const [viewing, setViewing] = useState<OutgoingMessage | null>(null);
  const [retrying, setRetrying] = useState(false);
  const columnFilters = useServerColumnFilters(() => setPage(1));

  // Canales
  const [channels, setChannels] = useState({ email: true, sender_name: "", reply_to: "" });
  const [savingChannels, setSavingChannels] = useState(false);
  const [channelsError, setChannelsError] = useState("");

  useEffect(() => {
    let cancelled = false;
    apiFetch<MessagingData>("/v1/messaging")
      .then((response) => {
        if (cancelled) return;
        setData(response);
        setChannels({ email: response.channels.email, sender_name: response.channels.sender_name, reply_to: response.channels.reply_to });
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar las notificaciones."); });
    return () => { cancelled = true; };
  }, [reload]);

  const logParams = (params: URLSearchParams) => {
    if (search.trim()) params.set("search", search.trim());
    if (statusFilter) params.set("status", statusFilter);
    if (audienceFilter) params.set("audience", audienceFilter);
    return params;
  };

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLogLoading(true);
      const params = logParams(new URLSearchParams({ per_page: String(perPage), page: String(page) }));
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<PaginatedResponse<OutgoingMessage>>(`/v1/outgoing-messages?${params}`);
        if (!cancelled) { setLog(response); setLogError(""); }
      } catch {
        if (!cancelled) setLogError("No se pudo cargar la bitácora.");
      } finally {
        if (!cancelled) setLogLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- logParams solo lee los filtros, que ya están en la lista
  }, [page, perPage, search, statusFilter, audienceFilter, columnFilters.param, sort, reload]);

  async function saveChannels() {
    setSavingChannels(true);
    setChannelsError("");
    try {
      const response = await apiFetch<MessagingData>("/v1/messaging/channels", { method: "PUT", body: JSON.stringify(channels) });
      setData(response);
      toast("Canales guardados");
    } catch (err) {
      setChannelsError(firstError(err, "No se pudieron guardar los canales."));
    } finally {
      setSavingChannels(false);
    }
  }

  async function openMessage(message: OutgoingMessage) {
    setViewing(message);
    try {
      setViewing(await apiFetch<OutgoingMessage>(`/v1/outgoing-messages/${message.id}`));
    } catch (err) {
      toast(firstError(err, "No se pudo abrir el mensaje."), "danger");
      setViewing(null);
    }
  }

  async function retry(message: OutgoingMessage) {
    setRetrying(true);
    try {
      const updated = await apiFetch<OutgoingMessage>(`/v1/outgoing-messages/${message.id}/retry`, { method: "POST" });
      setViewing((current) => (current?.id === updated.id ? updated : current));
      toast(updated.status === "SENT" ? "Mensaje enviado" : "El envío volvió a fallar", updated.status === "SENT" ? undefined : "danger");
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo reintentar."), "danger");
    } finally {
      setRetrying(false);
    }
  }

  async function exportLog(format: "xlsx" | "csv") {
    setExporting(true);
    try {
      const rows = await fetchAllPages<OutgoingMessage>("/v1/outgoing-messages", applySort(columnFilters.apply(logParams(new URLSearchParams())), sort));
      await recordExport("messaging", format, rows.length);
      if (format === "xlsx") await exportXlsx("bitacora-de-envios", "Bitácora de envíos", MESSAGE_EXPORT, rows);
      else exportCsv("bitacora-de-envios", MESSAGE_EXPORT, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  async function exportTemplates(format: "xlsx" | "csv") {
    if (!data) return;
    setExportingTemplates(true);
    try {
      await recordExport("messaging", format, data.templates.length);
      if (format === "xlsx") await exportXlsx("plantillas-de-notificacion", "Plantillas de notificación", TEMPLATE_EXPORT, data.templates);
      else exportCsv("plantillas-de-notificacion", TEMPLATE_EXPORT, data.templates);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExportingTemplates(false);
    }
  }

  const templateCols: Column<MessageTemplate>[] = [
    {
      label: "Plantilla",
      render: (t) => (
        <span className="block min-w-0">
          <strong className="block font-semibold">{t.label}</strong>
          <small className="app-muted block text-[12px]">{t.customized ? "Texto de la empresa" : "Texto del sistema"} · <code>{t.event}</code></small>
        </span>
      ),
    },
    { label: "Canal", render: () => <Chip tone="info">Correo</Chip> },
    { label: "Destinatario", render: (t) => audienceLabel(t.audience) },
    { label: "Aprobación Meta", render: () => <span className="app-muted">—</span> },
    { label: "Activa", render: (t) => (t.active ? <Chip tone="success">Sí</Chip> : <Chip tone="muted">No</Chip>) },
    {
      label: "",
      render: (t) => (
        <Btn variant="ghost" size="sm" onClick={() => setEditing(t)}>{canManage ? "Editar" : "Ver"}</Btn>
      ),
    },
  ];

  const logCols: Column<OutgoingMessage>[] = [
    { label: "Fecha", sortKey: "date", filter: "date", render: (m) => <span className="whitespace-nowrap">{dateTime(m.at)}</span> },
    { label: "Canal", render: (m) => <Chip tone="info">{m.channel === "EMAIL" ? "Correo" : "WhatsApp"}</Chip> },
    {
      label: "Para", sortKey: "to", filter: "text",
      render: (m) => (
        <span className="block min-w-0">
          {m.to_name && <span className="block">{m.to_name}</span>}
          <small className="app-muted block font-mono text-[12px] [overflow-wrap:anywhere]">{m.to ?? "Sin correo"}</small>
        </span>
      ),
    },
    {
      label: "Mensaje", sortKey: "subject", filter: "text",
      render: (m) => (
        <span className="block min-w-0">
          <strong className="block font-semibold [overflow-wrap:anywhere]">{m.subject}</strong>
          <small className="app-muted block text-[12px]">{audienceLabel(m.audience)} · {m.event_label}</small>
        </span>
      ),
    },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (m) => {
        const [label, tone] = stateOf(MESSAGE_STATES, m.status);
        return (
          <span className="inline-flex flex-col items-start gap-0.5">
            <Badge tone={tone}>{label}</Badge>
            {m.opened_at && <small className="text-[12px] text-[color:var(--p-success-text)]">Abierto {dateTime(m.opened_at)}</small>}
            {m.error && <small className={`text-[12px] ${m.status === "FAILED" ? "text-[color:var(--p-danger-text)]" : "app-muted"}`}>{m.error}</small>}
          </span>
        );
      },
    },
    {
      label: "",
      render: (m) => m.status === "FAILED" ? (
        <Btn variant="secondary" size="sm" permission="messaging.manage" disabled={retrying} onClick={(event) => { event.stopPropagation(); retry(m); }}>Reintentar</Btn>
      ) : null,
    },
  ];

  return (
    <Screen>
      <PageHead title="Notificaciones" />

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : !data ? (
        <Card><p className="app-muted">Cargando notificaciones…</p></Card>
      ) : (
        <>
          <Kpis>
            <Kpi label="Mensajes enviados" value={data.kpis.sent} icon={<Send size={20} />} />
            <Kpi label="Abiertos" value={data.kpis.opened} sub={data.kpis.sent ? `${Math.round((data.kpis.opened / data.kpis.sent) * 100)} % de los enviados` : undefined} icon={<MailOpen size={20} />} tone="success" />
            <Kpi label="Fallidos" value={data.kpis.failed} sub={data.kpis.queued ? `${data.kpis.queued} en cola` : undefined} icon={<AlertTriangle size={20} />} tone="danger" />
            <Kpi label="Plantillas activas" value={data.kpis.active_templates} sub={`de ${data.templates.length}`} icon={<FileText size={20} />} tone="warning" />
          </Kpis>

          <Tabs
            tabs={[["TPL", "Plantillas", null], ["LOG", "Bitácora", data.kpis.total], ["CH", "Canales", null]]}
            value={tab}
            onChange={setTab}
          />

          {tab === "TPL" && (
            <Card title="Plantillas por evento" flush>
              <DataTable tableId="message-templates" cols={templateCols} rows={data.templates} rowKey={(t) => t.event} onRowClick={setEditing} />
              <div className="px-5 py-3">
                <ExportButtons permission="messaging.export" total={data.templates.length} busy={exportingTemplates} onExport={exportTemplates} />
              </div>
            </Card>
          )}

          {tab === "LOG" && (
            <Card flush>
              <div className="px-5 pt-4">
                <Filters>
                  <SearchInput value={search} onChange={(value) => { setSearch(value); setPage(1); }} placeholder="Buscar destinatario o contenido" />
                  <FilterSelect label="Destinatario" value={audienceFilter} onChange={(value) => { setAudienceFilter(value); setPage(1); }} options={AUDIENCES} />
                  <FilterSelect label="Estado" value={statusFilter} onChange={(value) => { setStatusFilter(value); setPage(1); }} options={MESSAGE_STATES.map(([key, label]) => [key, label])} />
                  <ColumnFilterBar
                    filters={columnFilters}
                    hasOtherFilters={Boolean(search || audienceFilter || statusFilter)}
                    onClearAll={() => { setSearch(""); setAudienceFilter(""); setStatusFilter(""); setPage(1); }}
                  />
                </Filters>
              </div>
              {logError ? (
                <div className="px-5 pb-4"><LoadError text={logError} onRetry={() => setReload((n) => n + 1)} /></div>
              ) : (
                <>
                  <DataTable
                    tableId="outgoing-messages" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={logLoading}
                    onFilter={(column) => addColumnFilter(columnFilters, column)}
                    cols={logCols}
                    rows={log?.data ?? []}
                    rowKey={(m) => m.id}
                    onRowClick={openMessage}
                    loading={logLoading && !log}
                    empty="Aún no sale ningún correo."
                  />
                  {log && (
                    <Pager
                      loading={logLoading}
                      page={log.current_page}
                      lastPage={log.last_page}
                      total={log.total}
                      perPage={log.per_page}
                      onPage={setPage}
                      onPerPage={(size) => { setPerPage(size); setPage(1); }}
                      left={<ExportButtons permission="messaging.export" total={log.total} busy={exporting} onExport={exportLog} />}
                    />
                  )}
                </>
              )}
            </Card>
          )}

          {tab === "CH" && (
            <div className="grid gap-x-[18px] lg:grid-cols-2">
              <Card title="Canales de la empresa">
                <div className="grid gap-3">
                  <label className="flex items-start gap-2 text-[13px]">
                    <input type="checkbox" className="mt-1" checked={channels.email} disabled={!canManage} onChange={(e) => setChannels((c) => ({ ...c, email: e.target.checked }))} />
                    <span>
                      <strong className="block font-semibold">Correo transaccional</strong>
                      <span className="app-muted text-[12px]">Avisos del equipo y correos al cliente. Apagado, ningún correo de la empresa sale y queda Omitido en la bitácora.</span>
                    </span>
                  </label>
                  <Field label="Nombre del remitente" hint={`Sale desde ${data.channels.from_address}. Sin nombre, usa el de la plataforma.`}>
                    <input value={channels.sender_name} maxLength={80} disabled={!canManage} onChange={(e) => setChannels((c) => ({ ...c, sender_name: e.target.value }))}
                      placeholder="Ej.: Soporte de tu empresa" className="app-input h-10 w-full rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3" />
                  </Field>
                  <Field label="Correo para respuestas" hint="Si el cliente responde el correo, le llega a esta dirección.">
                    <input type="email" value={channels.reply_to} disabled={!canManage} onChange={(e) => setChannels((c) => ({ ...c, reply_to: e.target.value }))}
                      placeholder="soporte@tuempresa.com" className="app-input h-10 w-full rounded-lg border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3" />
                  </Field>
                  <label className="flex items-start gap-2 text-[13px] opacity-60">
                    <input type="checkbox" className="mt-1" checked={false} disabled readOnly />
                    <span>
                      <strong className="block font-semibold">WhatsApp Business</strong>
                      <span className="app-muted text-[12px]">Proveedor por definir: no se envía ni se simula ningún mensaje.</span>
                    </span>
                  </label>
                  {channelsError && <p className="text-[12.5px] font-medium text-[color:var(--p-danger-text)]">{channelsError}</p>}
                  <div className="flex justify-end">
                    <Btn permission="messaging.manage" onClick={saveChannels} disabled={savingChannels}>{savingChannels ? "Guardando…" : "Guardar"}</Btn>
                  </div>
                </div>
              </Card>
              <Card title="Apertura de los correos">
                <Notice>
                  <p className="text-[12.5px]">
                    Cada correo lleva una imagen invisible que marca cuándo se abrió. Es un dato aproximado: algunos programas de correo no cargan imágenes y otros las abren solos.
                  </p>
                </Notice>
                <p className="app-muted mt-2 flex items-center gap-1.5 text-[12.5px]"><CheckCheck size={14} /> {data.kpis.opened} de {data.kpis.sent} enviados se abrieron.</p>
              </Card>
            </div>
          )}
        </>
      )}

      {editing && (
        <TemplateModal
          template={editing}
          canManage={canManage}
          onClose={() => setEditing(null)}
          onSaved={(response, message) => { setData(response); setEditing(null); toast(message); }}
        />
      )}

      {viewing && (
        <Modal
          title="Mensaje"
          size="lg"
          onClose={() => setViewing(null)}
          footer={viewing.status === "FAILED" ? (
            <div className="flex justify-end">
              <Btn permission="messaging.manage" onClick={() => retry(viewing)} disabled={retrying}>{retrying ? "Reintentando…" : "Reintentar"}</Btn>
            </div>
          ) : undefined}
        >
          <Kv rows={[
            ["Para", [viewing.to_name, viewing.to].filter(Boolean).join(" · ") || "Sin correo"],
            ["Destinatario", audienceLabel(viewing.audience)],
            ["Evento", viewing.event_label],
            ["Estado", <Badge key="s" tone={stateOf(MESSAGE_STATES, viewing.status)[1]}>{stateOf(MESSAGE_STATES, viewing.status)[0]}</Badge>],
            viewing.error ? ["Motivo", viewing.error] : null,
            ["Creado", dateTime(viewing.at)],
            viewing.sent_at ? ["Enviado", dateTime(viewing.sent_at)] : null,
            viewing.opened_at ? ["Abierto", dateTime(viewing.opened_at)] : null,
            ["Intentos", String(viewing.attempts)],
          ]} />
          <div className="mt-3 rounded-xl border border-[color:var(--app-border)] p-3">
            <p className="font-semibold">{viewing.subject}</p>
            {viewing.body === undefined ? <p className="app-muted mt-1">Cargando…</p> : <p className="mt-1 whitespace-pre-line text-[13px]">{viewing.body}</p>}
          </div>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
