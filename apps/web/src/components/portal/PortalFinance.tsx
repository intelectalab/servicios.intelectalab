"use client";

import { useEffect, useState } from "react";
import { Download, Link2, Wallet } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import { INVOICE_STATES, PAYMENT_STATES, downloadInvoicePdf, downloadPaymentPdf, methodLabel, typeLabel } from "@/lib/finance";
import { fileSize } from "@/lib/operations";
import { downloadContractPdf } from "@/lib/contracts";
import type { Contract } from "@/types/commercial";
import type { ExportColumn } from "@/lib/export-table";
import type { PortalDocument, PortalFinance as FinanceData, PortalInvoiceRow, PortalPaymentRow, PortalReceivable, PortalStatement } from "@/lib/portal";
import type { InvoiceDetail, PaymentDetail } from "@/types/finance";
import { Badge, Btn, Card, Chip, DataTable, LoadError, Modal, Notice, type Column } from "@/components/ui/proto";
import { PortalTitle, usePortal } from "./PortalShell";
import { PortalExport } from "./PortalExport";

function errorText(err: unknown, fallback: string) {
  return err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : fallback;
}

const DOCUMENT_EXPORT: ExportColumn<PortalDocument>[] = [
  { label: "Documento", value: (row) => row.title, width: 40 },
  { label: "Categoría", value: (row) => row.category, width: 18, kind: "center" },
  { label: "Origen", value: (row) => row.source ?? "", width: 18, kind: "center" },
  { label: "Versiones", value: (row) => row.versions ?? "", width: 12, kind: "number" },
  { label: "Fecha", value: (row) => shortDate(row.updated_at), width: 15, kind: "center" },
];

