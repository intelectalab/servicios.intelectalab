"use client";

import { useCallback, useEffect, useMemo, useState, useSyncExternalStore, type ReactNode } from "react";
import Link from "next/link";
import { CalendarDays, CalendarRange, Check, Lock, Pencil, Plus, Wrench } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError, toLocalInput } from "@/lib/crm";
import { AGENDA_STATES, AGENDA_TYPES } from "@/lib/operations";
import {
  Badge, Btn, Field, Filters, FormGrid, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, deniedText, useToasts, type Tone,
} from "@/components/ui/proto";
import { useAccess } from "@/lib/access-context";
import { PickSelect } from "@/components/ui/pick-select";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { AgendaEvent, AgendaOptions, AgendaWeek, AgendaWorkOrder } from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const DAY_MS = 86_400_000;

// En 24 horas ("09:00", "15:30"): cabe en una línea junto al tipo.
const hour = (value: string) => new Date(value).toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit", hour12: false });
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

type Entry = { key: string; at: string; person: { id: string; name: string } | null; event?: AgendaEvent; order?: AgendaWorkOrder };
type View = "week" | "month";

const VIEW_KEY = "erp.view.agenda";
const viewListeners = new Set<() => void>();

/** Vista elegida (semana o mes), recordada en el navegador de cada persona. */
function useAgendaView(): [View, (view: View) => void] {
  const view = useSyncExternalStore<View>(
    (listener) => {
      viewListeners.add(listener);
      return () => { viewListeners.delete(listener); };
    },
    () => {
      try { return window.localStorage.getItem(VIEW_KEY) === "month" ? "month" : "week"; } catch { return "week"; }
    },
    () => "week",
  );
  const change = useCallback((next: View) => {
    try { window.localStorage.setItem(VIEW_KEY, next); } catch { /* sin almacenamiento, no se recuerda */ }
    viewListeners.forEach((listener) => listener());
  }, []);

  return [view, change];
}

const startOfDay = (date: Date) => new Date(date.getFullYear(), date.getMonth(), date.getDate());
const addDays = (date: Date, days: number) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
/** Lunes de la semana de ese día. */
const mondayOf = (date: Date) => addDays(date, -((date.getDay() + 6) % 7));

// Tonos para distinguir personas. Sin verde, rojo ni ámbar, que en el sistema son estados.
const PERSON_HUES = [205, 265, 320, 185, 240, 295, 345, 222];

/** Color de una persona según su lugar en la lista de la empresa: estable y distinto entre las primeras. */
function personColor(index: number): string {
  return `hsl(${PERSON_HUES[index % PERSON_HUES.length]} 62% ${index >= PERSON_HUES.length ? 62 : 48}%)`;
}

const SEG = "app-surface inline-flex h-[38px] items-center gap-0.5 rounded-[9px] border border-[color:var(--app-border)] p-[3px]";
const segButton = (on: boolean) =>
  `inline-flex h-full cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-[7px] px-2.5 text-[12.5px] font-medium transition-colors ${
    on ? "bg-[#1B8FC9] text-[#fff]" : "app-muted hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
  }`;

