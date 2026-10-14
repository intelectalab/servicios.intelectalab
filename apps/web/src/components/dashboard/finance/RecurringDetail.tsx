"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Play } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { customerName, money, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { CYCLE_STATES, EVENT_STATES, RECURRING_STATES, financeHistoryLabel, frequencyLabel } from "@/lib/finance";
import { useAccess } from "@/lib/access-context";
import {
  Badge, Btn, Card, Chip, Field, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, StateTrack, TD, TH, Timeline, useToasts,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { CycleRunModal } from "@/components/dashboard/finance/RecurringList";
import type { CycleRunSummary, RecurringDetail as Instance } from "@/types/finance";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

type Transition = "suspend" | "reactivate" | "cancel";

const TRANSITIONS: Record<"suspend" | "cancel", { title: string; text: string; confirm: string; done: string }> = {
  suspend: {
    title: "Suspender", text: "Mientras esté suspendida no se procesan sus ciclos ni se generan eventos facturables.",
    confirm: "Suspender", done: "Instancia suspendida",
  },
  cancel: {
    title: "Cancelar", text: "La instancia deja de cobrarse definitivamente. Sus ciclos ya procesados se conservan.",
    confirm: "Cancelar instancia", done: "Instancia cancelada",
  },
};

/** "Renovar": nueva vigencia (y, si se indica, nuevo precio) sin tocar la anterior. */
function RenewModal({ instance, onClose, onDone }: { instance: Instance; onClose: () => void; onDone: () => void }) {
  const [months, setMonths] = useState("12");
  const [price, setPrice] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      await apiFetch(`/v1/recurring/${instance.id}/renew`, { method: "POST", body: JSON.stringify({ months: Number(months), price: price === "" ? null : Number(price) }) });
      onDone();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!fields.months && !fields.price) setError(firstError(err, "No se pudo renovar el servicio."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Renovar servicio recurrente"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="renew-form" disabled={saving} permission="recurring.manage">{saving ? "Renovando…" : "Renovar"}</Btn>
        </>
      }
    >
      <form id="renew-form" onSubmit={submit} className="grid gap-3">
        {error && <Notice tone="warn">{error}</Notice>}
        <Field label="Meses de renovación" required error={errors.months}>
          <input className={INPUT} type="number" min="1" max="120" step="1" inputMode="numeric" value={months} onChange={(e) => setMonths(e.target.value)} required autoFocus />
        </Field>
        <Field label="Nuevo precio unitario (opcional)" error={errors.price} hint={`Actual: ${money(instance.unit_price, instance.currency)} (sin IGV)`}>
          <input className={INPUT} type="number" min="0.01" step="0.01" inputMode="decimal" value={price} onChange={(e) => setPrice(e.target.value)} />
        </Field>
        <p className="app-muted text-xs">La vigencia anterior se conserva en el historial. Un precio nuevo se aplica desde el próximo ciclo; los ciclos ya procesados no cambian.</p>
      </form>
    </Modal>
  );
}

