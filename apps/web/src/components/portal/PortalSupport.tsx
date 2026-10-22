"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ApiError, apiFetch } from "@/lib/api";
import { excelTone, stateOf } from "@/lib/commercial";
import { TICKET_STATES } from "@/lib/support";
import type { ExportColumn } from "@/lib/export-table";
import { ATTENTION, dateTimeText, type PortalTicket, type PortalTicketRow } from "@/lib/portal";
import { Badge, Btn, Card, Chip, DataTable, Field, INPUT, LoadError, type Column } from "@/components/ui/proto";
import { PortalBack, PortalTitle, usePortal } from "./PortalShell";
import { PortalExport } from "./PortalExport";

function errorText(err: unknown, fallback: string) {
  return err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : fallback;
}

const EXPORT: ExportColumn<PortalTicketRow>[] = [
  { label: "Ticket", value: (row) => row.ticket_number, width: 18, kind: "center" },
  { label: "Asunto", value: (row) => row.subject, width: 44 },
  { label: "Creado", value: (row) => dateTimeText(row.created_at), width: 18, kind: "center" },
  { label: "Atención", value: (row) => ATTENTION[row.attention][0], width: 18, kind: "center" },
  { label: "Estado", value: (row) => stateOf(TICKET_STATES, row.status)[0], width: 16, kind: "status", tone: (row) => excelTone(stateOf(TICKET_STATES, row.status)[1]) },
];

