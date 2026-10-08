"use client";

import { useState, type FormEvent } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { ACTOR_ENTITY, DOCUMENT_TYPES, ROLE_LABELS } from "@/lib/actors";
import { PickSelect } from "@/components/ui/pick-select";
import { Btn, Field, FormGrid, INPUT, Modal, Notice } from "@/components/ui/proto";
import type { ActorDetail, ActorDuplicate, ActorKind, ActorRole } from "@/types/actor";
import type { CustomFieldDefinition } from "@/types/custom-field";

const ROLES: ActorRole[] = ["CUSTOMER", "SUPPLIER", "EMPLOYEE"];
const CHECK = "h-[17px] w-[17px] accent-[#1B8FC9]";

type Values = Record<string, string>;

function initialValues(kind: ActorKind, actor?: ActorDetail): Values {
  if (kind === "ORGANIZATION") {
    return {
      legal_name: actor?.organization?.legal_name ?? "",
      trade_name: actor?.organization?.trade_name ?? "",
      tax_identifier: actor?.organization?.tax_identifier ?? "",
      sector: actor?.sector ?? "",
      sla_level: actor?.sla_level ?? "ESTANDAR",
      email: actor?.email ?? "",
      phone: actor?.phone ?? "",
      address: actor?.address ?? "",
    };
  }
  return {
    given_names: actor?.person?.given_names ?? "",
    family_name: actor?.person?.family_name ?? "",
    document_type: actor?.person?.document_type ?? "DNI",
    document_number: actor?.person?.document_number ?? "",
    email: actor?.email ?? "",
    phone: actor?.phone ?? "",
    position: actor?.position ?? "",
  };
}

