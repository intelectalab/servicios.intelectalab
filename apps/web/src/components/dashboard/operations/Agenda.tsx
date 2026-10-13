"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { Check, Pencil, Plus, Wrench } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError, toLocalInput } from "@/lib/crm";
import { AGENDA_STATES, AGENDA_TYPES } from "@/lib/operations";
import {
  Badge, Btn, Field, FormGrid, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, useToasts, type Tone,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { AgendaEvent, AgendaOptions, AgendaWeek, AgendaWorkOrder } from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const DAY_MS = 86_400_000;

/** Lunes de la semana de hoy (en el horario de quien mira), movido `offset` semanas. */
function weekStart(offset: number): Date {
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  start.setDate(start.getDate() - ((start.getDay() + 6) % 7) + offset * 7);
  return start;
}

const hour = (value: string) => new Date(value).toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit" });
const sameDay = (value: string, day: Date) => new Date(value).toDateString() === day.toDateString();

// Verde, ámbar y gris son estados; lo demás va en el azul del logo. La orden de trabajo, en neutro.
const EVENT_TONE: Record<Tone, string> = {
  info: "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]",
  accent: "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]",
  success: "bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]",
  muted: "bg-[var(--p-muted-soft)] text-[color:var(--p-muted-text)]",
  neutral: "bg-[var(--p-neutral-soft)] text-[color:var(--p-neutral-text)]",
  warning: "bg-[var(--p-warning-soft)] text-[color:var(--p-warning-text)]",
  danger: "bg-[var(--p-danger-soft)] text-[color:var(--p-danger-text)]",
};
const ITEM = "grid w-full gap-px rounded-md border-l-[3px] border-current px-2 py-1.5 text-left text-xs transition-shadow hover:shadow-[0_2px_8px_rgba(16,24,40,.14)]";

type Entry = { key: string; at: string; event?: AgendaEvent; order?: AgendaWorkOrder };

export default function Agenda() {
  const { toast, toasts } = useToasts();
  const [offset, setOffset] = useState(0);
  const [week, setWeek] = useState<AgendaWeek | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [options, setOptions] = useState<AgendaOptions | null>(null);

  const [form, setForm] = useState<{ event?: AgendaEvent } | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);

  const days = useMemo(() => {
    const start = weekStart(offset);
    return Array.from({ length: 7 }, (_, index) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + index));
  }, [offset]);

  useEffect(() => {
    let cancelled = false;
    const from = days[0];
    const to = new Date(from.getFullYear(), from.getMonth(), from.getDate() + 7);

    async function load() {
      setLoading(true);
      try {
        const next = await apiFetch<AgendaWeek>(`/v1/agenda?${new URLSearchParams({ from: from.toISOString(), to: to.toISOString() })}`);
        if (!cancelled) {
          setWeek(next);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar la agenda.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [days, reload]);

  // Personas y clientes del formulario: después de la semana.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<AgendaOptions>("/v1/agenda/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  const entries: Entry[] = useMemo(() => [
    ...(week?.data ?? []).map((event) => ({ key: event.id, at: event.starts_at, event })),
    ...(week?.work_orders ?? []).map((order) => ({ key: order.id, at: order.starts_at, order })),
  ].sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime()), [week]);

  const opened = week?.data.find((event) => event.id === openId) ?? null;
  const today = new Date();
  const range = `${days[0].toLocaleDateString("es-PE", { day: "numeric", month: "short" })} – ${days[6].toLocaleDateString("es-PE", { day: "numeric", month: "short", year: "numeric" })}`;

  return (
    <Screen>
      <PageHead
        title="Agenda"
        sub={<span className="inline-flex flex-wrap items-center gap-2"><strong className="app-text text-[14px] font-semibold">{range}</strong><ScopeNote resource="agenda" /></span>}
        actions={
          <>
            <div role="group" aria-label="Semana" className="app-surface inline-flex h-[38px] items-center gap-0.5 rounded-[9px] border border-[color:var(--app-border)] p-[3px]">
              {([[-1, "‹ Semana anterior"], [0, "Hoy"], [1, "Siguiente ›"]] as [number, string][]).map(([step, label]) => (
                <button
                  key={label}
                  type="button"
                  onClick={() => setOffset((current) => (step === 0 ? 0 : current + step))}
                  className={`h-full cursor-pointer whitespace-nowrap rounded-[7px] px-2.5 text-[12.5px] font-medium transition-colors ${
                    step === 0 && offset === 0 ? "bg-[#1B8FC9] text-[#fff]" : "app-muted hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
            <Btn onClick={() => setForm({})} permission="agenda.create">
              <Plus size={16} />
              <span>Nuevo evento</span>
            </Btn>
          </>
        }
      />

      {error ? (
        <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
      ) : (
        <div
          className={`grid grid-cols-1 gap-2 pb-1.5 transition-opacity sm:grid-cols-[repeat(7,minmax(130px,1fr))] sm:overflow-x-auto ${loading && week ? "opacity-60" : ""}`}
          aria-busy={loading}
        >
          {days.map((day) => {
            const list = entries.filter((entry) => sameDay(entry.at, day));
            const isToday = day.toDateString() === today.toDateString();
            return (
              <section
                key={day.toISOString()}
                aria-label={day.toLocaleDateString("es-PE", { weekday: "long", day: "numeric", month: "long" })}
                className={`app-surface grid content-start gap-1.5 rounded-xl border p-2 sm:min-h-[180px] ${
                  isToday ? "border-[#1B8FC9] shadow-[inset_0_0_0_1px_#1B8FC9]" : "border-[color:var(--app-border)]"
                }`}
              >
                <header className="app-muted flex items-baseline justify-between text-xs capitalize">
                  <span>{day.toLocaleDateString("es-PE", { weekday: "short" })}</span>
                  <strong className="app-text text-xl font-semibold tabular-nums">{day.getDate()}</strong>
                </header>
                {!week ? (
                  <div className="h-10 animate-pulse rounded-md bg-[var(--app-surface-muted)]" />
                ) : list.length ? (
                  list.map(({ key, event, order }) =>
                    event ? (
                      <button
                        key={key}
                        type="button"
                        onClick={() => setOpenId(event.id)}
                        className={`${ITEM} cursor-pointer ${EVENT_TONE[stateOf(AGENDA_STATES, event.status)[1]]} ${event.status === "CANCELLED" ? "line-through decoration-1" : ""}`}
                      >
                        <small className="tabular-nums">{hour(event.starts_at)} · {stateOf(AGENDA_TYPES, event.event_type)[0]}</small>
                        <strong className="app-text font-semibold [overflow-wrap:anywhere]">{event.title}</strong>
                        <span className="app-muted [overflow-wrap:anywhere]">{event.assignee?.name ?? "Sin responsable"}</span>
                      </button>
                    ) : order ? (
                      <Link key={key} href={`/work-orders/${order.id}`} className={`${ITEM} ${EVENT_TONE[order.status === "COMPLETED" ? "success" : "neutral"]}`}>
                        <small className="inline-flex items-center gap-1 tabular-nums"><Wrench size={11} aria-hidden="true" />{hour(order.starts_at)} · {order.code}</small>
                        <strong className="app-text font-semibold [overflow-wrap:anywhere]">{order.title}</strong>
                        <span className="app-muted [overflow-wrap:anywhere]">{order.assignee?.name ?? "Sin asignar"}</span>
                      </Link>
                    ) : null,
                  )
                ) : (
                  <p className="app-muted my-1 text-center text-xs sm:my-3">—</p>
                )}
              </section>
            );
          })}
        </div>
      )}

      {form && (
        <AgendaFormModal
          event={form.event}
          options={options}
          toast={toast}
          onClose={() => setForm(null)}
          onSaved={() => {
            setForm(null);
            setReload((n) => n + 1);
          }}
        />
      )}
      {opened && !form && (
        <AgendaEventModal
          event={opened}
          toast={toast}
          onClose={() => setOpenId(null)}
          onEdit={() => setForm({ event: opened })}
          onChanged={() => setReload((n) => n + 1)}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Ficha de un evento, sobre la semana: sus datos y sus acciones. */
function AgendaEventModal({
  event, onClose, onEdit, onChanged, toast,
}: { event: AgendaEvent; onClose: () => void; onEdit: () => void; onChanged: () => void; toast: Toast }) {
  const [busy, setBusy] = useState(false);
  const [cancelling, setCancelling] = useState(false);
  const scheduled = event.status === "SCHEDULED";
  const [label, tone] = stateOf(AGENDA_STATES, event.status);

  async function act(action: "done" | "cancel", done: string, reason?: string): Promise<boolean> {
    setBusy(true);
    try {
      await apiFetch(`/v1/agenda/${event.id}/${action}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(done);
      onChanged();
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      // Otra persona lo cambió: se muestra la versión vigente.
      if (err instanceof ApiError && err.status === 409) onChanged();
      return false;
    } finally {
      setBusy(false);
    }
  }

  if (cancelling) {
    return (
      <ReasonModal
        title="Cancelar evento"
        text={event.title}
        label="Motivo de la cancelación"
        confirmLabel="Cancelar evento"
        busy={busy}
        onClose={() => setCancelling(false)}
        onConfirm={async (reason) => {
          if (await act("cancel", "Evento cancelado", reason)) setCancelling(false);
        }}
      />
    );
  }

  return (
    <Modal
      title={event.title}
      size="sm"
      onClose={onClose}
      footer={
        scheduled ? (
          <>
            <Btn variant="danger-ghost" onClick={() => setCancelling(true)} permission="agenda.manage" disabled={busy}>Cancelar evento</Btn>
            <Btn variant="secondary" onClick={onEdit} permission="agenda.manage" disabled={busy}>
              <Pencil size={15} />
              <span>Editar</span>
            </Btn>
            <Btn onClick={() => act("done", "Evento marcado como realizado")} permission="agenda.manage" disabled={busy}>
              <Check size={15} />
              <span>Marcar realizado</span>
            </Btn>
          </>
        ) : (
          <Btn variant="ghost" onClick={onClose}>Cerrar</Btn>
        )
      }
    >
      <Kv
        rows={[
          ["Estado", <Badge key="status" tone={tone}>{label}</Badge>],
          ["Tipo", stateOf(AGENDA_TYPES, event.event_type)[0]],
          ["Inicio", dateTime(event.starts_at)],
          ["Fin", dateTime(event.ends_at)],
          ["Responsable", event.assignee?.name ?? "—"],
          ["Cliente", event.customer?.name ?? "—"],
          ["Lugar", event.location ?? "—"],
          event.notes ? ["Notas", <span key="notes" className="whitespace-pre-wrap">{event.notes}</span>] : null,
          event.cancel_reason ? ["Motivo de la cancelación", event.cancel_reason] : null,
        ]}
      />
    </Modal>
  );
}

/** Alta o edición de un evento. Si la persona ya tiene algo a esa hora, avisa antes de guardar. */
function AgendaFormModal({
  event, options, onClose, onSaved, toast,
}: { event?: AgendaEvent; options: AgendaOptions | null; onClose: () => void; onSaved: () => void; toast: Toast }) {
  const [title, setTitle] = useState(event?.title ?? "");
  const [type, setType] = useState<string>(event?.event_type ?? "MEETING");
  // Como el prototipo: propone mañana a esta hora.
  const [start, setStart] = useState(() => toLocalInput(event?.starts_at ?? new Date(Math.ceil((Date.now() + DAY_MS) / 1_800_000) * 1_800_000)));
  const [end, setEnd] = useState(event ? toLocalInput(event.ends_at) : "");
  const [assignee, setAssignee] = useState(event?.assignee?.id ?? "");
  const [customer, setCustomer] = useState(event?.customer?.id ?? "");
  const [location, setLocation] = useState(event?.location ?? "");
  const [notes, setNotes] = useState(event?.notes ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [conflict, setConflict] = useState("");
  const [saving, setSaving] = useState(false);

  // Cambiar el horario o la persona vuelve a pedir la comprobación de cruces.
  const touch = <T,>(set: (value: T) => void) => (value: T) => { set(value); setConflict(""); };

  async function save() {
    const problems: Record<string, string> = {};
    if (!title.trim()) problems.title = "El título es obligatorio.";
    if (!start) problems.starts_at = "Indica cuándo empieza.";
    if (start && end && new Date(end) <= new Date(start)) problems.ends_at = "El fin debe ser posterior al inicio.";
    setErrors(problems);
    if (Object.keys(problems).length) return;

    setSaving(true);
    try {
      await apiFetch<AgendaEvent>(`/v1/agenda${event ? `/${event.id}` : ""}`, {
        method: event ? "PUT" : "POST",
        body: JSON.stringify({
          title: title.trim(), event_type: type,
          starts_at: new Date(start).toISOString(), ends_at: end ? new Date(end).toISOString() : null,
          assigned_user_id: assignee || null, customer_id: customer || null,
          location: location.trim() || null, notes: notes.trim() || null,
          force: Boolean(conflict),
        }),
      });
      toast(event ? "Evento actualizado" : "Evento agendado");
      onSaved();
    } catch (err) {
      const code = err instanceof ApiError ? (err.body as { code?: string } | null)?.code : undefined;
      if (code === "AGENDA_CONFLICT" && err instanceof ApiError) {
        setErrors({});
        setConflict(err.message);
      } else {
        setErrors(fieldErrors(err));
        toast(firstError(err, "No se pudo guardar el evento."), "danger");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={event ? "Editar evento" : "Nuevo evento"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission={event ? "agenda.manage" : "agenda.create"}>{conflict ? "Guardar igual" : "Guardar"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Título" required span={3} error={errors.title}>
          <input className={INPUT} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Tipo" error={errors.event_type}>
          <PickSelect className={INPUT} ariaLabel="Tipo" value={type} onChange={setType} options={AGENDA_TYPES.map(([key, label]): [string, string] => [key, label])} />
        </Field>
        <Field label="Inicio" required error={errors.starts_at}>
          <input className={INPUT} type="datetime-local" value={start} onChange={(e) => touch(setStart)(e.target.value)} />
        </Field>
        <Field label="Fin" error={errors.ends_at} hint="Sin fin, dura una hora.">
          <input className={INPUT} type="datetime-local" value={end} onChange={(e) => touch(setEnd)(e.target.value)} />
        </Field>
        <Field label="Responsable" span={event ? 3 : undefined} error={errors.assigned_user_id}>
          <PickSelect
            className={INPUT} ariaLabel="Responsable" searchable value={assignee} onChange={touch(setAssignee)} disabled={!options}
            options={[...(event ? [] : [["", "Yo"] as [string, string]]), ...(options?.people ?? []).map((person): [string, string] => [person.id, person.name])]}
          />
        </Field>
        <Field
          label="Cliente" span={event ? 3 : 2} error={errors.customer_id}
          hint={options && options.customers === null ? "Tu rol no tiene permiso core.view: no puedes elegir un cliente." : undefined}
        >
          <PickSelect
            className={INPUT} ariaLabel="Cliente" searchable value={customer} onChange={setCustomer} disabled={!options?.customers}
            options={[["", "Sin cliente"], ...(options?.customers ?? []).map((item): [string, string] => [item.id, item.name])]}
          />
        </Field>
        <Field label="Lugar" span={3} error={errors.location}>
          <input className={INPUT} value={location} onChange={(e) => setLocation(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Notas" span={3} error={errors.notes}>
          <textarea className={`${INPUT} min-h-[70px]`} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} />
        </Field>
      </FormGrid>
      {conflict && (
        <div className="mt-3.5">
          <Notice tone="warn">{conflict} Pulsa «Guardar igual» para agendarlo de todos modos.</Notice>
        </div>
      )}
    </Modal>
  );
}
