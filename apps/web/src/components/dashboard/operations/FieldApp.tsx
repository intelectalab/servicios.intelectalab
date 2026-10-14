"use client";

import { useCallback, useEffect, useState } from "react";
import Link from "next/link";
import { Camera, Clock, MapPin, Navigation, RefreshCw, Upload, Wifi, WifiOff } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import { WORK_ORDER_STATES } from "@/lib/operations";
import {
  applyOp, effectiveStatus, enqueueOp, isNetworkError, newOp, offlineMode, queuedOps, savedRoute, saveRoute, setOfflineMode, syncLog, syncQueue,
  type FieldOp, type FieldOpKind, type SyncEntry,
} from "@/lib/field-queue";
import { Badge, Btn, Card, Chip, Filters, LoadError, PageHead, Screen, useToasts } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { FieldMap } from "@/components/dashboard/operations/FieldMap";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { TimeEntryFormModal } from "@/components/dashboard/operations/ProjectModals";
import type { FieldOrder, FieldRoute, TimeEntryOptions } from "@/types/operations";

const BLUE = "#1B8FC9";
const DONE_TEXT: Record<FieldOpKind, string> = {
  start: "Visita iniciada", unblock: "Visita reanudada", block: "Visita bloqueada", complete: "Visita completada",
  check: "Checklist actualizado", evidence: "Evidencia adjunta",
};

