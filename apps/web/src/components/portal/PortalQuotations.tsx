"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Check, Download } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { QUOTATION_STATES, excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { downloadQuotationPdf } from "@/lib/quotation-pdf";
import { dateTimeText, type PortalQuotation, type PortalQuotationRow } from "@/lib/portal";
import type { ExportColumn } from "@/lib/export-table";
import type { Quotation } from "@/types/commercial";
import { Badge, Btn, Card, DataTable, Field, INPUT, Kv, LoadError, Modal, Notice, type Column } from "@/components/ui/proto";
import { PortalBack, PortalTitle, usePortal } from "./PortalShell";
import { PortalExport } from "./PortalExport";

const EXPORT: ExportColumn<PortalQuotationRow>[] = [
  { label: "Número", value: (row) => row.quotation_number, width: 18, kind: "center" },
  { label: "Emitida", value: (row) => shortDate(row.created_at), width: 15, kind: "center" },
  { label: "Válida hasta", value: (row) => shortDate(row.valid_until), width: 15, kind: "center" },
  { label: "Total", value: (row) => row.total, width: 16, kind: "money", currency: (row) => row.currency },
  { label: "Estado", value: (row) => stateOf(QUOTATION_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(QUOTATION_STATES, row.status)[1]) },
];

export function PortalQuotationsList() {
  const router = useRouter();
  const [rows, setRows] = useState<PortalQuotationRow[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalQuotationRow[]>("/v1/portal/quotations")
      .then((data) => { if (!cancelled) { setRows(data); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus cotizaciones."); });
    return () => { cancelled = true; };
  }, [reload]);

  const cols: Column<PortalQuotationRow>[] = [
    { label: "Número", render: (row) => <strong className="font-semibold">{row.quotation_number}</strong> },
    { label: "Emitida", render: (row) => shortDate(row.created_at) },
    { label: "Válida hasta", render: (row) => shortDate(row.valid_until) },
    { label: "Total", num: true, render: (row) => money(row.total, row.currency) },
    { label: "Estado", render: (row) => { const [label, tone] = stateOf(QUOTATION_STATES, row.status); return <Badge tone={tone}>{label}</Badge>; } },
  ];

  return (
    <>
      <PortalTitle>Cotizaciones</PortalTitle>
      <Card flush>
        {error ? <LoadError text={error} onRetry={() => setReload((n) => n + 1)} /> : (
          <>
            <DataTable cols={cols} rows={rows ?? []} rowKey={(row) => row.id} loading={!rows} empty="No tienes cotizaciones." onRowClick={(row) => router.push(`/portal/quotations/${row.id}`)} />
            <PortalExport file="cotizaciones" title="Cotizaciones" columns={EXPORT} rows={rows ?? []} />
          </>
        )}
      </Card>
    </>
  );
}

export function PortalQuotationDetail({ id }: { id: string }) {
  const { toast } = usePortal();
  const [quotation, setQuotation] = useState<PortalQuotation | null>(null);
  const [error, setError] = useState("");
  const [missing, setMissing] = useState(false);
  const [reload, setReload] = useState(0);
  const [deciding, setDeciding] = useState<"APPROVED" | "REJECTED" | null>(null);
  const [comment, setComment] = useState("");
  const [commentError, setCommentError] = useState("");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<PortalQuotation>(`/v1/portal/quotations/${id}`)
      .then((data) => { if (!cancelled) { setQuotation(data); setError(""); } })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) setMissing(true);
        else setError("No se pudo cargar la cotización.");
      });
    return () => { cancelled = true; };
  }, [id, reload]);

  async function decide() {
    if (!deciding) return;
    if (deciding === "REJECTED" && !comment.trim()) {
      setCommentError("Cuéntanos el motivo para ajustar la propuesta.");
      return;
    }
    setBusy(true);
    try {
      const data = await apiFetch<PortalQuotation>(`/v1/portal/quotations/${id}/decision`, {
        method: "POST", body: JSON.stringify({ decision: deciding, comment: comment.trim() || null }),
      });
      setQuotation(data);
      toast(deciding === "APPROVED" ? "Cotización aprobada" : "Cotización rechazada");
      setDeciding(null);
    } catch (err) {
      toast(err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : "No se pudo registrar tu decisión.", "danger");
    } finally {
      setBusy(false);
    }
  }

  async function pdf() {
    if (!quotation) return;
    try {
      await downloadQuotationPdf(quotation as unknown as Quotation);
    } catch {
      toast("No se pudo generar el PDF.", "danger");
    }
  }

  if (missing) {
    return (
      <>
        <PortalBack href="/portal/quotations">Cotizaciones</PortalBack>
        <Card>
          <h2 className="text-lg font-semibold">No encontrada</h2>
          <p className="app-muted mt-1">El recurso no existe o no pertenece a tu empresa.</p>
        </Card>
      </>
    );
  }

  if (error) return <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>;
  if (!quotation) return <div className="app-surface h-64 animate-pulse rounded-xl" aria-busy="true" />;

  const [label, tone] = stateOf(QUOTATION_STATES, quotation.status);
  const currency = quotation.currency;
  const itemCols: Column<PortalQuotation["items"][number]>[] = [
    { label: "Servicio", render: (item) => item.description },
    { label: "Cant.", num: true, render: (item) => item.quantity },
    { label: "Precio", num: true, render: (item) => money(item.unit_price, currency) },
    {
      label: "Desc.", num: true,
      render: (item) => {
        const gross = item.quantity * item.unit_price;
        return `${gross > 0 ? Math.round((item.discount / gross) * 1000) / 10 : 0}%`;
      },
    },
    { label: "Total", num: true, render: (item) => money(item.line_total, currency) },
  ];

  return (
    <>
      <PortalBack href="/portal/quotations">Cotizaciones</PortalBack>
      <PortalTitle>
        Cotización {quotation.quotation_number}{quotation.version > 1 ? ` (v${quotation.version})` : ""} <Badge tone={tone}>{label}</Badge>
      </PortalTitle>

      <Card flush>
        <DataTable cols={itemCols} rows={quotation.items} rowKey={(item) => item.id} empty="Sin líneas." />
        <div className="flex justify-end px-5 pt-3">
          <div className="w-full max-w-[320px]">
            <Kv rows={[
              ["Subtotal", money(quotation.subtotal + quotation.discount_total, currency)],
              quotation.discount_total ? ["Descuentos", `- ${money(quotation.discount_total, currency)}`] : null,
              ["IGV", money(quotation.tax_total, currency)],
              ["Total", <strong key="total" className="text-lg">{money(quotation.total, currency)}</strong>],
            ]} />
          </div>
        </div>
        <div className="px-5 pb-[18px] pt-2">
          <p className="app-muted text-xs">
            {quotation.notes ? `${quotation.notes} · ` : ""}{quotation.valid_until ? `Válida hasta ${shortDate(quotation.valid_until)}.` : ""}
          </p>

          {quotation.status === "SUBMITTED" && (
            <div className="mt-4">
              {!quotation.can_decide ? (
                <p className="app-muted">Tu rol de portal no puede aprobar cotizaciones.</p>
              ) : quotation.expired ? (
                <Notice tone="warn">La cotización venció y ya no se puede aprobar. Solicita una nueva versión.</Notice>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Btn onClick={() => { setComment(""); setCommentError(""); setDeciding("APPROVED"); }}><Check size={15} /> Aprobar cotización</Btn>
                  <Btn variant="danger-ghost" onClick={() => { setComment(""); setCommentError(""); setDeciding("REJECTED"); }}>Rechazar</Btn>
                </div>
              )}
            </div>
          )}

          <div className="mt-4 flex flex-wrap gap-2">
            <Btn variant="secondary" onClick={pdf}><Download size={15} /> Descargar PDF</Btn>
          </div>

          {quotation.approvals.length > 0 && (
            <>
              <h3 className="mt-5 text-[14px] font-semibold">Decisiones</h3>
              <ul className="mt-2 grid gap-2">
                {quotation.approvals.map((approval, index) => {
                  const [text, color] = stateOf(QUOTATION_STATES, approval.decision);
                  return (
                    <li key={index} className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                      <Badge tone={color}>{text}</Badge>
                      <span>{approval.channel === "PORTAL" ? "desde el portal" : "por la empresa"}</span>
                      {approval.comment && <span className="app-muted">· “{approval.comment}”</span>}
                      <small className="app-muted w-full text-xs">{dateTimeText(approval.decided_at)}</small>
                    </li>
                  );
                })}
              </ul>
            </>
          )}
        </div>
      </Card>

      {deciding && (
        <Modal
          title={deciding === "APPROVED" ? "Aprobar cotización" : "Rechazar cotización"}
          size="sm"
          onClose={() => setDeciding(null)}
          footer={
            <>
              <Btn variant="ghost" onClick={() => setDeciding(null)}>Cancelar</Btn>
              <Btn variant={deciding === "APPROVED" ? "primary" : "danger"} disabled={busy} onClick={decide}>
                {deciding === "APPROVED" ? "Aprobar" : "Rechazar"}
              </Btn>
            </>
          }
        >
          <p className="text-[14px]">
            {deciding === "APPROVED"
              ? "Tu aprobación queda registrada con fecha y usuario. La empresa preparará el contrato."
              : "Cuéntanos el motivo para ajustar la propuesta."}
          </p>
          <div className="mt-3">
            <Field label={deciding === "APPROVED" ? "Comentario para la empresa" : "Motivo"} required={deciding === "REJECTED"} error={commentError}>
              <textarea className={INPUT} rows={3} value={comment} onChange={(e) => setComment(e.target.value)} autoFocus />
            </Field>
          </div>
        </Modal>
      )}
    </>
  );
}
