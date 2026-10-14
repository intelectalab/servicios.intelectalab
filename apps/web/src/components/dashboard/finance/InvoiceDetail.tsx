"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, FileCode2, FileText, Send } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { isoDateFromNow, money, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import {
  FISCAL_STATES, INVOICE_STATES, INVOICE_TRACK, MOVEMENT_LABELS, RECEIVABLE_STATES, VOID_STATES, WITHHOLDING_STATES, downloadInvoicePdf, financeHistoryLabel, invoiceCustomer,
  methodLabel, netToCollect, taxIdText, typeLabel, withholdingLabel,
} from "@/lib/finance";
import {
  Badge, Btn, Card, Chip, Field, INPUT, Kv, LoadError, Modal, Notice, PageHead, Screen, StateTrack, TD, TH, Timeline, useDenied, useToasts,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { InvoiceDetail as Invoice } from "@/types/finance";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

/** Constancia de depósito de la detracción o certificado de retención que entrega el cliente. */
function WithholdingReceiptModal({ invoice, onClose, onDone }: { invoice: Invoice; onClose: () => void; onDone: () => void }) {
  const detraction = invoice.withholding?.type === "DETRACTION";
  const [number, setNumber] = useState("");
  const [date, setDate] = useState(isoDateFromNow(0));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setErrors({});
    try {
      await apiFetch(`/v1/invoices/${invoice.id}/withholding-receipt`, { method: "POST", body: JSON.stringify({ number: number.trim(), date }) });
      onDone();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!fields.number && !fields.date) setError(firstError(err, "No se pudo registrar la constancia."));
      setSaving(false);
    }
  }

  return (
    <Modal
      title={detraction ? "Constancia de depósito de detracción" : "Certificado de retención"}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" form="withholding-form" disabled={saving} permission="receivables.manage">{saving ? "Registrando…" : "Registrar"}</Btn>
        </>
      }
    >
      <form id="withholding-form" onSubmit={submit} className="grid gap-3">
        {error && <Notice tone="warn">{error}</Notice>}
        <p>
          {detraction
            ? "El cliente depositó la detracción en la cuenta del Banco de la Nación. Al registrarla, se descuenta de la cuenta por cobrar y entra a la cuenta de detracciones."
            : "El cliente retuvo el 3 % y entregó el certificado. Se descuenta de la cuenta por cobrar como crédito contra el IGV."}
        </p>
        <Field label={detraction ? "N.º de constancia" : "N.º de certificado"} required error={errors.number}>
          <input className={INPUT} value={number} onChange={(e) => setNumber(e.target.value)} maxLength={100} required autoFocus />
        </Field>
        <Field label="Fecha" error={errors.date}>
          <input className={INPUT} type="date" value={date} max={isoDateFromNow(0)} onChange={(e) => setDate(e.target.value)} required />
        </Field>
        <p><strong className="font-semibold">Importe: {money(invoice.withholding?.amount ?? 0)}</strong></p>
      </form>
    </Modal>
  );
}

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast, toasts } = useToasts();

  const [invoice, setInvoice] = useState<Invoice | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [issuing, setIssuing] = useState(false);
  const [discarding, setDiscarding] = useState(false);
  const [crediting, setCrediting] = useState(false);
  const [receiving, setReceiving] = useState(false);
  const [voiding, setVoiding] = useState(false);
  const modeDenied = useDenied("billing.manage");

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Invoice>(`/v1/invoices/${id}`)
      .then((next) => {
        if (cancelled) return;
        setInvoice(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "El comprobante no existe." : "No se pudo cargar el comprobante.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Ejecuta una acción sobre el comprobante; devuelve si salió bien. La ficha se vuelve a pedir. */
  async function act(path: string, body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      await apiFetch(`/v1/invoices/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      toast(done);
      return true;
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      return false;
    } finally {
      setBusy(false);
      // También tras un error: si otra persona lo cambió, se ve la versión vigente.
      setReload((n) => n + 1);
    }
  }

  /** En un borrador de factura: cómo se calculará su detracción o retención al emitir. */
  async function setWithholdingMode(value: string) {
    const [mode, code] = value.split(":");
    setBusy(true);
    try {
      await apiFetch(`/v1/invoices/${id}/withholding`, { method: "PUT", body: JSON.stringify({ mode, code: code ?? null }) });
      toast("Detracción / retención actualizada");
    } catch (err) {
      toast(firstError(err, "No se pudo actualizar."), "danger");
    } finally {
      setBusy(false);
      setReload((n) => n + 1);
    }
  }

  async function downloadPdf() {
    if (!invoice) return;
    setPrinting(true);
    toast("Generando PDF…");
    try {
      await downloadInvoicePdf(invoice);
    } catch {
      toast("No se pudo generar el PDF", "danger");
    } finally {
      setPrinting(false);
    }
  }

  const back = (
    <Link href="/invoices" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Comprobantes
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

  if (!invoice) {
    return (
      <Screen>
        {back}
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
        {toasts}
      </Screen>
    );
  }

  const status = invoice.status;
  const currency = invoice.currency;
  const receivable = invoice.receivable;
  const history = invoice.history ?? [];
  const visited = history.flatMap((entry) => (entry.metadata.to ? [entry.metadata.to] : [])).concat("DRAFT");
  const label = `${typeLabel(invoice.invoice_type)} ${invoice.invoice_number ?? "(borrador)"}`;
  const provider = invoice.fiscal_provider;
  // Se envía (o reenvía) lo emitido que SUNAT aún no aceptó; sin proveedor solo queda el rechazado, que avisa al pulsar.
  const canSend = (status === "ISSUED" || status === "CORRECTED") && Boolean(invoice.invoice_number)
    && (invoice.fiscal_status === "REJECTED" || (provider.connected && invoice.fiscal_status !== "ACCEPTED"));
  // Serie de contingencia: las que empiezan con cero.
  const contingency = Boolean(invoice.series?.startsWith("0"));
  // Emitido, la detracción o retención fijada en el comprobante; en borrador, la que le correspondería.
  const withheld = invoice.withholding ?? invoice.withholding_preview;
  const draftInvoice = status === "DRAFT" && invoice.invoice_type === "INVOICE";
  const modeValue = invoice.withholding_mode === "DETRACTION" ? `DETRACTION:${invoice.withholding_mode_code ?? ""}` : invoice.withholding_mode;
  const voidable = status === "ISSUED" && invoice.fiscal_status === "ACCEPTED" && invoice.invoice_type !== "CREDIT_NOTE";
  const receivableOpen = receivable ? receivable.status === "OPEN" || receivable.status === "PARTIAL" : false;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={label}
        sub={`${invoiceCustomer(invoice)} · ${taxIdText(invoice.customer_tax_id)}`}
        actions={
          <>
            {status === "DRAFT" && (
              <>
                <Btn onClick={() => setIssuing(true)} permission="billing.approve" disabled={busy}>
                  <Send size={16} />
                  <span>Emitir comprobante</span>
                </Btn>
                <Btn variant="danger-ghost" onClick={() => setDiscarding(true)} permission="billing.manage" disabled={busy}>Descartar borrador</Btn>
              </>
            )}
            {status === "ISSUED" && invoice.invoice_type !== "CREDIT_NOTE" && (
              <Btn variant="danger-ghost" onClick={() => setCrediting(true)} permission="billing.approve" disabled={busy}>Emitir nota de crédito</Btn>
            )}
            {canSend && (
              <Btn variant="secondary" onClick={() => act("fiscal-retry", null, "Envío a SUNAT solicitado")} permission="billing.approve" disabled={busy}>
                {invoice.fiscal_status === "NOT_SENT" ? "Enviar a SUNAT" : "Reintentar envío SUNAT"}
              </Btn>
            )}
            {voidable && (
              <Btn variant="ghost" onClick={() => setVoiding(true)} permission="billing.approve" disabled={busy || Boolean(invoice.void_blocked)} title={invoice.void_blocked ?? undefined}>
                Comunicación de baja
              </Btn>
            )}
            <Btn variant="secondary" onClick={downloadPdf} disabled={printing}>
              <FileText size={16} />
              <span>{status === "DRAFT" ? "Vista previa PDF" : "Descargar PDF"}</span>
            </Btn>
            {invoice.invoice_number && (
              // Aún sin función: depende del proveedor de facturación electrónica.
              <Btn variant="ghost" onClick={() => toast("XML y CDR aún no están disponibles: llegarán con el proveedor de facturación electrónica.", "warning")}>
                <FileCode2 size={16} />
                <span>XML / CDR</span>
              </Btn>
            )}
          </>
        }
      />

      <SortableBoard
        storageKey="erp.cards.invoice-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["detail", "withholding", "fiscal", "states"] },
          { className: "min-w-0 lg:min-h-24", cards: ["receivable", "payments", "links", "history"] },
        ]}
        cards={[
          {
            id: "detail",
            node: (
              <Card title="Detalle">
                <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                  <table className="proto-tbl w-full border-collapse text-[13px]">
                    <thead>
                      <tr>
                        <th className={TH}>Descripción</th>
                        <th className={`${TH} text-right`}>Cant.</th>
                        <th className={`${TH} text-right`}>Valor unit.</th>
                        <th className={`${TH} text-right`}>Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      {invoice.items.map((item) => (
                        <tr key={item.id}>
                          <td className={TD} data-label="Descripción">
                            {item.description}
                            {item.billable_event_id && <small className="app-muted block text-xs">evento {item.billable_event_id.slice(0, 8)}</small>}
                          </td>
                          <td className={`${TD} text-right tabular-nums`} data-label="Cant.">{item.quantity}</td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Valor unit.">{money(item.unit_price, currency)}</td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Total">{money(item.line_total, currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="mt-3 sm:ml-auto sm:max-w-[420px]">
                  <Kv
                    rows={[
                      ["Op. gravada", money(invoice.subtotal, currency)],
                      ["IGV 18%", money(invoice.tax_total, currency)],
                      ["Importe total", <strong key="total" className="text-[17px] font-semibold tabular-nums">{money(invoice.total, currency)}</strong>],
                      currency === "USD" ? [
                        "Tipo de cambio",
                        invoice.exchange_rate ? `${invoice.exchange_rate.toFixed(3)} · equivale a ${money(invoice.total * invoice.exchange_rate)}` : "Se fija al emitir",
                      ] : null,
                      withheld ? [withholdingLabel(withheld), `− ${money(withheld.amount)}`] : null,
                      withheld ? [
                        "Neto a cobrar al cliente",
                        <strong key="net" className="font-semibold tabular-nums">
                          {currency === "USD" && !invoice.exchange_rate ? "Se fija al emitir" : money(netToCollect(invoice, withheld.amount), currency)}
                        </strong>,
                      ] : null,
                    ]}
                  />
                  {draftInvoice && (
                    <div className="mt-2">
                      <Field
                        label="Detracción / retención"
                        hint={modeDenied ?? "Se calcula en soles y queda fijada al emitir. Aplica a facturas por más de S/ 700."}
                      >
                        <PickSelect
                          className={`${INPUT} disabled:opacity-60`} ariaLabel="Detracción / retención" searchable disabled={busy || Boolean(modeDenied)} value={modeValue}
                          onChange={(value) => { if (value !== modeValue) setWithholdingMode(value); }}
                          options={[
                            ["AUTO", "Automática (según la configuración fiscal)"],
                            ...invoice.detraction_codes.map((code): [string, string] => [`DETRACTION:${code.code}`, `Detracción ${code.rate} % · ${code.code} ${code.name}`]),
                            ["RETENTION", "Retención 3 % (cliente agente de retención)"],
                            ["NONE", "No aplica"],
                          ]}
                        />
                      </Field>
                    </div>
                  )}
                </div>
                {invoice.reason && <Notice tone="warn"><strong className="font-semibold">Motivo:</strong> {invoice.reason}</Notice>}
              </Card>
            ),
          },
          ...(invoice.withholding && status !== "DRAFT" ? [{
            id: "withholding",
            node: (
              <Card title={invoice.withholding.type === "DETRACTION" ? "Detracción (SPOT)" : "Retención del IGV"}>
                <Kv
                  rows={[
                    ["Importe", `${money(invoice.withholding.amount)} (en soles)`],
                    ["Estado", <Badge key="status" tone={stateOf(WITHHOLDING_STATES, invoice.withholding.status)[1]}>{stateOf(WITHHOLDING_STATES, invoice.withholding.status)[0]}</Badge>],
                    invoice.withholding.type === "DETRACTION"
                      ? ["Depósito en", `Cuenta de detracciones del Banco de la Nación ${invoice.detraction_account ?? "(sin registrar)"}`]
                      : ["Agente de retención", invoiceCustomer(invoice)],
                    invoice.withholding.number
                      ? [invoice.withholding.type === "DETRACTION" ? "Constancia" : "Certificado", `${invoice.withholding.number}${invoice.withholding.date ? ` · ${shortDate(invoice.withholding.date)}` : ""}`]
                      : null,
                  ]}
                />
                {invoice.withholding.status === "PENDING" && receivableOpen && status === "ISSUED" && (
                  <div className="mt-1">
                    <Btn variant="secondary" onClick={() => setReceiving(true)} permission="receivables.manage" disabled={busy}>
                      {invoice.withholding.type === "DETRACTION" ? "Registrar constancia de depósito" : "Registrar certificado de retención"}
                    </Btn>
                  </div>
                )}
              </Card>
            ),
          }] : []),
          {
            id: "fiscal",
            node: (
              <Card title="Emisión electrónica">
                <Kv
                  rows={[
                    ["Estado SUNAT", <Badge key="fiscal" tone={stateOf(FISCAL_STATES, invoice.fiscal_status)[1]}>{stateOf(FISCAL_STATES, invoice.fiscal_status)[0]}</Badge>],
                    ["Respuesta", invoice.fiscal_response ?? (invoice.fiscal_status === "PENDING" ? "Esperando la respuesta del proveedor…" : "—")],
                    ["Proveedor", provider.connected ? provider.name : <span key="provider" className="app-muted">Sin proveedor conectado</span>],
                    ["Serie", <span key="series" className="inline-flex items-center gap-1.5">{invoice.series ?? "—"}{contingency && <Chip tone="warning">Contingencia</Chip>}</span>],
                    invoice.void_request ? [
                      "Comunicación de baja",
                      <span key="void" className="grid gap-0.5">
                        <span className="inline-flex flex-wrap items-center gap-1.5">
                          <Badge tone={stateOf(VOID_STATES, invoice.void_request.status)[1]}>{stateOf(VOID_STATES, invoice.void_request.status)[0]}</Badge>
                          {invoice.void_request.ticket && <span className="app-muted text-xs tabular-nums">ticket {invoice.void_request.ticket}</span>}
                        </span>
                        {invoice.void_request.response && <small className="app-muted text-xs">{invoice.void_request.response}</small>}
                      </span>,
                    ] : null,
                  ]}
                />
              </Card>
            ),
          },
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={status === "CANCELLED" || status === "VOID" ? INVOICE_STATES.filter(([key]) => ["DRAFT", status].includes(key)) : INVOICE_TRACK} current={status} visited={visited} />
                  {status === "DRAFT" && <p className="app-muted text-[12.5px]">Al emitirlo recibe serie y correlativo y nace su cuenta por cobrar. Después ya no se modifica.</p>}
                  {status === "ISSUED" && <p className="app-muted text-[12.5px]">Un comprobante emitido no se edita: se corrige con una nota de crédito.</p>}
                  {status === "CANCELLED" && <p className="app-muted text-[12.5px]">Borrador descartado: sus eventos volvieron a quedar por facturar.</p>}
                  {status === "VOID" && <p className="app-muted text-[12.5px]">Anulado ante SUNAT con una comunicación de baja. Su cuenta por cobrar quedó sin saldo.</p>}
                </div>
              </Card>
            ),
          },
          {
            id: "receivable",
            node: (
              <Card title="Cuenta por cobrar">
                {receivable ? (
                  <>
                    <Kv
                      rows={[
                        ["Vence", <span key="due" className="inline-flex flex-wrap items-center gap-1.5">{shortDate(receivable.due_date)}{receivable.overdue && <Chip tone="danger">Vencida</Chip>}</span>],
                        ["Original", money(receivable.original_amount, receivable.currency)],
                        ["Saldo", <strong key="balance" className="font-semibold tabular-nums">{money(receivable.balance_amount, receivable.currency)}</strong>],
                        ["Estado", <Badge key="status" tone={stateOf(RECEIVABLE_STATES, receivable.status)[1]}>{stateOf(RECEIVABLE_STATES, receivable.status)[0]}</Badge>],
                      ]}
                    />
                    <h4 className="mb-1 mt-3 text-[13px] font-semibold">Movimientos</h4>
                    <ul className="grid">
                      {receivable.movements.map((movement) => (
                        <li key={movement.id} className="flex flex-wrap items-baseline gap-x-2 border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                          <span className="min-w-0 flex-1">{MOVEMENT_LABELS[movement.movement_type] ?? movement.movement_type} · {movement.reference ?? "—"}</span>
                          <strong className={`font-semibold tabular-nums ${movement.amount < 0 ? "text-[color:var(--p-success-text)]" : ""}`}>{money(movement.amount, receivable.currency)}</strong>
                          <small className="app-muted w-full text-xs">{dateTime(movement.at)}</small>
                        </li>
                      ))}
                    </ul>
                  </>
                ) : (
                  <p className="app-muted">{invoice.invoice_type === "CREDIT_NOTE" ? "Una nota de crédito no genera cuenta por cobrar." : "Se crea al emitir."}</p>
                )}
              </Card>
            ),
          },
          ...(invoice.payments.length ? [{
            id: "payments",
            node: (
              <Card title="Pagos aplicados">
                <ul className="grid">
                  {invoice.payments.map((payment) => (
                    <li key={payment.id} className="border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                      <strong className="font-semibold">{payment.payment_number}</strong> · {methodLabel(payment.payment_method)}
                      <small className="app-muted block text-xs">{payment.paid_at ? shortDate(payment.paid_at) : "—"} · {money(payment.applied, currency)}</small>
                    </li>
                  ))}
                </ul>
              </Card>
            ),
          }] : []),
          ...(invoice.credit_note || invoice.related ? [{
            id: "links",
            node: (
              <Card title={invoice.credit_note ? "Corregido por" : "Corrige a"}>
                {invoice.credit_note && <Link href={`/invoices/${invoice.credit_note.id}`} className={`${LINK} font-semibold`}>{invoice.credit_note.invoice_number}</Link>}
                {invoice.related && <Link href={`/invoices/${invoice.related.id}`} className={`${LINK} font-semibold`}>{invoice.related.invoice_number}</Link>}
              </Card>
            ),
          }] : []),
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
                          <Badge tone={stateOf(INVOICE_STATES, to)[1]}>{stateOf(INVOICE_STATES, to)[0]}</Badge>
                          {entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(INVOICE_STATES, entry.metadata.from)[0]}</span>
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
              </Card>
            ),
          },
        ]}
      />

      {issuing && (
        <Modal
          title="Emitir comprobante"
          size="sm"
          onClose={() => setIssuing(false)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setIssuing(false)}>Cancelar</Btn>
              <Btn
                disabled={busy}
                permission="billing.approve"
                onClick={async () => {
                  if (await act("issue", null, "Comprobante emitido")) setIssuing(false);
                }}
              >
                Emitir
              </Btn>
            </>
          }
        >
          <p className="text-[15px]">{label} · {money(invoice.total, currency)}</p>
          <p className="app-muted mt-2">Se asigna serie y correlativo y se crea la cuenta por cobrar. Después no se puede editar.</p>
          {withheld && <p className="app-muted mt-2">Queda con {withholdingLabel(withheld).toLowerCase()}: {money(withheld.amount)}.</p>}
          {invoice.contingency_mode ? (
            <Notice tone="warn">La empresa está emitiendo <strong className="font-semibold">en contingencia</strong>: el comprobante saldrá con la serie de contingencia y se informará a SUNAT al restablecer el servicio.</Notice>
          ) : provider.connected ? (
            <p className="app-muted mt-2">Al emitirlo se envía a SUNAT por {provider.name}.</p>
          ) : (
            <Notice tone="warn">No hay un proveedor de facturación electrónica conectado: el comprobante queda emitido en el sistema y <strong className="font-semibold">sin enviar a SUNAT</strong>.</Notice>
          )}
          {currency === "USD" && <p className="app-muted">Se emite con el tipo de cambio de venta vigente.</p>}
        </Modal>
      )}
      {discarding && (
        <Modal
          title="Descartar borrador"
          size="sm"
          onClose={() => setDiscarding(false)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setDiscarding(false)}>Cancelar</Btn>
              <Btn
                variant="danger"
                disabled={busy}
                permission="billing.manage"
                onClick={async () => {
                  if (await act("discard", null, "Borrador descartado")) setDiscarding(false);
                }}
              >
                Descartar
              </Btn>
            </>
          }
        >
          <p className="text-[15px]">{label} · {money(invoice.total, currency)}</p>
          <p className="app-muted mt-2">El borrador no llegó a emitirse. Sus eventos vuelven a quedar por facturar.</p>
        </Modal>
      )}
      {crediting && (
        <ReasonModal
          title="Nota de crédito"
          text="Anula el comprobante emitiendo un documento correctivo. El original no se borra."
          label="Motivo (código SUNAT / descripción)"
          confirmLabel="Emitir nota de crédito"
          busy={busy}
          onClose={() => setCrediting(false)}
          onConfirm={async (reason) => {
            if (await act("credit-note", { reason }, "Nota de crédito emitida")) setCrediting(false);
          }}
        />
      )}
      {receiving && (
        <WithholdingReceiptModal
          invoice={invoice}
          onClose={() => setReceiving(false)}
          onDone={() => {
            setReceiving(false);
            toast("Constancia registrada: la cuenta por cobrar se actualizó");
            setReload((n) => n + 1);
          }}
        />
      )}
      {voiding && (
        <ReasonModal
          title="Comunicación de baja"
          text="Anula el comprobante ante SUNAT. Solo procede dentro de los 7 días de emitido y si el cliente no lo pagó; en otro caso, usa nota de crédito."
          label="Motivo"
          confirmLabel="Enviar baja"
          busy={busy}
          onClose={() => setVoiding(false)}
          onConfirm={async (reason) => {
            if (await act("void", { reason }, "Comunicación de baja enviada")) setVoiding(false);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