export default function Agenda() {
  const { toast, toasts } = useToasts();
  const { can, loading: accessLoading } = useAccess();
  const [view, setView] = useAgendaView();
  // Día de referencia: la semana o el mes que se muestra es el que lo contiene.
  const [cursor, setCursor] = useState(() => startOfDay(new Date()));
  const [person, setPerson] = useState("");
  const [showCancelled, setShowCancelled] = useState(false);
  const [week, setWeek] = useState<AgendaWeek | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [options, setOptions] = useState<AgendaOptions | null>(null);

  const [form, setForm] = useState<{ event?: AgendaEvent; day?: Date } | null>(null);
  const [openId, setOpenId] = useState<string | null>(null);

  const canCreate = !accessLoading && can("agenda.create");
  const today = startOfDay(new Date());

  // Días a la vista: la semana (lunes a domingo) o las semanas completas que cubren el mes.
  const days = useMemo(() => {
    if (view === "week") {
      const start = mondayOf(cursor);
      return Array.from({ length: 7 }, (_, index) => addDays(start, index));
    }
    const start = mondayOf(new Date(cursor.getFullYear(), cursor.getMonth(), 1));
    const last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
    const length = Math.ceil((Math.round((last.getTime() - start.getTime()) / DAY_MS) + 1) / 7) * 7;
    return Array.from({ length }, (_, index) => addDays(start, index));
  }, [view, cursor]);

  useEffect(() => {
    let cancelled = false;
    const from = days[0];
    const to = addDays(days[days.length - 1], 1);

    async function load() {
      setLoading(true);
      try {
        const params = new URLSearchParams({ from: from.toISOString(), to: to.toISOString() });
        if (person) params.set("user", person);
        const next = await apiFetch<AgendaWeek>(`/v1/agenda?${params}`);
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
  }, [days, person, reload]);

  // Personas y clientes (filtro y formulario): después de la agenda.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<AgendaOptions>("/v1/agenda/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  const cancelledCount = (week?.data ?? []).filter((event) => event.status === "CANCELLED").length;
  const entries: Entry[] = useMemo(() => [
    ...(week?.data ?? []).filter((event) => showCancelled || event.status !== "CANCELLED")
      .map((event) => ({ key: event.id, at: event.starts_at, person: event.assignee, event })),
    ...(week?.work_orders ?? []).map((order) => ({ key: order.id, at: order.starts_at, person: order.assignee, order })),
  ].sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime()), [week, showCancelled]);

  // El color por persona solo ayuda cuando hay más de una a la vista.
  const colored = !person && new Set(entries.map((entry) => entry.person?.id).filter(Boolean)).size > 1;
  const colorOf = (id: string) => {
    const index = (options?.people ?? []).map((item) => item.id).sort().indexOf(id);
    return personColor(index < 0 ? 0 : index);
  };
  const opened = week?.data.find((event) => event.id === openId) ?? null;

  const range = view === "week"
    ? `${days[0].toLocaleDateString("es-PE", { day: "numeric", month: "short" })} – ${days[6].toLocaleDateString("es-PE", { day: "numeric", month: "short", year: "numeric" })}`
    : cursor.toLocaleDateString("es-PE", { month: "long", year: "numeric" }).replace(/^./, (letter) => letter.toUpperCase());
  const onToday = view === "week"
    ? mondayOf(cursor).getTime() === mondayOf(today).getTime()
    : cursor.getFullYear() === today.getFullYear() && cursor.getMonth() === today.getMonth();
  const step = (direction: -1 | 1) => setCursor((current) => (
    view === "week" ? addDays(current, direction * 7) : new Date(current.getFullYear(), current.getMonth() + direction, 1)
  ));

  const who = (entry: Entry, fallback: string) => (
    <span className="app-muted inline-flex min-w-0 items-center gap-1 [overflow-wrap:anywhere]">
      {colored && entry.person && <i aria-hidden="true" className="h-2 w-2 shrink-0 rounded-full" style={{ background: colorOf(entry.person.id) }} />}
      {entry.person?.name ?? fallback}
    </span>
  );

  return (
    <Screen>
      <PageHead
        title="Agenda"
        sub={<strong className="app-text text-[14px] font-semibold">{range}</strong>}
        actions={
          <>
            <div role="group" aria-label="Tipo de vista" className={SEG}>
              {([["week", "Semana", <CalendarDays key="week" size={15} />], ["month", "Mes", <CalendarRange key="month" size={15} />]] as [View, string, ReactNode][]).map(([mode, label, icon]) => (
                <button key={mode} type="button" aria-pressed={view === mode} onClick={() => setView(mode)} className={segButton(view === mode)}>
                  {icon}
                  <span>{label}</span>
                </button>
              ))}
            </div>
            <div role="group" aria-label={view === "week" ? "Semana" : "Mes"} className={SEG}>
              <button type="button" onClick={() => step(-1)} className={segButton(false)}>‹ {view === "week" ? "Semana anterior" : "Mes anterior"}</button>
              <button type="button" onClick={() => setCursor(today)} className={segButton(onToday)}>Hoy</button>
              <button type="button" onClick={() => step(1)} className={segButton(false)}>Siguiente ›</button>
            </div>
            <Btn onClick={() => setForm({})} permission="agenda.create">
              <Plus size={16} />
              <span>Nuevo evento</span>
            </Btn>
          </>
        }
      />

      <Filters>
        <div className="flex-[1_1_180px] sm:w-[240px] sm:flex-none">
          <PickSelect
            className="app-surface app-text flex h-10 w-full cursor-pointer items-center justify-between gap-2 rounded-[9px] border border-[color:var(--app-border)] px-2.5 text-left text-[13px] outline-none focus:border-[#1B8FC9]"
            ariaLabel="Persona" searchable value={person} onChange={setPerson} disabled={!options}
            options={[["", "Persona: todos"], ...(options?.people ?? []).map((item): [string, string] => [item.id, item.name])]}
          />
        </div>
        <label className="inline-flex cursor-pointer items-center gap-2 text-[13px]">
          <input type="checkbox" checked={showCancelled} onChange={(event) => setShowCancelled(event.target.checked)} className="h-4 w-4 cursor-pointer accent-[#1B8FC9]" />
          <span>Ver cancelados{cancelledCount ? ` (${cancelledCount})` : ""}</span>
        </label>
        <ScopeNote resource="agenda" />
      </Filters>

      {error ? (
        <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
      ) : view === "week" ? (
        <div
          className={`grid grid-cols-1 gap-2 pb-1.5 transition-opacity sm:grid-cols-[repeat(7,minmax(130px,1fr))] sm:overflow-x-auto ${loading && week ? "opacity-60" : ""}`}
          aria-busy={loading}
        >
          {days.map((day) => {
            const list = entries.filter((entry) => sameDay(entry.at, day));
            const isToday = day.getTime() === today.getTime();
            return (
              <section
                key={day.toISOString()}
                aria-label={day.toLocaleDateString("es-PE", { weekday: "long", day: "numeric", month: "long" })}
                // Un clic en el hueco libre del día agenda ahí.
                onClick={(event) => { if (canCreate && event.target === event.currentTarget) setForm({ day }); }}
                className={`group app-surface grid content-start gap-1.5 rounded-xl border p-2 sm:min-h-[180px] ${canCreate ? "cursor-cell" : ""} ${
                  isToday ? "border-[#1B8FC9] shadow-[inset_0_0_0_1px_#1B8FC9]" : "border-[color:var(--app-border)]"
                }`}
              >
                <header className="app-muted pointer-events-none flex items-center justify-between gap-1 text-xs capitalize">
                  <span>{day.toLocaleDateString("es-PE", { weekday: "short" })}</span>
                  <span className="inline-flex items-center gap-1">
                    {canCreate ? (
                      <button
                        type="button"
                        onClick={() => setForm({ day })}
                        title="Nuevo evento este día"
                        aria-label={`Nuevo evento el ${day.toLocaleDateString("es-PE", { weekday: "long", day: "numeric" })}`}
                        className="pointer-events-auto grid h-6 w-6 cursor-pointer place-items-center rounded-md text-[color:var(--p-brand-text)] hover:bg-[var(--p-brand-soft)] sm:opacity-0 sm:focus-visible:opacity-100 sm:group-hover:opacity-100"
                      >
                        <Plus size={14} />
                      </button>
                    ) : !accessLoading && (
                      // Sin permiso no se esconde: sale con candado y el motivo, como todo botón.
                      <button
                        type="button"
                        disabled
                        title={deniedText("agenda.create")}
                        aria-label={`Nuevo evento el ${day.toLocaleDateString("es-PE", { weekday: "long", day: "numeric" })}: ${deniedText("agenda.create")}`}
                        className="pointer-events-auto grid h-6 w-6 cursor-not-allowed place-items-center rounded-md opacity-45 sm:opacity-0 sm:group-hover:opacity-45"
                      >
                        <Lock size={13} />
                      </button>
                    )}
                    <strong className="app-text text-xl font-semibold tabular-nums">{day.getDate()}</strong>
                  </span>
                </header>
                {!week ? (
                  <div className="pointer-events-none h-10 animate-pulse rounded-md bg-[var(--app-surface-muted)]" />
                ) : list.length ? (
                  list.map((entry) =>
                    entry.event ? (
                      <button
                        key={entry.key}
                        type="button"
                        onClick={() => setOpenId(entry.key)}
                        className={`${ITEM} cursor-pointer ${EVENT_TONE[stateOf(AGENDA_STATES, entry.event.status)[1]]} ${entry.event.status === "CANCELLED" ? "line-through decoration-1" : ""}`}
                      >
                        <small className="tabular-nums">{hour(entry.at)} · {stateOf(AGENDA_TYPES, entry.event.event_type)[0]}</small>
                        <strong className="app-text font-semibold [overflow-wrap:anywhere]">{entry.event.title}</strong>
                        {who(entry, "Sin responsable")}
                      </button>
                    ) : entry.order ? (
                      <Link key={entry.key} href={`/work-orders/${entry.order.id}`} className={`${ITEM} ${EVENT_TONE[entry.order.status === "COMPLETED" ? "success" : "neutral"]}`}>
                        <small className="inline-flex items-center gap-1 tabular-nums"><Wrench size={11} aria-hidden="true" />{hour(entry.at)} · {entry.order.code}</small>
                        <strong className="app-text font-semibold [overflow-wrap:anywhere]">{entry.order.title}</strong>
                        {who(entry, "Sin asignar")}
                      </Link>
                    ) : null,
                  )
                ) : (
                  <p className="app-muted pointer-events-none my-1 text-center text-xs sm:my-3">—</p>
                )}
              </section>
            );
          })}
        </div>
      ) : (
        <div className={`app-surface overflow-hidden rounded-xl border border-[color:var(--app-border)] transition-opacity ${loading && week ? "opacity-60" : ""}`} aria-busy={loading}>
          <div className="app-muted grid grid-cols-7 border-b border-[color:var(--app-border)] text-center text-[10.5px] font-semibold uppercase tracking-[.06em]">
            {days.slice(0, 7).map((day) => <span key={day.getDay()} className="px-1 py-2">{day.toLocaleDateString("es-PE", { weekday: "short" })}</span>)}
          </div>
          <div className="grid grid-cols-7">
            {days.map((day, index) => {
              const list = entries.filter((entry) => sameDay(entry.at, day));
              const isToday = day.getTime() === today.getTime();
              const outside = day.getMonth() !== cursor.getMonth();
              return (
                <button
                  key={day.toISOString()}
                  type="button"
                  // El día lleva a su semana, donde se ve y se gestiona cada evento.
                  onClick={() => { setCursor(day); setView("week"); }}
                  title="Ver la semana de este día"
                  aria-label={`${day.toLocaleDateString("es-PE", { weekday: "long", day: "numeric", month: "long" })}: ${list.length} en agenda`}
                  className={`grid min-h-[64px] cursor-pointer content-start gap-1 border-[color:var(--app-border)] p-1.5 text-left transition-colors hover:bg-[var(--app-surface-muted)] sm:min-h-[104px] sm:p-2 ${
                    index % 7 ? "border-l" : ""
                  } ${index >= 7 ? "border-t" : ""} ${outside ? "bg-[var(--app-surface-muted)]" : ""}`}
                >
                  <span className="flex flex-wrap items-center justify-between gap-0.5">
                    <strong
                      className={`grid h-6 min-w-6 place-items-center rounded-full px-1 text-[13px] font-semibold tabular-nums ${
                        isToday ? "bg-[#1B8FC9] text-[#fff]" : outside ? "app-muted" : "app-text"
                      }`}
                    >
                      {day.getDate()}
                    </strong>
                    {list.length > 0 && <span className="rounded-[9px] bg-[var(--p-brand-soft)] px-1.5 text-[11px] font-semibold tabular-nums text-[color:var(--p-brand-text)] sm:hidden">{list.length}</span>}
                  </span>
                  {list.slice(0, 2).map((entry) => {
                    const tone = entry.event ? stateOf(AGENDA_STATES, entry.event.status)[1] : entry.order?.status === "COMPLETED" ? "success" : "neutral";
                    return (
                      <span key={entry.key} className={`hidden items-center gap-1 truncate rounded px-1.5 py-0.5 text-[11.5px] sm:flex ${EVENT_TONE[tone]} ${entry.event?.status === "CANCELLED" ? "line-through" : ""}`}>
                        {colored && entry.person && <i aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full" style={{ background: colorOf(entry.person.id) }} />}
                        <span className="tabular-nums">{hour(entry.at)}</span>
                        <span className="app-text truncate">{entry.event?.title ?? `${entry.order?.code} · ${entry.order?.title}`}</span>
                      </span>
                    );
                  })}
                  {list.length > 2 && <span className="app-muted hidden text-[11.5px] sm:block">+{list.length - 2} más</span>}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {form && (
        <AgendaFormModal
          event={form.event}
          day={form.day}
          person={person}
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
  event, day, person, options, onClose, onSaved, toast,
}: {
  event?: AgendaEvent;
  /** Día elegido en la semana: el evento se propone ese día a las 9:00. */
  day?: Date;
  /** Persona del filtro: se propone como responsable. */
  person?: string;
  options: AgendaOptions | null;
  onClose: () => void;
  onSaved: () => void;
  toast: Toast;
}) {
  const [title, setTitle] = useState(event?.title ?? "");
  const [type, setType] = useState<string>(event?.event_type ?? "MEETING");
  // Desde un día de la semana, ese día a las 9:00; si no, como el prototipo: mañana a esta hora.
  const [start, setStart] = useState(() => toLocalInput(
    event?.starts_at
      ?? (day ? new Date(day.getFullYear(), day.getMonth(), day.getDate(), 9) : new Date(Math.ceil((Date.now() + DAY_MS) / 1_800_000) * 1_800_000)),
  ));
  const [end, setEnd] = useState(event ? toLocalInput(event.ends_at) : "");
  const [assignee, setAssignee] = useState(event?.assignee?.id ?? person ?? "");
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
