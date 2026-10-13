"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Check, Clock, FileText, MessageSquareText, Pencil, Upload, Users } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { customerName, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import {
  BILLING_NOTES, CHECKABLE, CHECKLIST_STATES, WORK_ORDER_MOVES, WORK_ORDER_OPEN, WORK_ORDER_STATES,
  downloadWorkOrderPdf, operationsHistoryLabel, scheduledText, type WorkOrderMove,
} from "@/lib/operations";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, Field, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, StateTrack, Timeline, Who, deniedText, useToasts,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { TimeEntryFormModal } from "@/components/dashboard/operations/ProjectModals";
import { AssignWorkOrderModal, WorkOrderFormModal } from "@/components/dashboard/operations/WorkOrderModals";
import { PriorityChip } from "@/components/dashboard/operations/WorkOrdersList";
import type { ChecklistItem, TimeEntryOptions, WorkOrderDetail as WorkOrder, WorkOrderOptions } from "@/types/operations";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

/** Acción que pide un motivo antes de ejecutarse: un cambio de estado o devolver el checklist. */
type Reasoned = { path: string; title: string; label: string; confirm: string; done: string };

export default function WorkOrderDetail() {
  const { id } = useParams<{ id: string }>();
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();

  const [order, setOrder] = useState<WorkOrder | null>(null);
  const [options, setOptions] = useState<WorkOrderOptions | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [editing, setEditing] = useState(false);
  const [assigning, setAssigning] = useState(false);
  const [reasoning, setReasoning] = useState<Reasoned | null>(null);
  const [noting, setNoting] = useState<ChecklistItem | null>(null);
  const [note, setNote] = useState("");
  const [logging, setLogging] = useState(false);
  const [timeOptions, setTimeOptions] = useState<TimeEntryOptions | null>(null);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<WorkOrder>(`/v1/work-orders/${id}`)
      .then((next) => {
        if (cancelled) return;
        setOrder(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "La orden de trabajo no existe." : "No se pudo cargar la orden de trabajo.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  // Técnicos para asignar: después de la ficha.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<WorkOrderOptions>("/v1/work-orders/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  /** Ejecuta una acción sobre la orden y refresca la ficha con lo que devuelve la API. */
  async function act(path: string, method: "POST" | "PATCH", body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      const updated = await apiFetch<WorkOrder>(`/v1/work-orders/${id}/${path}`, { method, body: body ? JSON.stringify(body) : undefined });
      setOrder(updated);
      toast(done);
      return true;
    } catch (err) {
      const pending = err instanceof ApiError ? err.errors?.checklist?.slice(1) : undefined;
      toast(firstError(err, "No se pudo completar la acción."), "danger", pending?.length ? pending.join(" · ") : undefined);
      // Otra persona la cambió: se muestra la versión vigente.
      if (err instanceof ApiError && err.status === 409) setReload((n) => n + 1);
      return false;
    } finally {
      setBusy(false);
    }
  }

  function move(transition: WorkOrderMove) {
    if (!transition.reason) {
      act(transition.path, "POST", null, transition.done);
      return;
    }
    setReasoning({ path: transition.path, title: `${transition.label} orden`, label: transition.reason, confirm: transition.label, done: transition.done });
  }

  function openLogging() {
    setLogging(true);
    if (!timeOptions) apiFetch<TimeEntryOptions>("/v1/time-entries/options").then(setTimeOptions).catch(() => setTimeOptions({ projects: [], work_orders: [] }));
  }

  async function downloadPdf() {
    if (!order) return;
    setPrinting(true);
    toast("Generando PDF…");
    try {
      await downloadWorkOrderPdf(order);
    } catch {
      toast("No se pudo generar el PDF", "danger");
    } finally {
      setPrinting(false);
    }
  }

  const back = (
    <Link href="/work-orders" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Órdenes de trabajo
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

  if (!order) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const status = order.status;
  const open = WORK_ORDER_OPEN.includes(status);
  const history = order.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("PLANNED");
  const available = WORK_ORDER_MOVES.filter((transition) => transition.from.includes(status));

  const checklist = order.checklist;
  const items = order.checklist_items;
  const canManage = !accessLoading && can("work_orders.manage");
  const checkable = canManage && CHECKABLE.includes(status) && checklist?.status !== "APPROVED";
  const checkHint = !canManage ? deniedText("work_orders.manage")
    : checklist?.status === "APPROVED" ? "El checklist ya fue aprobado."
    : status === "PLANNED" ? "Asigna la orden para marcar ítems."
    : !CHECKABLE.includes(status) ? "El checklist de una orden cerrada ya no se cambia." : undefined;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={order.title}
        sub={[order.code, customerName(order.customer), order.location].filter(Boolean).join(" · ")}
        actions={
          <>
            <Btn variant="secondary" onClick={downloadPdf} disabled={printing} title="Informe de servicio en PDF">
              <FileText size={16} />
              <span>Informe PDF</span>
            </Btn>
            <Btn variant="secondary" onClick={() => setAssigning(true)} permission="work_orders.approve" disabled={!open || busy} title={open ? undefined : "OT cerrada"}>
              <Users size={16} />
              <span>{order.assignee ? "Reasignar" : "Asignar técnico"}</span>
            </Btn>
            <Btn variant="secondary" onClick={() => setEditing(true)} permission="work_orders.manage" disabled={!open || busy} title={open ? undefined : "OT cerrada"}>
              <Pencil size={16} />
              <span>Editar</span>
            </Btn>
            <Btn variant="ghost" onClick={openLogging} permission="time_entries.create" disabled={status === "CANCELLED"} title={status === "CANCELLED" ? "OT cancelada" : undefined}>
              <Clock size={16} />
              <span>Registrar horas</span>
            </Btn>
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.work-order-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["states", "checklist", "evidence"] },
          { className: "min-w-0 lg:min-h-24", cards: ["detail", "history"] },
        ]}
        cards={[
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={WORK_ORDER_STATES} current={status} visited={visited} />
                  {status === "BLOCKED" && order.blocked_reason && <Notice tone="warn"><strong className="font-semibold">Bloqueada:</strong> {order.blocked_reason}</Notice>}
                  {status === "CANCELLED" && order.cancel_reason && <Notice tone="warn"><strong className="font-semibold">Cancelada:</strong> {order.cancel_reason}</Notice>}
                  {open ? (
                    <div className="flex flex-wrap gap-1.5">
                      {status === "PLANNED" && (
                        <Btn variant="secondary" onClick={() => setAssigning(true)} permission="work_orders.approve" disabled={busy}>Asignar</Btn>
                      )}
                      {available.map((transition) => (
                        <Btn
                          key={transition.path}
                          variant={transition.reason ? "danger-ghost" : "secondary"}
                          onClick={() => move(transition)}
                          permission="work_orders.manage"
                          disabled={busy}
                        >
                          {transition.label}
                        </Btn>
                      ))}
                    </div>
                  ) : (
                    <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>
                  )}
                </div>
              </Card>
            ),
          },
          {
            id: "checklist",
            node: (
              <Card title={checklist ? `Checklist: ${checklist.name}` : "Checklist"}>
                {checklist ? (
                  <div className="grid gap-3">
                    {order.checklist_review?.note && (
                      <Notice tone="warn">
                        <strong className="font-semibold">Devuelto{order.checklist_review.by ? ` por ${order.checklist_review.by}` : ""}:</strong> {order.checklist_review.note}
                      </Notice>
                    )}
                    <ul className="grid">
                      {items.map((item) => (
                        <li key={item.id} className="flex items-start gap-2.5 border-b border-[color:var(--app-border)] py-2 last:border-b-0">
                          <button
                            type="button"
                            role="checkbox"
                            aria-checked={item.done}
                            aria-label={`${item.done ? "Desmarcar" : "Marcar"} ítem: ${item.label}`}
                            title={checkable ? undefined : checkHint}
                            disabled={!checkable || busy}
                            onClick={() => act(`checklist-items/${item.id}`, "PATCH", { done: !item.done }, "Checklist actualizado")}
                            className={`mt-0.5 grid h-5 w-5 shrink-0 cursor-pointer place-items-center rounded-full border transition-colors disabled:cursor-not-allowed disabled:opacity-55 ${
                              item.done ? "border-[#1B8FC9] bg-[#1B8FC9] text-[#fff]" : "border-[color:var(--p-field-border)] bg-[var(--app-surface)] hover:border-[#1B8FC9]"
                            }`}
                          >
                            {item.done && <Check size={13} strokeWidth={3} />}
                          </button>
                          <div className="min-w-0 flex-1">
                            <span className={item.done ? "app-muted line-through" : ""}>{item.label}</span>
                            {item.required && <em className="ml-1 not-italic text-[color:var(--p-danger-text)]" title="Obligatorio">*</em>}
                            {item.evidence_note && <span className="ml-2"><Chip>{item.evidence_note}</Chip></span>}
                            {item.done && item.done_at && (
                              <small className="app-muted block text-xs">{item.done_by ?? "—"} · {dateTime(item.done_at)}</small>
                            )}
                          </div>
                          <button
                            type="button"
                            aria-label={`Nota de evidencia: ${item.label}`}
                            title={checkable ? "Nota de evidencia" : checkHint}
                            disabled={!checkable || busy}
                            onClick={() => {
                              setNote(item.evidence_note ?? "");
                              setNoting(item);
                            }}
                            className="app-muted grid h-7 w-7 shrink-0 cursor-pointer place-items-center rounded-md hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-45"
                          >
                            <MessageSquareText size={15} />
                          </button>
                        </li>
                      ))}
                    </ul>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <span className="app-muted text-[12.5px]">
                        {checklist.done}/{checklist.total} ítems · obligatorios pendientes: {checklist.required_pending}
                      </span>
                      <Badge tone={stateOf(CHECKLIST_STATES, checklist.status)[1]}>{stateOf(CHECKLIST_STATES, checklist.status)[0]}</Badge>
                    </div>
                    {checklist.status === "COMPLETED" && (
                      <div className="flex flex-wrap gap-1.5">
                        <Btn variant="secondary" onClick={() => act("checklist/approve", "POST", null, "Checklist aprobado")} permission="work_orders.approve" disabled={busy || status === "CANCELLED"}>
                          Aprobar checklist
                        </Btn>
                        <Btn
                          variant="danger-ghost"
                          onClick={() => setReasoning({ path: "checklist/return", title: "Devolver checklist", label: "Motivo de la devolución", confirm: "Devolver", done: "Checklist devuelto" })}
                          permission="work_orders.approve"
                          disabled={busy || !open}
                          title={open ? undefined : "OT cerrada"}
                        >
                          Devolver
                        </Btn>
                      </div>
                    )}
                    {order.checklist_review && !order.checklist_review.note && checklist.status === "APPROVED" && (
                      <p className="app-muted text-[12.5px]">Aprobado por {order.checklist_review.by ?? "—"} · {dateTime(order.checklist_review.at)}</p>
                    )}
                    {checkHint && checklist.status !== "APPROVED" && <p className="app-muted text-[12.5px]">{checkHint}</p>}
                  </div>
                ) : (
                  <p className="app-muted">Esta orden no tiene checklist.</p>
                )}
              </Card>
            ),
          },
          {
            id: "evidence",
            node: (
              <Card title="Evidencias">
                <p className="app-muted mb-3">Sin evidencias.</p>
                {/* Aún sin función: depende de la pantalla Documentos. */}
                <Btn variant="secondary" size="sm" onClick={() => toast("Subir evidencias aún no está disponible: llegará con Documentos.", "warning")}>
                  <Upload size={14} />
                  <span>Subir evidencia</span>
                </Btn>
              </Card>
            ),
          },
          {
            id: "detail",
            node: (
              <Card title="Detalle">
                <Kv
                  rows={[
                    ["Servicio", order.service?.name ?? "—"],
                    ["Facturación del servicio", order.service ? BILLING_NOTES[order.service.billing_mode] ?? order.service.billing_mode : "—"],
                    [
                      "Contrato",
                      order.contract ? (
                        <Link key="contract" href={`/contracts/${order.contract.id}`} className={LINK}>{order.contract.contract_number}</Link>
                      ) : "—",
                    ],
                    ["Técnico", <Who key="assignee" name={order.assignee?.name} />],
                    ["Programada", scheduledText(order.scheduled_start)],
                    order.scheduled_end ? ["Fin programado", dateTime(order.scheduled_end)] : null,
                    ["Lugar", order.location || "—"],
                    ["Prioridad", <PriorityChip key="priority" priority={order.priority} />],
                    order.description ? ["Descripción", <span key="description" className="whitespace-pre-line">{order.description}</span>] : null,
                    order.started_at ? ["Inicio de ejecución", dateTime(order.started_at)] : null,
                    order.completed_at ? ["Completada", dateTime(order.completed_at)] : null,
                    ["Horas registradas", `${order.hours.toFixed(1)} h`],
                    ["Evento facturable", <span key="billable" className="app-muted">Ninguno</span>],
                    ["Creada por", order.creator?.name ?? "—"],
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
                          <Badge tone={stateOf(WORK_ORDER_STATES, to)[1]}>{stateOf(WORK_ORDER_STATES, to)[0]}</Badge>
                          {entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(WORK_ORDER_STATES, entry.metadata.from)[0]}</span>
                          )}
                        </>
                      ) : (
                        <span className="font-medium">{operationsHistoryLabel(entry)}</span>
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

      {editing && (
        <WorkOrderFormModal
          order={order}
          options={options}
          toast={toast}
          onClose={() => setEditing(false)}
          onSaved={(updated) => {
            setOrder(updated);
            setEditing(false);
          }}
        />
      )}
      {assigning && (
        <AssignWorkOrderModal
          order={order}
          technicians={options?.technicians ?? null}
          toast={toast}
          onClose={() => setAssigning(false)}
          onSaved={(updated) => {
            setOrder(updated);
            setAssigning(false);
          }}
        />
      )}
      {logging && (
        <TimeEntryFormModal
          options={timeOptions}
          workOrderId={order.id}
          toast={toast}
          onClose={() => setLogging(false)}
          onSaved={() => {
            setLogging(false);
            // Las horas registradas suman en el detalle de la orden.
            setReload((n) => n + 1);
          }}
        />
      )}
      {reasoning && (
        <ReasonModal
          title={reasoning.title}
          text={`${order.code} · ${order.title}`}
          label={reasoning.label}
          confirmLabel={reasoning.confirm}
          busy={busy}
          onClose={() => setReasoning(null)}
          onConfirm={async (reason) => {
            if (await act(reasoning.path, "POST", { reason }, reasoning.done)) setReasoning(null);
          }}
        />
      )}
      {noting && (
        <Modal
          title="Nota de evidencia"
          size="sm"
          onClose={() => setNoting(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setNoting(null)}>Cancelar</Btn>
              <Btn
                disabled={busy}
                permission="work_orders.manage"
                onClick={async () => {
                  if (await act(`checklist-items/${noting.id}`, "PATCH", { done: true, evidence_note: note.trim() || null }, "Checklist actualizado")) setNoting(null);
                }}
              >
                Guardar y marcar
              </Btn>
            </>
          }
        >
          <p className="text-[15px]">{noting.label}</p>
          <div className="mt-3">
            <Field label="Evidencia" hint="Qué se hizo o qué se comprobó. El ítem queda marcado.">
              <textarea className={INPUT} rows={3} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} placeholder="Registrado en campo" autoFocus />
            </Field>
          </div>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