/** Enlace para abrir la navegación del teléfono hacia la visita. */
function directions(order: FieldOrder): string | null {
  const target = order.latitude !== null && order.longitude !== null ? `${order.latitude},${order.longitude}` : order.location;
  return target ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(target)}` : null;
}

/** Mapa esquemático (sin internet): las visitas ubicadas, numeradas en el orden sugerido y unidas por la ruta. */
function RouteMap({ orders, statusOf }: { orders: FieldOrder[]; statusOf: (order: FieldOrder) => string }) {
  const points = orders.map((order, index) => ({ order, index })).filter(({ order }) => order.latitude !== null && order.longitude !== null);
  if (!points.length) {
    return <p className="app-muted px-4 py-7 text-center text-[12.5px]">Sin ubicaciones para mostrar. Indica las coordenadas en la orden de trabajo.</p>;
  }

  const W = 320, H = 190, pad = 0.012;
  const lats = points.map(({ order }) => order.latitude as number), lngs = points.map(({ order }) => order.longitude as number);
  const minLat = Math.min(...lats) - pad, maxLat = Math.max(...lats) + pad, minLng = Math.min(...lngs) - pad, maxLng = Math.max(...lngs) + pad;
  const xy = (order: FieldOrder): [number, number] => [
    (((order.longitude as number) - minLng) / (maxLng - minLng || 1)) * (W - 30) + 15,
    (1 - ((order.latitude as number) - minLat) / (maxLat - minLat || 1)) * (H - 30) + 15,
  ];
  const path = points.map(({ order }, index) => `${index ? "L" : "M"}${xy(order).map((n) => n.toFixed(1)).join(" ")}`).join(" ");

  return (
    <div className="px-3 pt-2.5">
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Mapa de visitas del día" className="block h-auto w-full rounded-[14px]">
        <rect width={W} height={H} fill="var(--app-surface-muted)" />
        {[40, 95, 150].map((y) => <path key={`h${y}`} d={`M0 ${y} C 80 ${y - 18}, 160 ${y + 22}, ${W} ${y - 6}`} fill="none" stroke="var(--app-surface)" strokeWidth={6} />)}
        {[70, 170, 260].map((x) => <path key={`v${x}`} d={`M${x} 0 C ${x - 15} 60, ${x + 20} 120, ${x - 5} ${H}`} fill="none" stroke="var(--app-surface)" strokeWidth={6} />)}
        <path d={path} fill="none" stroke={BLUE} strokeWidth={3} strokeDasharray="6 5" />
        {points.map(({ order, index }) => {
          const [x, y] = xy(order);
          return (
            <g key={order.id}>
              <circle cx={x} cy={y} r={11} fill={statusOf(order) === "IN_PROGRESS" ? "var(--p-warning-text)" : BLUE} stroke="var(--app-surface)" strokeWidth={2.5} />
              <text x={x} y={y + 4} textAnchor="middle" fill="#fff" fontSize={11} fontWeight={700}>{index + 1}</text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

export default function FieldApp() {
  const { toast, toasts } = useToasts();
  const [technicianId, setTechnicianId] = useState("");
  const [route, setRoute] = useState<FieldRoute | null>(null);
  const [error, setError] = useState("");
  const [offline, setOffline] = useState(false);
  const [queue, setQueue] = useState<FieldOp[]>([]);
  const [log, setLog] = useState<SyncEntry[]>([]);
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState("");
  const [blocking, setBlocking] = useState<FieldOrder | null>(null);
  const [logging, setLogging] = useState<FieldOrder | null>(null);
  const [timeOptions, setTimeOptions] = useState<TimeEntryOptions | null>(null);

  const viewerId = route?.viewer_id ?? "";

  const refreshQueue = useCallback(async (userId: string) => {
    if (!userId) return;
    setQueue(await queuedOps(userId).catch(() => []));
    setLog(syncLog(userId));
  }, []);

  /** Carga la ruta; sin señal, muestra la última que esta sesión vio. Devuelve si hubo respuesta. */
  const load = useCallback(async (technician: string, keepOffline = false): Promise<boolean> => {
    try {
      const next = await apiFetch<FieldRoute>(`/v1/field/route${technician ? `?user=${technician}` : ""}`);
      saveRoute(next);
      setRoute(next);
      setError("");
      if (!keepOffline) setOffline(false);
      await refreshQueue(next.viewer_id);
      return true;
    } catch (err) {
      if (!isNetworkError(err)) {
        setError(firstError(err, "No se pudo cargar la ruta."));
        return true;
      }
      const saved = savedRoute(technician || undefined);
      if (saved) {
        setRoute(saved);
        setError("");
        await refreshQueue(saved.viewer_id);
      } else {
        setError("Sin conexión, y este teléfono aún no tiene guardada tu ruta. Vuelve a intentarlo con señal.");
      }
      setOffline(true);
      return false;
    }
  }, [refreshQueue]);

  useEffect(() => {
    // Si la persona eligió trabajar sin conexión, se respeta al volver a abrir la app.
    const manual = offlineMode();
    const saved = manual ? savedRoute(technicianId || undefined) : null;
    const start = async () => {
      if (saved) {
        setRoute(saved);
        setOffline(true);
        await refreshQueue(saved.viewer_id);
      } else {
        await load(technicianId);
      }
    };
    start();
  }, [technicianId, load, refreshQueue]);

  useEffect(() => {
    const tick = () => setNow(new Date().toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit", hour12: false }));
    tick();
    const timer = window.setInterval(tick, 30_000);
    // El teléfono avisa cuando pierde la red.
    const down = () => setOffline(true);
    window.addEventListener("offline", down);
    return () => {
      window.clearInterval(timer);
      window.removeEventListener("offline", down);
    };
  }, []);

  /** Ejecuta un cambio: con señal va a la API; sin ella (o si se cae) queda guardado en el teléfono. */
  async function perform(order: FieldOrder, kind: FieldOpKind, extra: Partial<FieldOp> = {}): Promise<boolean> {
    if (!viewerId) return false;
    const label = `${DONE_TEXT[kind]}${extra.fileName ? ` · ${extra.fileName}` : ""} · ${order.code}`;
    const op = newOp({ userId: viewerId, workOrderId: order.id, kind, label, ...extra });
    const keep = async () => {
      await enqueueOp(op);
      await refreshQueue(viewerId);
      toast("Guardado en el teléfono", "warning", "Se sincronizará al recuperar la conexión");
    };

    setBusy(true);
    try {
      if (offline) {
        await keep();
        return true;
      }
      await applyOp(op);
      toast(DONE_TEXT[kind]);
      await load(technicianId);
      return true;
    } catch (err) {
      if (isNetworkError(err)) {
        setOffline(true);
        await keep();
        return true;
      }
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      await load(technicianId);
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function toggleNetwork() {
    if (!offline) {
      setOfflineMode(true);
      setOffline(true);
      toast("Modo sin conexión: los cambios se guardan en el teléfono", "warning");
      return;
    }
    setBusy(true);
    setOfflineMode(false);
    const reached = await load(technicianId);
    setBusy(false);
    if (reached) toast("Conexión recuperada", "success", queue.length ? `Pulsa Sincronizar para enviar ${queue.length} cambio(s)` : undefined);
    else toast("Aún no hay conexión con el servidor.", "danger");
  }

  async function sync() {
    setBusy(true);
    try {
      const results = await syncQueue(viewerId);
      const bad = results.filter((entry) => !entry.ok);
      const left = (await queuedOps(viewerId).catch(() => [])).length;
      toast(
        `${results.length - bad.length} cambio(s) sincronizados`,
        bad.length || left ? "warning" : "success",
        bad.length ? `${bad.length} rechazado(s): ${bad[0].message}` : left ? `${left} siguen en el teléfono: se cortó la conexión` : undefined,
      );
      await load(technicianId);
    } finally {
      setBusy(false);
    }
  }

  function openLogging(order: FieldOrder) {
    setLogging(order);
    if (!timeOptions) apiFetch<TimeEntryOptions>("/v1/time-entries/options").then(setTimeOptions).catch(() => setTimeOptions({ projects: [], work_orders: [] }));
  }

  if (error && !route) {
    return (
      <Screen>
        <PageHead title="App del técnico" />
        <Card><LoadError text={error} onRetry={() => load(technicianId)} /></Card>
        {toasts}
      </Screen>
    );
  }

  const pendingFor = (order: FieldOrder) => queue.filter((op) => op.workOrderId === order.id);
  const statusOf = (order: FieldOrder) => effectiveStatus(order.status, pendingFor(order));
  // Lo que el técnico ya cerró sin conexión deja de estar pendiente, aunque aún no se haya enviado.
  const orders = (route?.orders ?? []).filter((order) => statusOf(order) !== "COMPLETED");
  const technicians = route?.technicians ?? [];
  const viewingOther = Boolean(route && route.technician.id !== route.viewer_id);

  return (
    <Screen>
      <PageHead title="App del técnico" />

      {technicians.length > 0 && (
        <Filters>
          <div className="flex-[1_1_200px] sm:w-[280px] sm:flex-none">
            <PickSelect
              className="app-surface app-text flex h-10 w-full cursor-pointer items-center justify-between gap-2 rounded-[9px] border border-[color:var(--app-border)] px-2.5 text-left text-[13px] outline-none focus:border-[#1B8FC9]"
              ariaLabel="Técnico" searchable value={technicianId || route?.technician.id || ""} onChange={setTechnicianId} disabled={offline}
              options={technicians.map((item): [string, string] => [item.id, `Ver como ${item.name}`])}
            />
          </div>
          <span className="app-muted flex-[1_1_240px] text-[12.5px]">
            Ves la app de cualquier técnico a tu alcance. Las acciones que hagas se registran con tu usuario.
          </span>
        </Filters>
      )}

      <div className="grid grid-cols-1 items-start gap-5 min-[900px]:grid-cols-[minmax(300px,400px)_1fr]">
        <div className="-mx-4 overflow-hidden bg-[var(--app-bg,var(--app-surface-muted))] min-[900px]:mx-0 min-[900px]:max-w-[400px] min-[900px]:rounded-[34px] min-[900px]:border-[10px] min-[900px]:border-[color:var(--app-border)] min-[900px]:shadow-[0_18px_40px_rgba(8,25,55,.18)]">
          <div className="app-surface flex items-center justify-between px-[18px] pb-1 pt-2 text-xs font-semibold">
            <span className="tabular-nums">{now}</span>
            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-px text-[11px] ${offline ? "bg-[var(--p-danger-soft)] text-[color:var(--p-danger-text)]" : "bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]"}`}>
              {offline ? <WifiOff size={11} aria-hidden="true" /> : <Wifi size={11} aria-hidden="true" />}
              {offline ? "Sin conexión" : "En línea"}
            </span>
          </div>

          <div className="flex items-center justify-between gap-2.5 bg-[#1B8FC9] px-4 pb-3 pt-2.5 text-[#fff]">
            <div className="min-w-0">
              <strong className="block truncate text-[15px] font-semibold">{route?.technician.name ?? "—"}</strong>
              <small className="text-xs opacity-80">{orders.length} visita(s) pendientes</small>
            </div>
            <button
              type="button" onClick={toggleNetwork} disabled={busy}
              className={`h-9 shrink-0 cursor-pointer whitespace-nowrap rounded-[9px] border px-3 text-[12.5px] font-medium disabled:cursor-wait disabled:opacity-60 ${
                offline ? "border-[#fff] bg-[#fff] text-[#1B8FC9]" : "border-[rgba(255,255,255,.35)] bg-[rgba(255,255,255,.14)] text-[#fff]"
              }`}
            >
              {offline ? "Volver a conectar" : "Trabajar sin conexión"}
            </button>
          </div>

          {queue.length > 0 && (
            <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5 border-b border-[color:var(--p-notice-line)] bg-[var(--p-notice-bg)] px-3.5 py-2.5 text-[13px] text-[color:var(--p-notice-text)]">
              <RefreshCw size={17} aria-hidden="true" />
              <span><strong className="font-semibold">{queue.length}</strong> cambio(s) guardados en el teléfono</span>
              {offline ? (
                <small className="w-full text-xs">Se enviarán al recuperar la señal</small>
              ) : (
                <span className="ml-auto"><Btn size="sm" onClick={sync} disabled={busy}>Sincronizar</Btn></span>
              )}
            </div>
          )}

          <FieldMap
            offline={offline}
            points={orders.flatMap((order, index) => (order.latitude !== null && order.longitude !== null
              ? [{ id: order.id, number: index + 1, latitude: order.latitude, longitude: order.longitude, title: order.title, active: statusOf(order) === "IN_PROGRESS" }]
              : []))}
            fallback={<RouteMap orders={orders} statusOf={statusOf} />}
          />

          <div className="grid gap-2.5 px-3 pb-4 pt-2.5">
            {!route ? (
              <div className="h-28 animate-pulse rounded-[14px] bg-[var(--app-surface)]" aria-busy="true" />
            ) : orders.length ? (
              orders.map((order, index) => (
                <VisitCard
                  key={order.id}
                  order={order}
                  number={index + 1}
                  pending={pendingFor(order)}
                  offline={offline}
                  busy={busy}
                  onAct={(kind, extra) => perform(order, kind, extra)}
                  onBlock={() => setBlocking(order)}
                  onHours={() => openLogging(order)}
                />
              ))
            ) : (
              <p className="app-muted px-4 py-5 text-center">Sin visitas pendientes. ¡Buen trabajo!</p>
            )}
          </div>
        </div>

        <div className="grid min-w-0 gap-0">
          {viewingOther && (
            <p className="app-muted mb-3 text-[12.5px]">Estás viendo la app de {route?.technician.name}.</p>
          )}
          <Card title="Cómo funciona el modo sin conexión">
            <ol className="list-decimal space-y-1 pl-5 text-[12.5px]">
              <li>Si el teléfono pierde la señal, la app pasa sola a «Sin conexión». También puedes activarlo con «Trabajar sin conexión».</li>
              <li>Inicia una visita, marca ítems del checklist o adjunta fotos: se guardan en el teléfono.</li>
              <li>Vuelve a conectar y pulsa Sincronizar: cada cambio se envía en orden.</li>
              <li>Si algo cambió mientras tanto (por ejemplo, la orden fue cancelada), ese cambio se rechaza y se informa, sin perder los demás.</li>
            </ol>
          </Card>
          <Card title="Últimas sincronizaciones">
            {log.length ? (
              <ul className="grid gap-2">
                {log.slice(0, 8).map((entry, index) => (
                  <li key={`${entry.at}-${index}`} className="text-[13px]">
                    <Chip tone={entry.ok ? "success" : "danger"}>{entry.ok ? "OK" : "Rechazado"}</Chip> <span>{entry.label}</span>
                    <small className="app-muted block text-xs">{entry.message} · {dateTime(entry.at)}</small>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="app-muted text-[12.5px]">Aún no hay sincronizaciones.</p>
            )}
          </Card>
          <Card title="Ruta sugerida">
            {orders.length ? (
              <>
                <ol className="list-decimal space-y-1 pl-5 text-[12.5px]">
                  {orders.map((order) => (
                    <li key={order.id}>{order.customer ?? order.title} · {order.scheduled_start ? dateTime(order.scheduled_start) : "sin hora"}</li>
                  ))}
                </ol>
                <p className="app-muted mt-2 text-xs">
                  Desde la primera visita programada, siempre la más cercana. La ruta va en línea recta, sin calles ni tráfico: usa «Cómo llegar» para abrir la navegación del teléfono.
                </p>
              </>
            ) : (
              <p className="app-muted text-[12.5px]">—</p>
            )}
          </Card>
        </div>
      </div>

      {blocking && (
        <ReasonModal
          title="Marcar como bloqueada"
          text="Indica qué impide continuar (por ejemplo, el cliente no habilitó el acceso)."
          label="Motivo del bloqueo"
          confirmLabel="Bloquear"
          busy={busy}
          onClose={() => setBlocking(null)}
          onConfirm={async (reason) => {
            if (await perform(blocking, "block", { reason })) setBlocking(null);
          }}
        />
      )}
      {logging && (
        <TimeEntryFormModal
          options={timeOptions}
          workOrderId={logging.id}
          toast={toast}
          onClose={() => setLogging(null)}
          onSaved={() => setLogging(null)}
        />
      )}
      {toasts}
    </Screen>
  );
}

/** Tarjeta de una visita: sus datos, su checklist mientras está en ejecución y sus acciones. */
function VisitCard({
  order, number, pending, offline, busy, onAct, onBlock, onHours,
}: {
  order: FieldOrder;
  number: number;
  pending: FieldOp[];
  offline: boolean;
  busy: boolean;
  onAct: (kind: FieldOpKind, extra?: Partial<FieldOp>) => void;
  onBlock: () => void;
  onHours: () => void;
}) {
  const status = effectiveStatus(order.status, pending);
  const [label, tone] = stateOf(WORK_ORDER_STATES, status);

  // El checklist tal como quedará al sincronizar: lo marcado y las fotos tomadas sin conexión.
  const items = order.checklist_items.map((item) => {
    const mine = pending.filter((op) => op.itemId === item.id);
    const lastCheck = [...mine].reverse().find((op) => op.kind === "check" || op.kind === "evidence");
    const photo = [...mine].reverse().find((op) => op.kind === "evidence");
    return {
      ...item,
      done: lastCheck ? (lastCheck.kind === "evidence" ? true : Boolean(lastCheck.done)) : item.done,
      photo: photo?.fileName ?? item.evidence?.file_name ?? null,
      waiting: mine.length > 0,
    };
  });
  const requiredLeft = items.filter((item) => item.required && !item.done).length;
  const doneCount = items.filter((item) => item.done).length;
  const approved = order.checklist_status === "APPROVED";
  const way = directions(order);
  // El botón general del prototipo: adjunta al ítem que pide foto obligatoria, luego al que la menciona (o al primero).
  const evidenceItem = items.find((item) => item.evidence_required && !item.photo)
    ?? items.find((item) => /fotogr|foto|evid/i.test(item.label)) ?? items[0];

  // Los campos de archivo van ocultos (sr-only) dentro de su etiqueta, que debe ser `relative`:
  // si no, quedan colocados respecto de la página y le añaden un desplazamiento vacío al final.
  const pickPhoto = (itemId: string) => (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (file) onAct("evidence", { itemId, file, fileName: file.name });
  };

  return (
    <article className={`app-surface rounded-[14px] border-l-4 p-3 shadow-[0_1px_3px_rgba(8,25,55,.08)] ${status === "IN_PROGRESS" ? "border-l-[color:var(--p-warning-text)] outline outline-2 outline-[#1B8FC9]" : "border-l-[#1B8FC9]"}`}>
      <div className="grid grid-cols-[auto_1fr_auto] items-start gap-2.5">
        <span className="grid h-[26px] w-[26px] place-items-center rounded-full bg-[#1B8FC9] text-xs font-bold text-[#fff]">{number}</span>
        <div className="min-w-0">
          <strong className="block text-sm font-semibold leading-snug [overflow-wrap:anywhere]">{order.title}</strong>
          <small className="app-muted block text-xs [overflow-wrap:anywhere]">{[order.customer, order.location].filter(Boolean).join(" · ") || "—"}</small>
          <small className="app-muted block text-xs">{order.scheduled_start ? dateTime(order.scheduled_start) : "Sin hora"} · {order.code}</small>
        </div>
        <Badge tone={tone}>{label}</Badge>
      </div>

      {status === "BLOCKED" && order.blocked_reason && <p className="mt-2 text-xs text-[color:var(--p-warning-text)]">{order.blocked_reason}</p>}
      {pending.length > 0 && (
        <p className="mt-2 flex items-center gap-1.5 text-xs text-[color:var(--p-warning-text)]">
          <Clock size={14} aria-hidden="true" />
          {pending.length} cambio(s) sin sincronizar
        </p>
      )}
      {items.length > 0 && (
        <p className="app-muted mt-2 text-xs">Checklist {doneCount} de {items.length}{approved ? " · aprobado" : ""}</p>
      )}

      {status === "IN_PROGRESS" && items.length > 0 && (
        <>
          <ul className="mt-1.5 grid gap-1">
            {items.map((item) => (
              <li key={item.id} className="flex items-stretch gap-1">
                <label className={`flex min-h-11 flex-1 cursor-pointer items-start gap-2 rounded-lg bg-[var(--app-surface-muted)] px-2 py-2.5 text-[13px] ${item.waiting ? "outline outline-1 outline-[color:var(--p-notice-line)]" : ""}`}>
                  <input
                    type="checkbox" checked={item.done}
                    // Con foto obligatoria, se marca al tomarla (botón de la cámara).
                    disabled={busy || approved || (item.evidence_required && !item.photo && !item.done)}
                    onChange={(event) => onAct("check", { itemId: item.id, done: event.target.checked })}
                    className="mt-0.5 h-[18px] w-[18px] shrink-0 cursor-pointer accent-[#1B8FC9] disabled:cursor-not-allowed"
                  />
                  <span className="min-w-0 [overflow-wrap:anywhere]">
                    {item.label}{item.required && <em className="not-italic text-[color:var(--p-danger-text)]" title="Obligatorio"> *</em>}
                    {item.photo ? <small className="app-muted block text-xs">Foto: {item.photo}</small>
                      : item.evidence_required && <small className="block text-xs text-[color:var(--p-warning-text)]">Foto obligatoria: tómala con la cámara para marcarlo</small>}
                  </span>
                </label>
                <label
                  title="Tomar foto de evidencia"
                  className={`relative grid w-11 shrink-0 cursor-pointer place-items-center rounded-lg border border-[color:var(--app-border)] text-[color:var(--p-brand-text)] hover:bg-[var(--p-brand-soft)] ${busy || approved ? "pointer-events-none opacity-50" : ""}`}
                >
                  <Camera size={18} aria-hidden="true" />
                  <span className="sr-only">Tomar foto de evidencia de {item.label}</span>
                  <input type="file" accept="image/*" capture="environment" className="sr-only" disabled={busy || approved} onChange={pickPhoto(item.id)} />
                </label>
              </li>
            ))}
          </ul>
          {evidenceItem && (
            <label className={`relative mt-2 flex cursor-pointer items-center gap-2 rounded-lg border border-dashed border-[#1B8FC9] p-2 text-[13px] text-[color:var(--p-brand-text)] ${busy || approved ? "pointer-events-none opacity-50" : ""}`}>
              <Upload size={16} aria-hidden="true" />
              <span>Adjuntar foto de evidencia</span>
              <input type="file" accept="image/*" className="sr-only" disabled={busy || approved} onChange={pickPhoto(evidenceItem.id)} />
            </label>
          )}
        </>
      )}

      <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
        {status === "ASSIGNED" && <Btn onClick={() => onAct("start")} permission="work_orders.manage" disabled={busy}>Iniciar visita</Btn>}
        {status === "IN_PROGRESS" && (
          <>
            <Btn
              onClick={() => onAct("complete")} permission="work_orders.manage" disabled={busy || requiredLeft > 0}
              title={requiredLeft ? "Completa los ítems obligatorios" : undefined}
            >
              Completar
            </Btn>
            <Btn variant="ghost" onClick={onBlock} permission="work_orders.manage" disabled={busy}>Bloqueada</Btn>
          </>
        )}
        {status === "BLOCKED" && <Btn variant="secondary" onClick={() => onAct("unblock")} permission="work_orders.manage" disabled={busy}>Reanudar</Btn>}
        <Btn variant="ghost" onClick={onHours} permission="time_entries.create" disabled={busy || offline} title={offline ? "Registrar horas necesita conexión" : undefined}>
          <Clock size={15} />
          <span>Horas</span>
        </Btn>
        {way && (
          <a href={way} target="_blank" rel="noopener noreferrer" className="inline-flex h-10 items-center gap-1.5 rounded-[9px] px-2.5 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:bg-[var(--p-brand-soft)]">
            <Navigation size={15} aria-hidden="true" />
            Cómo llegar
          </a>
        )}
        <Link href={`/work-orders/${order.id}`} className="inline-flex h-10 items-center gap-1.5 rounded-[9px] px-2.5 text-[13px] font-medium text-[color:var(--p-brand-text)] hover:bg-[var(--p-brand-soft)]">
          <MapPin size={15} aria-hidden="true" />
          Detalle
        </Link>
      </div>
      {status === "IN_PROGRESS" && requiredLeft > 0 && (
        <p className="app-muted mt-1.5 text-xs">Faltan {requiredLeft} ítem(s) obligatorio(s) para completar.</p>
      )}
    </article>
  );
}