export default function RecurringDetail() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAccess();
  const { toast, toasts } = useToasts();

  const [instance, setInstance] = useState<Instance | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [renewing, setRenewing] = useState(false);
  const [asking, setAsking] = useState<"suspend" | "cancel" | null>(null);
  const [result, setResult] = useState<CycleRunSummary | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Instance>(`/v1/recurring/${id}`)
      .then((next) => {
        if (cancelled) return;
        setInstance(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El servicio recurrente no existe." : "No se pudo cargar el servicio recurrente.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Cambia el estado de la instancia; devuelve si salió bien. La ficha se vuelve a pedir. */
  async function transition(path: Transition, body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      await apiFetch(`/v1/recurring/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      toast(done);
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      return false;
    } finally {
      setBusy(false);
      // También tras un error: si otra persona la cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  async function processCycle() {
    setBusy(true);
    try {
      setResult(await apiFetch<CycleRunSummary>("/v1/recurring/run-cycles", { method: "POST", body: JSON.stringify({ instance_id: id }) }));
      setConfirming(false);
    } catch (err) {
      toast(firstError(err, "No se pudo procesar el ciclo."), "danger");
    } finally {
      setBusy(false);
      setReload((n) => n + 1);
    }
  }

  const back = (
    <Link href="/recurring" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Recurrencia
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

  if (!instance) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const status = instance.status;
  const currency = instance.currency;
  const history = instance.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("ACTIVE");
  const seesEvents = can("billing.view");
  // Los días que faltan para el ciclo que toca: a mano se puede adelantar, pero no más allá del margen.
  const daysToNext = instance.days_to_next;
  const canProcess = status === "ACTIVE" && daysToNext !== null && daysToNext <= instance.single_horizon_days;
  const whyNot = status !== "ACTIVE"
    ? "Solo se procesan los ciclos de una instancia activa"
    : `El próximo ciclo aún está a más de ${instance.single_horizon_days} días`;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={instance.service_name ?? "Servicio recurrente"}
        sub={
          <>
            {customerName(instance.customer)}
            {instance.contract && (
              <>
                {" · contrato "}
                <Link href={`/contracts/${instance.contract.id}`} className={LINK}>{instance.contract.contract_number}</Link>
              </>
            )}
          </>
        }
        actions={
          <>
            <Btn onClick={() => setConfirming(true)} permission="recurring.create" disabled={busy || !canProcess} title={canProcess ? undefined : whyNot}>
              <Play size={16} />
              <span>Procesar ciclo de esta instancia</span>
            </Btn>
            {status !== "CANCELLED" && (
              <Btn variant="secondary" onClick={() => setRenewing(true)} permission="recurring.manage" disabled={busy}>Renovar</Btn>
            )}
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.recurring-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-2"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["instance"] },
          { className: "min-w-0 lg:min-h-24", cards: ["states"] },
        ]}
        cards={[
          {
            id: "instance",
            node: (
              <Card title="Instancia">
                <Kv
                  rows={[
                    ["Frecuencia", frequencyLabel(instance.frequency, instance.interval_value)],
                    ["Precio snapshot", `${money(instance.unit_price, currency)} × ${instance.quantity} (sin IGV)`],
                    ["Monto por ciclo", <strong key="amount" className="font-semibold tabular-nums">{money(instance.amount, currency)}</strong>],
                    [
                      "Próximo ciclo",
                      <span key="next" className="inline-flex flex-wrap items-center gap-1.5">
                        {shortDate(instance.next_cycle_date)}
                        {instance.due && <Chip tone="warning">Vencido</Chip>}
                      </span>,
                    ],
                    [
                      "Vigencia",
                      <span key="term" className="inline-flex flex-wrap items-center gap-1.5">
                        {shortDate(instance.start_date)} → {shortDate(instance.end_date)}
                        {instance.renewal_due && <Chip tone="warning">Por renovar</Chip>}
                      </span>,
                    ],
                    // Aún no hay renovación automática: solo el aviso.
                    ["Renovación automática", "No (se alerta 45 días antes)"],
                    instance.terms.length ? [
                      "Vigencias anteriores",
                      <span key="terms" className="grid gap-0.5">
                        {instance.terms.map((term) => (
                          <span key={term.id}>{shortDate(term.start)} → {shortDate(term.end)} · {money(term.unit_price, term.currency)}</span>
                        ))}
                      </span>,
                    ] : null,
                  ]}
                />
                <p className="app-muted text-xs">Cambiar el precio del catálogo no modifica los ciclos de esta instancia: se cobra lo pactado.</p>
              </Card>
            ),
          },
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={RECURRING_STATES} current={status} visited={visited} />
                  {instance.status_reason && (status === "SUSPENDED" || status === "CANCELLED") && (
                    <Notice tone="warn"><strong className="font-semibold">Motivo:</strong> {instance.status_reason}</Notice>
                  )}
                  {(status === "ACTIVE" || status === "SUSPENDED") && (
                    <div className="flex flex-wrap gap-2">
                      {status === "ACTIVE" && <Btn size="sm" variant="danger-ghost" onClick={() => setAsking("suspend")} permission="recurring.manage" disabled={busy}>Suspender</Btn>}
                      {status === "SUSPENDED" && (
                        <Btn size="sm" variant="secondary" onClick={() => transition("reactivate", null, "Instancia reactivada")} permission="recurring.manage" disabled={busy}>Reactivar</Btn>
                      )}
                      <Btn size="sm" variant="danger-ghost" onClick={() => setAsking("cancel")} permission="recurring.manage" disabled={busy}>Cancelar</Btn>
                    </div>
                  )}
                  {status === "EXPIRED" && <p className="app-muted text-[12.5px]">Su vigencia terminó. Renuévala para que vuelva a generar ciclos.</p>}
                  <Timeline
                    items={history.map((entry, index) => {
                      const to = entry.metadata.to;
                      return {
                        key: `${entry.at}-${index}`,
                        head: to ? (
                          <>
                            <Badge tone={stateOf(RECURRING_STATES, to)[1]}>{stateOf(RECURRING_STATES, to)[0]}</Badge>
                            {entry.metadata.from && entry.metadata.from !== to && (
                              <span className="app-muted text-[12.5px]">desde {stateOf(RECURRING_STATES, entry.metadata.from)[0]}</span>
                            )}
                          </>
                        ) : (
                          <span className="font-medium">{financeHistoryLabel(entry)}</span>
                        ),
                        meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                        note: entry.metadata.note,
                      };
                    })}
                  />
                </div>
              </Card>
            ),
          },
        ]}
      />

      <Card title="Ciclos">
        {instance.cycles.length ? (
          <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
            <table className="proto-tbl w-full border-collapse text-[13px]">
              <thead>
                <tr>
                  <th className={TH}>Periodo</th>
                  <th className={TH}>Clave de idempotencia</th>
                  <th className={`${TH} text-right`}>Monto</th>
                  <th className={TH}>Evento facturable</th>
                  <th className={`${TH} text-right`}>Intentos</th>
                  <th className={TH}>Estado</th>
                </tr>
              </thead>
              <tbody>
                {instance.cycles.map((cycle) => (
                  <tr key={cycle.id}>
                    <td className={TD} data-label="Periodo"><strong className="font-semibold tabular-nums">{cycle.period}</strong></td>
                    <td className={TD} data-label="Clave de idempotencia"><code className="app-muted text-xs [overflow-wrap:anywhere]">{cycle.idempotency_key}</code></td>
                    <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Monto">{money(cycle.amount, currency)}</td>
                    <td className={TD} data-label="Evento facturable">
                      {cycle.billable_event_status ? (
                        <span className="inline-flex flex-wrap items-center gap-1.5">
                          <Badge tone={stateOf(EVENT_STATES, cycle.billable_event_status)[1]}>{stateOf(EVENT_STATES, cycle.billable_event_status)[0]}</Badge>
                          {seesEvents && <Link href="/billable-events" className={`${LINK} text-xs`}>Ver</Link>}
                        </span>
                      ) : cycle.error ? (
                        <span className="text-xs text-[color:var(--p-danger-text)]">{cycle.error}</span>
                      ) : "—"}
                    </td>
                    <td className={`${TD} text-right tabular-nums`} data-label="Intentos">{cycle.attempts || 1}</td>
                    <td className={TD} data-label="Estado"><Badge tone={stateOf(CYCLE_STATES, cycle.status)[1]}>{stateOf(CYCLE_STATES, cycle.status)[0]}</Badge></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="app-muted">Aún no se procesó ningún ciclo.</p>
        )}
      </Card>

      {confirming && (
        <Modal
          title="Procesar ciclo"
          size="sm"
          onClose={() => setConfirming(false)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setConfirming(false)}>Cancelar</Btn>
              <Btn disabled={busy} permission="recurring.create" onClick={processCycle}>{busy ? "Procesando…" : "Procesar"}</Btn>
            </>
          }
        >
          <p className="text-[15px]">{instance.service_name} · {money(instance.amount, currency)}</p>
          <p className="app-muted mt-2">
            Se procesa el ciclo del {shortDate(instance.next_cycle_date)} y queda su evento facturable. Un periodo no se procesa dos veces.
          </p>
          {daysToNext !== null && daysToNext > 0 && (
            <Notice tone="warn">Ese ciclo aún no vence ({daysToNext === 1 ? "falta 1 día" : `faltan ${daysToNext} días`}): se facturaría por adelantado.</Notice>
          )}
        </Modal>
      )}
      {renewing && (
        <RenewModal
          instance={instance}
          onClose={() => setRenewing(false)}
          onDone={() => {
            setRenewing(false);
            toast("Servicio renovado");
            setReload((n) => n + 1);
          }}
        />
      )}
      {asking && (
        <ReasonModal
          title={TRANSITIONS[asking].title}
          text={TRANSITIONS[asking].text}
          label="Motivo"
          confirmLabel={TRANSITIONS[asking].confirm}
          busy={busy}
          onClose={() => setAsking(null)}
          onConfirm={async (reason) => {
            if (await transition(asking, { reason }, TRANSITIONS[asking].done)) setAsking(null);
          }}
        />
      )}
      {result && <CycleRunModal summary={result} onClose={() => setResult(null)} />}
      {toasts}
    </Screen>
  );
}
