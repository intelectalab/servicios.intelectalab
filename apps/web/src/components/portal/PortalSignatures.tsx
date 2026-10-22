"use client";

import { useEffect, useState } from "react";
import { Check } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { excelTone, money, shortDate, stateOf } from "@/lib/commercial";
import { SIGNATURE_STATES, slaLabel } from "@/lib/contracts";
import type { ExportColumn } from "@/lib/export-table";
import type { SignatureRequest } from "@/types/commercial";
import { Badge, Btn, Card, DataTable, Field, INPUT, LoadError, Modal, Notice, TD, TH, type Column } from "@/components/ui/proto";
import { PortalTitle, usePortal } from "./PortalShell";
import { PortalExport } from "./PortalExport";

type Pending = SignatureRequest & {
  mine: boolean;
  contract: {
    contract_number: string; company_name: string; customer_name: string; currency: string; start_date: string; end_date: string;
    sla_level: string; terms: string | null; total_value: number;
    items: { id: string; description: string; quantity: number; unit_price: number; line_total: number }[];
  };
};

type History = { id: string; contract_number: string | null; status: SignatureRequest["status"]; created_at: string; signed_at: string | null; declined_reason: string | null };

type Data = { pending: Pending[]; history: History[] };

function errorsOf(err: unknown): Record<string, string> {
  if (err instanceof ApiError && err.errors) return Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]]));
  return { status: err instanceof Error ? err.message : "No se pudo completar la acción." };
}

const EXPORT: ExportColumn<History>[] = [
  { label: "Contrato", value: (row) => row.contract_number ?? "", width: 18, kind: "center" },
  { label: "Enviado", value: (row) => shortDate(row.created_at), width: 15, kind: "center" },
  { label: "Firmado", value: (row) => (row.signed_at ? shortDate(row.signed_at) : ""), width: 15, kind: "center" },
  { label: "Estado", value: (row) => stateOf(SIGNATURE_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(SIGNATURE_STATES, row.status)[1]) },
  { label: "Motivo de rechazo", value: (row) => row.declined_reason ?? "", width: 40 },
];

