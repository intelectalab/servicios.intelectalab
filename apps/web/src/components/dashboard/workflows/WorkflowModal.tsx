"use client";

import { useState } from "react";
import { Plus, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { Btn, Field, INPUT, Modal, Notice } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { WorkflowAction, WorkflowCondition, WorkflowRule, WorkflowsData } from "@/lib/workflows";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

export type WorkflowDraft = { id?: string; name: string; trigger: string; condition: WorkflowCondition; actions: WorkflowAction[] };

const SECTION = "rounded-[10px] border border-[color:var(--app-border)] p-3";
const STEP = "mb-2 text-[11px] font-semibold uppercase tracking-[.05em] text-[color:var(--p-brand-text)]";

/** Nueva regla o edición: Cuando (disparador) → Si (condición del catálogo) → Entonces (1 a 3 acciones). */
export function WorkflowModal({
  data, draft, onClose, onSaved, toast,
}: { data: WorkflowsData; draft: WorkflowDraft; onClose: () => void; onSaved: (rule: WorkflowRule) => void; toast: Toast }) {
  const [name, setName] = useState(draft.name);
  const [trigger, setTrigger] = useState(draft.trigger);
  const [field, setField] = useState(draft.condition?.field ?? "");
  const [op, setOp] = useState(draft.condition?.op ?? "");
  const [value, setValue] = useState(draft.condition ? String(draft.condition.value) : "");
  const [actions, setActions] = useState<WorkflowAction[]>(draft.actions.length ? draft.actions : [{ type: "notify_owner", text: "" }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const definition = data.catalog.triggers.find((t) => t.key === trigger);
  const fieldDef = definition?.fields.find((f) => f.key === field);
  const operators = fieldDef ? Object.entries(data.catalog.operators[fieldDef.type]) : [];

  function changeTrigger(next: string) {
    setTrigger(next);
    // Los campos son de cada disparador: al cambiarlo, la condición vuelve a "Siempre".
    setField("");
    setOp("");
    setValue("");
    const target = data.catalog.triggers.find((t) => t.key === next);
    if (target && !target.customer) setActions((list) => list.map((a) => (a.type === "portal_notice" ? { type: "notify_owner", text: a.text } : a)));
  }

  function changeField(next: string) {
    setField(next);
    const target = definition?.fields.find((f) => f.key === next);
    setOp(target ? Object.keys(data.catalog.operators[target.type])[0] : "");
    setValue(target?.type === "choice" ? target.options[0]?.value ?? "" : target?.type === "boolean" ? "true" : "");
  }

  const updateAction = (index: number, patch: Partial<WorkflowAction>) => setActions((list) => list.map((a, i) => (i === index ? { ...a, ...patch } : a)));

  async function save() {
    const local: Record<string, string> = {};
    if (!name.trim()) local.name = "Indica un nombre.";
    if (!trigger) local.trigger = "Elige cuándo se dispara.";
    if (fieldDef?.type === "number" && (value.trim() === "" || Number.isNaN(Number(value)))) local["condition.value"] = "Escribe un número.";
    actions.forEach((action, index) => {
      if (!action.text.trim()) local[`actions.${index}.text`] = "Escribe el texto del aviso.";
      if (action.type === "notify_role" && !action.role_id) local[`actions.${index}.role_id`] = "Elige un rol.";
    });
    setErrors(local);
    if (Object.keys(local).length) return;

    setBusy(true);
    try {
      const body = {
        name: name.trim(),
        trigger,
        condition: field ? { field, op, value: fieldDef?.type === "number" ? Number(value) : fieldDef?.type === "boolean" ? value === "true" : value } : null,
        actions: actions.map((a) => ({ type: a.type, role_id: a.type === "notify_role" ? a.role_id : undefined, text: a.text.trim() })),
      };
      const saved = await apiFetch<WorkflowRule>(draft.id ? `/v1/workflows/${draft.id}` : "/v1/workflows", {
        method: draft.id ? "PUT" : "POST",
        body: JSON.stringify(body),
      });
      toast(draft.id ? "Workflow actualizado" : "Workflow creado y activo");
      onSaved(saved);
      onClose();
    } catch (err) {
      const fields = fieldErrors(err);
      setErrors(fields);
      if (!Object.keys(fields).length) toast(firstError(err, "No se pudo guardar el workflow."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const actionTypes = (Object.entries(data.catalog.actions) as [WorkflowAction["type"], string][])
    .filter(([type]) => type !== "portal_notice" || definition?.customer !== false);

  return (
    <Modal
      title={draft.id ? "Editar workflow" : "Nuevo workflow"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy}>{draft.id ? "Guardar cambios" : "Crear workflow"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <Field label="Nombre" required error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={150} disabled={busy} autoFocus placeholder="Ej.: Ticket crítico: avisar a Gerencia" />
        </Field>

        <section className={SECTION}>
          <p className={STEP}>Cuando</p>
          <Field label="Ocurre" required error={errors.trigger}>
            <PickSelect
              className={INPUT} ariaLabel="Disparador" value={trigger} onChange={changeTrigger} disabled={busy} searchable
              options={data.catalog.triggers.map((t): [string, string] => [t.key, t.available ? t.label : `${t.label} (módulo apagado)`])}
            />
          </Field>
          {definition && !definition.available && <div className="mt-2"><Notice tone="warn">El módulo de este disparador está apagado en tu plan: la regla no correrá hasta que se active.</Notice></div>}
        </section>

        <section className={SECTION}>
          <p className={STEP}>Si</p>
          <div className="grid gap-3 md:grid-cols-3">
            <Field label="Campo" error={errors["condition.field"]}>
              <PickSelect
                className={INPUT} ariaLabel="Campo de la condición" value={field} onChange={changeField} disabled={busy || !definition?.fields.length}
                options={[["", "Siempre (sin condición)"], ...(definition?.fields ?? []).map((f): [string, string] => [f.key, f.label])]}
              />
            </Field>
            {fieldDef && (
              <>
                <Field label="Operador" error={errors["condition.op"]}>
                  <PickSelect className={INPUT} ariaLabel="Operador" value={op} onChange={setOp} disabled={busy} options={operators.map(([key, label]): [string, string] => [key, label])} />
                </Field>
                <Field label="Valor" error={errors["condition.value"]}>
                  {fieldDef.type === "number" ? (
                    <input className={INPUT} type="number" step="any" value={value} onChange={(e) => setValue(e.target.value)} disabled={busy} />
                  ) : fieldDef.type === "boolean" ? (
                    <PickSelect className={INPUT} ariaLabel="Valor" value={value} onChange={setValue} disabled={busy} options={[["true", "Sí"], ["false", "No"]]} />
                  ) : (
                    <PickSelect className={INPUT} ariaLabel="Valor" value={value} onChange={setValue} disabled={busy} options={fieldDef.options.map((o): [string, string] => [o.value, o.label])} />
                  )}
                </Field>
              </>
            )}
          </div>
        </section>

        <section className={SECTION}>
          <p className={STEP}>Entonces</p>
          <div className="grid gap-3">
            {actions.map((action, index) => (
              <div key={index} className="grid items-start gap-2 md:grid-cols-[200px_180px_1fr_auto]">
                <Field label={`Acción ${index + 1}`} error={errors[`actions.${index}.type`]}>
                  <PickSelect
                    className={INPUT} ariaLabel={`Acción ${index + 1}`} value={action.type} disabled={busy}
                    onChange={(type) => updateAction(index, { type: type as WorkflowAction["type"], role_id: type === "notify_role" ? action.role_id : undefined })}
                    options={actionTypes.map(([type, label]): [string, string] => [type, label])}
                  />
                </Field>
                {action.type === "notify_role" ? (
                  <Field label="Rol" error={errors[`actions.${index}.role_id`]}>
                    <PickSelect
                      className={INPUT} ariaLabel={`Rol de la acción ${index + 1}`} value={action.role_id ?? ""} disabled={busy}
                      onChange={(roleId) => updateAction(index, { role_id: roleId })}
                      options={[["", "Elige un rol"], ...data.roles.map((r): [string, string] => [r.id, r.name])]}
                    />
                  </Field>
                ) : (
                  <div className="hidden md:block" />
                )}
                <Field label={action.type === "portal_notice" ? "Texto que verá el cliente" : "Texto del aviso"} required error={errors[`actions.${index}.text`]}>
                  <input className={INPUT} value={action.text} onChange={(e) => updateAction(index, { text: e.target.value })} maxLength={300} disabled={busy} />
                </Field>
                <div className="md:pt-[22px]">
                  <Btn variant="ghost" size="sm" onClick={() => setActions((list) => list.filter((_, i) => i !== index))} disabled={busy || actions.length === 1} title="Quitar esta acción" aria-label={`Quitar la acción ${index + 1}`}>
                    <Trash2 size={14} />
                  </Btn>
                </div>
              </div>
            ))}
            {actions.length < 3 && (
              <div>
                <Btn variant="secondary" size="sm" onClick={() => setActions((list) => [...list, { type: "notify_owner", text: "" }])} disabled={busy}>
                  <Plus size={14} />
                  <span>Agregar acción</span>
                </Btn>
              </div>
            )}
          </div>
          <p className="app-muted mt-3 text-xs">Los avisos llegan solo a quien puede ver ese registro, por la campana y por correo según lo que cada persona eligió en Mi perfil. Los eventos facturables de las OT se crean solos: no hace falta una regla.</p>
        </section>
      </div>
    </Modal>
  );
}
