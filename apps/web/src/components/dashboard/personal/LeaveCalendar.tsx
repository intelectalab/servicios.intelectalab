"use client";

import { useEffect, useMemo, useState } from "react";
import { ChevronLeft, ChevronRight } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { LEAVE_STATES, isoDay } from "@/lib/personal";
import { Btn, Card, Chip, LoadError, Modal } from "@/components/ui/proto";
import type { LeaveRequest } from "@/types/personal";

const addDays = (date: Date, days: number) => new Date(date.getFullYear(), date.getMonth(), date.getDate() + days);
/** Lunes de la semana de ese día. */
const mondayOf = (date: Date) => addDays(date, -((date.getDay() + 6) % 7));

// Aprobada en verde; solicitada en ámbar a rayas (aún no es definitiva).
const CHIP = "flex w-full min-w-0 cursor-pointer items-center gap-1 rounded px-1.5 py-0.5 text-left text-[11.5px] font-medium transition-shadow hover:shadow-[0_2px_8px_rgba(16,24,40,.14)]";
const TONE: Record<string, string> = {
  APPROVED: "bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]",
  REQUESTED:
    "border border-dashed border-[color:color-mix(in_srgb,var(--p-warning-text)_55%,transparent)] text-[color:var(--p-warning-text)] [background-image:repeating-linear-gradient(135deg,var(--p-warning-soft)_0_6px,transparent_6px_11px)]",
};

const covers = (leave: LeaveRequest, day: string) => leave.date_from.slice(0, 10) <= day && leave.date_to.slice(0, 10) >= day;

function LeaveChip({ leave, onOpen }: { leave: LeaveRequest; onOpen: (leave: LeaveRequest) => void }) {
  return (
    <button
      type="button"
      onClick={() => onOpen(leave)}
      title={`${leave.employee.name} · ${leave.type.name} · ${stateOf(LEAVE_STATES, leave.status)[0]}`}
      className={`${CHIP} ${TONE[leave.status] ?? TONE.APPROVED}`}
    >
      <span className="truncate">{leave.employee.name}</span>
    </button>
  );
}

