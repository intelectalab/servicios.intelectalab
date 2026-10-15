"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { apiFetch } from "@/lib/api";
import { customerName, shortDate, stateOf } from "@/lib/commercial";
import { dateTime, fieldErrors, firstError } from "@/lib/crm";
import { WARRANTY_STATES, supportHistoryLabel } from "@/lib/support";
import { Badge, Btn, Chip, Field, INPUT, Kv, Modal, SearchSelect, Timeline } from "@/components/ui/proto";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import type { WarrantyClaim, WarrantyClaimDetail, WarrantyOptions } from "@/types/support";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const LINK = "text-[color:var(--p-brand-text)] hover:underline";

/**
 * Reclamo de garantía (modal del prototipo): servicio contratado y descripción de la falla. Desde
 * un ticket llega con el ticket y su cliente; desde Garantías se elige el servicio y, si se quiere,
 * un ticket del mismo cliente. Al registrarlo se valida la cobertura en el acto.
 */
export function WarrantyClaimFormModal({
  ticket, onClose, onSaved, toast,
}: {
  ticket?: { id: string; number: string; subject: string; customer_id: string };
  onClose: () => void;
  onSaved: (claim: WarrantyClaimDetail) => void;
  toast: Toast;
}) {
  const [options, setOptions] = useState<WarrantyOptions | null>(null);
  const [service, setService] = useState("");
  const [ticketId, setTicketId] = useState(ticket?.id ?? "");
  const [description, setDescription] = useState(ticket?.subject ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    apiFetch<WarrantyOptions>("/v1/warranty-claims/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => { if (!cancelled) toast("No se pudieron cargar los servicios contratados.", "danger"); });
    return () => { cancelled = true; };
  }, [toast]);

  const services = useMemo(
    () => (options?.contracted_services ?? []).filter((item) => !ticket || item.customer_id === ticket.customer_id),
    [options, ticket],
  );
  const chosen = services.find((item) => item.id === service);
  const tickets = (options?.tickets ?? []).filter((item) => chosen && item.customer_id === chosen.customer_id);

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      onSaved(await apiFetch<WarrantyClaimDetail>("/v1/warranty-claims", {
        method: "POST",
        body: JSON.stringify({ contracted_service_id: service, ticket_id: ticketId || null, description }),
      }));
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo registrar el reclamo."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Reclamo de garantía"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy || !options} permission="warranty.create">Registrar reclamo</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        {ticket && <p className="app-muted text-[12.5px]">Ticket {ticket.number}: el reclamo queda enlazado a él.</p>}
        <Field
          label="Servicio contratado"
          required
          error={errors.contracted_service_id}
          hint={chosen ? (chosen.warranty_months ? `Garantía del servicio: ${chosen.warranty_months} ${chosen.warranty_months === 1 ? "mes" : "meses"}.` : "El servicio no tiene garantía: el reclamo quedará fuera de cobertura.") : undefined}
        >
          <SearchSelect
            value={service}
            onChange={(value) => { setService(value); if (!ticket) setTicketId(""); }}
            options={services.map((item) => ({
              value: item.id,
              label: `${item.service ?? "Servicio"}${item.contract ? ` · ${item.contract}` : ""}`,
              hint: ticket ? undefined : item.customer ?? undefined,
            }))}
            placeholder={options ? (services.length ? "Seleccionar…" : "El cliente no tiene servicios contratados") : "Cargando…"}
            searchPlaceholder="Buscar servicio o cliente…"
          />
        </Field>
        {!ticket && (
          <Field label="Ticket" hint="Opcional: un ticket del mismo cliente que aún no tenga reclamo." error={errors.ticket_id}>
            <SearchSelect
              value={ticketId}
              onChange={setTicketId}
              disabled={!chosen}
              options={[{ value: "", label: "Sin ticket" }, ...tickets.map((item) => ({ value: item.id, label: `${item.number} · ${item.subject}` }))]}
              placeholder="Sin ticket"
              searchPlaceholder="Buscar ticket…"
            />
          </Field>
        )}
        <Field label="Descripción de la falla" required error={errors.description}>
          <textarea className={INPUT} rows={4} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={5000} />
        </Field>
      </div>
    </Modal>
  );
}

