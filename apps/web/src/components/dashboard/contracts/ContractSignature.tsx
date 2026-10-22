"use client";

import { useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { shortDate, stateOf } from "@/lib/commercial";
import { SIGNATURE_PROVIDERS, SIGNATURE_STATES, SIGNER_STATES } from "@/lib/contracts";
import { Badge, Btn, Field, INPUT, Kv, Modal, Notice, SearchSelect } from "@/components/ui/proto";
import type { Contract, SignatureRequest } from "@/types/commercial";

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

const dateTime = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

/** Contenido de la tarjeta "Firma electrónica" de la ficha (el del prototipo). */
export function SignatureSummary({ contract, onCancel }: { contract: Contract; onCancel: (signature: SignatureRequest) => void }) {
  const signature = contract.signature;
  if (!signature || signature.status === "CANCELLED") {
    return (
      <p className="app-muted">
        {contract.signature_required
          ? contract.status === "DRAFT"
            ? "Pendiente de enviar a firma. El Portal Admin del cliente firma desde su portal con un código a su correo."
            : "Contrato anterior a la firma electrónica."
          : contract.status === "DRAFT"
            ? "La firma electrónica no es obligatoria en esta empresa, pero puedes enviar el contrato a firma."
            : "La firma electrónica no es obligatoria en esta empresa."}
      </p>
    );
  }

  const [label, tone] = stateOf(SIGNATURE_STATES, signature.status);
  return (
    <div className="grid gap-3">
      {signature.changed && (
        <Notice tone="warn">El contrato cambió después de enviarse a firma: ya no se puede firmar. Anula la solicitud y envíalo de nuevo.</Notice>
      )}
      <Kv
        rows={[
          ["Estado", <Badge key="status" tone={tone}>{label}</Badge>],
          ["Proveedor", SIGNATURE_PROVIDERS[signature.provider] ?? signature.provider],
          ["Huella del documento", <span key="hash" className="break-all font-mono text-[11.5px]" title={signature.document_hash}>{signature.document_hash.slice(0, 32)}…</span>],
          ...signature.signers.map((signer): [string, React.ReactNode] => {
            const [state, color] = SIGNER_STATES[signer.status] ?? [signer.status, "neutral"];
            return [
              signer.side === "CLIENTE" ? "Firma del cliente" : "Firma de la empresa",
              <div key={signer.side}>
                <span className="inline-flex flex-wrap items-center gap-1.5">{signer.name ?? "Por designar"} <Badge tone={color}>{state}</Badge></span>
                {signer.signed_at && (
                  <>
                    <small className="app-muted block text-[11.5px]">
                      {dateTime(signer.signed_at)}{signer.otp_verified ? " · código verificado" : ""}{signer.ip_address ? ` · IP ${signer.ip_address}` : ""}
                    </small>
                    {signer.evidence_hash && <small className="app-muted block font-mono text-[11px]">evidencia {signer.evidence_hash.slice(0, 20)}…</small>}
                  </>
                )}
              </div>,
            ];
          }),
          signature.declined_reason ? ["Motivo de rechazo", signature.declined_reason] : null,
          ["Vence", shortDate(signature.expires_at)],
        ]}
      />
      {(signature.status === "PENDING" || signature.status === "DECLINED") && (
        <div className="flex flex-wrap gap-1.5">
          <Btn variant="ghost" size="sm" permission="contracts.manage" onClick={() => onCancel(signature)}>Anular solicitud</Btn>
        </div>
      )}
    </div>
  );
}

/** Enviar a firma: el firmante es un Portal Admin activo del cliente. */
export function SendSignatureModal({ contract, onClose, onDone }: { contract: Contract; onClose: () => void; onDone: (contract: Contract) => void }) {
  const signers = contract.signature_signers ?? [];
  const [signer, setSigner] = useState(signers[0]?.id ?? "");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function send() {
    setBusy(true);
    setError("");
    try {
      onDone(await apiFetch<Contract>(`/v1/contracts/${contract.id}/signature-requests`, { method: "POST", body: JSON.stringify({ portal_user_id: signer || null }) }));
    } catch (err) {
      setError(firstError(err, "No se pudo enviar a firma."));
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Enviar a firma"
      size="sm"
      onClose={onClose}
      footer={<><Btn variant="ghost" onClick={onClose}>Cancelar</Btn><Btn disabled={busy || !signers.length} onClick={send}>{busy ? "Enviando…" : "Enviar a firma"}</Btn></>}
    >
      {signers.length ? (
        <div className="grid gap-3">
          <p>El cliente lo verá en su portal y recibirá un aviso por correo. Vence en 15 días.</p>
          <Field label="Firma por el cliente">
            <SearchSelect value={signer} onChange={setSigner} options={signers.map((item) => ({ value: item.id, label: item.name, hint: item.email }))} />
          </Field>
          <p className="app-muted text-[12.5px]">Si cambias el contrato después de enviarlo, la firma ya no procederá y habrá que enviarlo de nuevo.</p>
        </div>
      ) : (
        <Notice tone="warn">El cliente no tiene un usuario Portal Admin activo que pueda firmar. Invítalo desde Administración → Usuarios del portal.</Notice>
      )}
      {error && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{error}</p>}
    </Modal>
  );
}

/** Firmar por la empresa: código al correo de quien firma y aceptación. */
export function CompanySignModal({ contract, onClose, onDone }: { contract: Contract; onClose: () => void; onDone: (contract: Contract) => void }) {
  const signature = contract.signature!;
  const base = `/v1/contracts/${contract.id}/signature-requests/${signature.id}`;
  const [code, setCode] = useState("");
  const [accept, setAccept] = useState(false);
  const [sent, setSent] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function sendCode() {
    setBusy(true);
    setErrors({});
    try {
      await apiFetch(`${base}/code`, { method: "POST" });
      setSent(true);
    } catch (err) {
      setErrors({ code: firstError(err, "No se pudo enviar el código.") });
    } finally {
      setBusy(false);
    }
  }

  async function sign() {
    setBusy(true);
    setErrors({});
    try {
      onDone(await apiFetch<Contract>(`${base}/company-sign`, { method: "POST", body: JSON.stringify({ code: code.trim(), accept }) }));
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      else setErrors({ code: firstError(err, "No se pudo firmar.") });
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Firmar por la empresa"
      size="sm"
      onClose={onClose}
      footer={<><Btn variant="ghost" onClick={onClose}>Cancelar</Btn><Btn disabled={busy || !sent || code.trim().length !== 6 || !accept} onClick={sign}>Firmar</Btn></>}
    >
      <div className="grid gap-3">
        <p>Firmas el contrato {contract.contract_number} como representante de la empresa. Se registra la evidencia: la huella del documento, tu usuario, la fecha y la IP.</p>
        <div className="flex flex-wrap items-end gap-2">
          <div className="min-w-0 flex-1">
            <Field label="Código de tu correo" required error={errors.code} hint={sent ? "Te lo enviamos a tu correo; vence en 10 minutos." : undefined}>
              <input className={INPUT} inputMode="numeric" maxLength={6} autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ""))} disabled={!sent} />
            </Field>
          </div>
          <Btn variant="secondary" disabled={busy} onClick={sendCode}>{sent ? "Reenviar código" : "Enviar código"}</Btn>
        </div>
        <label className="flex cursor-pointer items-start gap-2.5">
          <input type="checkbox" className="mt-0.5 h-[17px] w-[17px] accent-[#1B8FC9]" checked={accept} onChange={(e) => setAccept(e.target.checked)} />
          <span>Leí el contrato y acepto sus términos en nombre de la empresa.</span>
        </label>
        {(errors.status || errors.accept) && <p className="text-[12.5px] text-[color:var(--p-danger-text)]">{errors.status || errors.accept}</p>}
      </div>
    </Modal>
  );
}