/** Alta o edición de una organización o persona, con sus roles. No deja duplicar identidades. */
export function ActorForm({
  kind, actor, customFields = [], customValues = {}, onClose, onSaved, onOpenExisting,
}: {
  kind: ActorKind;
  /** Al editar. */
  actor?: ActorDetail;
  /** Columnas personalizadas de la tabla: también se llenan aquí. */
  customFields?: CustomFieldDefinition[];
  /** Sus valores actuales, al editar. */
  customValues?: Record<string, string>;
  onClose: () => void;
  onSaved: (actor: ActorDetail, message: string) => void;
  /** Abrir la identidad que ya existía en lugar de crear otra. */
  onOpenExisting: (id: string, message?: string) => void;
}) {
  const isOrg = kind === "ORGANIZATION";
  const [values, setValues] = useState<Values>(() => initialValues(kind, actor));
  const [roles, setRoles] = useState<ActorRole[]>(actor?.roles ?? ["CUSTOMER"]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [duplicates, setDuplicates] = useState<ActorDuplicate[]>([]);
  const [custom, setCustom] = useState<Record<string, string>>(() => ({ ...customValues }));
  const [saving, setSaving] = useState(false);

  const set = (key: string) => (event: { target: { value: string } }) => setValues((current) => ({ ...current, [key]: event.target.value }));
  const availableRoles = ROLES.filter((role) => isOrg ? role !== "EMPLOYEE" : true);

  function validate() {
    const found: Record<string, string> = {};
    if (isOrg) {
      if (!values.legal_name.trim()) found.legal_name = "Este dato es obligatorio.";
      if (!/^(10|20)\d{9}$/.test(values.tax_identifier.trim())) found.tax_identifier = "RUC inválido (11 dígitos).";
    } else {
      if (!values.given_names.trim()) found.given_names = "Este dato es obligatorio.";
      if (!values.family_name.trim()) found.family_name = "Este dato es obligatorio.";
    }
    if (!roles.length) found.roles = "Selecciona al menos un rol.";
    return found;
  }

  /** Guarda las columnas personalizadas que cambiaron. */
  async function saveCustom(partyId: string) {
    const changed = customFields.filter((field) => (custom[field.id] ?? "") !== (customValues[field.id] ?? ""));
    await Promise.all(changed.map((field) => apiFetch("/v1/custom-fields/values", {
      method: "POST",
      body: JSON.stringify({ field_id: field.id, entity_type: ACTOR_ENTITY, entity_id: partyId, value: custom[field.id] ?? "" }),
    })));
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const found = validate();
    setErrors(found);
    setDuplicates([]);
    if (Object.keys(found).length) return;

    const fields = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value.trim()]));
    // El cargo es un dato del empleado.
    if (!isOrg && !roles.includes("EMPLOYEE")) delete fields.position;

    setSaving(true);
    try {
      if (actor) {
        let saved = await apiFetch<ActorDetail>(`/v1/parties/${actor.id}`, { method: "PUT", body: JSON.stringify(fields) });
        for (const role of roles.filter((item) => !actor.roles.includes(item))) {
          saved = await apiFetch<ActorDetail>(`/v1/parties/${actor.id}/roles`, { method: "POST", body: JSON.stringify({ role }) });
        }
        await saveCustom(actor.id);
        onSaved(saved, "Party actualizada");
      } else {
        const saved = await apiFetch<ActorDetail>("/v1/parties", { method: "POST", body: JSON.stringify({ ...fields, kind, roles }) });
        // La identidad ya existe: si un campo personalizado falla, se completa luego desde la tabla.
        await saveCustom(saved.id).catch(() => undefined);
        onSaved(saved, "Party creada en CORE");
      }
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setDuplicates(((err.body as { duplicates?: ActorDuplicate[] } | undefined)?.duplicates) ?? []);
      } else if (err instanceof ApiError && err.errors) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([field, messages]) => [field.split(".")[0], messages[0]])));
      } else {
        setErrors({ form: err instanceof ApiError ? err.message : "No se pudo guardar." });
      }
      setSaving(false);
    }
  }

  async function addRole(duplicate: ActorDuplicate, role: ActorRole) {
    setSaving(true);
    try {
      await apiFetch(`/v1/parties/${duplicate.id}/roles`, { method: "POST", body: JSON.stringify({ role }) });
      onOpenExisting(duplicate.id, "Rol agregado a la Party existente");
    } catch (err) {
      setErrors({ form: err instanceof ApiError ? err.message : "No se pudo agregar el rol." });
      setSaving(false);
    }
  }

  const title = actor ? `Editar ${actor.name}` : isOrg ? "Nueva organización" : "Nueva persona";

  return (
    <Modal title={title} size="lg" onClose={onClose}>
      <form onSubmit={submit} noValidate>
        <FormGrid>
          {isOrg ? (
            <>
              <Field label="Razón social" required span={2} error={errors.legal_name}>
                <input className={INPUT} value={values.legal_name} onChange={set("legal_name")} autoFocus />
              </Field>
              <Field label="Nombre comercial" error={errors.trade_name}>
                <input className={INPUT} value={values.trade_name} onChange={set("trade_name")} />
              </Field>
              <Field label="RUC" required error={errors.tax_identifier} hint="Se valida duplicidad por RUC y nombre">
                <input className={INPUT} value={values.tax_identifier} onChange={set("tax_identifier")} placeholder="20XXXXXXXXX" inputMode="numeric" maxLength={11} />
              </Field>
              <Field label="Sector" error={errors.sector}>
                <input className={INPUT} value={values.sector} onChange={set("sector")} />
              </Field>
              <Field label="Nivel de SLA" error={errors.sla_level}>
                <PickSelect
                  className={INPUT}
                  value={values.sla_level}
                  onChange={(value) => setValues((current) => ({ ...current, sla_level: value }))}
                  options={[["ESTANDAR", "Estándar"], ["PREMIUM", "Premium"]]}
                />
              </Field>
              <Field label="Correo" error={errors.email}>
                <input className={INPUT} type="email" value={values.email} onChange={set("email")} />
              </Field>
              <Field label="Teléfono" error={errors.phone}>
                <input className={INPUT} value={values.phone} onChange={set("phone")} />
              </Field>
              <Field label="Dirección fiscal" span={2} error={errors.address}>
                <input className={INPUT} value={values.address} onChange={set("address")} />
              </Field>
            </>
          ) : (
            <>
              <Field label="Nombres" required error={errors.given_names}>
                <input className={INPUT} value={values.given_names} onChange={set("given_names")} autoFocus />
              </Field>
              <Field label="Apellidos" required error={errors.family_name}>
                <input className={INPUT} value={values.family_name} onChange={set("family_name")} />
              </Field>
              <Field label="Tipo doc." error={errors.document_type}>
                <PickSelect
                  className={INPUT}
                  value={values.document_type}
                  onChange={(value) => setValues((current) => ({ ...current, document_type: value }))}
                  options={DOCUMENT_TYPES}
                />
              </Field>
              <Field label="Número de documento" error={errors.document_number}>
                <input className={INPUT} value={values.document_number} onChange={set("document_number")} />
              </Field>
              <Field label="Correo" error={errors.email}>
                <input className={INPUT} type="email" value={values.email} onChange={set("email")} />
              </Field>
              <Field label="Teléfono" error={errors.phone}>
                <input className={INPUT} value={values.phone} onChange={set("phone")} />
              </Field>
              <Field label="Cargo" error={errors.position} hint={roles.includes("EMPLOYEE") ? undefined : "Se guarda con el rol Empleado"}>
                <input className={INPUT} value={values.position} onChange={set("position")} disabled={!roles.includes("EMPLOYEE")} />
              </Field>
            </>
          )}
        </FormGrid>

        {customFields.length > 0 && (
          <div className="mt-3.5">
            <p className="app-muted mb-2 text-[11px] font-semibold uppercase tracking-[.06em]">Columnas personalizadas</p>
            <FormGrid>
              {customFields.map((field) => (
                field.field_type === "boolean" ? (
                  <label key={field.id} className="flex cursor-pointer items-center gap-2 self-end pb-2.5">
                    <input
                      type="checkbox"
                      className={CHECK}
                      checked={custom[field.id] === "true"}
                      onChange={(event) => setCustom((current) => ({ ...current, [field.id]: String(event.target.checked) }))}
                    />
                    <span className="text-[12.5px] font-medium">{field.label}</span>
                  </label>
                ) : (
                  <Field key={field.id} label={field.label}>
                    <input
                      className={INPUT}
                      type={field.field_type}
                      value={custom[field.id] ?? ""}
                      onChange={(event) => setCustom((current) => ({ ...current, [field.id]: event.target.value }))}
                    />
                  </Field>
                )
              ))}
            </FormGrid>
          </div>
        )}

        <fieldset className="mt-3.5 rounded-[7px] border border-[color:var(--app-border)] px-3 pb-2.5 pt-2">
          <legend className="px-1 text-[12.5px] font-medium">Roles en CORE <em className="not-italic text-[#D93A40]">*</em></legend>
          <div className="flex flex-wrap gap-x-5 gap-y-2">
            {availableRoles.map((role) => {
              // Un rol que la identidad ya tiene no se quita desde aquí.
              const locked = Boolean(actor?.roles.includes(role));
              return (
                <label key={role} className={`flex items-center gap-2 ${locked ? "opacity-70" : "cursor-pointer"}`}>
                  <input
                    type="checkbox"
                    className={CHECK}
                    checked={roles.includes(role)}
                    disabled={locked}
                    onChange={(event) => setRoles((current) => (event.target.checked ? [...current, role] : current.filter((item) => item !== role)))}
                  />
                  <span>{ROLE_LABELS[role]}</span>
                </label>
              );
            })}
          </div>
          {errors.roles && <small className="mt-1 block text-xs text-[#D93A40]">{errors.roles}</small>}
        </fieldset>

        {duplicates.length > 0 && (
          <div className="mt-3.5">
            <Notice tone="warn">
              <strong className="font-semibold">Ya existe en CORE</strong>
              <p>Para no duplicar identidades, reutiliza la existente o agrégale el rol.</p>
              {duplicates.map((duplicate) => (
                <div key={duplicate.id} className="mt-2 flex flex-wrap items-center gap-1.5">
                  <span>{duplicate.name} · {duplicate.roles.map((role) => ROLE_LABELS[role]).join(", ") || "sin roles"}</span>
                  <Btn variant="ghost" size="sm" onClick={() => onOpenExisting(duplicate.id)}>Abrir</Btn>
                  {roles.filter((role) => !duplicate.roles.includes(role)).map((role) => (
                    <Btn key={role} variant="secondary" size="sm" permission="core.manage" disabled={saving} onClick={() => addRole(duplicate, role)}>
                      Agregar rol {ROLE_LABELS[role]}
                    </Btn>
                  ))}
                </div>
              ))}
            </Notice>
          </div>
        )}

        {errors.form && <p className="mt-3 text-[12.5px] text-[#D93A40]">{errors.form}</p>}

        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar en CORE"}</Btn>
        </div>
      </form>
    </Modal>
  );
}
