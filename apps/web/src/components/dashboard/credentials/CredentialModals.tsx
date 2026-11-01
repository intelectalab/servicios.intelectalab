"use client";

import { useEffect, useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { stateOf } from "@/lib/commercial";
import { ACCESS_LABELS, CREDENTIAL_STATES, CREDENTIAL_TYPES, REVEAL_SECONDS, limaDate, limaInputDate, typeLabel } from "@/lib/credentials";
import { Badge, Btn, Card, Chip, Field, FormGrid, INPUT, Kv, LoadError, Modal, Notice, Timeline } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { CredentialAction, CredentialHistory, CredentialRow, CredentialStatus } from "@/types/credentials";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** El vencimiento más próximo que se puede elegir: mañana (hoy ya no sirve). */
const tomorrowInLima = () => limaInputDate(new Date(Date.now() + 86_400_000));

export function CredentialStatusBadge({ status }: { status: CredentialStatus }) {
  const [label, tone] = stateOf(CREDENTIAL_STATES, status);
  return <Badge tone={tone}>{label}</Badge>;
}

/** Campo del valor secreto: oculto, con un ojo para comprobar lo que se escribió. */
function SecretInput({ value, onChange, disabled, label }: { value: string; onChange: (value: string) => void; disabled?: boolean; label: string }) {
  const [shown, setShown] = useState(false);
  return (
    <div className="relative">
      <input
        className={`${INPUT} pr-10 font-mono`}
        type={shown ? "text" : "password"}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        disabled={disabled}
        autoComplete="new-password"
        spellCheck={false}
        aria-label={label}
      />
      <button
        type="button"
        onClick={() => setShown((current) => !current)}
        className="app-muted absolute right-1 top-1/2 grid h-8 w-8 -translate-y-1/2 cursor-pointer place-items-center rounded-lg hover:bg-[var(--app-surface-muted)]"
        aria-label={shown ? "Ocultar lo escrito" : "Ver lo escrito"}
        title={shown ? "Ocultar lo escrito" : "Ver lo escrito"}
      >
        {shown ? <EyeOff size={16} /> : <Eye size={16} />}
      </button>
    </div>
  );
}

/** Nueva credencial: el valor se guarda cifrado y no vuelve a mostrarse en listados. */
export function CredentialCreateModal({ onClose, onSaved, toast }: { onClose: () => void; onSaved: () => void; toast: Toast }) {
  const [name, setName] = useState("");
  const [type, setType] = useState<string>("SERVIDOR");
  const [owner, setOwner] = useState("");
  const [expires, setExpires] = useState("");
  const [minDate] = useState(tomorrowInLima);
  const [secret, setSecret] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    const local: Record<string, string> = {};
    if (!name.trim()) local.name = "Indica un nombre.";
    if (!secret) local.secret = "Ingresa el valor secreto.";
    setErrors(local);
    if (Object.keys(local).length) return;

    setBusy(true);
    try {
      await apiFetch("/v1/credentials", {
        method: "POST",
        body: JSON.stringify({ name: name.trim(), credential_type: type, owner_label: owner.trim() || null, expires_at: expires || null, secret }),
      });
      toast("Credencial guardada cifrada");
      onSaved();
      onClose();
    } catch (err) {
      setErrors(fieldErrors(err));
      if (!Object.keys(fieldErrors(err)).length) toast(firstError(err, "No se pudo guardar la credencial."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Nueva credencial"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy}>Guardar cifrado</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Nombre" required span={2} error={errors.name}>
          <input className={INPUT} value={name} onChange={(event) => setName(event.target.value)} maxLength={150} disabled={busy} autoFocus />
        </Field>
        <Field label="Tipo" required error={errors.credential_type}>
          <PickSelect className={INPUT} ariaLabel="Tipo" value={type} onChange={setType} disabled={busy} options={CREDENTIAL_TYPES} />
        </Field>
        <Field label="Pertenece a" span={2} hint="Un cliente, un servidor, un servicio…" error={errors.owner_label}>
          <input className={INPUT} value={owner} onChange={(event) => setOwner(event.target.value)} maxLength={150} disabled={busy} placeholder="Ej.: Cliente: Plaza Sol" />
        </Field>
        <Field label="Expira" hint="Vacío: no vence" error={errors.expires_at}>
          <input className={INPUT} type="date" value={expires} min={minDate} onChange={(event) => setExpires(event.target.value)} disabled={busy} />
        </Field>
        <Field label="Valor secreto" required span={3} hint="Se guarda cifrado; nunca aparece en listados, exportaciones ni en la auditoría." error={errors.secret}>
          <SecretInput label="Valor secreto" value={secret} onChange={setSecret} disabled={busy} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

const ACTION_TEXT: Record<CredentialAction, { title: string; text: string; confirm: string; done: string }> = {
  reveal: {
    title: "Revelar secreto",
    text: `El valor se mostrará ${REVEAL_SECONDS} segundos. La revelación queda registrada (sin el valor).`,
    confirm: "Revelar secreto",
    done: "Secreto revelado (registrado)",
  },
  use: {
    title: "Usar credencial",
    text: "El valor se copia al portapapeles sin mostrarse en pantalla. El uso queda registrado.",
    confirm: "Copiar valor",
    done: "Valor copiado al portapapeles (registrado)",
  },
  rotate: {
    title: "Rotar credencial",
    text: "Se guarda el valor nuevo como otra versión; la anterior queda en el historial.",
    confirm: "Rotar credencial",
    done: "Nueva versión creada",
  },
  revoke: {
    title: "Revocar credencial",
    text: "La credencial dejará de poder usarse. No se borra su historial.",
    confirm: "Revocar credencial",
    done: "Credencial revocada",
  },
};

/** Revelar, usar, rotar o revocar: cada una pide motivo. Revelar y usar devuelven el valor. */
export function CredentialActionModal({
  credential, action, onClose, onDone, toast,
}: {
  credential: CredentialRow;
  action: CredentialAction;
  onClose: () => void;
  onDone: (secret?: string) => void;
  toast: Toast;
}) {
  const copy = ACTION_TEXT[action];
  const [reason, setReason] = useState("");
  const [secret, setSecret] = useState("");
  // Al rotar se conserva el vencimiento si sigue en el futuro; si ya pasó, hay que poner otro (o dejarlo sin vencimiento).
  const [expires, setExpires] = useState(() =>
    credential.expires_at && new Date(credential.expires_at).getTime() > Date.now() ? limaInputDate(credential.expires_at) : "",
  );
  const [minDate] = useState(tomorrowInLima);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function confirm() {
    const local: Record<string, string> = {};
    if (!reason.trim()) local.reason = "Indica el motivo: el acceso al Vault queda registrado.";
    if (action === "rotate" && !secret) local.secret = "Ingresa el valor nuevo.";
    setErrors(local);
    if (Object.keys(local).length) return;

    setBusy(true);
    try {
      const body = action === "rotate" ? { reason: reason.trim(), secret, expires_at: expires || null } : { reason: reason.trim() };
      const response = await apiFetch<{ secret?: string }>(`/v1/credentials/${credential.id}/${action}`, { method: "POST", body: JSON.stringify(body) });
      if (action === "use") {
        try {
          await navigator.clipboard.writeText(response.secret ?? "");
        } catch {
          toast("El navegador no permitió copiar al portapapeles.", "danger", "El uso ya quedó registrado.");
          onDone();
          onClose();
          return;
        }
      }
      toast(copy.done);
      onDone(action === "reveal" ? response.secret : undefined);
      onClose();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!fields.reason && !fields.secret && !fields.expires_at) toast(firstError(err, "No se pudo completar la acción."), "danger");
      // El estado pudo cambiar (venció o la revocó otra persona): se refresca el listado.
      if (fields.status) {
        onDone();
        onClose();
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={copy.title}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Volver</Btn>
          <Btn variant={action === "revoke" ? "danger" : "primary"} onClick={confirm} disabled={busy}>{copy.confirm}</Btn>
        </>
      }
    >
      <p className="mb-1 font-semibold">{credential.name}</p>
      <p className="app-muted mb-3 text-[13px]">{copy.text}</p>
      <div className="grid gap-3">
        {action === "rotate" && (
          <>
            <Field label="Valor nuevo" required error={errors.secret}>
              <SecretInput label="Valor nuevo" value={secret} onChange={setSecret} disabled={busy} />
            </Field>
            <Field label="Expira" hint="Vacío: no vence" error={errors.expires_at}>
              <input className={INPUT} type="date" value={expires} min={minDate} onChange={(event) => setExpires(event.target.value)} disabled={busy} />
            </Field>
          </>
        )}
        <Field label="Motivo del acceso" required error={errors.reason}>
          <textarea className={INPUT} rows={3} maxLength={500} value={reason} onChange={(event) => setReason(event.target.value)} disabled={busy} autoFocus={action !== "rotate"} placeholder="Queda registrado en el historial" />
        </Field>
      </div>
    </Modal>
  );
}

/** Historial de la credencial: datos, versiones y accesos (sin valores). */
export function CredentialHistoryModal({ credential, onClose }: { credential: CredentialRow; onClose: () => void }) {
  const [history, setHistory] = useState<CredentialHistory | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<CredentialHistory>(`/v1/credentials/${credential.id}/history`)
      .then((data) => { if (!cancelled) { setHistory(data); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el historial."); });
    return () => { cancelled = true; };
  }, [credential.id, reload]);

  return (
    <Modal title={credential.name} size="lg" onClose={onClose}>
      <Kv
        rows={[
          ["Tipo", typeLabel(credential.type)],
          ["Pertenece a", credential.owner_label || "—"],
          ["Estado", <CredentialStatusBadge key="s" status={credential.status} />],
          ["Versión", `v${credential.version}`],
          ["Expira", credential.expires_at ? limaDate(credential.expires_at) : "No vence"],
          ["Creada por", credential.creator ?? "—"],
        ]}
      />
      {error ? (
        <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
      ) : !history ? (
        <p className="app-muted py-3">Cargando historial…</p>
      ) : (
        <div className="mt-3 grid gap-4 md:grid-cols-2">
          <Card title="Versiones">
            <Timeline
              items={history.versions.map((version) => ({
                key: String(version.version),
                head: <strong>v{version.version}</strong>,
                meta: `${version.user ?? "—"} · ${dateTime(version.created_at)}`,
                note: version.reason,
              }))}
            />
          </Card>
          <Card title="Accesos (sin valores)">
            <Timeline
              empty="Sin accesos."
              items={history.accesses.map((access, index) => ({
                key: `${access.created_at}-${index}`,
                head: (
                  <>
                    <strong>{ACCESS_LABELS[access.action] ?? access.action}</strong>
                    {!access.success && <Chip tone="danger">Rechazado</Chip>}
                  </>
                ),
                meta: `${access.user ?? "—"} · ${dateTime(access.created_at)}`,
                note: access.reason,
              }))}
            />
          </Card>
        </div>
      )}
      {history && history.accesses.length >= 200 && <Notice>Se muestran los 200 accesos más recientes; el resto está en Auditoría.</Notice>}
    </Modal>
  );
}