export function PortalDocuments() {
  const { toast } = usePortal();
  const [rows, setRows] = useState<PortalDocument[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [busy, setBusy] = useState<string | null>(null);
  const [link, setLink] = useState<{ url: string; name: string; size: number; expires_at: string } | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalDocument[]>("/v1/portal/documents")
      .then((data) => { if (!cancelled) { setRows(data); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus documentos."); });
    return () => { cancelled = true; };
  }, [reload]);

  /** El contrato en PDF, el mismo que genera el sistema (firmado, con sus sellos). */
  async function contractPdf(document: PortalDocument) {
    setBusy(document.id);
    try {
      await downloadContractPdf(await apiFetch<Contract>(`/v1/portal/contracts/${document.id}`));
    } catch {
      toast("No se pudo generar el PDF del contrato.", "danger");
    } finally {
      setBusy(null);
    }
  }

  async function temporaryUrl(document: PortalDocument) {
    setBusy(document.id);
    try {
      setLink(await apiFetch(`/v1/portal/documents/${document.id}/temporary-url`, { method: "POST" }));
      toast("Enlace temporal generado");
    } catch (err) {
      toast(errorText(err, "No se pudo generar el enlace."), "danger");
    } finally {
      setBusy(null);
    }
  }

  const cols: Column<PortalDocument>[] = [
    {
      label: "Documento",
      render: (row) => (
        <div className="min-w-[180px]">
          <strong className="font-semibold">{row.title}</strong>
          <small className="app-muted block text-xs">{row.category}{row.source ? ` · ${row.source}` : ""}{row.versions ? ` · v${row.versions}` : ""}</small>
        </div>
      ),
    },
    { label: "Fecha", render: (row) => shortDate(row.updated_at) },
    {
      label: "",
      render: (row) => (
        <div className="flex sm:justify-end">
          {row.kind === "contract" ? (
            <Btn variant="secondary" size="sm" disabled={busy === row.id} onClick={() => contractPdf(row)}><Download size={14} /> Descargar PDF</Btn>
          ) : (
            <Btn variant="secondary" size="sm" disabled={busy === row.id} onClick={() => temporaryUrl(row)}><Link2 size={14} /> Obtener enlace de descarga</Btn>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PortalTitle>Documentos</PortalTitle>
      <Card flush>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : (
          <>
            <DataTable cols={cols} rows={rows ?? []} rowKey={(row) => row.id} loading={!rows} empty="No hay documentos compartidos contigo." />
            <PortalExport file="documentos" title="Documentos" columns={DOCUMENT_EXPORT} rows={rows ?? []} />
          </>
        )}
      </Card>

      {link && (
        <Modal
          title="Enlace de descarga"
          size="md"
          onClose={() => setLink(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setLink(null)}>Cerrar</Btn>
              <a href={link.url} target="_blank" rel="noopener noreferrer" className="inline-flex h-[38px] items-center gap-[7px] rounded-[9px] bg-[var(--p-accent,#1B8FC9)] px-4 text-[13px] font-medium text-[color:var(--p-on-accent,#fff)] hover:bg-[var(--p-accent-hover,#1679AB)]">
                <Download size={15} /> Descargar
              </a>
            </>
          }
        >
          <p>Válido por 5 minutos.</p>
          <pre className="mt-2 whitespace-pre-wrap break-all rounded-lg bg-[var(--app-surface-muted)] px-3 py-2 text-xs">{link.url}</pre>
          <p className="app-muted mt-2 text-xs">{link.name} · {fileSize(link.size)}</p>
        </Modal>
      )}
    </>
  );
}

const RECEIVABLE_EXPORT: ExportColumn<PortalReceivable>[] = [
  { label: "Comprobante", value: (row) => row.invoice_number ?? "", width: 18, kind: "center" },
  { label: "Vence", value: (row) => shortDate(row.due_date), width: 15, kind: "center" },
  { label: "Vencido", value: (row) => (row.overdue ? "Sí" : "No"), width: 10, kind: "center" },
  { label: "Saldo", value: (row) => row.balance_amount, width: 16, kind: "money", currency: (row) => row.currency },
  { label: "Detracción (S/)", value: (row) => row.detraction ?? "", width: 16, kind: "number" },
];

const INVOICE_EXPORT: ExportColumn<PortalInvoiceRow>[] = [
  { label: "Comprobante", value: (row) => row.invoice_number ?? "", width: 18, kind: "center" },
  { label: "Tipo", value: (row) => typeLabel(row.invoice_type), width: 16, kind: "center" },
  { label: "Emisión", value: (row) => shortDate(row.issue_date), width: 15, kind: "center" },
  { label: "Total", value: (row) => row.total, width: 16, kind: "money", currency: (row) => row.currency },
  { label: "Estado", value: (row) => stateOf(INVOICE_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(INVOICE_STATES, row.status)[1]) },
];

const PAYMENT_EXPORT: ExportColumn<PortalPaymentRow>[] = [
  { label: "Recibo", value: (row) => row.payment_number, width: 18, kind: "center" },
  { label: "Fecha", value: (row) => shortDate(row.paid_at), width: 15, kind: "center" },
  { label: "Método", value: (row) => methodLabel(row.payment_method), width: 18, kind: "center" },
  { label: "Monto", value: (row) => row.amount, width: 16, kind: "money", currency: (row) => row.currency },
];

export function PortalInvoices() {
  const { me, can, toast } = usePortal();
  const [data, setData] = useState<FinanceData | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [selected, setSelected] = useState<ReadonlySet<string>>(new Set());
  const [paying, setPaying] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<FinanceData>("/v1/portal/invoices")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus facturas y pagos."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function invoicePdf(row: PortalInvoiceRow) {
    try {
      await downloadInvoicePdf(await apiFetch<InvoiceDetail>(`/v1/portal/invoices/${row.id}`));
    } catch {
      toast("No se pudo generar el PDF.", "danger");
    }
  }

  async function receiptPdf(row: PortalPaymentRow) {
    try {
      await downloadPaymentPdf(await apiFetch<PaymentDetail>(`/v1/portal/payments/${row.id}`));
    } catch {
      toast("No se pudo generar el recibo.", "danger");
    }
  }

  /** Estado de cuenta en PDF, con la composición de los demás documentos. */
  async function statementPdf() {
    try {
      const statement = await apiFetch<PortalStatement>("/v1/portal/statement");
      await downloadDocumentPdf({
        file: `Estado_de_cuenta_${statement.date}`,
        title: "ESTADO DE CUENTA",
        number: shortDate(statement.date),
        left: [["Cliente", statement.customer_name]],
        right: [["Fecha", shortDate(statement.date)]],
        head: ["#", "Comprobante", "Emisión", "Vence", "Importe", "Saldo"],
        rows: statement.rows.map((row, index) => [
          String(index + 1), `${typeLabel(row.invoice_type)} ${row.invoice_number ?? ""}`.trim(), shortDate(row.issue_date),
          `${shortDate(row.due_date)}${row.overdue ? " (vencido)" : ""}`, pdfAmount(row.original_amount), pdfAmount(row.balance_amount),
        ]),
        numCols: [4, 5],
        totals: statement.totals.flatMap((total) => [
          [`Facturado (${total.currency})`, pdfMoney(total.original, total.currency)] as [string, string],
          [`Saldo pendiente (${total.currency})`, pdfMoney(total.balance, total.currency), true] as [string, string, boolean],
        ]),
        notes: [["Aviso", "Saldo al día de emisión de este documento. Los pagos en tránsito pueden no figurar todavía."]],
        signatures: () => [],
      });
    } catch {
      toast("No se pudo generar el estado de cuenta.", "danger");
    }
  }

  const open = data?.receivables ?? [];
  const canPay = can("pay");
  const picked = open.filter((row) => selected.has(row.id));
  const pickedTotal = picked.reduce((sum, row) => sum + row.balance_amount, 0);
  const pickedCurrency = picked[0]?.currency ?? "PEN";

  const receivableCols: Column<PortalReceivable>[] = [
    ...(canPay ? [{
      label: "",
      render: (row: PortalReceivable) => (
        <input
          type="checkbox"
          aria-label={`Seleccionar ${row.invoice_number ?? "comprobante"}`}
          className="h-4 w-4 cursor-pointer accent-[color:var(--p-accent,#1B8FC9)]"
          checked={selected.has(row.id)}
          onChange={(e) => setSelected((current) => {
            const next = new Set(current);
            if (e.target.checked) next.add(row.id); else next.delete(row.id);
            return next;
          })}
        />
      ),
    }] : []),
    { label: "Comprobante", render: (row) => <strong className="font-semibold tabular-nums">{row.invoice_number ?? "—"}</strong> },
    {
      label: "Vence",
      render: (row) => <span className="inline-flex flex-wrap items-center gap-1.5">{shortDate(row.due_date)}{row.overdue && <Chip tone="danger">Vencido</Chip>}</span>,
    },
    {
      label: "Saldo", num: true,
      render: (row) => (
        <div>
          {money(row.balance_amount, row.currency)}
          {row.detraction !== null && <small className="app-muted block text-xs font-normal">incluye detracción {money(row.detraction)} a depositar en el BN</small>}
        </div>
      ),
    },
  ];

  const invoiceCols: Column<PortalInvoiceRow>[] = [
    {
      label: "Comprobante",
      render: (row) => (
        <div>
          <strong className="font-semibold tabular-nums">{row.invoice_number ?? "—"}</strong>
          <small className="app-muted block text-xs">{typeLabel(row.invoice_type)}</small>
        </div>
      ),
    },
    { label: "Emisión", render: (row) => shortDate(row.issue_date) },
    { label: "Total", num: true, render: (row) => money(row.total, row.currency) },
    { label: "Estado", render: (row) => { const [label, tone] = stateOf(INVOICE_STATES, row.status); return <Badge tone={tone}>{label}</Badge>; } },
    { label: "", render: (row) => <div className="flex sm:justify-end"><Btn variant="ghost" size="sm" onClick={() => invoicePdf(row)}><Download size={14} /> PDF</Btn></div> },
  ];

  const paymentCols: Column<PortalPaymentRow>[] = [
    { label: "Recibo", render: (row) => <span className="tabular-nums">{row.payment_number}</span> },
    { label: "Fecha", render: (row) => shortDate(row.paid_at) },
    { label: "Método", render: (row) => methodLabel(row.payment_method) },
    {
      label: "Monto", num: true,
      render: (row) => (
        <div>
          {money(row.amount, row.currency)}
          {row.status === "REFUNDED" && <small className="block text-xs font-normal"><Badge tone={stateOf(PAYMENT_STATES, row.status)[1]}>{stateOf(PAYMENT_STATES, row.status)[0]}</Badge></small>}
        </div>
      ),
    },
    { label: "", render: (row) => <div className="flex sm:justify-end"><Btn variant="ghost" size="sm" onClick={() => receiptPdf(row)}><Download size={14} /> Recibo</Btn></div> },
  ];

  return (
    <>
      <PortalTitle actions={<Btn variant="secondary" onClick={statementPdf}><Download size={15} /> Estado de cuenta (PDF)</Btn>}>Facturas y pagos</PortalTitle>
      {error ? <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card> : (
        <>
          <Card title="Pendientes de pago" flush>
            <div className="pt-2">
              <DataTable cols={receivableCols} rows={open} rowKey={(row) => row.id} loading={!data} empty="No tienes saldos pendientes. ¡Gracias!" />
            </div>
            {canPay && open.length > 0 && (
              <div className="flex flex-wrap items-center justify-between gap-2 border-t border-[color:var(--app-border)] px-5 py-3">
                <span>Seleccionado: <strong className="tabular-nums">{money(pickedTotal, pickedCurrency)}</strong></span>
                <Btn disabled={!picked.length} title={picked.length ? undefined : "Selecciona comprobantes"} onClick={() => setPaying(true)}>
                  <Wallet size={15} /> Pagar en línea
                </Btn>
              </div>
            )}
            <PortalExport file="pendientes-de-pago" title="Pendientes de pago" columns={RECEIVABLE_EXPORT} rows={open} />
          </Card>

          <Card title="Comprobantes" flush>
            <div className="pt-2">
              <DataTable dense cols={invoiceCols} rows={data?.invoices ?? []} rowKey={(row) => row.id} loading={!data} empty="Sin comprobantes." />
            </div>
            <PortalExport file="comprobantes" title="Comprobantes" columns={INVOICE_EXPORT} rows={data?.invoices ?? []} />
          </Card>

          <Card title="Pagos realizados" flush>
            <div className="pt-2">
              <DataTable dense cols={paymentCols} rows={data?.payments ?? []} rowKey={(row) => row.id} loading={!data} empty="Sin pagos registrados." />
            </div>
            <PortalExport file="pagos" title="Pagos realizados" columns={PAYMENT_EXPORT} rows={data?.payments ?? []} />
          </Card>
        </>
      )}

      {paying && (
        <Modal title="Pagar en línea" size="sm" onClose={() => setPaying(false)} footer={<Btn onClick={() => setPaying(false)}>Entendido</Btn>}>
          <Notice>El pago en línea aún no está disponible: {me.company_name} todavía no conectó su pasarela de pagos.</Notice>
          <p>
            Para pagar <strong className="tabular-nums">{money(pickedTotal, pickedCurrency)}</strong> usa las cuentas que figuran en tus
            comprobantes o coordina con tu ejecutivo. Cuando registremos tu pago, verás el saldo actualizado aquí.
          </p>
        </Modal>
      )}
    </>
  );
}
