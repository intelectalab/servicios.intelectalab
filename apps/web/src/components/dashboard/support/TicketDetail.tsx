"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Filter, Lock, ShieldCheck, Users, Wrench } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, setFlash, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { WORK_ORDER_STATES } from "@/lib/operations";
import {
  CATEGORIES, CHANNELS, SLA_MODES, TICKET_MOVES, TICKET_STATES, WARRANTY_STATES, minutesText, pausedMinutes, supportHistoryLabel, type TicketMove,
} from "@/lib/support";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, INPUT, Kv, LoadError, PageHead, Screen, StateTrack, Timeline, Who, deniedText, useToasts,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { PriorityTag, SlaBig, useNow } from "@/components/dashboard/support/SupportParts";
import { AssignTicketModal, TicketWorkOrderModal, TriageModal } from "@/components/dashboard/support/TicketModals";
import { WarrantyClaimFormModal } from "@/components/dashboard/support/WarrantyModals";
import type { TicketDetail as Ticket, TicketComment, TicketOptions } from "@/types/support";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

/** Burbuja de la conversación: nota interna, respuesta del equipo o mensaje del cliente (estilos del prototipo). */
function Message({ comment }: { comment: TicketComment }) {
  const kind = comment.internal ? "internal" : comment.author_type === "PORTAL" ? "customer" : "agent";
  const box = kind === "internal"
    ? "ml-auto border-dashed border-[color:color-mix(in_srgb,var(--p-warning-text)_45%,transparent)] bg-[var(--p-warning-soft)]"
    : kind === "customer" ? "border-[color:var(--app-border)] bg-[var(--app-surface-muted)]" : "ml-auto border-transparent bg-[var(--p-brand-soft)]";

  return (
    <li className={`max-w-full rounded-lg border px-3 py-2.5 sm:max-w-[92%] ${box}`}>
      <div className="mb-1 flex flex-wrap items-center gap-2">
        <strong className="font-semibold">{comment.author ?? (kind === "customer" ? "Cliente" : "—")}</strong>
        {kind === "internal" ? (
          <Chip tone="warning"><Lock size={11} className="mr-1" aria-hidden="true" />Nota interna</Chip>
        ) : kind === "customer" ? <Chip>Cliente</Chip> : <Chip tone="accent">Respuesta pública</Chip>}
        {comment.at && <small className="app-muted ml-auto text-[11.5px]">{dateTime(comment.at)}</small>}
      </div>
      <p className="whitespace-pre-line [overflow-wrap:anywhere]">{comment.body}</p>
    </li>
  );
}

