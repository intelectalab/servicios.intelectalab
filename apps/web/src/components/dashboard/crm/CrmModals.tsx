"use client";

import { useEffect, useState } from "react";
import { Lock } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { customerDocument, customerName, isoDateFromNow, money } from "@/lib/commercial";
import { LOSS_REASONS, fieldErrors, firstError, leadName } from "@/lib/crm";
import { useAccess } from "@/lib/access-context";
import { Btn, Field, FormGrid, INPUT, Modal, SearchSelect, deniedText, useDenied, type SelectOption } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { Actor, ActorDuplicate } from "@/types/actor";
import type { Customer, PaginatedResponse } from "@/types/customer";
import type { ConversionCandidate, Lead, LeadDetail, LeadOptions, Opportunity, OpportunityDetail, OpportunityOptions } from "@/types/crm";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const SELECT_LIMIT = 200;

/** Alta o edición de un lead. */
export function LeadFormModal({
  lead, sources, onClose, onSaved, toast,
}: {
  lead?: Lead;
  sources: LeadOptions["sources"];
  onClose: () => void;
  onSaved: (lead: LeadDetail) => void;
  toast: Toast;
}) {
  const [company, setCompany] = useState(lead?.organization_name ?? "");
  const [contact, setContact] = useState(lead?.person_name ?? "");
  const [position, setPosition] = useState(lead?.position ?? "");
  const [email, setEmail] = useState(lead?.email ?? "");
  const [phone, setPhone] = useState(lead?.phone ?? "");
  const [sourceId, setSourceId] = useState(lead?.source_id ?? "");
  const [interest, setInterest] = useState(lead?.interest ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    const found: Record<string, string> = {};
    if (!company.trim()) found.organization_name = "Indica la empresa.";
    if (!contact.trim()) found.person_name = "Indica el contacto.";
    if (!email.trim()) found.email = "Indica el correo.";
    if (!sourceId) found.source_id = "Indica la fuente.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<LeadDetail>(lead ? `/v1/crm/leads/${lead.id}` : "/v1/crm/leads", {
        method: lead ? "PUT" : "POST",
        body: JSON.stringify({
          organization_name: company.trim(),
          person_name: contact.trim(),
          position: position.trim() || null,
          email: email.trim(),
          phone: phone.trim() || null,
          interest: interest.trim() || null,
          source_id: sourceId,
        }),
      });
      toast(lead ? "Lead actualizado" : saved.core_party ? "Lead creado y vinculado a la identidad existente en CORE" : "Lead creado");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar el lead."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={lead ? `Editar lead ${lead.lead_number}` : "Nuevo lead"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="leads.manage">{lead ? "Guardar" : "Crear lead"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Empresa" required span={2} error={errors.organization_name}>
          <input className={INPUT} value={company} onChange={(e) => setCompany(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Contacto" required error={errors.person_name}>
          <input className={INPUT} value={contact} onChange={(e) => setContact(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Cargo" error={errors.position}>
          <input className={INPUT} value={position} onChange={(e) => setPosition(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Correo" required error={errors.email}>
          <input className={INPUT} type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Teléfono" error={errors.phone}>
          <input className={INPUT} value={phone} onChange={(e) => setPhone(e.target.value)} maxLength={50} />
        </Field>
        <Field label="Fuente" required error={errors.source_id}>
          <PickSelect
            className={INPUT}
            ariaLabel="Fuente"
            value={sourceId}
            onChange={setSourceId}
            options={[["", "Seleccionar…"], ...sources.map((source): [string, string] => [source.id, source.name])]}
          />
        </Field>
        <Field label="Campaña" hint="Se elegirá aquí cuando exista la pantalla Campañas.">
          <PickSelect className={INPUT} ariaLabel="Campaña" value="" onChange={() => undefined} options={[["", "Sin campaña"]]} disabled />
        </Field>
        <Field label="Interés" error={errors.interest}>
          <input className={INPUT} value={interest} onChange={(e) => setInterest(e.target.value)} maxLength={255} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Calificar un lead: crea su oportunidad y deja el lead como convertido. */
export function QualifyLeadModal({
  lead, onClose, onCreated, toast,
}: {
  lead: Lead;
  onClose: () => void;
  onCreated: (opportunityId: string) => void;
  toast: Toast;
}) {
  const [name, setName] = useState(`${lead.interest || "Servicio"} — ${leadName(lead)}`);
  const [amount, setAmount] = useState("");
  const [close, setClose] = useState(isoDateFromNow(30));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!(Number(amount) > 0)) {
      setErrors({ amount: "Indica el monto estimado." });
      return;
    }
    setSaving(true);
    try {
      const created = await apiFetch<{ id: string }>(`/v1/crm/leads/${lead.id}/opportunity`, {
        method: "POST",
        body: JSON.stringify({ name: name.trim() || null, amount: Number(amount), expected_close_date: close || null }),
      });
      onCreated(created.id);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo crear la oportunidad."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Calificar lead → oportunidad"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="opportunities.manage">Crear oportunidad</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Nombre de la oportunidad" span={3} error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Monto estimado (S/)" required error={errors.amount}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} autoFocus />
        </Field>
        <Field label="Cierre esperado" error={errors.expected_close_date}>
          <input className={INPUT} type="date" value={close} onChange={(e) => setClose(e.target.value)} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Confirmación con motivo obligatorio (descartar un lead). */
export function ReasonModal({
  title, text, label, confirmLabel, busy, onClose, onConfirm,
}: {
  title: string;
  text: string;
  label: string;
  confirmLabel: string;
  busy: boolean;
  onClose: () => void;
  onConfirm: (reason: string) => void;
}) {
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");

  return (
    <Modal
      title={title}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Volver</Btn>
          <Btn
            variant="danger"
            disabled={busy}
            onClick={() => {
              if (!reason.trim()) setError("Debes indicar un motivo.");
              else onConfirm(reason.trim());
            }}
          >
            {confirmLabel}
          </Btn>
        </>
      }
    >
      <p className="text-[15px]">{text}</p>
      <div className="mt-3">
        <Field label={label} required error={error}>
          <textarea className={INPUT} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Queda registrado en auditoría" autoFocus />
        </Field>
      </div>
    </Modal>
  );
}

/** Alta o edición de una oportunidad. */
export function OpportunityFormModal({
  opportunity, sources, onClose, onSaved, toast,
}: {
  opportunity?: Opportunity;
  sources: OpportunityOptions["sources"];
  onClose: () => void;
  onSaved: (opportunity: OpportunityDetail) => void;
  toast: Toast;
}) {
  const { can, loading: accessLoading } = useAccess();
  const canSeeCustomers = !accessLoading && can("core.view");

  const [name, setName] = useState(opportunity?.name ?? "");
  const [customerId, setCustomerId] = useState(opportunity?.customer_id ?? "");
  const [amount, setAmount] = useState(opportunity ? String(Number(opportunity.expected_value)) : "");
  const [close, setClose] = useState(opportunity?.expected_close_date?.slice(0, 10) ?? "");
  const [sourceId, setSourceId] = useState(opportunity?.source_id ?? "");
  const [customers, setCustomers] = useState<Customer[]>(opportunity?.customer ? [opportunity.customer] : []);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!canSeeCustomers) return;
    let cancelled = false;
    apiFetch<PaginatedResponse<Customer>>(`/v1/customers?status=ACTIVE&per_page=${SELECT_LIMIT}`)
      .then((page) => {
        if (cancelled) return;
        // El cliente de la oportunidad puede no venir en la lista (más de 200 clientes).
        const current = opportunity?.customer;
        setCustomers(current && !page.data.some((customer) => customer.id === current.id) ? [current, ...page.data] : page.data);
      })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [canSeeCustomers, opportunity]);

  async function save() {
    const found: Record<string, string> = {};
    if (!name.trim()) found.name = "El nombre es obligatorio.";
    if (amount === "" || !(Number(amount) >= 0)) found.amount = "Indica el monto.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<OpportunityDetail>(opportunity ? `/v1/crm/opportunities/${opportunity.id}` : "/v1/crm/opportunities", {
        method: opportunity ? "PUT" : "POST",
        body: JSON.stringify({
          name: name.trim(),
          amount: Number(amount),
          customer_id: customerId || null,
          expected_close_date: close || null,
          source_id: sourceId || null,
        }),
      });
      if (opportunity) toast("Oportunidad actualizada");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar la oportunidad."), "danger");
    } finally {
      setSaving(false);
    }
  }

  const customerOptions: SelectOption[] = [
    { value: "", label: "Sin cliente (se resuelve al convertir)" },
    ...customers.map((customer) => ({ value: customer.id, label: customerName(customer), hint: customerDocument(customer) })),
  ];

  return (
    <Modal
      title={opportunity ? `Editar oportunidad ${opportunity.opportunity_number}` : "Nueva oportunidad"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="opportunities.manage">{opportunity ? "Guardar" : "Crear"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Nombre" required span={3} error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field
          label="Cliente existente en CORE (opcional)"
          span={3}
          error={errors.customer_id}
          hint={!accessLoading && !canSeeCustomers ? deniedText("core.view") : undefined}
        >
          <SearchSelect
            value={customerId}
            onChange={setCustomerId}
            options={customerOptions}
            searchPlaceholder="Buscar cliente…"
            disabled={!canSeeCustomers}
          />
        </Field>
        <Field label="Monto (S/)" required error={errors.amount}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
        </Field>
        <Field label="Cierre esperado" error={errors.expected_close_date}>
          <input className={INPUT} type="date" value={close} onChange={(e) => setClose(e.target.value)} />
        </Field>
        <Field label="Fuente" error={errors.source_id}>
          <PickSelect
            className={INPUT}
            ariaLabel="Fuente"
            value={sourceId}
            onChange={setSourceId}
            options={[["", "Cliente existente"], ...sources.filter((source) => source.code !== "CLIENTE_EXISTENTE").map((source): [string, string] => [source.id, source.name])]}
          />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Cerrar una oportunidad: ganada exige evidencia; perdida exige motivo. */
export function CloseOpportunityModal({
  opportunity, outcome, onClose, onClosed, toast,
}: {
  opportunity: Pick<Opportunity, "id" | "name" | "expected_value">;
  outcome: "WON" | "LOST";
  onClose: () => void;
  onClosed: (opportunity: OpportunityDetail) => void;
  toast: Toast;
}) {
  const won = outcome === "WON";
  const [evidence, setEvidence] = useState("");
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function save() {
    const value = (won ? evidence : reason).trim();
    if (!value) {
      setError(won ? "Registra la evidencia de cierre (orden de compra, correo, acta)." : "El motivo de pérdida es obligatorio.");
      return;
    }
    setSaving(true);
    try {
      const updated = await apiFetch<OpportunityDetail>(`/v1/crm/opportunities/${opportunity.id}/${won ? "win" : "lose"}`, {
        method: "POST",
        body: JSON.stringify(won ? { evidence: value } : { reason: value }),
      });
      toast(won ? "Oportunidad ganada" : "Oportunidad marcada como perdida");
      onClosed(updated);
    } catch (err) {
      toast(firstError(err, "No se pudo cerrar la oportunidad."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={won ? "Marcar como ganada" : "Marcar como perdida"}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn variant={won ? "primary" : "danger"} onClick={save} disabled={saving} permission="opportunities.manage">Confirmar</Btn>
        </>
      }
    >
      <p className="text-[15px]">{opportunity.name} · {money(opportunity.expected_value)}</p>
      <div className="mt-3">
        {won ? (
          <>
            <Field label="Evidencia de cierre" required error={error}>
              <textarea
                className={INPUT}
                rows={3}
                value={evidence}
                onChange={(e) => setEvidence(e.target.value)}
                placeholder="Ej.: Orden de compra OC-2291 recibida por correo"
                maxLength={2000}
                autoFocus
              />
            </Field>
            <p className="app-muted mt-2 text-xs">Se registra fecha, responsable y evidencia. Luego podrás convertirla en cliente.</p>
          </>
        ) : (
          <Field label="Motivo de pérdida" required error={error}>
            <PickSelect
              className={INPUT}
              ariaLabel="Motivo de pérdida"
              value={reason}
              onChange={setReason}
              options={[["", "Seleccionar…"], ...LOSS_REASONS.map((item): [string, string] => [item, item])]}
            />
          </Field>
        )}
      </div>
    </Modal>
  );
}

const RADIO_CARD = "flex items-start gap-3 rounded-xl border p-3";

/**
 * Convertir una oportunidad ganada en cliente: reutiliza una identidad que ya existe en
 * Clientes y actores o crea la organización. Nunca un cliente paralelo.
 */
export function ConvertOpportunityModal({
  opportunity, onClose, onConverted, toast,
}: {
  opportunity: OpportunityDetail;
  onClose: () => void;
  onConverted: (opportunity: OpportunityDetail) => void;
  toast: Toast;
}) {
  const { can, loading: accessLoading } = useAccess();
  const cannotCreate = useDenied("core.manage");
  const canSearch = !accessLoading && can("core.view");
  // La oportunidad ya es de un cliente: se convierte con ese.
  const fixed = Boolean(opportunity.customer_id);

  const [candidates, setCandidates] = useState<ConversionCandidate[]>(opportunity.conversion_candidates);
  const [mode, setMode] = useState<"REUSE" | "CREATE">(opportunity.conversion_candidates.length || cannotCreate ? "REUSE" : "CREATE");
  const [partyId, setPartyId] = useState(opportunity.conversion_candidates[0]?.id ?? "");
  const [legalName, setLegalName] = useState(opportunity.lead?.organization_name ?? "");
  const [ruc, setRuc] = useState("");
  const [sector, setSector] = useState("");
  const [others, setOthers] = useState<SelectOption[]>([]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // Además de las coincidencias, se puede buscar cualquier identidad ya registrada.
  useEffect(() => {
    if (!canSearch || fixed) return;
    let cancelled = false;
    apiFetch<PaginatedResponse<Actor>>(`/v1/parties?per_page=${SELECT_LIMIT}`)
      .then((page) => {
        if (cancelled) return;
        setOthers(page.data.map((actor) => ({
          value: actor.id,
          label: actor.name,
          hint: actor.organization ? `RUC ${actor.organization.tax_identifier || "—"}` : `${actor.person?.document_type ?? ""} ${actor.person?.document_number ?? ""}`.trim(),
        })));
      })
      .catch(() => undefined);
    return () => { cancelled = true; };
  }, [canSearch, fixed]);

  const reuseOptions: SelectOption[] = [
    ...candidates.map((candidate) => ({
      value: candidate.id,
      label: candidate.name,
      hint: `${candidate.document} · ${candidate.is_customer ? "ya es cliente" : "aún no es cliente"} · coincidencia`,
    })),
    ...others.filter((option) => !candidates.some((candidate) => candidate.id === option.value)),
  ];
  const canReuse = reuseOptions.length > 0;

  async function save() {
    const found: Record<string, string> = {};
    if (mode === "REUSE" && !partyId) found.party_id = "Selecciona la identidad existente a reutilizar.";
    if (mode === "CREATE") {
      if (!legalName.trim()) found.legal_name = "Indica la razón social.";
      if (!/^(10|20)\d{9}$/.test(ruc.trim())) found.tax_identifier = "RUC inválido (11 dígitos).";
    }
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const updated = await apiFetch<OpportunityDetail>(`/v1/crm/opportunities/${opportunity.id}/convert`, {
        method: "POST",
        body: JSON.stringify(
          mode === "REUSE"
            ? { mode, party_id: partyId }
            : { mode, legal_name: legalName.trim(), tax_identifier: ruc.trim(), sector: sector.trim() || null },
        ),
      });
      toast(mode === "REUSE" ? "Conversión registrada: se reutilizó el cliente existente" : "Cliente creado en CORE y conversión registrada");
      onConverted(updated);
    } catch (err) {
      // Ya existe: se ofrece reutilizarla en vez de crear otra.
      const duplicates = err instanceof ApiError && err.status === 409 ? ((err.body as { duplicates?: ActorDuplicate[] } | null)?.duplicates ?? []) : [];
      if (duplicates.length) {
        setCandidates((list) => [
          ...duplicates.filter((item) => !list.some((candidate) => candidate.id === item.id))
            .map((item) => ({ id: item.id, name: item.name, document: `RUC ${ruc.trim()}`, is_customer: item.roles.includes("CUSTOMER") })),
          ...list,
        ]);
        setPartyId(duplicates[0].id);
        setMode("REUSE");
        toast("Esa organización ya existe en CORE", "warning", "Quedó seleccionada para reutilizarla.");
      } else {
        setErrors(fieldErrors(err));
        toast(firstError(err, "No se pudo convertir la oportunidad."), "danger");
      }
    } finally {
      setSaving(false);
    }
  }

  const on = "border-[#1B8FC9] bg-[color-mix(in_srgb,var(--p-brand-soft)_50%,transparent)]";
  const off = "border-[color:var(--app-border)]";

  return (
    <Modal
      title="Convertir oportunidad en cliente CORE"
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="opportunities.manage">Convertir</Btn>
        </>
      }
    >
      <p className="mb-3">La conversión se registra una sola vez: si se repite, no crea un segundo cliente.</p>
      <fieldset className="grid gap-2.5">
        <legend className="sr-only">Cómo convertir</legend>

        <div className={`${RADIO_CARD} ${mode === "REUSE" ? on : off} ${canReuse ? "" : "opacity-55"}`}>
          <input
            type="radio"
            name="convert-mode"
            id="convert-reuse"
            className="mt-1 accent-[#1B8FC9]"
            checked={mode === "REUSE"}
            disabled={!canReuse}
            onChange={() => setMode("REUSE")}
          />
          <div className="grid min-w-0 flex-1 gap-1.5">
            <label htmlFor="convert-reuse" className="cursor-pointer font-semibold">Reutilizar una identidad existente</label>
            <small className="app-muted">
              {fixed
                ? "La oportunidad ya es de este cliente."
                : candidates.length
                  ? "Coincidencias en CORE:"
                  : canReuse
                    ? "No se encontraron coincidencias en CORE; puedes buscar la identidad."
                    : "No se encontraron coincidencias en CORE."}
            </small>
            {canReuse && (
              <Field label="Identidad" error={errors.party_id}>
                <SearchSelect
                  value={partyId}
                  onChange={(value) => {
                    setPartyId(value);
                    setMode("REUSE");
                  }}
                  options={reuseOptions}
                  placeholder="Buscar en Clientes y actores…"
                  searchPlaceholder="Nombre o documento…"
                  disabled={fixed}
                />
              </Field>
            )}
          </div>
        </div>

        <div className={`${RADIO_CARD} ${mode === "CREATE" ? on : off} ${cannotCreate || fixed ? "opacity-55" : ""}`} title={cannotCreate}>
          <input
            type="radio"
            name="convert-mode"
            id="convert-create"
            className="mt-1 accent-[#1B8FC9]"
            checked={mode === "CREATE"}
            disabled={Boolean(cannotCreate) || fixed}
            onChange={() => setMode("CREATE")}
          />
          <div className="grid min-w-0 flex-1 gap-1.5">
            <label htmlFor="convert-create" className="flex cursor-pointer items-center gap-1.5 font-semibold">
              Crear organización + cliente
              {cannotCreate && <Lock size={13} aria-hidden="true" />}
            </label>
            <small className="app-muted">
              {cannotCreate ?? "Se crea la organización con rol Cliente y, si el lead tiene contacto, una persona con rol Contacto."}
            </small>
            {mode === "CREATE" && (
              <FormGrid>
                <Field label="Razón social" required error={errors.legal_name}>
                  <input className={INPUT} value={legalName} onChange={(e) => setLegalName(e.target.value)} maxLength={255} />
                </Field>
                <Field label="RUC" required error={errors.tax_identifier}>
                  <input className={INPUT} value={ruc} onChange={(e) => setRuc(e.target.value)} placeholder="20XXXXXXXXX" inputMode="numeric" maxLength={11} />
                </Field>
                <Field label="Sector" error={errors.sector}>
                  <input className={INPUT} value={sector} onChange={(e) => setSector(e.target.value)} maxLength={150} />
                </Field>
              </FormGrid>
            )}
          </div>
        </div>
      </fieldset>
    </Modal>
  );
}