export function PortalSupportList() {
  const router = useRouter();
  const { toast } = usePortal();
  const [rows, setRows] = useState<PortalTicketRow[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [subject, setSubject] = useState("");
  const [description, setDescription] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalTicketRow[]>("/v1/portal/tickets")
      .then((data) => { if (!cancelled) { setRows(data); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus tickets."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function create() {
    const next: Record<string, string> = {};
    if (!subject.trim()) next.subject = "El asunto es obligatorio.";
    if (!description.trim()) next.description = "Cuéntanos qué pasa.";
    setErrors(next);
    if (Object.keys(next).length) return;
    setBusy(true);
    try {
      await apiFetch("/v1/portal/tickets", { method: "POST", body: JSON.stringify({ subject: subject.trim(), description: description.trim() }) });
      toast("Ticket creado. Te responderemos pronto.");
      setSubject("");
      setDescription("");
      setReload((n) => n + 1);
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      else toast(errorText(err, "No se pudo crear el ticket."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const cols: Column<PortalTicketRow>[] = [
    {
      label: "Ticket",
      render: (row) => (
        <div className="min-w-[180px]">
          <strong className="font-semibold">{row.subject}</strong>
          <small className="app-muted block text-xs">{row.ticket_number}</small>
        </div>
      ),
    },
    { label: "Creado", render: (row) => dateTimeText(row.created_at) },
    { label: "Atención", render: (row) => { const [label, tone] = ATTENTION[row.attention]; return <Chip tone={tone}>{label}</Chip>; } },
    { label: "Estado", render: (row) => { const [label, tone] = stateOf(TICKET_STATES, row.status); return <Badge tone={tone}>{label}</Badge>; } },
  ];

  return (
    <>
      <PortalTitle>Soporte</PortalTitle>
      <Card title="Nuevo ticket">
        <div className="grid gap-3">
          <Field label="Asunto" required error={errors.subject}>
            <input className={INPUT} value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={255} />
          </Field>
          <Field label="Descripción" required error={errors.description}>
            <textarea className={INPUT} rows={3} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={5000} />
          </Field>
          <div className="flex justify-end">
            <Btn onClick={create} disabled={busy}>{busy ? "Creando…" : "Crear ticket"}</Btn>
          </div>
        </div>
      </Card>
      <Card title="Mis tickets" flush>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : (
          <>
            <div className="pt-2">
              <DataTable cols={cols} rows={rows ?? []} rowKey={(row) => row.id} loading={!rows} empty="Sin tickets." onRowClick={(row) => router.push(`/portal/support/${row.id}`)} />
            </div>
            <PortalExport file="tickets" title="Mis tickets" columns={EXPORT} rows={rows ?? []} />
          </>
        )}
      </Card>
    </>
  );
}

export function PortalTicketDetail({ id }: { id: string }) {
  const { toast } = usePortal();
  const [ticket, setTicket] = useState<PortalTicket | null>(null);
  const [error, setError] = useState("");
  const [missing, setMissing] = useState(false);
  const [reload, setReload] = useState(0);
  const [reply, setReply] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalTicket>(`/v1/portal/tickets/${id}`)
      .then((data) => { if (!cancelled) { setTicket(data); setError(""); } })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) setMissing(true);
        else setError("No se pudo cargar el ticket.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  async function send() {
    if (!reply.trim()) {
      toast("Escribe tu respuesta.", "warning");
      return;
    }
    setBusy(true);
    try {
      setTicket(await apiFetch<PortalTicket>(`/v1/portal/tickets/${id}/comments`, { method: "POST", body: JSON.stringify({ body: reply.trim() }) }));
      setReply("");
      toast("Respuesta enviada");
    } catch (err) {
      toast(errorText(err, "No se pudo enviar la respuesta."), "danger");
    } finally {
      setBusy(false);
    }
  }

  if (missing) {
    return (
      <>
        <PortalBack href="/portal/support">Soporte</PortalBack>
        <Card>
          <h2 className="text-lg font-semibold">No encontrado</h2>
          <p className="app-muted mt-1">El recurso no existe o no pertenece a tu empresa.</p>
        </Card>
      </>
    );
  }
  if (error) return <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>;
  if (!ticket) return <div className="app-surface h-64 animate-pulse rounded-xl" aria-busy="true" />;

  const [label, tone] = stateOf(TICKET_STATES, ticket.status);

  return (
    <>
      <PortalBack href="/portal/support">Soporte</PortalBack>
      <PortalTitle>{ticket.subject} <Badge tone={tone}>{label}</Badge></PortalTitle>
      <Card>
        <p className="app-muted -mt-1 mb-3 text-xs">{ticket.ticket_number} · Creado {dateTimeText(ticket.created_at)}</p>
        <ul className="grid gap-2.5">
          {ticket.comments.map((comment) => (
            <li
              key={comment.id}
              className={`max-w-[85%] rounded-xl px-3.5 py-2.5 ${comment.from_customer
                ? "justify-self-end bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]"
                : "justify-self-start bg-[var(--app-surface-muted)]"}`}
            >
              <div className="mb-0.5 flex flex-wrap items-baseline justify-between gap-x-3">
                <strong className="text-[13px] font-semibold">{comment.from_customer ? (comment.mine ? "Tú" : comment.author ?? "Tu equipo") : "Equipo de soporte"}</strong>
                <small className="text-[11.5px] opacity-75">{dateTimeText(comment.created_at)}</small>
              </div>
              <p className="whitespace-pre-wrap text-[13.5px]">{comment.body}</p>
            </li>
          ))}
        </ul>

        {ticket.resolution && (
          <div className="mt-3 rounded-xl border border-[color:var(--p-success-line,var(--app-border))] px-3.5 py-2.5">
            <strong className="text-[13px]">Resolución</strong>
            <p className="mt-0.5 text-[13.5px]">{ticket.resolution}</p>
          </div>
        )}

        {ticket.status !== "CLOSED" && (
          <div className="mt-4 grid gap-2">
            <textarea className={INPUT} rows={3} value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Escribe tu respuesta…" aria-label="Respuesta" maxLength={5000} />
            <div className="flex justify-end"><Btn onClick={send} disabled={busy}>{busy ? "Enviando…" : "Enviar"}</Btn></div>
          </div>
        )}
      </Card>
    </>
  );
}