/** Cobertura de un reclamo como en la tabla del prototipo: chip y "N meses · condiciones · hasta…". */
export function CoverageCell({ claim }: { claim: Pick<WarrantyClaim, "coverage"> }) {
  if (!claim.coverage) return <span className="app-muted">Sin validar</span>;
  return (
    <div className="grid gap-0.5">
      <span>{claim.coverage.valid ? <Chip tone="success">Dentro de garantía</Chip> : <Chip tone="danger">Fuera de cobertura</Chip>}</span>
      <small className="app-muted block text-xs">
        {claim.coverage.policy}{claim.coverage.until ? ` · hasta ${shortDate(claim.coverage.until)}` : ""}
      </small>
    </div>
  );
}

export type ClaimAction = "check-coverage" | "approve" | "reject" | "resolve";

/** Botones de un reclamo según su estado (los de la columna Acciones del prototipo). */
export function ClaimActions({
  claim, busy, onAction, size = "sm",
}: { claim: WarrantyClaim; busy: boolean; onAction: (action: ClaimAction) => void; size?: "sm" | "md" }) {
  const actions = [];
  if (!claim.coverage && ["SUBMITTED", "UNDER_REVIEW"].includes(claim.status)) {
    actions.push(<Btn key="check" size={size} variant="secondary" onClick={() => onAction("check-coverage")} permission="warranty.manage" disabled={busy}>Validar cobertura</Btn>);
  }
  if (claim.status === "UNDER_REVIEW") {
    actions.push(
      <Btn key="approve" size={size} variant="secondary" onClick={() => onAction("approve")} permission="warranty.approve" disabled={busy || !claim.coverage?.valid} title={claim.coverage?.valid ? undefined : "Fuera de cobertura"}>
        Aprobar
      </Btn>,
      <Btn key="reject" size={size} variant="danger-ghost" onClick={() => onAction("reject")} permission="warranty.approve" disabled={busy}>Rechazar</Btn>,
    );
  }
  if (claim.status === "APPROVED") {
    actions.push(<Btn key="resolve" size={size} variant="ghost" onClick={() => onAction("resolve")} permission="warranty.manage" disabled={busy}>Marcar resuelto</Btn>);
  }
  return actions.length ? <div className="flex flex-wrap gap-1.5">{actions}</div> : null;
}

