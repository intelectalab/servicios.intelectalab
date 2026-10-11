"use client";

import { useState } from "react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { Btn, Field, FormGrid, INPUT, Modal } from "@/components/ui/proto";
import type { Campaign, CampaignDetail } from "@/types/crm";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Alta o edición de una campaña. */
export function CampaignFormModal({
  campaign, onClose, onSaved, toast,
}: {
  campaign?: Campaign;
  onClose: () => void;
  onSaved: (campaign: CampaignDetail) => void;
  toast: Toast;
}) {
  const [name, setName] = useState(campaign?.name ?? "");
  const [channel, setChannel] = useState(campaign?.channel ?? "");
  const [start, setStart] = useState(campaign?.starts_at ?? "");
  const [end, setEnd] = useState(campaign?.ends_at ?? "");
  const [budget, setBudget] = useState(campaign ? String(campaign.budget) : "");
  const [consent, setConsent] = useState(campaign?.consent_required ?? true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    const found: Record<string, string> = {};
    if (!name.trim()) found.name = "El nombre es obligatorio.";
    if (start && end && end < start) found.ends_at = "El fin no puede ser anterior al inicio.";
    if (budget !== "" && !(Number(budget) >= 0)) found.budget = "El presupuesto no puede ser negativo.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<CampaignDetail>(campaign ? `/v1/crm/campaigns/${campaign.id}` : "/v1/crm/campaigns", {
        method: campaign ? "PUT" : "POST",
        body: JSON.stringify({
          name: name.trim(),
          channel: channel.trim() || null,
          starts_at: start || null,
          ends_at: end || null,
          budget: budget === "" ? 0 : Number(budget),
          consent_required: consent,
        }),
      });
      toast(campaign ? "Campaña actualizada" : "Campaña creada");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar la campaña."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={campaign ? `Editar campaña ${campaign.code}` : "Nueva campaña"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="campaigns.manage">{campaign ? "Guardar" : "Crear"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Nombre" required span={2} error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Canal" error={errors.channel}>
          <input className={INPUT} value={channel} onChange={(e) => setChannel(e.target.value)} maxLength={100} placeholder="Evento, correo, redes…" />
        </Field>
        <Field label="Inicio" error={errors.starts_at}>
          <input className={INPUT} type="date" value={start} onChange={(e) => setStart(e.target.value)} />
        </Field>
        <Field label="Fin" error={errors.ends_at}>
          <input className={INPUT} type="date" value={end} min={start || undefined} onChange={(e) => setEnd(e.target.value)} />
        </Field>
        <Field label="Presupuesto (S/)" error={errors.budget}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={budget} onChange={(e) => setBudget(e.target.value)} />
        </Field>
        <div className="md:col-span-3">
          <label className="flex cursor-pointer items-start gap-2 text-[13px]">
            <input
              type="checkbox"
              checked={consent}
              onChange={(e) => setConsent(e.target.checked)}
              className="mt-0.5 h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9]"
            />
            <span>
              Exigir el consentimiento registrado de cada miembro
              <small className="app-muted block text-xs">Sin él, un lead no se puede agregar a la campaña.</small>
            </span>
          </label>
        </div>
      </FormGrid>
    </Modal>
  );
}
