"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, Check, FileText, Lock, ReceiptText, Settings } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { excelTone, money, shortDate, stateOf, takeFlash } from "@/lib/commercial";
import { dateTime, firstError } from "@/lib/crm";
import {
  ORDER_COMMITTED, ORDER_RECEIVABLE, PURCHASE_ORDER_STATES, SUPPLIER_INVOICE_STATES, docTypeLabel, downloadPurchaseOrderPdf, pctText, purchasingHistoryLabel,
} from "@/lib/purchasing";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, ExportButtons, Kv, LoadError, Notice, PageHead, Screen, StateTrack, TD, TH, Timeline, Who, deniedText, useToasts,
} from "@/components/ui/proto";
import { SortableBoard } from "@/components/ui/sortable-cards";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { PurchaseOrderFormModal, ReceiptModal, SupplierInvoiceFormModal, SupplierInvoiceModal } from "@/components/dashboard/purchasing/PurchaseModals";
import { ReceptionBar } from "@/components/dashboard/purchasing/PurchaseOrdersList";
import type { PurchaseOrderDetail as Order, PurchaseOrderItem, PurchaseOrderStatus, SupplierInvoice } from "@/types/purchasing";

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

// El recorrido normal; rechazada y anulada solo se muestran si la orden pasó por ahí.
const MAIN_TRACK: PurchaseOrderStatus[] = ["DRAFT", "SUBMITTED", "APPROVED", "PARTIALLY_RECEIVED", "RECEIVED", "CLOSED"];

const ITEM_COLUMNS = (currency: string): ExportColumn<PurchaseOrderItem>[] => [
  { label: "Descripción", value: (item) => item.description, width: 44 },
  { label: "Cuenta", value: (item) => item.account, width: 36 },
  { label: "Cant.", value: (item) => item.quantity, width: 10, kind: "number" },
  { label: "Costo unit.", value: (item) => item.unit_cost, width: 14, kind: "money", currency: () => currency },
  { label: "Subtotal", value: (item) => item.subtotal, width: 14, kind: "money", currency: () => currency },
];

const INVOICE_COLUMNS: ExportColumn<SupplierInvoice>[] = [
  { label: "Comprobante", value: (invoice) => invoice.doc_number, width: 18, kind: "center" },
  { label: "Tipo", value: (invoice) => docTypeLabel(invoice.doc_type), width: 22 },
  { label: "Emisión", value: (invoice) => shortDate(invoice.issue_date), width: 15, kind: "center" },
  { label: "Moneda", value: (invoice) => invoice.currency, width: 10, kind: "center" },
  { label: "Total", value: (invoice) => invoice.total, width: 14, kind: "money", currency: (invoice) => invoice.currency },
  { label: "Cruce con OC", value: (invoice) => invoice.match?.label ?? "", width: 46 },
  {
    label: "Estado", value: (invoice) => stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[0], width: 20, kind: "status",
    tone: (invoice) => excelTone(stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[1]),
  },
];

type Ask = "reject" | "cancel";

