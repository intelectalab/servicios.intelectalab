"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Check, FileSignature, FileText, Settings } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { downloadQuotationPdf } from "@/lib/quotation-pdf";
import { ORDER_STATES } from "@/lib/orders";
import {
  FREQUENCIES, QUOTATION_STATES, customerName, historyLabel, money, setFlash, shortDate, stateOf, takeFlash,
} from "@/lib/commercial";
import {
  Badge, Btn, Card, Field, INPUT, Kv, LinkBtn, LoadError, Modal, Notice, PageHead, Screen, StateTrack, TD, TH, Timeline, Who,
  useToasts, type Tone,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import type { Quotation } from "@/types/commercial";

// Acciones que piden confirmación; "reason" indica si llevan comentario y si es obligatorio.
type Confirm = {
  path: string;
  title: string;
  text: string;
  confirmLabel: string;
  done: string;
  danger?: boolean;
  reason?: { field: "comment" | "reason"; label: string; required: boolean };
};

const CONFIRMS: Record<"approve" | "reject" | "cancel", Confirm> = {
  approve: {
    path: "approve", title: "Aprobar cotización", confirmLabel: "Aprobar", done: "Cotización aprobada",
    text: "La decisión registra usuario, fecha y motivo.",
    reason: { field: "comment", label: "Comentario de aprobación", required: false },
  },
  reject: {
    path: "reject", title: "Rechazar cotización", confirmLabel: "Rechazar", done: "Cotización rechazada", danger: true,
    text: "La decisión registra usuario, fecha y motivo.",
    reason: { field: "reason", label: "Motivo del rechazo", required: true },
  },
  cancel: {
    path: "cancel", title: "Cancelar cotización", confirmLabel: "Cancelar cotización", done: "Cancelar: listo", danger: true,
    text: "La cotización queda cancelada y ya no se podrá enviar ni aprobar.",
  },
};

const CONTRACT_STATUS: Record<string, [string, Tone]> = {
  DRAFT: ["Borrador", "neutral"], ACTIVE: ["Vigente", "success"], SUSPENDED: ["Suspendido", "warning"],
  TERMINATED: ["Terminado", "muted"], EXPIRED: ["Vencido", "warning"],
};

// Origen de la cotización; sin dato es una cotización directa.
const SOURCES: Record<string, string> = { OPPORTUNITY: "Oportunidad CRM", PORTAL: "Solicitud del portal" };

// Estado en el que cada movimiento deja la cotización, para cuando la auditoría no lo guardó.
const ACTION_STATE: Record<string, string> = {
  "quotation.create": "DRAFT", "quotation.version": "DRAFT", "quotation.submit": "SUBMITTED", "quotation.approve": "APPROVED",
  "quotation.reject": "REJECTED", "quotation.expire": "EXPIRED", "quotation.cancel": "CANCELLED",
};

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

export default function QuotationDetail() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAccess();
  const { toast, toasts } = useToasts();

  const [quotation, setQuotation] = useState<Quotation | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [confirming, setConfirming] = useState<Confirm | null>(null);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState("");
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash, flash.includes("no se pudo") ? "warning" : "success");
  }, [toast]);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const data = await apiFetch<Quotation>(`/v1/quotations/${id}`);
        if (!cancelled) {
          setQuotation(data);
          setError("");
        }
      } catch (err) {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "Cotización no encontrada." : "No se pudo cargar la cotización.");
      }
    }
    load();
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Ejecuta una transición de la cotización y recarga su ficha. */
  async function run(path: string, done: string, failed: string, body?: Record<string, string>) {
    setBusy(true);
    try {
      await apiFetch(`/v1/quotations/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      toast(done);
      setConfirming(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, failed), "danger");
    } finally {
      setBusy(false);
    }
  }

  function ask(confirm: Confirm) {
    setReason("");
    setReasonError("");
    setConfirming(confirm);
  }

  function confirmNow() {
    if (!confirming) return;
    const text = reason.trim();
    if (confirming.reason?.required && !text) {
      setReasonError("Este dato es obligatorio.");
      return;
    }
    run(confirming.path, confirming.done, "No se pudo completar la acción.", confirming.reason && text ? { [confirming.reason.field]: text } : undefined);
  }

  /** Orden directa desde la cotización aprobada; como el prototipo, lleva al listado de órdenes. */
  async function createOrder() {
    setBusy(true);
    try {
      await apiFetch(`/v1/quotations/${id}/order`, { method: "POST" });
      setFlash("Orden creada");
      router.push("/orders");
    } catch (err) {
      toast(firstError(err, "No se pudo crear la orden."), "danger");
      setBusy(false);
    }
  }

  /** Convierte la cotización aprobada en contrato y abre su ficha. */
  async function convertToContract() {
    setBusy(true);
    try {
      const contract = await apiFetch<{ id: string }>(`/v1/quotations/${id}/contract`, { method: "POST" });
      setFlash("Contrato creado en borrador");
      router.push(`/contracts/${contract.id}`);
    } catch (err) {
      toast(firstError(err, "No se pudo crear el contrato."), "danger");
      setBusy(false);
    }
  }

  async function newVersion() {
    setBusy(true);
    try {
      const version = await apiFetch<Quotation>(`/v1/quotations/${id}/versions`, { method: "POST" });
      setFlash("Nueva versión creada en borrador");
      router.push(`/quotations/${version.id}`);
    } catch (err) {
      toast(firstError(err, "No se pudo crear la nueva versión."), "danger");
      setBusy(false);
    }
  }

  async function downloadPdf() {
    if (!quotation) return;
    setPrinting(true);
    toast("Generando PDF…");
    try {
      await downloadQuotationPdf(quotation);
    } catch {
      toast("No se pudo generar el PDF", "danger");
    } finally {
      setPrinting(false);
    }
  }

  const back = (
    <Link href="/quotations" className="mb-3 inline-flex items-center gap-1.5 text-[13px] font-medium text-[#1679AB] hover:underline">
      <ArrowLeft size={15} /> Cotizaciones
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

  if (!quotation) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const canApprove = can("quotations.approve");
  // Segregación de funciones: quien creó la cotización no la aprueba.
  const ownBlocked = Boolean(quotation.own_approval_blocked);
  const status = quotation.status;
  const expired = quotation.valid_until !== null && new Date(`${quotation.valid_until.slice(0, 10)}T23:59:59`) < new Date();
  // Motivo por el que no se puede aprobar aunque el rol tenga el permiso.
  const approveBlocked = ownBlocked
    ? "Segregación de funciones: no puedes aprobar tu propia cotización"
    : expired ? "Vencida: no se puede aprobar" : undefined;
  const currency = quotation.currency;
  const gross = Number(quotation.subtotal) + Number(quotation.discount_total);
  const history = quotation.history ?? [];
  const approvals = quotation.approvals ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  const canVersion =
    ["SUBMITTED", "REJECTED", "EXPIRED", "APPROVED"].includes(status) && !quotation.superseded_by && !quotation.contract;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={`Cotización ${quotation.quotation_number}`}
        sub={
          <>
            {customerName(quotation.customer)} ·{" "}
            {currency === "USD" && `en dólares${quotation.exchange_rate ? ` (T.C. ${Number(quotation.exchange_rate).toFixed(3)})` : ""} · `}
            emitida {shortDate(quotation.created_at)} · válida hasta {shortDate(quotation.valid_until)}
            {expired && status === "SUBMITTED" && <span className="text-[#D93A40]"> · vencida</span>}
          </>
        }
        actions={
          <>
            {status === "DRAFT" && (
              <LinkBtn href={`/quotations/${quotation.id}/edit`} permission="quotations.manage" variant="secondary">
                <Settings size={16} />
                <span>Editar</span>
              </LinkBtn>
            )}
            {status === "SUBMITTED" && (
              <>
                <Btn
                  onClick={() => ask(CONFIRMS.approve)}
                  permission="quotations.approve"
                  disabled={busy || expired || ownBlocked}
                  title={approveBlocked}
                >
                  <Check size={16} />
                  <span>Aprobar</span>
                </Btn>
                <Btn variant="danger-ghost" onClick={() => ask(CONFIRMS.reject)} permission="quotations.approve" disabled={busy}>
                  Rechazar
                </Btn>
              </>
            )}
            {status === "APPROVED" && (
              <Btn
                onClick={convertToContract}
                permission="contracts.create"
                disabled={busy || Boolean(quotation.contract)}
                title={quotation.contract ? "Ya se convirtió en contrato" : undefined}
              >
                <FileSignature size={16} />
                <span>Convertir en contrato</span>
              </Btn>
            )}
            {status === "APPROVED" && quotation.direct_order_enabled && (
              <Btn
                variant="secondary"
                onClick={createOrder}
                permission="orders.create"
                disabled={busy || Boolean(quotation.order)}
                title={quotation.order ? "Ya tiene orden" : undefined}
              >
                Orden directa
              </Btn>
            )}
            {canVersion && (
              <Btn variant="ghost" onClick={newVersion} permission="quotations.manage" disabled={busy}>
                Nueva versión
              </Btn>
            )}
            <Btn variant="ghost" onClick={downloadPdf} disabled={printing} title="Descargar en PDF">
              <FileText size={16} />
              <span>Descargar PDF</span>
            </Btn>
          </>
        }
      />

      {ownBlocked && status === "SUBMITTED" && canApprove && (
        <Notice>
          Creaste esta cotización: por segregación de funciones debe aprobarla otra persona (por ejemplo, Gerencia).
        </Notice>
      )}
      {quotation.superseded_by && (
        <Notice tone="warn">
          Esta versión fue reemplazada por{" "}
          <Link href={`/quotations/${quotation.superseded_by.id}`} className="font-medium underline">
            {quotation.superseded_by.quotation_number}
          </Link>
          . Se conserva sin cambios.
        </Notice>
      )}
      {expired && status === "SUBMITTED" && (
        <Notice tone="warn">Esta cotización ya pasó su fecha de validez: no se puede aprobar. Puedes marcarla vencida o crear una nueva versión.</Notice>
      )}

      <SortableBoard
        storageKey="erp.cards.quotation-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["lines", "states"] },
          { className: "min-w-0 lg:min-h-24", cards: ["trace", "approvals", "history"] },
        ]}
        cards={[
          {
            id: "lines",
            node: (
          <Card title="Líneas cotizadas (snapshot comercial)">
            <div className="proto-tbl-wrap -mx-3 overflow-x-auto sm:-mx-5">
              <table className="proto-tbl w-full border-collapse text-[13px]">
                <thead>
                  <tr>
                    <th className={TH}>Servicio</th>
                    <th className={`${TH} text-right`}>Cant.</th>
                    <th className={`${TH} text-right`}>P. unit.</th>
                    <th className={`${TH} text-right`}>Desc.</th>
                    <th className={`${TH} text-right`}>Total</th>
                  </tr>
                </thead>
                <tbody>
                  {quotation.items.map((item) => {
                    const snapshot = item.service_snapshot;
                    // El catálogo está en soles: en dólares se compara al tipo de cambio de la cotización.
                    const rate = currency === "USD" ? Number(quotation.exchange_rate ?? 0) : 1;
                    const catalog = rate > 0 ? item.service?.current_price : null;
                    const catalogPrice = catalog ? Math.round((Number(catalog.amount) / rate) * 100) / 100 : 0;
                    const details = [
                      snapshot?.code,
                      snapshot?.unit ?? item.service?.unit,
                      snapshot?.frequency && `recurrente ${FREQUENCIES[snapshot.frequency]}`,
                      item.price_version && `precio v${item.price_version}`,
                      catalog && catalogPrice !== Number(item.unit_price)
                        ? `hoy el catálogo dice ${money(catalogPrice, currency)} (esta cotización no cambia)`
                        : null,
                    ].filter(Boolean);
                    const grossLine = Number(item.quantity) * Number(item.unit_price);
                    const pct = grossLine > 0 ? Math.round((Number(item.discount) / grossLine) * 1000) / 10 : 0;
                    return (
                      <tr key={item.id}>
                        <td className={TD} data-label="Servicio">
                          <strong className="font-semibold">{item.description}</strong>
                          {details.length > 0 && <small className="app-muted block text-xs">{details.join(" · ")}</small>}
                        </td>
                        <td className={`${TD} text-right tabular-nums`} data-label="Cant.">{Number(item.quantity)}</td>
                        <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="P. unit.">{money(item.unit_price, currency)}</td>
                        <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Desc.">{pct}%</td>
                        <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Total"><strong>{money(item.line_total, currency)}</strong></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            <div className="ml-auto mt-2.5 max-w-[360px]">
              <Kv
                rows={[
                  ["Subtotal", money(gross, currency)],
                  ["Descuentos", `− ${money(quotation.discount_total, currency)}`],
                  ["IGV 18%", money(quotation.tax_total, currency)],
                  ["Total", <strong key="total" className="text-xl font-semibold">{money(quotation.total, currency)}</strong>],
                  currency === "USD" && quotation.exchange_rate
                    ? ["Equivalente", money(Number(quotation.total) * Number(quotation.exchange_rate))]
                    : null,
                ]}
              />
            </div>
            {quotation.notes && <p className="app-muted text-[12.5px]">{quotation.notes}</p>}
          </Card>
            ),
          },
          {
            id: "states",
            node: (
          <Card title="Estados">
            <div className="grid gap-2.5">
              <StateTrack states={QUOTATION_STATES} current={status} visited={visited} />
              {status === "DRAFT" || status === "SUBMITTED" ? (
                <div className="flex flex-wrap gap-1.5">
                  {status === "DRAFT" && (
                    <Btn
                      variant="secondary"
                      onClick={() => run("submit", "Enviar a aprobación: listo", "No se pudo enviar a aprobación.")}
                      permission="quotations.manage"
                      disabled={busy}
                    >
                      Enviar a aprobación
                    </Btn>
                  )}
                  {status === "SUBMITTED" && (
                    <>
                      {/* Como el prototipo, las decisiones también están aquí, además de arriba. */}
                      <Btn
                        variant="secondary"
                        onClick={() => ask(CONFIRMS.approve)}
                        permission="quotations.approve"
                        disabled={busy || expired || ownBlocked}
                        title={approveBlocked}
                      >
                        Aprobar
                      </Btn>
                      <Btn variant="danger-ghost" onClick={() => ask(CONFIRMS.reject)} permission="quotations.approve" disabled={busy}>
                        Rechazar
                      </Btn>
                      <Btn
                        variant="secondary"
                        onClick={() => run("expire", "Marcar vencida: listo", "No se pudo marcar vencida.")}
                        permission="quotations.approve"
                        disabled={busy}
                      >
                        Marcar vencida
                      </Btn>
                    </>
                  )}
                  <Btn variant="danger-ghost" onClick={() => ask(CONFIRMS.cancel)} permission="quotations.manage" disabled={busy}>
                    Cancelar
                  </Btn>
                </div>
              ) : (
                <p className="app-muted text-[12.5px]">Sin transiciones disponibles desde este estado.</p>
              )}
            </div>
          </Card>
            ),
          },
          {
            id: "trace",
            node: (
          <Card title="Trazabilidad">
            <Kv
              rows={[
                ["Origen", SOURCES[quotation.source_type ?? ""] ?? "Cotización directa ERP"],
                quotation.previous
                  ? [
                      "Versión anterior",
                      <Link key="previous" href={`/quotations/${quotation.previous.id}`} className="text-[#1679AB] hover:underline">
                        {quotation.previous.quotation_number}
                      </Link>,
                    ]
                  : null,
                quotation.contract
                  ? [
                      "Contrato",
                      <span key="contract" className="inline-flex flex-wrap items-center gap-1.5">
                        <Link href={`/contracts/${quotation.contract.id}`} className="text-[#1679AB] hover:underline">
                          {quotation.contract.contract_number}
                        </Link>
                        <Badge tone={CONTRACT_STATUS[quotation.contract.status]?.[1] ?? "neutral"}>
                          {CONTRACT_STATUS[quotation.contract.status]?.[0] ?? quotation.contract.status}
                        </Badge>
                      </span>,
                    ]
                  : null,
                quotation.order
                  ? [
                      "Orden",
                      <span key="order" className="inline-flex flex-wrap items-center gap-1.5">
                        <Link href={`/orders/${quotation.order.id}`} className="text-[#1679AB] hover:underline">
                          {quotation.order.order_number}
                        </Link>
                        <Badge tone={stateOf(ORDER_STATES, quotation.order.status)[1]}>
                          {stateOf(ORDER_STATES, quotation.order.status)[0]}
                        </Badge>
                      </span>,
                    ]
                  : null,
                ["Responsable", <Who key="owner" name={quotation.creator?.name} />],
                ["Visible en portal", status !== "DRAFT" ? "Sí: el cliente puede verla y aprobarla" : "No (borrador)"],
              ]}
            />
          </Card>
            ),
          },
          {
            id: "approvals",
            node: (
          <Card title="Aprobaciones">
            {approvals.length ? (
              <ul>
                {approvals.map((approval) => (
                  <li key={approval.id} className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-[color:var(--app-border)] py-[7px] last:border-b-0">
                    <Badge tone={approval.decision === "APPROVED" ? "success" : "danger"}>
                      {approval.decision === "APPROVED" ? "Aprobada" : "Rechazada"}
                    </Badge>
                    <strong className="font-semibold">{approval.approver?.name ?? "—"}</strong>
                    <span>· backoffice</span>
                    <small className="app-muted w-full text-xs">
                      {dateTime(approval.decided_at)}{approval.comments ? ` · ${approval.comments}` : ""}
                    </small>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="app-muted">Sin decisiones registradas.</p>
            )}
          </Card>
            ),
          },
          {
            id: "history",
            node: (
          <Card title="Historial">
            <Timeline
              items={history.map((entry, index) => {
                // Como el prototipo: cada movimiento se lee por el estado en el que deja la cotización.
                const to = entry.metadata.to ?? ACTION_STATE[entry.action];
                return {
                  key: `${entry.at}-${index}`,
                  head: to ? (
                    <>
                      <Badge tone={stateOf(QUOTATION_STATES, to)[1]}>{stateOf(QUOTATION_STATES, to)[0]}</Badge>
                      {entry.metadata.from && entry.metadata.from !== to && (
                        <span className="app-muted text-[12.5px]">desde {stateOf(QUOTATION_STATES, entry.metadata.from)[0]}</span>
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
          title={confirming.title}
          size="sm"
          onClose={() => setConfirming(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setConfirming(null)}>Volver</Btn>
              <Btn variant={confirming.danger ? "danger" : "primary"} disabled={busy} onClick={confirmNow}>
                {confirming.confirmLabel}
              </Btn>
            </>
          }
        >
          <p className="text-[15px]">{confirming.text}</p>
          {confirming.reason && (
            <div className="mt-3">
              <Field label={confirming.reason.label} required={confirming.reason.required} error={reasonError}>
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
          )}
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