/** Ejecuta una acción sobre un reclamo; rechazar y resolver piden su texto. */
export function useClaimActions(toast: Toast, onDone: (claim: WarrantyClaimDetail) => void) {
  const [busy, setBusy] = useState(false);
  const [asking, setAsking] = useState<{ claim: WarrantyClaim; action: "reject" | "resolve" } | null>(null);

  const DONE: Record<ClaimAction, string> = {
    "check-coverage": "Cobertura validada", approve: "Cobertura aprobada", reject: "Reclamo rechazado", resolve: "Reclamo resuelto",
  };

  async function run(claim: WarrantyClaim, action: ClaimAction, reason?: string) {
    setBusy(true);
    try {
      const next = await apiFetch<WarrantyClaimDetail>(`/v1/warranty-claims/${claim.id}/${action}`, { method: "POST", body: reason ? JSON.stringify({ reason }) : undefined });
      toast(DONE[action]);
      setAsking(null);
      onDone(next);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  function start(claim: WarrantyClaim, action: ClaimAction) {
    if (action === "reject" || action === "resolve") setAsking({ claim, action });
    else run(claim, action);
  }

  const reasonModal = asking && (
    <ReasonModal
      title={asking.action === "reject" ? "Rechazar reclamo" : "Marcar resuelto"}
      text={`${asking.claim.number} · ${asking.claim.description}`}
      label={asking.action === "reject" ? "Motivo del rechazo" : "Cómo se resolvió"}
      confirmLabel={asking.action === "reject" ? "Rechazar" : "Marcar resuelto"}
      busy={busy}
      onClose={() => setAsking(null)}
      onConfirm={(reason) => run(asking.claim, asking.action, reason)}
    />
  );

  return { busy, start, reasonModal };
}

/** Ficha del reclamo (mejora sobre el prototipo, que solo tiene la tabla): datos, cobertura, acciones e historial. */
export function WarrantyClaimDetailModal({
  claimId, onClose, onChanged, toast,
}: {
  claimId: string;
  onClose: () => void;
  onChanged: () => void;
  toast: Toast;
}) {
  const [claim, setClaim] = useState<WarrantyClaimDetail | null>(null);
  const [error, setError] = useState("");
  const { busy, start, reasonModal } = useClaimActions(toast, (next) => {
    setClaim(next);
    onChanged();
  });

  useEffect(() => {
    let cancelled = false;
    apiFetch<WarrantyClaimDetail>(`/v1/warranty-claims/${claimId}`)
      .then((next) => { if (!cancelled) setClaim(next); })
      .catch(() => { if (!cancelled) setError("No se pudo cargar el reclamo."); });
    return () => { cancelled = true; };
  }, [claimId]);

  const [label, tone] = claim ? stateOf(WARRANTY_STATES, claim.status) : ["", "neutral" as const];

  return (
    <>
      <Modal title={claim ? `Reclamo ${claim.number}` : "Reclamo de garantía"} size="lg" onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
        {error ? (
          <p className="text-[color:var(--p-danger-text)]">{error}</p>
        ) : !claim ? (
          <div className="h-40 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" />
        ) : (
          <div className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <Badge tone={tone}>{label}</Badge>
              <ClaimActions claim={claim} busy={busy} onAction={(action) => start(claim, action)} size="md" />
            </div>
            <Kv
              rows={[
                ["Descripción", <span key="description" className="whitespace-pre-line">{claim.description}</span>],
                ["Cliente", claim.customer ? <Link key="customer" href={`/actors/${claim.customer.party_id}`} className={LINK}>{customerName(claim.customer)}</Link> : "—"],
                ["Servicio", claim.service?.name ?? "—"],
                ["Contrato", claim.contract ?? "—"],
                ["Ticket", claim.ticket ? (claim.ticket.visible ? <Link key="ticket" href={`/tickets/${claim.ticket.id}`} className={LINK}>{claim.ticket.number}</Link> : claim.ticket.number) : "—"],
                ["Cobertura", <CoverageCell key="coverage" claim={claim} />],
                claim.coverage ? ["Desde", `${claim.coverage.base ?? "—"}${claim.coverage.base_date ? ` · ${shortDate(claim.coverage.base_date)}` : ""}`] : null,
                claim.coverage ? ["Validada", dateTime(claim.coverage.checked_at)] : null,
                claim.reject_reason ? ["Motivo del rechazo", claim.reject_reason] : null,
                claim.resolution ? ["Resolución", <span key="resolution" className="whitespace-pre-line">{claim.resolution}</span>] : null,
                claim.reviewer ? ["Revisado por", `${claim.reviewer}${claim.reviewed_at ? ` · ${dateTime(claim.reviewed_at)}` : ""}`] : null,
                ["Registrado", `${claim.creator ?? "—"}${claim.created_at ? ` · ${dateTime(claim.created_at)}` : ""}`],
              ]}
            />
            <div>
              <h3 className="mb-2 text-[14px] font-semibold">Historial</h3>
              <Timeline
                items={claim.history.map((entry, index) => {
                  const to = entry.metadata.to;
                  return {
                    key: `${entry.at}-${index}`,
                    head: to ? <Badge tone={stateOf(WARRANTY_STATES, to)[1]}>{stateOf(WARRANTY_STATES, to)[0]}</Badge> : <span className="font-medium">{supportHistoryLabel(entry)}</span>,
                    meta: `${entry.user ?? "Sistema"} · ${dateTime(entry.at)}`,
                    note: entry.metadata.note,
                  };
                })}
              />
            </div>
          </div>
        )}
      </Modal>
      {reasonModal}
    </>
  );
}