/** Portal → Firmas: el contrato por firmar (el documento completo) y el historial, como el prototipo. */
export default function PortalSignatures() {
  const { toast } = usePortal();
  const [data, setData] = useState<Data | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [selectedId, setSelectedId] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<Data>("/v1/portal/signatures")
      .then((response) => { if (!cancelled) { setData(response); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar tus contratos por firmar."); });
    return () => { cancelled = true; };
  }, [reload]);

  const selected = data?.pending.find((item) => item.id === selectedId) ?? data?.pending[0] ?? null;

  const cols: Column<History>[] = [
    { label: "Contrato", render: (row) => <strong className="font-semibold">{row.contract_number ?? "—"}</strong> },
    { label: "Enviado", render: (row) => shortDate(row.created_at) },
    { label: "Firmado", render: (row) => (row.signed_at ? shortDate(row.signed_at) : "—") },
    {
      label: "Estado",
      render: (row) => (
        <div className="grid justify-items-start gap-0.5">
          <Badge tone={stateOf(SIGNATURE_STATES, row.status)[1]}>{stateOf(SIGNATURE_STATES, row.status)[0]}</Badge>
          {row.declined_reason && <small className="app-muted max-w-[260px] text-xs">{row.declined_reason}</small>}
        </div>
      ),
    },
    {
      label: "",
      render: (row) => (row.status === "PENDING" && (data?.pending.length ?? 0) > 1 && row.id !== selected?.id ? (
        <div className="flex sm:justify-end"><Btn variant="ghost" size="sm" onClick={() => setSelectedId(row.id)}>Abrir</Btn></div>
      ) : null),
    },
  ];

  return (
    <>
      <PortalTitle>Contratos por firmar</PortalTitle>
      {error ? <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card> : !data ? (
        <div className="app-surface h-64 animate-pulse rounded-xl" aria-busy="true" />
      ) : (
        <>
          {selected ? (
            <SignCard
              key={selected.id}
              pending={selected}
              onDone={(response, message) => { setData(response); setSelectedId(null); toast(message); }}
            />
          ) : (
            <Card><p className="app-muted">No tienes contratos pendientes de firma.</p></Card>
          )}
          <Card title="Historial de firmas" flush>
            <div className="pt-2">
              <DataTable dense cols={cols} rows={data.history} rowKey={(row) => row.id} empty="Sin contratos." />
            </div>
            <PortalExport file="firmas" title="Historial de firmas" columns={EXPORT} rows={data.history} />
          </Card>
        </>
      )}
    </>
  );
}

function SignCard({ pending, onDone }: { pending: Pending; onDone: (data: Data, message: string) => void }) {
  const { contract } = pending;
  const client = pending.signers.find((signer) => signer.side === "CLIENTE");
  const canSign = pending.mine && client?.status === "PENDING" && !pending.changed;
  const [typedName, setTypedName] = useState("");
  const [code, setCode] = useState("");
  const [accept, setAccept] = useState(false);
  const [sent, setSent] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState("");

  async function sendCode() {
    setBusy(true);
    setErrors({});
    try {
      await apiFetch(`/v1/portal/signatures/${pending.id}/code`, { method: "POST" });
      setSent(true);
    } catch (err) {
      setErrors(errorsOf(err));
    } finally {
      setBusy(false);
    }
  }

  async function sign() {
    const found: Record<string, string> = {};
    if (!typedName.trim()) found.typed_name = "Escribe tu nombre completo.";
    if (!code.trim()) found.code = "Pide el código y escríbelo.";
    if (!accept) found.accept = "Debes aceptar los términos del contrato.";
    setErrors(found);
    if (Object.keys(found).length) return;
    setBusy(true);
    try {
      const data = await apiFetch<Data>(`/v1/portal/signatures/${pending.id}/sign`, {
        method: "POST", body: JSON.stringify({ typed_name: typedName.trim(), code: code.trim(), accept }),
      });
      onDone(data, "Contrato firmado. ¡Gracias!");
    } catch (err) {
      setErrors(errorsOf(err));
      setBusy(false);
    }
  }

  async function decline() {
    if (!reason.trim()) {
      setErrors({ reason: "Indica el motivo." });
      return;
    }
    setBusy(true);
    try {
      const data = await apiFetch<Data>(`/v1/portal/signatures/${pending.id}/decline`, { method: "POST", body: JSON.stringify({ reason: reason.trim() }) });
      onDone(data, "Firma rechazada: tu ejecutivo recibirá el motivo.");
    } catch (err) {
      setErrors(errorsOf(err));
      setBusy(false);
    }
  }

  const [label, tone] = stateOf(SIGNATURE_STATES, pending.status);

  return (
    <Card title={<span className="inline-flex flex-wrap items-center gap-2">Contrato {contract.contract_number} <Badge tone={tone}>{label}</Badge></span>}>
      {/* El documento que se firma (el .doc-paper del prototipo). */}
      <div className="rounded-xl border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-4 py-4 text-[13.5px] sm:px-6">
        <p className="text-center font-semibold tracking-[.02em]">CONTRATO DE PRESTACIÓN DE SERVICIOS</p>
        <p className="mt-3">
          Entre <strong>{contract.company_name}</strong> y <strong>{contract.customer_name}</strong>, vigente del {shortDate(contract.start_date)} al{" "}
          {shortDate(contract.end_date)}, nivel de servicio {slaLabel(contract.sla_level)}.
        </p>
        <div className="proto-tbl-wrap mt-3 overflow-x-auto">
          <table className="proto-tbl w-full border-collapse text-[13px]">
            <thead>
              <tr>
                <th className={TH}>Servicio</th>
                <th className={`${TH} text-right`}>Cant.</th>
                <th className={`${TH} text-right`}>Precio</th>
                <th className={`${TH} text-right`}>Total</th>
              </tr>
            </thead>
            <tbody>
              {contract.items.map((item) => (
                <tr key={item.id}>
                  <td className={TD} data-label="Servicio">{item.description}</td>
                  <td className={`${TD} text-right tabular-nums`} data-label="Cant.">{item.quantity}</td>
                  <td className={`${TD} text-right tabular-nums`} data-label="Precio">{money(item.unit_price, contract.currency)}</td>
                  <td className={`${TD} text-right tabular-nums`} data-label="Total">{money(item.line_total, contract.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="mt-2 text-right font-semibold">Valor total: {money(contract.total_value, contract.currency)}</p>
        {contract.terms && <p className="mt-2 text-[12.5px]">{contract.terms}</p>}
        <p className="app-muted mt-2 break-all text-[11.5px]">Huella del documento (SHA-256): <span className="font-mono">{pending.document_hash}</span></p>
      </div>

      {pending.changed ? (
        <div className="mt-4"><Notice tone="warn">Este contrato cambió después de enviarse a firma. Tu ejecutivo te enviará la versión vigente.</Notice></div>
      ) : canSign ? (
        <div className="mt-4 grid gap-3">
          <div className="grid gap-3 md:grid-cols-3">
            <div className="md:col-span-2">
              <Field label="Escribe tu nombre completo" required error={errors.typed_name}>
                <input className={INPUT} value={typedName} onChange={(e) => setTypedName(e.target.value)} placeholder={client?.name ?? ""} autoComplete="name" />
              </Field>
            </div>
            <Field label="Código de verificación" required error={errors.code} hint={sent ? "Te lo enviamos por correo; vence en 10 minutos." : undefined}>
              <div className="flex gap-1.5">
                <input
                  className={INPUT} inputMode="numeric" maxLength={6} autoComplete="one-time-code" value={code}
                  onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} disabled={!sent}
                />
                <Btn variant="secondary" size="sm" className="h-10 shrink-0" disabled={busy} onClick={sendCode}>{sent ? "Reenviar" : "Enviar código"}</Btn>
              </div>
            </Field>
          </div>
          <label className="flex cursor-pointer items-start gap-2.5">
            <input type="checkbox" className="mt-0.5 h-[17px] w-[17px] accent-[#1B8FC9]" checked={accept} onChange={(e) => setAccept(e.target.checked)} />
            <span>Leí el contrato y acepto sus términos y condiciones</span>
          </label>
          {(errors.accept || errors.status) && <p className="text-[12.5px] text-[color:var(--p-danger-text)]">{errors.accept || errors.status}</p>}
          <div className="flex flex-wrap justify-end gap-2">
            <Btn variant="danger-ghost" disabled={busy} onClick={() => { setReason(""); setErrors({}); setDeclining(true); }}>Rechazar</Btn>
            {/* Sin aceptar los términos no se firma (lo exige también la API). */}
            <Btn disabled={busy || !accept} title={accept ? undefined : "Marca que leíste el contrato y aceptas sus términos"} onClick={sign}>
              <Check size={15} /> Firmar contrato
            </Btn>
          </div>
          <p className="app-muted text-xs">Se registran la fecha, la hora, la IP y la huella del documento.</p>
        </div>
      ) : (
        <p className="app-muted mt-4">
          {client?.status === "SIGNED" ? "Ya firmaste este contrato. Falta la firma de la empresa." : `La firma está asignada a ${client?.name ?? "otra persona"}.`}
        </p>
      )}

      {declining && (
        <Modal
          title="Rechazar firma"
          size="sm"
          onClose={() => setDeclining(false)}
          footer={<><Btn variant="ghost" onClick={() => setDeclining(false)}>Cancelar</Btn><Btn variant="danger" disabled={busy} onClick={decline}>Rechazar</Btn></>}
        >
          <p className="text-[14px]">Tu ejecutivo recibirá el motivo para ajustar el contrato.</p>
          <div className="mt-3">
            <Field label="Motivo" required error={errors.reason}>
              <textarea className={INPUT} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus maxLength={1000} />
            </Field>
          </div>
        </Modal>
      )}
    </Card>
  );
}