/** Pestaña Calendario: el mes del equipo con sus ausencias aprobadas y por aprobar. */
export default function LeaveCalendar({ reload, onOpen }: { reload: number; onOpen: (leave: LeaveRequest) => void }) {
  const [cursor, setCursor] = useState(() => {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1);
  });
  const [leaves, setLeaves] = useState<LeaveRequest[] | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [retry, setRetry] = useState(0);
  const [dayOpen, setDayOpen] = useState<string | null>(null);

  // Semanas completas del mes, de lunes a domingo (hasta 42 días: dentro del tope de la API).
  const days = useMemo(() => {
    const first = mondayOf(cursor);
    const last = addDays(mondayOf(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0)), 6);
    const list: Date[] = [];
    for (let day = first; day <= last; day = addDays(day, 1)) list.push(day);
    return list;
  }, [cursor]);
  const from = isoDay(days[0]);
  const to = isoDay(days[days.length - 1]);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      try {
        const response = await apiFetch<{ data: LeaveRequest[] }>(`/v1/hr/leaves/calendar?from=${from}&to=${to}`);
        if (!cancelled) {
          setLeaves(response.data);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudo cargar el calendario.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [from, to, reload, retry]);

  const today = isoDay(new Date());
  const monthText = cursor.toLocaleDateString("es-PE", { month: "long", year: "numeric" });
  const month = monthText.charAt(0).toUpperCase() + monthText.slice(1);
  const onDay = (day: string) => (leaves ?? []).filter((leave) => covers(leave, day));
  // En el teléfono: lista de los días del mes con alguna ausencia.
  const monthDays = days.filter((day) => day.getMonth() === cursor.getMonth());
  const busyDays = monthDays.map((day) => ({ day, list: onDay(isoDay(day)) })).filter((entry) => entry.list.length);

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-1.5">
          <Btn variant="secondary" size="sm" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1))} aria-label="Mes anterior" title="Mes anterior">
            <ChevronLeft size={15} />
          </Btn>
          <h2 className="min-w-[150px] text-center text-[15px] font-semibold">{month}</h2>
          <Btn variant="secondary" size="sm" onClick={() => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1))} aria-label="Mes siguiente" title="Mes siguiente">
            <ChevronRight size={15} />
          </Btn>
          <Btn variant="ghost" size="sm" onClick={() => { const now = new Date(); setCursor(new Date(now.getFullYear(), now.getMonth(), 1)); }}>Hoy</Btn>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-[12px]" aria-label="Leyenda">
          <span className={`inline-flex rounded px-1.5 py-0.5 font-medium ${TONE.APPROVED}`}>Aprobada</span>
          <span className={`inline-flex rounded px-1.5 py-0.5 font-medium ${TONE.REQUESTED}`}>Solicitada</span>
        </div>
      </div>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setRetry((n) => n + 1)} /></Card>
      ) : (
        <>
          {/* Teléfono: los días con ausencias, uno debajo de otro. */}
          <div className={`grid gap-2.5 transition-opacity sm:hidden ${loading && leaves ? "opacity-60" : ""}`} aria-busy={loading}>
            {!leaves ? (
              <Card><div className="h-24 animate-pulse rounded bg-[var(--app-surface-muted)]" /></Card>
            ) : busyDays.length ? (
              busyDays.map(({ day, list }) => (
                <section key={isoDay(day)} className="app-surface rounded-xl border border-[color:var(--app-border)] p-3">
                  <h3 className={`mb-2 text-[13px] font-semibold first-letter:uppercase ${isoDay(day) === today ? "text-[color:var(--p-brand-text)]" : ""}`}>
                    {day.toLocaleDateString("es-PE", { weekday: "long", day: "numeric", month: "long" })}
                    {isoDay(day) === today && <span className="ml-1.5"><Chip tone="info">Hoy</Chip></span>}
                  </h3>
                  <div className="grid gap-1.5">
                    {list.map((leave) => (
                      <button
                        key={leave.id}
                        type="button"
                        onClick={() => onOpen(leave)}
                        className={`${CHIP} justify-between py-1.5 text-[12.5px] ${TONE[leave.status] ?? TONE.APPROVED}`}
                      >
                        <span className="truncate">{leave.employee.name}</span>
                        <span className="shrink-0 text-[11.5px] font-normal">{leave.type.name}</span>
                      </button>
                    ))}
                  </div>
                </section>
              ))
            ) : (
              <Card><p className="app-muted text-center">Sin ausencias este mes.</p></Card>
            )}
          </div>

          {/* Pantallas medianas y grandes: el mes en cuadrícula. */}
          <div className={`app-surface hidden overflow-hidden rounded-xl border border-[color:var(--app-border)] transition-opacity sm:block ${loading && leaves ? "opacity-60" : ""}`} aria-busy={loading}>
            <div className="app-muted grid grid-cols-7 border-b border-[color:var(--app-border)] text-center text-[10.5px] font-semibold uppercase tracking-[.06em]">
              {days.slice(0, 7).map((day) => <span key={day.getDay()} className="px-1 py-2">{day.toLocaleDateString("es-PE", { weekday: "short" })}</span>)}
            </div>
            <div className="grid grid-cols-7">
              {days.map((day, index) => {
                const key = isoDay(day);
                const list = onDay(key);
                const outside = day.getMonth() !== cursor.getMonth();
                return (
                  <div
                    key={key}
                    className={`grid min-h-[104px] min-w-0 content-start gap-1 border-[color:var(--app-border)] p-1.5 ${index % 7 ? "border-l" : ""} ${index >= 7 ? "border-t" : ""} ${outside ? "bg-[var(--app-surface-muted)]" : ""}`}
                  >
                    <strong
                      className={`grid h-6 w-fit min-w-6 place-items-center rounded-full px-1 text-[13px] font-semibold tabular-nums ${
                        key === today ? "bg-[var(--p-accent,#1B8FC9)] text-[color:var(--p-on-accent,#fff)]" : outside ? "app-muted" : "app-text"
                      }`}
                    >
                      {day.getDate()}
                    </strong>
                    {!leaves ? (
                      <span className="h-4 animate-pulse rounded bg-[var(--app-surface-muted)]" />
                    ) : (
                      <>
                        {list.slice(0, 3).map((leave) => <LeaveChip key={leave.id} leave={leave} onOpen={onOpen} />)}
                        {list.length > 3 && (
                          <button type="button" onClick={() => setDayOpen(key)} className="app-muted cursor-pointer text-left text-[11.5px] hover:underline">
                            +{list.length - 3} más
                          </button>
                        )}
                      </>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        </>
      )}

      {dayOpen && (
        <Modal
          title={new Date(`${dayOpen}T12:00:00`).toLocaleDateString("es-PE", { weekday: "long", day: "numeric", month: "long" })}
          size="sm"
          onClose={() => setDayOpen(null)}
          footer={<Btn variant="ghost" onClick={() => setDayOpen(null)}>Cerrar</Btn>}
        >
          <div className="grid gap-1.5">
            {onDay(dayOpen).map((leave) => (
              <button
                key={leave.id}
                type="button"
                onClick={() => { setDayOpen(null); onOpen(leave); }}
                className={`${CHIP} justify-between py-1.5 text-[12.5px] ${TONE[leave.status] ?? TONE.APPROVED}`}
              >
                <span className="truncate">{leave.employee.name}</span>
                <span className="shrink-0 text-[11.5px] font-normal">{leave.type.name}</span>
              </button>
            ))}
          </div>
        </Modal>
      )}
    </>
  );
}
