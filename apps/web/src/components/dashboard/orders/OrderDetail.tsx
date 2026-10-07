"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, FileText } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { customerName, historyLabel, money, shortDate, stateOf } from "@/lib/commercial";
import { ORDER_STATES, downloadOrderPdf, orderItemUnit } from "@/lib/orders";
import {
  Badge, Btn, Card, Field, INPUT, Kv, LoadError, Modal, PageHead, Screen, StateTrack, TD, TH, Timeline, Who, useToasts,
  type BtnVariant,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import type { Order, OrderStatus } from "@/types/commercial";

// Secuencia lineal: no se completa sin ejecutar ni se cancela una orden en ejecución.
// "reason" indica que la transición pide un motivo antes de ejecutarse.
type Transition = { from: OrderStatus[]; path: string; label: string; variant: BtnVariant; reason?: boolean };

const TRANSITIONS: Transition[] = [
  { from: ["DRAFT"], path: "confirm", label: "Confirmar", variant: "secondary" },
  { from: ["CONFIRMED"], path: "start", label: "Iniciar ejecución", variant: "secondary" },
  { from: ["IN_PROGRESS"], path: "complete", label: "Completar", variant: "secondary" },
  { from: ["DRAFT", "CONFIRMED"], path: "cancel", label: "Cancelar", variant: "danger-ghost", reason: true },
];

// Estado en el que deja la orden su creación, para los movimientos que no lo guardaron.
const ACTION_STATE: Record<string, string> = { "order.generate": "DRAFT" };

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

const LINK = "text-[#1679AB] hover:underline";

export default function OrderDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast, toasts } = useToasts();

  const [order, setOrder] = useState<Order | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [confirming, setConfirming] = useState<Transition | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState("");
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Order>(`/v1/orders/${id}`)
      .then((data) => {
        if (cancelled) return;
        setOrder(data);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Orden no encontrada." : "No se pudo cargar la orden.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Cambia el estado de la orden; la API devuelve la ficha ya actualizada. */
  async function run(transition: Transition, note?: string) {
    setBusy(true);
    try {
      const updated = await apiFetch<Order>(`/v1/orders/${id}/${transition.path}`, {
        method: "POST",
        body: note ? JSON.stringify({ reason: note }) : undefined,
      });
      setOrder(updated);
      toast(`${transition.label}: listo`);
      setConfirming(null);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  function start(transition: Transition) {
    if (!transition.reason) {
      run(transition);
      return;
    }
    setReason("");
    setReasonError("");
    setConfirming(transition);
  }

  function confirmNow() {
    if (!confirming) return;
    const text = reason.trim();
    if (!text) {
      setReasonError("Debes indicar un motivo.");
      return;
    }
    run(confirming, text);
  }

  async function downloadPdf() {
    if (!order) return;
    setPrinting(true);
    toast("Generando PDF…");
    try {
      await downloadOrderPdf(order);
    } catch {
      toast("No se pudo generar el PDF", "danger");
    } finally {
      setPrinting(false);
    }
  }

  const back = (
    <Link href="/orders" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[#1679AB] hover:underline">
      <ArrowLeft size={15} /> Órdenes
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
  const currency = order.currency;
  const items = order.items ?? [];
  const history = order.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  const available = TRANSITIONS.filter((transition) => transition.from.includes(status));

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={`Orden ${order.order_number}`}
        sub={`${customerName(order.customer)} · ${shortDate(order.order_date)}`}
        actions={
          <Btn variant="secondary" onClick={downloadPdf} disabled={printing} title="Descargar en PDF">
            <FileText size={16} />
            <span>Descargar PDF</span>
          </Btn>
        }
      />

      <SortableBoard
        storageKey="erp.cards.order-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["data", "lines"] },
          { className: "min-w-0 lg:min-h-24", cards: ["states", "related", "history"] },
        ]}
        cards={[
          {
            id: "data",
            node: (
              <Card title="Datos de la orden">
                <Kv
                  rows={[
                    ["Cliente", customerName(order.customer)],
                    ["Emisión", shortDate(order.order_date)],
                    ["Notas", order.notes || "—"],
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "lines",
            node: (
              <Card title="Líneas de la orden">
                {items.length ? (
                  <>
                    <div className="proto-tbl-wrap -mx-3 overflow-x-auto sm:-mx-5">
                      <table className="proto-tbl w-full border-collapse text-[13px]">
                        <thead>
                          <tr>
                            <th className={TH}>Servicio</th>
                            <th className={TH}>Unidad</th>
                            <th className={`${TH} text-right`}>Cant.</th>
                            <th className={`${TH} text-right`}>P. unit.</th>
                            <th className={`${TH} text-right`}>Total</th>
                          </tr>
                        </thead>
                        <tbody>
                          {items.map((item) => (
                            <tr key={item.id}>
                              <td className={TD} data-label="Servicio">
                                <strong className="font-semibold">{item.description}</strong>
                                {item.service_snapshot?.code && <small className="app-muted block text-xs">{item.service_snapshot.code}</small>}
                              </td>
                              <td className={TD} data-label="Unidad">{orderItemUnit(item) ?? "—"}</td>
                              <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Cant.">{Number(item.quantity)}</td>
                              <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="P. unit.">{money(item.unit_price, currency)}</td>
                              <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Total"><strong>{money(item.line_total, currency)}</strong></td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    <p className="mt-3 text-right"><strong>Total: {money(order.total, currency)}</strong></p>
                  </>
                ) : (
                  <p className="app-muted">Sin registros</p>
                )}
              </Card>
            ),
          },
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={ORDER_STATES} current={status} visited={visited} />
                  {available.length ? (
                    <div className="flex flex-wrap gap-1.5">
                      {available.map((transition) => (
                        <Btn
                          key={transition.path}
                          variant={transition.variant}
                          onClick={() => start(transition)}
                          permission="orders.manage"
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
            id: "related",
            node: (
              <Card title="Relacionado">
                <Kv
                  rows={[
                    [
                      "Cotización",
                      order.quotation ? (
                        <Link key="quotation" href={`/quotations/${order.quotation.id}`} className={LINK}>
                          {order.quotation.quotation_number}
                        </Link>
                      ) : "—",
                    ],
                    [
                      "Contrato",
                      order.contract ? (
                        <Link key="contract" href={`/contracts/${order.contract.id}`} className={LINK}>
                          {order.contract.contract_number}
                        </Link>
                      ) : "—",
                    ],
                    ["Responsable", <Who key="owner" name={order.creator?.name} />],
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
                    const to = entry.metadata.to ?? ACTION_STATE[entry.action];
                    return {
                      key: `${entry.at}-${index}`,
                      head: to ? (
                        <>
                          <Badge tone={stateOf(ORDER_STATES, to)[1]}>{stateOf(ORDER_STATES, to)[0]}</Badge>
                          {entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(ORDER_STATES, entry.metadata.from)[0]}</span>
                          )}
                        </>
                      ) : (
                        <span className="font-medium">{historyLabel(entry)}</span>
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

      {confirming && (
        <Modal
          title={confirming.label}
          size="sm"
          onClose={() => setConfirming(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setConfirming(null)}>Volver</Btn>
              <Btn variant="danger" disabled={busy} onClick={confirmNow}>{confirming.label}</Btn>
            </>
          }
        >
          <p className="text-[15px]">Esta transición requiere una justificación.</p>
          <div className="mt-3">
            <Field label="Motivo" required error={reasonError}>
              <textarea
                className={INPUT}
                rows={3}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                placeholder="Queda registrado en auditoría"
                autoFocus
              />
            </Field>
          </div>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