export default function PurchaseOrderDetail() {
  const { id } = useParams<{ id: string }>();
  const { toast, toasts } = useToasts();

  const [order, setOrder] = useState<Order | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState(false);
  const [printing, setPrinting] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [editing, setEditing] = useState(false);
  const [receiving, setReceiving] = useState(false);
  const [invoicing, setInvoicing] = useState(false);
  const [asking, setAsking] = useState<Ask | null>(null);
  const [openInvoice, setOpenInvoice] = useState<string | null>(null);

  useEffect(() => {
    const flash = takeFlash();
    if (flash) toast(flash);
  }, [toast]);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Order>(`/v1/purchase-orders/${id}`)
      .then((next) => {
        if (cancelled) return;
        setOrder(next);
        setError("");
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError && err.status === 404 ? "La orden de compra no existe." : "No se pudo cargar la orden de compra.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  /** Ejecuta un cambio de estado; devuelve si salió bien. La ficha se vuelve a pedir. */
  async function act(path: string, body: unknown, done: string): Promise<boolean> {
    setBusy(true);
    try {
      await apiFetch(`/v1/purchase-orders/${id}/${path}`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
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

  async function downloadPdf() {
    if (!order) return;
    setPrinting(true);
    toast("Generando PDF…");
    try {
      await downloadPurchaseOrderPdf(order);
    } catch {
      toast("No se pudo generar el PDF", "danger");
    } finally {
      setPrinting(false);
    }
  }

  /** Exporta una de las tablas de la ficha, ya cargada completa. */
  async function exportTable<T>(resource: "purchases" | "payables", format: "xlsx" | "csv", name: string, title: string, columns: ExportColumn<T>[], rows: T[]) {
    setExporting(true);
    try {
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport(resource, format, rows.length);
      if (format === "xlsx") await exportXlsx(name, title, columns, rows);
      else exportCsv(name, columns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar la tabla.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const back = (
    <Link href="/purchase-orders" className="app-muted mb-3 inline-flex items-center gap-1.5 text-[13px] hover:text-[var(--app-text)]">
      <ArrowLeft size={15} />
      Órdenes de compra
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
  const history = order.history ?? [];
  const visited = history.flatMap((entry) => [entry.metadata.to, entry.metadata.from].filter((state): state is string => Boolean(state))).concat("DRAFT");
  const track = PURCHASE_ORDER_STATES.filter(([key]) => MAIN_TRACK.includes(key) || key === status || visited.includes(key));
  const invoices = order.supplier_invoices;
  const budget = order.budget;

  return (
    <Screen>
      {back}
      <PageHead
        showTitle
        title={`Orden de compra ${order.order_number}`}
        sub={`${order.supplier.name ?? "—"} · ${order.cost_center.name ?? "—"} · requerida para ${order.needed_by ? shortDate(order.needed_by) : "—"}`}
        actions={
          <>
            {status === "DRAFT" && (
              <Btn variant="secondary" onClick={() => setEditing(true)} permission="purchases.manage" disabled={busy}>
                <Settings size={16} />
                <span>Editar</span>
              </Btn>
            )}
            {ORDER_RECEIVABLE.includes(status) && (
              <Btn onClick={() => setReceiving(true)} permission="purchases.manage" disabled={busy}>
                <Check size={16} />
                <span>Registrar recepción</span>
              </Btn>
            )}
            {ORDER_COMMITTED.includes(status) && (
              <Btn variant="secondary" onClick={() => setInvoicing(true)} permission="payables.create" disabled={busy}>
                <ReceiptText size={16} />
                <span>Registrar comprobante del proveedor</span>
              </Btn>
            )}
            <Btn variant="secondary" onClick={downloadPdf} disabled={printing}>
              <FileText size={16} />
              <span>{status === "DRAFT" || status === "SUBMITTED" ? "Vista previa PDF" : "Descargar PDF"}</span>
            </Btn>
          </>
        }
      />

      {order.segregated && <Notice>Creaste esta orden: por segregación de funciones debe aprobarla otra persona.</Notice>}
      {budget?.over && (status === "DRAFT" || status === "SUBMITTED") && (
        <Notice tone="warn">
          <strong className="font-semibold">Supera el presupuesto disponible del centro de costo.</strong> Disponible {money(budget.available)} frente a {money(order.subtotal, currency)}.
        </Notice>
      )}
      {order.reason && (status === "REJECTED" || status === "CANCELLED") && (
        <Notice tone="warn"><strong className="font-semibold">{status === "REJECTED" ? "Motivo del rechazo:" : "Motivo de la anulación:"}</strong> {order.reason}</Notice>
      )}

      <SortableBoard
        storageKey="erp.cards.purchase-order-detail"
        className="grid grid-cols-1 gap-x-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        zones={[
          { className: "min-w-0 lg:min-h-24", cards: ["lines", "states", "invoices"] },
          { className: "min-w-0 lg:min-h-24", cards: ["budget", "reception", "data", "history"] },
        ]}
        cards={[
          {
            id: "lines",
            node: (
              <Card title="Líneas">
                <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                  <table className="proto-tbl w-full border-collapse text-[13px]">
                    <thead>
                      <tr>
                        <th className={TH}>Descripción</th>
                        <th className={TH}>Cuenta</th>
                        <th className={`${TH} text-right`}>Cant.</th>
                        <th className={`${TH} text-right`}>Costo unit.</th>
                        <th className={`${TH} text-right`}>Subtotal</th>
                      </tr>
                    </thead>
                    <tbody>
                      {order.items.map((item) => (
                        <tr key={item.id}>
                          <td className={TD} data-label="Descripción"><strong className="font-semibold">{item.description}</strong></td>
                          <td className={TD} data-label="Cuenta"><small className="text-xs">{item.account}</small></td>
                          <td className={`${TD} text-right tabular-nums`} data-label="Cant.">{item.quantity}</td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Costo unit.">{money(item.unit_cost, currency)}</td>
                          <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Subtotal">{money(item.subtotal, currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="mt-3 sm:ml-auto sm:max-w-[420px]">
                  <Kv
                    rows={[
                      ["Subtotal", money(order.subtotal, currency)],
                      ["IGV 18 %", money(order.tax, currency)],
                      ["Total", <strong key="total" className="text-[17px] font-semibold tabular-nums">{money(order.total, currency)}</strong>],
                    ]}
                  />
                </div>
                <div className="mt-1 flex">
                  <ExportButtons
                    permission="purchases.export" total={order.items.length} busy={exporting}
                    onExport={(format) => exportTable("purchases", format, `lineas-${order.order_number}`, `Líneas de ${order.order_number}`, ITEM_COLUMNS(currency), order.items)}
                  />
                </div>
              </Card>
            ),
          },
          {
            id: "states",
            node: (
              <Card title="Estados">
                <div className="grid gap-3">
                  <StateTrack states={track} current={status} visited={visited} />
                  <div className="flex flex-wrap gap-2">
                    {status === "DRAFT" && <Btn size="sm" disabled={busy} onClick={() => act("submit", null, "Enviada a aprobación")} permission="purchases.manage">Enviar a aprobación</Btn>}
                    {status === "SUBMITTED" && (
                      <>
                        <Btn size="sm" disabled={busy} onClick={() => act("approve", null, "Orden de compra aprobada")} permission="purchases.approve">Aprobar</Btn>
                        <Btn size="sm" variant="danger-ghost" disabled={busy} onClick={() => setAsking("reject")} permission="purchases.approve">Rechazar</Btn>
                      </>
                    )}
                    {status === "REJECTED" && <Btn size="sm" disabled={busy} onClick={() => act("reopen", null, "La orden volvió a borrador")} permission="purchases.manage">Corregir</Btn>}
                    {ORDER_COMMITTED.includes(status) && <Btn size="sm" variant="secondary" disabled={busy} onClick={() => act("close", null, "Orden de compra cerrada")} permission="purchases.manage">Cerrar</Btn>}
                    {(status === "DRAFT" || status === "SUBMITTED" || status === "APPROVED") && (
                      <Btn size="sm" variant="danger-ghost" disabled={busy} onClick={() => setAsking("cancel")} permission="purchases.manage">Anular</Btn>
                    )}
                  </div>
                  {status === "DRAFT" && <p className="app-muted text-[12.5px]">En borrador se edita. Enviada a aprobación, ya no cambian su proveedor, sus líneas ni sus importes.</p>}
                  {status === "SUBMITTED" && <p className="app-muted text-[12.5px]">La aprueba o rechaza otra persona. Rechazada, vuelve a borrador con «Corregir».</p>}
                  {ORDER_RECEIVABLE.includes(status) && <p className="app-muted text-[12.5px]">Registra la recepción conforme: habilita aprobar el comprobante del proveedor. Para cerrarla hace falta al menos una recepción.</p>}
                  {status === "CLOSED" && <p className="app-muted text-[12.5px]">Cerrada: ya no se recibe ni se factura contra ella.</p>}
                </div>
              </Card>
            ),
          },
          {
            id: "invoices",
            node: (
              <Card title="Comprobantes del proveedor">
                {invoices === null ? (
                  <p className="app-muted flex items-center gap-1.5">
                    <Lock size={14} className="shrink-0" aria-hidden="true" />
                    {deniedText("payables.view")}
                  </p>
                ) : !invoices.length ? (
                  <p className="app-muted">Aún no se registra el comprobante del proveedor.</p>
                ) : (
                  <>
                    <div className="proto-tbl-wrap -mx-1 overflow-x-auto">
                      <table className="proto-tbl w-full border-collapse text-[13px]">
                        <thead>
                          <tr>
                            <th className={TH}>Comprobante</th>
                            <th className={TH}>Emisión</th>
                            <th className={`${TH} text-right`}>Total</th>
                            <th className={TH}>Match</th>
                            <th className={TH}>Estado</th>
                          </tr>
                        </thead>
                        <tbody>
                          {invoices.map((invoice) => (
                            <tr key={invoice.id} className="cursor-pointer hover:bg-[var(--app-surface-muted)]" onClick={() => setOpenInvoice(invoice.id)}>
                              <td className={TD} data-label="Comprobante"><strong className="whitespace-nowrap font-semibold tabular-nums">{invoice.doc_number}</strong></td>
                              <td className={`${TD} whitespace-nowrap`} data-label="Emisión">{shortDate(invoice.issue_date)}</td>
                              <td className={`${TD} whitespace-nowrap text-right tabular-nums`} data-label="Total">{money(invoice.total, invoice.currency)}</td>
                              <td className={TD} data-label="Match">
                                <span title={invoice.match?.label}><Chip tone={invoice.match?.ok ? "success" : "danger"}>{invoice.match?.ok ? "Cuadra" : "Revisar"}</Chip></span>
                              </td>
                              <td className={TD} data-label="Estado"><Badge tone={stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[1]}>{stateOf(SUPPLIER_INVOICE_STATES, invoice.status)[0]}</Badge></td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    <div className="mt-3 flex">
                      <ExportButtons
                        permission="payables.export" total={invoices.length} busy={exporting}
                        onExport={(format) => exportTable("payables", format, `comprobantes-${order.order_number}`, `Comprobantes del proveedor de ${order.order_number}`, INVOICE_COLUMNS, invoices)}
                      />
                    </div>
                  </>
                )}
              </Card>
            ),
          },
          {
            id: "budget",
            node: (
              <Card title="Control de presupuesto">
                {budget ? (
                  <>
                    <Kv
                      rows={[
                        ["Presupuesto anual del centro", money(budget.annual)],
                        ["Ejecutado", money(budget.actual)],
                        ["Comprometido en otras OC", money(budget.committed)],
                        ["Disponible", <strong key="available" className={`font-semibold tabular-nums ${budget.available < 0 ? "text-[color:var(--p-danger-text)]" : ""}`}>{money(budget.available)}</strong>],
                        ["Esta orden (sin IGV)", money(order.subtotal, currency)],
                      ]}
                    />
                    <ReceptionBar percent={Math.round(((budget.actual + budget.committed) / Math.max(1, budget.annual)) * 100)} />
                  </>
                ) : (
                  <p className="app-muted">El centro de costo no tiene presupuesto aprobado.</p>
                )}
              </Card>
            ),
          },
          {
            id: "reception",
            node: (
              <Card title="Recepción">
                <ReceptionBar percent={order.received_pct} />
                <p className="mt-1.5 text-[13px]">{pctText(order.received_pct)} % recibido</p>
                {order.receipts.length > 0 && (
                  <ul className="mt-2 grid">
                    {order.receipts.map((receipt) => (
                      <li key={receipt.id} className="border-b border-[color:var(--app-border)] py-1.5 last:border-b-0">
                        <strong className="font-semibold">{pctText(receipt.pct)} %</strong> · {receipt.note ?? "Conformidad"}
                        <small className="app-muted block text-xs">{receipt.by ?? "—"} · {dateTime(receipt.at)}</small>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            ),
          },
          {
            id: "data",
            node: (
              <Card title="Datos">
                <Kv
                  rows={[
                    [
                      "Proveedor",
                      order.supplier.party_id
                        ? <Link key="supplier" href={`/actors/${order.supplier.party_id}`} className={LINK}>{order.supplier.name ?? "—"}</Link>
                        : order.supplier.name ?? "—",
                    ],
                    ["Solicitante", <Who key="creator" name={order.creator} />],
                    ["Aprobó", order.approver ? <Who key="approver" name={order.approver} /> : "—"],
                    ["Notas", order.notes ?? "—"],
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
                    const moved = to && entry.action !== "purchase_order.create" && entry.action !== "purchase_order.receive";
                    return {
                      key: `${entry.at}-${index}`,
                      head: (
                        <>
                          {to && <Badge tone={stateOf(PURCHASE_ORDER_STATES, to)[1]}>{stateOf(PURCHASE_ORDER_STATES, to)[0]}</Badge>}
                          {moved && entry.metadata.from && entry.metadata.from !== to && (
                            <span className="app-muted text-[12.5px]">desde {stateOf(PURCHASE_ORDER_STATES, entry.metadata.from)[0]}</span>
                          )}
                          {!moved && <span className="font-medium">{purchasingHistoryLabel(entry)}</span>}
                        </>
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
        <PurchaseOrderFormModal
          order={order}
          onClose={() => setEditing(false)}
          onSaved={(_, submitted) => {
            setEditing(false);
            toast(submitted ? "Orden de compra guardada y enviada a aprobación" : "Orden de compra guardada");
            setReload((n) => n + 1);
          }}
        />
      )}
      {receiving && (
        <ReceiptModal
          order={order}
          onClose={() => setReceiving(false)}
          onDone={() => {
            setReceiving(false);
            toast("Recepción registrada");
            setReload((n) => n + 1);
          }}
        />
      )}
      {invoicing && (
        <SupplierInvoiceFormModal
          purchaseOrderId={order.id}
          onClose={() => setInvoicing(false)}
          onSaved={(invoice) => {
            setInvoicing(false);
            toast("Comprobante registrado: pendiente de aprobación");
            setReload((n) => n + 1);
            setOpenInvoice(invoice.id);
          }}
        />
      )}
      {openInvoice && <SupplierInvoiceModal id={openInvoice} toast={toast} onClose={() => setOpenInvoice(null)} onChanged={() => setReload((n) => n + 1)} />}
      {asking && (
        <ReasonModal
          title={asking === "reject" ? "Rechazar orden de compra" : "Anular orden de compra"}
          text={asking === "reject"
            ? `${order.order_number} vuelve a quien la pidió, que puede corregirla y enviarla de nuevo.`
            : `${order.order_number} queda anulada. No se puede deshacer.`}
          label="Motivo"
          confirmLabel={asking === "reject" ? "Rechazar" : "Anular"}
          busy={busy}
          onClose={() => setAsking(null)}
          onConfirm={async (reason) => {
            if (await act(asking, { reason }, asking === "reject" ? "Orden de compra rechazada" : "Orden de compra anulada")) setAsking(null);
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
