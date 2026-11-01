"use client";

import { useEffect, useState } from "react";
import { Check, Copy } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { Badge, Btn, Field, INPUT, Kv, LoadError, Modal, Notice, Timeline } from "@/components/ui/proto";
import { DELIVERY_STATES, type DeliveryDetail, type IntegrationsData, type WebhookEndpoint } from "@/lib/integrations";
import { stateOf } from "@/lib/commercial";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Nuevo webhook o edición: nombre, dirección https, descripción y eventos. */
export function WebhookModal({
  data, endpoint, onClose, onSaved, toast,
}: { data: IntegrationsData; endpoint?: WebhookEndpoint; onClose: () => void; onSaved: (endpoint: WebhookEndpoint, secret?: string) => void; toast: Toast }) {
  const [name, setName] = useState(endpoint?.name ?? "");
  const [url, setUrl] = useState(endpoint?.url ?? "https://");
  const [description, setDescription] = useState(endpoint?.description ?? "");
  const [events, setEvents] = useState<string[]>(endpoint?.events ?? []);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    const local: Record<string, string> = {};
    if (!name.trim()) local.name = "Indica un nombre.";
    // Lo demás (red interna, puerto, nombre que no existe) lo valida el servidor con su mensaje.
    if (!/^https:\/\/\S+$/.test(url.trim())) local.url = "Escribe una dirección que empiece con https://";
    if (!events.length) local.events = "Elige al menos un evento.";
    setErrors(local);
    if (Object.keys(local).length) return;

    setBusy(true);
    try {
      const saved = await apiFetch<WebhookEndpoint & { secret?: string }>(endpoint ? `/v1/integrations/webhooks/${endpoint.id}` : "/v1/integrations/webhooks", {
        method: endpoint ? "PUT" : "POST",
        body: JSON.stringify({ name: name.trim(), url: url.trim(), description: description.trim() || null, events }),
      });
      toast(endpoint ? "Webhook actualizado" : "Webhook creado");
      onSaved(saved, saved.secret);
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors({ ...fields, events: fields.events ?? Object.entries(fields).find(([k]) => k.startsWith("events."))?.[1] ?? "" });
      if (!Object.keys(fields).length) toast(firstError(err, "No se pudo guardar el webhook."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={endpoint ? "Editar webhook" : "Nuevo webhook"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy}>{endpoint ? "Guardar cambios" : "Crear webhook"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <div className="grid gap-3 md:grid-cols-2">
          <Field label="Nombre" required error={errors.name}>
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} disabled={busy} autoFocus placeholder="Ej.: Contabilidad externa" />
          </Field>
          <Field label="Dirección (https)" required error={errors.url} hint="La del otro sistema que recibirá los avisos.">
            <input className={`${INPUT} font-mono text-[12.5px]`} value={url} onChange={(e) => setUrl(e.target.value)} maxLength={500} disabled={busy} spellCheck={false} />
          </Field>
        </div>
        <Field label="Descripción" error={errors.description}>
          <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={300} disabled={busy} placeholder="Para qué es (opcional)" />
        </Field>
        <fieldset>
          <legend className="mb-1.5 text-[12.5px] font-medium">Eventos que se envían <em className="not-italic text-[color:var(--p-danger-text)]">*</em></legend>
          <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
            {data.events.map((event) => (
              <label key={event.key} className="flex cursor-pointer items-center gap-2 rounded-lg border border-[color:var(--app-border)] px-2.5 py-2 text-[13px]">
                <input
                  type="checkbox" className="h-4 w-4 accent-[#1B8FC9]" checked={events.includes(event.key)} disabled={busy}
                  onChange={(e) => setEvents((list) => (e.target.checked ? [...list, event.key] : list.filter((k) => k !== event.key)))}
                />
                <span>{event.label}</span>
              </label>
            ))}
          </div>
          {errors.events && <small className="mt-1 block text-xs text-[color:var(--p-danger-text)]">{errors.events}</small>}
        </fieldset>
        <p className="app-muted text-xs">Solo direcciones https públicas (no se permiten redes internas). Cada aviso va firmado con la clave del webhook, que se guarda en el Credential Vault.</p>
      </div>
    </Modal>
  );
}

/** La clave de firma se ve una sola vez (al crear o rotar). */
export function SecretModal({ secret, onClose }: { secret: string; onClose: () => void }) {
  const [copied, setCopied] = useState(false);
  return (
    <Modal title="Clave de firma del webhook" size="sm" onClose={onClose} footer={<Btn onClick={onClose}>Ya la guardé</Btn>}>
      <Notice tone="warn">Cópiala ahora y guárdala en el otro sistema: no se vuelve a mostrar aquí. Si la pierdes, rótala.</Notice>
      <div className="mt-3 flex items-center gap-2">
        <code className="min-w-0 flex-1 break-all rounded-lg bg-[var(--app-surface-muted)] px-2.5 py-2 font-mono text-[12px]">{secret}</code>
        <Btn
          variant="secondary" size="sm"
          onClick={async () => {
            try {
              await navigator.clipboard.writeText(secret);
              setCopied(true);
            } catch {
              setCopied(false);
            }
          }}
        >
          {copied ? <Check size={14} /> : <Copy size={14} />}
          <span>{copied ? "Copiada" : "Copiar"}</span>
        </Btn>
      </div>
      <p className="app-muted mt-3 text-xs">
        Para comprobar un aviso: calcula HMAC-SHA256 de <code>{"{X-ERP-Timestamp}.{cuerpo}"}</code> con esta clave y compáralo con la cabecera <code>X-ERP-Signature</code> (sin «sha256=»).
      </p>
    </Modal>
  );
}

/** Un envío: lo que se mandó y cada intento con lo que respondió el otro sistema. */
export function DeliveryModal({ id, onClose, onRetry, canRetry }: { id: string; onClose: () => void; onRetry: () => void; canRetry: boolean }) {
  const [detail, setDetail] = useState<DeliveryDetail | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);

  useEffect(() => {
    let cancelled = false;
    apiFetch<DeliveryDetail>(`/v1/integration-deliveries/${id}`)
      .then((d) => { if (!cancelled) { setDetail(d); setError(""); } })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el envío."); });
    return () => { cancelled = true; };
  }, [id, reload]);

  const [label, tone] = detail ? stateOf(DELIVERY_STATES, detail.status) : ["", "neutral" as const];

  return (
    <Modal
      title="Envío de webhook"
      size="lg"
      onClose={onClose}
      footer={
        <>
          {detail && (detail.status === "DEAD" || detail.status === "RETRY") && (
            <Btn variant="secondary" permission="integrations.manage" disabled={!canRetry} onClick={onRetry}>Reprocesar ahora</Btn>
          )}
          <Btn variant="ghost" onClick={onClose}>Cerrar</Btn>
        </>
      }
    >
      {error ? (
        <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
      ) : !detail ? (
        <p className="app-muted">Cargando…</p>
      ) : (
        <div className="grid gap-4">
          <Kv
            rows={[
              ["Webhook", `${detail.endpoint}`],
              ["Dirección", <code key="u" className="font-mono text-[12px] [overflow-wrap:anywhere]">{detail.url}</code>],
              ["Evento", detail.event_label],
              ["Registro", detail.record ?? "—"],
              ["Estado", <Badge key="s" tone={tone}>{label}</Badge>],
              detail.next_attempt_at && detail.status === "RETRY" ? ["Próximo intento", dateTime(detail.next_attempt_at)] : null,
              detail.delivered_at ? ["Entregado", dateTime(detail.delivered_at)] : null,
            ]}
          />
          <div>
            <p className="mb-1 text-[13px] font-semibold">Intentos</p>
            <Timeline
              empty="Todavía no se intentó: sale en el próximo minuto."
              items={detail.attempts.map((a) => ({
                key: `${a.number}-${a.at}`,
                head: <><strong>Intento {a.number}</strong>{a.ok ? <Badge tone="success">Entregado</Badge> : <Badge tone="danger">Falló</Badge>}{a.code && <code className="text-[12px]">HTTP {a.code}</code>}</>,
                meta: `${dateTime(a.at)}${a.ms !== null ? ` · ${a.ms} ms` : ""}`,
                note: a.error ?? (a.body ? `Respuesta: ${a.body}` : null),
              }))}
            />
          </div>
          <div>
            <p className="mb-1 text-[13px] font-semibold">Lo que se envía</p>
            <pre className="max-h-[260px] overflow-auto whitespace-pre-wrap rounded-[9px] border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3 font-mono text-[12px] [overflow-wrap:anywhere]">{JSON.stringify(detail.payload, null, 2)}</pre>
          </div>
        </div>
      )}
    </Modal>
  );
}
