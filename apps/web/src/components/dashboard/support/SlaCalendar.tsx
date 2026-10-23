"use client";

import { useEffect, useState } from "react";
import { Lock, X } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { DAYS, SLA_MODES, dayText, minutesText, supportHistoryLabel } from "@/lib/support";
import { useAccess } from "@/lib/access-context";
import {
  Btn, Card, Chip, DataTable, Field, INPUT, LoadError, PageHead, Screen, Timeline, deniedText, useToasts, type Column,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { PickSelect } from "@/components/ui/pick-select";
import { PriorityTag } from "@/components/dashboard/support/SupportParts";
import type { SlaMode, SlaSettings, TicketPriority } from "@/types/support";

// Como el prototipo: de lunes a sábado y el domingo al final.
const WEEK = [1, 2, 3, 4, 5, 6, 0];

type Day = { on: boolean; from: string; to: string };

const hoursOf = (settings: SlaSettings): Record<string, Day> => Object.fromEntries(WEEK.map((day) => {
  const window = (settings.calendar.hours as Record<string, { from: string; to: string } | null>)[String(day)] ?? null;
  return [String(day), window ? { on: true, from: window.from, to: window.to } : { on: false, from: "09:00", to: "13:00" }];
}));

/** "En 3 h", "Mañana", "En 2 d": cuánto falta para una fecha, como el `relTime` del prototipo. */
function fromNow(value: string, now: number) {
  const ms = new Date(value).getTime() - now;
  const hours = ms / 3_600_000;
  if (hours < 1) return `En ${Math.max(1, Math.round(hours * 60))} min`;
  if (hours < 24) return `En ${Math.round(hours)} h`;
  return `En ${Math.round(hours / 24)} d`;
}

const hoursText = (minutes: number) => String(Math.round((minutes / 60) * 100) / 100);

type SimulationRow = { priority: TicketPriority; label: string; resolution: number; mode: SlaMode; due: string; premium_due: string };

export default function SlaCalendar() {
  const { can, loading: accessLoading } = useAccess();
  const { toast, toasts } = useToasts();
  const [settings, setSettings] = useState<SlaSettings | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);

  const [days, setDays] = useState<Record<string, Day>>({});
  const [modes, setModes] = useState<Record<string, SlaMode>>({});
  const [times, setTimes] = useState<Record<string, { response: string; resolution: string }>>({});
  const [premium, setPremium] = useState("50");
  const [holidayDate, setHolidayDate] = useState("");
  const [holidayName, setHolidayName] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});

  const canManage = !accessLoading && can("sla.manage");

  function apply(next: SlaSettings) {
    setSettings(next);
    setDays(hoursOf(next));
    setModes(Object.fromEntries(next.policies.map((policy) => [policy.priority, policy.mode])));
    setTimes(Object.fromEntries(next.policies.map((policy) => [policy.priority, { response: hoursText(policy.response_minutes), resolution: hoursText(policy.resolution_minutes) }])));
    setPremium(String(next.calendar.premium_percent));
  }

  useEffect(() => {
    let cancelled = false;
    apiFetch<SlaSettings>("/v1/sla-settings")
      .then((next) => { if (!cancelled) { apply(next); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el calendario laboral."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function save(path: string, method: "PUT" | "POST" | "DELETE", body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    setErrors({});
    try {
      apply(await apiFetch<SlaSettings>(`/v1/sla-settings/${path}`, { method, body: body ? JSON.stringify(body) : undefined }));
      toast(done);
      return true;
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar."), "danger");
      if (err instanceof ApiError && err.status === 409) setReload((n) => n + 1);
      return false;
    } finally {
      setBusy(false);
    }
  }

  if (error) {
    return <Screen><Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>{toasts}</Screen>;
  }
  if (!settings) {
    return <Screen><Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>{toasts}</Screen>;
  }

  const now = new Date(settings.simulation.from).getTime();
  const policyOf = (priority: string) => settings.policies.find((policy) => policy.priority === priority);
  const simulation: SimulationRow[] = settings.simulation.rows.map((row) => {
    const policy = policyOf(row.priority);
    return { ...row, label: policy?.label ?? "", resolution: policy?.resolution_minutes ?? 0, mode: policy?.mode ?? "BUSINESS" };
  });
  const upcoming = settings.holidays.filter((holiday) => holiday.date >= settings.today).slice(0, 4);
  const year = settings.today.slice(0, 4);
  const lock = settings.calendar.lock_version;
  const denied = canManage ? undefined : deniedText("sla.manage");
  // Sin permiso, las tarjetas editables lo dicen con candado (los campos quedan deshabilitados).
  const readOnly = denied ? <span title={denied}><Chip tone="neutral"><Lock size={11} className="mr-1" aria-hidden="true" />Solo lectura</Chip></span> : undefined;

  const simulationCols: Column<SimulationRow>[] = [
    { label: "Prioridad", render: (row) => <span className="inline-flex items-center gap-1.5 whitespace-nowrap"><PriorityTag priority={row.priority} /> {row.label}</span> },
    {
      label: "Resolución",
      render: (row) => (
        <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
          {minutesText(row.resolution)} <Chip tone={row.mode === "BUSINESS" ? "info" : "neutral"}>{row.mode === "BUSINESS" ? "hábiles" : "24×7"}</Chip>
        </span>
      ),
    },
    {
      label: "Vence",
      render: (row) => (
        <div className="min-w-[96px]">
          <strong className="font-semibold">{dateTime(row.due)}</strong>
          <small className="app-muted block text-xs">{fromNow(row.due, now)}</small>
        </div>
      ),
    },
    {
      label: "Con Premium",
      render: (row) => (
        <div className="min-w-[96px]">
          {dateTime(row.premium_due)}
          <small className="app-muted block text-xs">{fromNow(row.premium_due, now)}</small>
        </div>
      ),
    },
  ];

  return (
    <Screen>
      <PageHead title="Calendario laboral y SLA" />

      <SortableBoard
        storageKey="erp.cards.sla-calendar"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["hours", "times"] },
          { className: "min-w-0 lg:min-h-24", cards: ["simulation", "holidays", "history"] },
        ]}
        cards={[
          {
            id: "hours",
            node: (
              <Card title="Horario de atención" actions={readOnly}>
                <div className="grid gap-2" title={denied}>
                  {WEEK.map((day) => {
                    const value = days[String(day)];
                    if (!value) return null;
                    const set = (patch: Partial<Day>) => setDays((current) => ({ ...current, [String(day)]: { ...current[String(day)], ...patch } }));
                    return (
                      <div key={day} className="grid grid-cols-[minmax(110px,1fr)_1fr_1fr] items-center gap-2.5">
                        <label className="relative inline-flex cursor-pointer items-center gap-2">
                          <input type="checkbox" checked={value.on} onChange={(e) => set({ on: e.target.checked })} disabled={!canManage} className="h-4 w-4 accent-[#1B8FC9]" />
                          <span>{DAYS[day]}</span>
                        </label>
                        <label className="relative">
                          <span className="sr-only">Desde ({DAYS[day]})</span>
                          <input type="time" className={INPUT} value={value.from} onChange={(e) => set({ from: e.target.value })} disabled={!canManage || !value.on} />
                        </label>
                        <label className="relative">
                          <span className="sr-only">Hasta ({DAYS[day]})</span>
                          <input type="time" className={INPUT} value={value.to} onChange={(e) => set({ to: e.target.value })} disabled={!canManage || !value.on} />
                        </label>
                        {errors[`hours.${day}`] && <small className="col-span-3 text-xs text-[color:var(--p-danger-text)]">{errors[`hours.${day}`]}</small>}
                      </div>
                    );
                  })}
                  {errors.hours && <small className="text-xs text-[color:var(--p-danger-text)]">{errors.hours}</small>}
                </div>

                <h3 className="mb-2.5 mt-4 border-t border-[color:var(--app-border)] pt-3.5 text-[13px] font-semibold">Modo de cálculo por prioridad</h3>
                <div className="grid grid-cols-1 gap-x-3.5 gap-y-3 sm:grid-cols-2">
                  {settings.policies.map((policy) => (
                    <Field key={policy.priority} label={`${policy.priority} · ${policy.label}`}>
                      <PickSelect
                        className={INPUT}
                        ariaLabel={`Modo de cálculo de ${policy.priority}`}
                        value={modes[policy.priority] ?? policy.mode}
                        onChange={(value) => setModes((current) => ({ ...current, [policy.priority]: value as SlaMode }))}
                        options={Object.entries(SLA_MODES) as [string, string][]}
                        disabled={!canManage}
                      />
                    </Field>
                  ))}
                </div>
                <p className="app-muted mt-3 text-[12.5px]">Zona horaria {settings.calendar.timezone}. Cambiar el horario no mueve los vencimientos de los tickets ya clasificados.</p>
                <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                  <span className="app-muted text-[12px]">{settings.calendar.updated_by ? `Último cambio: ${settings.calendar.updated_by}` : ""}</span>
                  <Btn
                    permission="sla.manage"
                    disabled={busy}
                    onClick={() => save("calendar", "PUT", {
                      hours: Object.fromEntries(WEEK.map((day) => {
                        const value = days[String(day)];
                        return [String(day), value?.on ? { from: value.from, to: value.to } : null];
                      })),
                      modes,
                      lock_version: lock,
                    }, "Horario guardado")}
                  >
                    Guardar horario
                  </Btn>
                </div>
              </Card>
            ),
          },
          {
            id: "times",
            node: (
              <Card title="Tiempos por prioridad" actions={readOnly}>
                <div className="grid gap-2.5" title={denied}>
                  <div className="app-muted hidden grid-cols-[minmax(110px,1fr)_1fr_1fr] gap-2.5 text-[11px] font-semibold uppercase tracking-[.05em] sm:grid">
                    <span>Prioridad</span><span>Respuesta (h)</span><span>Resolución (h)</span>
                  </div>
                  {settings.policies.map((policy, index) => (
                    <div key={policy.priority} className="grid grid-cols-1 items-center gap-2.5 sm:grid-cols-[minmax(110px,1fr)_1fr_1fr]">
                      <span className="inline-flex items-center gap-1.5"><PriorityTag priority={policy.priority} /> {policy.label}</span>
                      <label className="relative">
                        <span className="sr-only">Respuesta de {policy.priority}, en horas</span>
                        <input
                          type="number" min={0.25} max={720} step={0.25} className={INPUT} disabled={!canManage}
                          value={times[policy.priority]?.response ?? ""}
                          onChange={(e) => setTimes((current) => ({ ...current, [policy.priority]: { ...current[policy.priority], response: e.target.value } }))}
                        />
                      </label>
                      <label className="relative">
                        <span className="sr-only">Resolución de {policy.priority}, en horas</span>
                        <input
                          type="number" min={0.25} max={720} step={0.25} className={INPUT} disabled={!canManage}
                          value={times[policy.priority]?.resolution ?? ""}
                          onChange={(e) => setTimes((current) => ({ ...current, [policy.priority]: { ...current[policy.priority], resolution: e.target.value } }))}
                        />
                      </label>
                      {(errors[`policies.${index}.response_hours`] || errors[`policies.${index}.resolution_hours`]) && (
                        <small className="text-xs text-[color:var(--p-danger-text)] sm:col-span-3">{errors[`policies.${index}.response_hours`] ?? errors[`policies.${index}.resolution_hours`]}</small>
                      )}
                    </div>
                  ))}
                  <div className="mt-1 grid grid-cols-1 items-center gap-2.5 sm:grid-cols-[minmax(110px,1fr)_2fr]">
                    <span>Contrato Premium</span>
                    <Field label="Porcentaje del tiempo normal" error={errors.premium_percent} hint="50 % = la mitad del tiempo.">
                      <input type="number" min={10} max={100} step={5} className={INPUT} value={premium} onChange={(e) => setPremium(e.target.value)} disabled={!canManage} />
                    </Field>
                  </div>
                </div>
                <div className="mt-3 flex justify-end">
                  <Btn
                    permission="sla.manage"
                    disabled={busy}
                    onClick={() => save("policies", "PUT", {
                      policies: settings.policies.map((policy) => ({
                        priority: policy.priority,
                        response_hours: Number(times[policy.priority]?.response),
                        resolution_hours: Number(times[policy.priority]?.resolution),
                      })),
                      premium_percent: Number(premium),
                      lock_version: lock,
                    }, "Tiempos de SLA guardados")}
                  >
                    Guardar tiempos
                  </Btn>
                </div>
              </Card>
            ),
          },
          {
            id: "simulation",
            node: (
              <Card title="Simulación: ticket creado ahora" flush>
                <DataTable cols={simulationCols} rows={simulation} rowKey={(row) => row.priority} dense />
                <p className="app-muted px-5 pb-4 pt-3 text-[12.5px]">
                  El contrato Premium reduce los tiempos al {settings.calendar.premium_percent} %; las pausas por «Esperando cliente» se suman al vencimiento.
                </p>
              </Card>
            ),
          },
          {
            id: "holidays",
            node: (
              <Card title={`Feriados (${settings.holidays.length})`}>
                <ul className="max-h-[300px] overflow-y-auto pr-1">
                  {settings.holidays.map((holiday) => (
                    <li key={holiday.id} className="flex items-center justify-between gap-2 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                      <span className={holiday.date < settings.today ? "app-muted" : ""}>{dayText(holiday.date)} · {holiday.name}</span>
                      <button
                        type="button"
                        aria-label={`Quitar ${holiday.name}`}
                        title={denied ?? "Quitar"}
                        disabled={!canManage || busy}
                        onClick={() => save(`holidays/${holiday.id}`, "DELETE", null, "Feriado quitado")}
                        className="app-muted grid h-7 w-7 shrink-0 cursor-pointer place-items-center rounded-md hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)] disabled:cursor-not-allowed disabled:opacity-45"
                      >
                        {canManage ? <X size={15} /> : <Lock size={13} aria-hidden="true" />}
                      </button>
                    </li>
                  ))}
                </ul>
                <div className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-[150px_1fr]">
                  <Field label="Fecha" required error={errors.date}>
                    <input type="date" className={INPUT} value={holidayDate} onChange={(e) => setHolidayDate(e.target.value)} disabled={!canManage} />
                  </Field>
                  <Field label="Nombre" required error={errors.name}>
                    <input className={INPUT} value={holidayName} onChange={(e) => setHolidayName(e.target.value)} maxLength={150} disabled={!canManage} />
                  </Field>
                </div>
                <div className="mt-2.5 flex justify-end">
                  <Btn
                    variant="secondary"
                    permission="sla.manage"
                    disabled={busy}
                    onClick={async () => {
                      if (await save("holidays", "POST", { date: holidayDate, name: holidayName }, "Feriado agregado")) {
                        setHolidayDate("");
                        setHolidayName("");
                      }
                    }}
                  >
                    Agregar feriado
                  </Btn>
                </div>
                <p className="app-muted mt-3 text-[12.5px]">
                  Lista referencial de feriados nacionales {year}. Próximos: {upcoming.map((holiday) => holiday.name).join(", ") || "—"}.
                </p>
              </Card>
            ),
          },
          {
            id: "history",
            node: (
              <Card title="Historial">
                <Timeline
                  items={settings.history.map((entry, index) => ({
                    key: `${entry.at}-${index}`,
                    head: <span className="font-medium">{supportHistoryLabel(entry)}</span>,
                    meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                    note: entry.metadata.note,
                  }))}
                  empty="Sin cambios: rige el horario de oficina de Lima del prototipo."
                />
              </Card>
            ),
          },
        ]}
      />
      {toasts}
    </Screen>
  );
}
