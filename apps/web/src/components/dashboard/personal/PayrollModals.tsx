"use client";

import { useEffect, useState } from "react";
import { Download, Send } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { APP_HEADERS } from "@/lib/auth";
import { excelTone, stateOf } from "@/lib/commercial";
import { firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import { OUTBOUND_STATES, PAYROLL_STATES, minutesLabel, periodLabel } from "@/lib/personal";
import { Badge, Btn, DataTable, ExportButtons, Field, INPUT, Kv, Modal, type Column } from "@/components/ui/proto";
import { when, type Toast } from "@/components/dashboard/personal/HrSyncShared";
import type { PayrollExport, PayrollExportDetail } from "@/types/personal";

export type PayrollAction = "download" | "resend" | "delivered";

/** "noviembre 2026" → "Noviembre 2026". */
export const periodTitle = (period: string) => periodLabel(period).replace(/^./, (letter) => letter.toUpperCase());

/** Descarga el Excel del envío (binario, con la cookie de la sesión). */
export async function downloadPayrollFile(item: Pick<PayrollExport, "id" | "period" | "version">) {
  const response = await fetch(`${process.env.NEXT_PUBLIC_API_URL ?? ""}/v1/hr/payroll-exports/${item.id}/file`, { headers: APP_HEADERS, credentials: "include" });
  if (!response.ok) {
    const body = await response.json().catch(() => null) as { message?: string } | null;
    throw new Error(body?.message ?? "No se pudo descargar el archivo.");
  }
  const url = URL.createObjectURL(await response.blob());
  const link = document.createElement("a");
  link.href = url;
  link.download = `envio_planilla_${item.period}_v${item.version}.xlsx`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

/** Botones de un envío: descargar, reenviar a RRHH o marcar el archivo como entregado. */
export function PayrollActions({
  item, busy, onAction, size = "sm",
}: { item: PayrollExport; busy: boolean; onAction: (action: PayrollAction) => void; size?: "sm" | "md" }) {
  return (
    <div className="flex flex-wrap gap-1.5">
      <Btn size={size} variant="secondary" permission="hr_sync.export" disabled={busy} onClick={() => onAction("download")}>
        <Download size={14} />
        <span>Descargar Excel</span>
      </Btn>
      {item.destination === "HR_SAAS" && (
        <Btn size={size} variant="ghost" permission="hr_sync.manage" disabled={busy} onClick={() => onAction("resend")}>
          <Send size={14} />
          <span>Reenviar a RRHH</span>
        </Btn>
      )}
      {item.destination === "FILE" && item.status === "GENERATED" && (
        <Btn size={size} variant="ghost" permission="hr_sync.manage" disabled={busy} onClick={() => onAction("delivered")}>
          Marcar como entregado
        </Btn>
      )}
    </div>
  );
}

/** Ejecuta las acciones de un envío; "marcar como entregado" pide antes una nota opcional. */
export function usePayrollActions(toast: Toast, onDone: (item: PayrollExport) => void) {
  const [busy, setBusy] = useState(false);
  const [delivering, setDelivering] = useState<PayrollExport | null>(null);
  const [note, setNote] = useState("");

  async function send(item: PayrollExport, body?: { note: string }) {
    setBusy(true);
    try {
      const next = await apiFetch<PayrollExport>(`/v1/hr/payroll-exports/${item.id}/send`, { method: "POST", body: body ? JSON.stringify(body) : undefined });
      const [label, tone] = stateOf(PAYROLL_STATES, next.status);
      if (item.destination === "HR_SAAS") toast(`Reenviado a RRHH · ${label}`, tone === "danger" ? "danger" : "success", next.provider_response ?? undefined);
      else toast("Archivo marcado como entregado");
      setDelivering(null);
      onDone(next);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  async function start(item: PayrollExport, action: PayrollAction) {
    if (action === "delivered") {
      setNote("");
      setDelivering(item);
      return;
    }
    if (action === "resend") {
      send(item);
      return;
    }
    setBusy(true);
    try {
      await downloadPayrollFile(item);
    } catch (err) {
      toast(err instanceof Error ? err.message : "No se pudo descargar el archivo.", "danger");
    } finally {
      setBusy(false);
    }
  }

  const deliverModal = delivering && (
    <Modal
      title="Marcar como entregado"
      size="sm"
      onClose={() => setDelivering(null)}
      footer={
        <>
          <Btn variant="ghost" onClick={() => setDelivering(null)} disabled={busy}>Volver</Btn>
          <Btn onClick={() => send(delivering, { note: note.trim() })} disabled={busy} permission="hr_sync.manage">Marcar como entregado</Btn>
        </>
      }
    >
      <p className="mb-3 text-[13.5px]">{periodTitle(delivering.period)} · versión {delivering.version}</p>
      <Field label="Nota" hint="A quién y cómo se entregó (opcional).">
        <textarea className={INPUT} rows={3} maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} autoFocus />
      </Field>
    </Modal>
  );

  return { busy, start, deliverModal };
}

type ByEmployee = PayrollExportDetail["by_employee"][number];
type Delivery = PayrollExportDetail["deliveries"][number];

const EMPLOYEE_COLUMNS: ExportColumn<ByEmployee>[] = [
  { label: "Trabajador", value: (row) => row.name, width: 34 },
  { label: "Código", value: (row) => row.code ?? "", width: 14, kind: "center" },
  { label: "Horas", value: (row) => Math.round((row.minutes / 60) * 100) / 100, width: 12, kind: "number" },
  { label: "Horas facturables", value: (row) => Math.round((row.billable_minutes / 60) * 100) / 100, width: 18, kind: "number" },
];

/** Ficha de un envío: resumen, horas por trabajador, entregas al SaaS de RRHH y huella del contenido. */
export function PayrollDetailModal({
  exportId, onClose, onChanged, toast,
}: { exportId: string; onClose: () => void; onChanged: () => void; toast: Toast }) {
  const [detail, setDetail] = useState<PayrollExportDetail | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [exporting, setExporting] = useState(false);
  const { busy, start, deliverModal } = usePayrollActions(toast, () => {
    setReload((n) => n + 1);
    onChanged();
  });

  useEffect(() => {
    let cancelled = false;
    apiFetch<PayrollExportDetail>(`/v1/hr/payroll-exports/${exportId}`)
      .then((next) => { if (!cancelled) { setDetail(next); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el envío."); });
    return () => { cancelled = true; };
  }, [exportId, reload]);

  async function exportEmployees(format: "xlsx" | "csv") {
    if (!detail) return;
    setExporting(true);
    try {
      await recordExport("hr_sync", format, detail.by_employee.length, false, `payroll_export=${detail.id}`);
      const name = `horas-planilla-${detail.period}-v${detail.version}`;
      if (format === "xlsx") await exportXlsx(name, `Horas por trabajador · ${periodTitle(detail.period)} v${detail.version}`, EMPLOYEE_COLUMNS, detail.by_employee);
      else exportCsv(name, EMPLOYEE_COLUMNS, detail.by_employee);
      toast(`${detail.by_employee.length} ${detail.by_employee.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const employeeCols: Column<ByEmployee>[] = [
    { label: "Trabajador", render: (row) => <span className="font-medium">{row.name}</span> },
    { label: "Código", render: (row) => row.code ?? <span className="app-muted">—</span> },
    { label: "Horas", num: true, render: (row) => minutesLabel(row.minutes) },
    { label: "Facturables", num: true, render: (row) => minutesLabel(row.billable_minutes) },
  ];

  const deliveryCols: Column<Delivery>[] = [
    { label: "Mensaje", render: (row) => <code className="font-mono text-[11.5px] [overflow-wrap:anywhere]">{row.event_id}</code> },
    {
      label: "Estado",
      render: (row) => {
        const [label, tone] = stateOf(OUTBOUND_STATES, row.status);
        return <Badge tone={tone}>{label}</Badge>;
      },
    },
    { label: "Intentos", num: true, render: (row) => row.attempts },
    { label: "Respuesta", render: (row) => row.response_code ?? <span className="app-muted">—</span> },
    { label: "Error", render: (row) => (row.last_error ? <span className="block max-w-[240px] text-xs [overflow-wrap:anywhere]">{row.last_error}</span> : <span className="app-muted">—</span>) },
    { label: "Enviado", render: (row) => <span className="whitespace-nowrap">{when(row.sent_at)}</span> },
    { label: "Próximo intento", render: (row) => <span className="whitespace-nowrap">{row.next_attempt_at && row.status !== "SENT" && row.status !== "DEAD" ? when(row.next_attempt_at) : "—"}</span> },
  ];

  const [label, tone] = detail ? stateOf(PAYROLL_STATES, detail.status) : ["", "neutral" as const];

  return (
    <>
      <Modal
        title={detail ? `Envío ${periodTitle(detail.period)} · v${detail.version}` : "Envío de horas a planilla"}
        size="lg"
        onClose={onClose}
        footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}
      >
        {error ? (
          <p className="text-[color:var(--p-danger-text)]">{error}</p>
        ) : !detail ? (
          <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <div className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Badge tone={tone}>{label}</Badge>
              <PayrollActions item={detail} busy={busy} onAction={(action) => start(detail, action)} size="md" />
            </div>
            <Kv
              rows={[
                ["Destino", detail.destination_label],
                ["Trabajadores", detail.employee_count],
                ["Horas", minutesLabel(detail.minutes_total)],
                ["Registros de horas", detail.summary.rows],
                detail.destination === "FILE" ? ["Altas · ceses", `${detail.summary.hires} · ${detail.summary.terminations}`] : null,
                detail.destination === "FILE" ? ["Ausencias", detail.summary.leaves] : null,
                detail.summary.unlinked.length
                  ? ["Sin código en el SaaS de RRHH", <span key="unlinked" className="text-[color:var(--p-warning-text)]">{detail.summary.unlinked.join(", ")} (no se enviaron)</span>]
                  : null,
                ["Enviado", when(detail.sent_at)],
                detail.acknowledged_at ? ["Recibido por RRHH", when(detail.acknowledged_at)] : null,
                ["Respuesta", detail.provider_response ? <span key="response" className="whitespace-pre-line">{detail.provider_response}</span> : "—"],
                ["Generado", `${detail.generated_by ?? "—"} · ${when(detail.created_at)}`],
              ]}
            />

            <div>
              <h3 className="mb-2 text-[14px] font-semibold">Horas por trabajador</h3>
              <div className="overflow-hidden rounded-xl border border-[color:var(--app-border)]">
                <DataTable cols={employeeCols} rows={detail.by_employee} rowKey={(row, index) => `${row.name}-${index}`} dense empty="Sin horas en este envío." />
                {detail.by_employee.length > 0 && (
                  <div className="flex flex-wrap items-center gap-2 border-t border-[color:var(--app-border)] px-3.5 py-2.5">
                    <ExportButtons permission="hr_sync.export" total={detail.by_employee.length} busy={exporting} onExport={exportEmployees} />
                    <span className="app-muted text-[12.5px]">{detail.by_employee.length} {detail.by_employee.length === 1 ? "trabajador" : "trabajadores"}</span>
                  </div>
                )}
              </div>
            </div>

            {detail.destination === "HR_SAAS" && (
              <div>
                <h3 className="mb-2 text-[14px] font-semibold">Entregas al SaaS de RRHH</h3>
                <div className="overflow-hidden rounded-xl border border-[color:var(--app-border)]">
                  <DataTable cols={deliveryCols} rows={detail.deliveries} rowKey={(row) => row.id} dense empty="Aún no hay entregas." />
                </div>
              </div>
            )}

            <p className="app-muted text-[11px] [overflow-wrap:anywhere]">SHA-256 del contenido: <code className="font-mono">{detail.sha256}</code></p>
          </div>
        )}
      </Modal>
      {deliverModal}
    </>
  );
}

/** Columnas del Excel del listado de envíos. */
export const PAYROLL_EXPORT_COLUMNS: ExportColumn<PayrollExport>[] = [
  { label: "Periodo", value: (row) => periodTitle(row.period), width: 18 },
  { label: "Versión", value: (row) => row.version, width: 10, kind: "center" },
  { label: "Destino", value: (row) => row.destination_label, width: 22 },
  { label: "Trabajadores", value: (row) => row.employee_count, width: 14, kind: "number" },
  { label: "Horas", value: (row) => row.hours_total, width: 12, kind: "number" },
  {
    label: "Estado", value: (row) => stateOf(PAYROLL_STATES, row.status)[0], width: 18, kind: "status",
    tone: (row) => excelTone(stateOf(PAYROLL_STATES, row.status)[1]),
  },
  { label: "Enviado", value: (row) => (row.sent_at ? when(row.sent_at) : ""), width: 22, kind: "center" },
  { label: "Recibido por RRHH", value: (row) => (row.acknowledged_at ? when(row.acknowledged_at) : ""), width: 22, kind: "center" },
  { label: "Respuesta", value: (row) => row.provider_response ?? "", width: 40 },
  { label: "Generado por", value: (row) => row.generated_by ?? "", width: 26 },
  { label: "Generado", value: (row) => when(row.created_at), width: 22, kind: "center" },
  { label: "SHA-256", value: (row) => row.sha256, width: 30 },
];