export default function TicketDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const now = useNow();

  const [ticket, setTicket] = useState<Ticket | null>(null);
  const [options, setOptions] = useState<TicketOptions | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [showInternal, setShowInternal] = useState(true);
  const [reply, setReply] = useState("");
  const [internal, setInternal] = useState(false);
  const [modal, setModal] = useState<"triage" | "assign" | "work-order" | "claim" | null>(null);
  const [reasoning, setReasoning] = useState<TicketMove | null>(null);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Ticket>(`/v1/tickets/${id}`)
      .then((next) => {
        if (cancelled) return;
        setTicket(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El ticket no existe." : "No se pudo cargar el ticket.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  // Responsables y políticas para clasificar y asignar: después de la ficha.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<TicketOptions>("/v1/tickets/options").then((next) => { if (!cancelled) setOptions(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  /** Ejecuta una acción sobre el ticket y refresca la ficha con lo que devuelve la API. */
  async function act(path: string, body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      setTicket(await apiFetch<Ticket>(`/v1/tickets/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined }));
      toast(done);
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      // Otra persona lo cambió: se muestra la versión vigente.
      if (err instanceof ApiError && err.status === 409) setReload((n) => n + 1);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function send() {
    if (!reply.trim()) {
      toast("Escribe un comentario.", "danger");
      return;
    }
    if (await act("comments", { body: reply.trim(), internal }, internal ? "Nota interna agregada" : "Respuesta enviada al cliente")) {
      setReply("");
      setInternal(false);
    }
  }

  const back = (
    <Link href="/tickets" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Tickets y SLA
    </Link>
  );

  if (error) {
    return (
      <Screen>
        {back}
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
        {toasts}
      </Screen>
    );
  }

  if (!ticket) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const status = ticket.status;
  const policy = ticket.policy;
  const history = ticket.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("OPEN");
  const available = TICKET_MOVES.filter((move) => move.from.includes(status));
  const canAnswer = !accessLoading && can("tickets.manage");
  const comments = ticket.comments.filter((comment) => showInternal || !comment.internal);
  const premium = policy && policy.factor < 1;
  const appliedResponse = policy?.applied_response_minutes ?? policy?.response_minutes ?? 0;
  const appliedResolution = policy?.applied_resolution_minutes ?? policy?.resolution_minutes ?? 0;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={ticket.subject}
        sub={`${ticket.number} · ${customerName(ticket.customer)} · canal ${CHANNELS[ticket.channel] ?? ticket.channel} · ${CATEGORIES[ticket.category] ?? ticket.category} · creado ${ticket.opened_at ? dateTime(ticket.opened_at) : "—"}`}
        actions={
          <>
            {["OPEN", "TRIAGED", "REOPENED"].includes(status) && (
              <Btn onClick={() => setModal("triage")} permission="tickets.manage" disabled={busy}>
                <Filter size={16} />
                <span>{status === "TRIAGED" ? "Reclasificar" : "Clasificar (triage)"}</span>
              </Btn>
            )}
            {!["OPEN", "CLOSED"].includes(status) && (
              <Btn variant="secondary" onClick={() => setModal("assign")} permission="tickets.manage" disabled={busy}>
                <Users size={16} />
                <span>{ticket.assignee ? "Reasignar" : "Asignar"}</span>
              </Btn>
            )}
            {ticket.priority && ticket.priority !== "P1" && !["RESOLVED", "CLOSED"].includes(status) && (
              <Btn variant="danger-ghost" onClick={() => act("escalate", null, "Ticket escalado; el aviso a Gerencia llegará con Notificaciones")} permission="tickets.approve" disabled={busy}>
                Escalar
              </Btn>
            )}
            {!ticket.work_order && !["OPEN", "CLOSED"].includes(status) && (
              <Btn variant="ghost" onClick={() => setModal("work-order")} permission="work_orders.create" disabled={busy}>
                <Wrench size={16} />
                <span>Crear OT</span>
              </Btn>
            )}
            {!ticket.claim && ticket.contract && (
              <Btn variant="ghost" onClick={() => setModal("claim")} permission="warranty.create" disabled={busy}>
                <ShieldCheck size={16} />
                <span>Reclamo de garantía</span>
              </Btn>
            )}
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.ticket-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["sla", "conversation"] },
          { className: "min-w-0 lg:min-h-24", cards: ["states", "context", "history"] },
        ]}
        cards={[
          {
            id: "sla",
            node: (
              <Card title="SLA">
                <SlaBig sla={ticket.sla} now={now} />
                {policy && ticket.sla.resolution_due_at ? (
                  <Kv
                    rows={[
                      [
                        "Política",
                        `${policy.priority} ${policy.label} · respuesta ${minutesText(appliedResponse)} · resolución ${minutesText(appliedResolution)}${premium ? " (contrato Premium)" : ""}`,
                      ],
                      ["Cálculo", SLA_MODES[policy.mode] ?? policy.mode],
                      [
                        "Respuesta vence",
                        `${ticket.sla.response_due_at ? dateTime(ticket.sla.response_due_at) : "—"}${ticket.sla.first_response_at ? ` · respondido ${dateTime(ticket.sla.first_response_at)}` : ""}`,
                      ],
                      ["Resolución vence", dateTime(ticket.sla.resolution_due_at)],
                      ["Tiempo en pausa", `${pausedMinutes(ticket.sla, now)} min`],
                      ticket.sla.resolved_at ? ["Resuelto", dateTime(ticket.sla.resolved_at)] : null,
                    ]}
                  />
                ) : (
                  <p className="app-muted">Clasifica el ticket para calcular su SLA.</p>
                )}
              </Card>
            ),
          },
          {
            id: "conversation",
            node: (
              <Card title="Conversación">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="app-muted text-[12.5px]">Las notas internas nunca se muestran en el portal.</span>
                  <label className="relative inline-flex cursor-pointer items-center gap-2 text-[12.5px]">
                    <input type="checkbox" checked={showInternal} onChange={(e) => setShowInternal(e.target.checked)} className="h-4 w-4 accent-[#1B8FC9]" />
                    <span>Mostrar notas internas</span>
                  </label>
                </div>
                {comments.length ? (
                  <ul className="my-2.5 grid gap-2.5">{comments.map((comment) => <Message key={comment.id} comment={comment} />)}</ul>
                ) : (
                  <p className="app-muted my-3">Sin mensajes.</p>
                )}
                {status === "CLOSED" ? (
                  <p className="app-muted text-[12.5px]">Ticket cerrado: reábrelo para seguir la conversación.</p>
                ) : (
                  <div className="grid gap-2" title={canAnswer ? undefined : deniedText("tickets.manage")}>
                    <textarea
                      className={INPUT}
                      rows={3}
                      value={reply}
                      onChange={(e) => setReply(e.target.value)}
                      placeholder="Escribe una respuesta…"
                      aria-label="Respuesta"
                      maxLength={5000}
                      disabled={!canAnswer || busy}
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <label className="relative inline-flex cursor-pointer items-center gap-2 text-[13px]">
                        <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} disabled={!canAnswer} className="h-4 w-4 accent-[#1B8FC9]" />
                        <span>Nota interna</span>
                      </label>
                      <Btn onClick={send} permission="tickets.manage" disabled={busy}>Enviar</Btn>
                    </div>
                  </div>
                )}
              </Card>
            ),
          },
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={TICKET_STATES} current={status} visited={visited} />
                  {available.length ? (
                    <div className="flex flex-wrap gap-1.5">
                      {available.map((move) => (
                        <Btn
                          key={move.path}
                          variant={move.danger ? "danger-ghost" : "secondary"}
                          onClick={() => (move.reason ? setReasoning(move) : act(move.path, null, move.done))}
                          permission="tickets.manage"
                          disabled={busy}
                        >
                          {move.label}
                        </Btn>
                      ))}
                    </div>
                  ) : (
                    <p className="app-muted text-[12.5px]">
                      {status === "OPEN" ? "Clasifica el ticket para continuar." : status === "TRIAGED" ? "Asigna el ticket para atenderlo." : "Sin transiciones disponibles desde este estado."}
                    </p>
                  )}
                </div>
              </Card>
            ),
          },
          {
            id: "context",
            node: (
              <Card title="Contexto">
                <Kv
                  rows={[
                    [
                      "Cliente",
                      ticket.customer ? <Link key="customer" href={`/actors/${ticket.customer.party_id}`} className={LINK}>{customerName(ticket.customer)}</Link> : "—",
                    ],
                    [
                      "Contrato",
                      ticket.contract ? (
                        <span key="contract">
                          {ticket.contract.visible ? <Link href={`/contracts/${ticket.contract.id}`} className={LINK}>{ticket.contract.number}</Link> : ticket.contract.number}
                          {" · SLA "}{ticket.contract.sla_level === "PREMIUM" ? "Premium" : "Estándar"}
                        </span>
                      ) : "Sin contrato (permitido por política)",
                    ],
                    ["Servicio", ticket.service?.name ?? "—"],
                    ["Prioridad", <PriorityTag key="priority" priority={ticket.priority} />],
                    ["Asignado", <Who key="assignee" name={ticket.assignee?.name} />],
                    ["Equipo", ticket.team ?? "—"],
                    ticket.work_order ? [
                      "Orden de trabajo",
                      <span key="wo" className="inline-flex flex-wrap items-center gap-1.5">
                        {ticket.work_order.visible ? <Link href={`/work-orders/${ticket.work_order.id}`} className={LINK}>{ticket.work_order.code}</Link> : ticket.work_order.code}
                        <Badge tone={stateOf(WORK_ORDER_STATES, ticket.work_order.status)[1]}>{stateOf(WORK_ORDER_STATES, ticket.work_order.status)[0]}</Badge>
                      </span>,
                    ] : null,
                    ticket.claim ? [
                      "Garantía",
                      <span key="claim" className="inline-flex flex-wrap items-center gap-1.5">
                        <Link href={`/warranty-claims?claim=${ticket.claim.id}`} className={LINK}>{ticket.claim.number}</Link>
                        <Badge tone={stateOf(WARRANTY_STATES, ticket.claim.status)[1]}>{stateOf(WARRANTY_STATES, ticket.claim.status)[0]}</Badge>
                      </span>,
                    ] : null,
                    ticket.resolution ? ["Resolución", <span key="resolution" className="whitespace-pre-line">{ticket.resolution}</span>] : null,
                    ticket.reopen_reason && status === "REOPENED" ? ["Motivo de reapertura", ticket.reopen_reason] : null,
                    ["Registrado por", ticket.creator ?? "—"],
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "history",
            node: (
              <Card title="Historial">
                <Timeline
                  items={history.map((entry, index) => {
                    const to = entry.metadata.to;
                    return {
                      key: `${entry.at}-${index}`,
                      head: to ? (
                        <>
                          <Badge tone={stateOf(TICKET_STATES, to)[1]}>{stateOf(TICKET_STATES, to)[0]}</Badge>
                          {entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(TICKET_STATES, entry.metadata.from)[0]}</span>
                          )}
                        </>
                      ) : (
                        <span className="font-medium">{supportHistoryLabel(entry)}</span>
                      ),
                      meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                      note: entry.metadata.note,
                    };
                  })}
                />
              </Card>
            ),
          },
        ]}
      />

      {modal === "triage" && (
        <TriageModal
          ticket={ticket}
          options={options}
          toast={toast}
          onClose={() => setModal(null)}
          onSaved={(next) => {
            setTicket(next);
            setModal(null);
            toast("Ticket clasificado · SLA calculado");
          }}
        />
      )}
      {modal === "assign" && (
        <AssignTicketModal
          ticket={ticket}
          options={options}
          toast={toast}
          onClose={() => setModal(null)}
          onSaved={(next) => {
            setTicket(next);
            setModal(null);
            toast("Ticket asignado");
          }}
        />
      )}
      {modal === "work-order" && (
        <TicketWorkOrderModal
          ticket={ticket}
          toast={toast}
          onClose={() => setModal(null)}
          onSaved={(result) => {
            setFlash(`Orden de trabajo ${result.work_order.code} creada desde el ticket`);
            router.push(`/work-orders/${result.work_order.id}`);
          }}
        />
      )}
      {modal === "claim" && (
        <WarrantyClaimFormModal
          ticket={{ id: ticket.id, number: ticket.number, subject: ticket.subject, customer_id: ticket.customer?.id ?? "" }}
          toast={toast}
          onClose={() => setModal(null)}
          onSaved={() => {
            setFlash("Reclamo registrado · cobertura validada");
            router.push("/warranty-claims");
          }}
        />
      )}
      {reasoning && (
        <ReasonModal
          title={`${reasoning.label} ticket`}
          text={`${ticket.number} · ${ticket.subject}`}
          label={reasoning.reason ?? "Motivo"}
          confirmLabel={reasoning.label}
          busy={busy}
          onClose={() => setReasoning(null)}
          onConfirm={async (reason) => {
            if (await act(reasoning.path, { reason }, reasoning.done)) setReasoning(null);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
