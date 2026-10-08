"use client";

import { useState, type FormEvent } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { BILLING_MODES } from "@/lib/commercial";
import { Btn, Field, FormGrid, INPUT, Modal } from "@/components/ui/proto";
import type { BillingMode, Service, ServiceCategory } from "@/types/commercial";

const NEW_CATEGORY = "__new__";

/** Alta y edición de un servicio; al editar no se toca el precio (tiene sus propias versiones). */
export default function ServiceFormModal({
  service, categories, suggestedCode = "", onClose, onSaved, onCategoryCreated,
}: {
  service?: Service;
  categories: ServiceCategory[];
  suggestedCode?: string;
  onClose: () => void;
  onSaved: (service: Service) => void;
  onCategoryCreated: (category: ServiceCategory) => void;
}) {
  const [form, setForm] = useState({
    code: service?.code ?? suggestedCode,
    name: service?.name ?? "",
    category_id: service?.category_id ?? "",
    unit: service ? service.unit ?? "" : "servicio",
    billing_mode: (service?.billing_mode ?? "ONE_TIME") as BillingMode,
    frequency: service?.recurrence?.frequency ?? "",
    warranty_months: String(service?.warranty_months ?? 0),
    price: "",
    description: service?.description ?? "",
  });
  const [newCategory, setNewCategory] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState("");
  const [saving, setSaving] = useState(false);
  const [creatingCategory, setCreatingCategory] = useState(false);

  const set = (key: keyof typeof form) => (value: string) => setForm((current) => ({ ...current, [key]: value }));

  function readErrors(err: unknown, fallback: string) {
    if (err instanceof ApiError && err.errors) {
      const found = Object.fromEntries(Object.entries(err.errors).map(([key, messages]) => [key, messages[0]]));
      setErrors(found);
      // Errores que no pertenecen a un campo del formulario (p. ej. el estado).
      if (found.status) setFormError(found.status);
      return;
    }
    setFormError(err instanceof ApiError ? err.message : fallback);
  }

  async function createCategory() {
    const name = newCategory.trim();
    if (!name) {
      setErrors({ category_name: "Escribe el nombre de la categoría." });
      return;
    }

    setCreatingCategory(true);
    setErrors({});
    try {
      const category = await apiFetch<ServiceCategory>("/v1/service-categories", {
        method: "POST",
        body: JSON.stringify({ name }),
      });
      onCategoryCreated(category);
      set("category_id")(category.id);
      setNewCategory("");
    } catch (err) {
      if (err instanceof ApiError && err.errors?.name) setErrors({ category_name: err.errors.name[0] });
      else readErrors(err, "No se pudo crear la categoría.");
    } finally {
      setCreatingCategory(false);
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    const found: Record<string, string> = {};
    if (form.category_id === NEW_CATEGORY) found.category_name = "Crea la categoría o elige una existente.";
    else if (!form.category_id) found.category_id = "Código, nombre y categoría son obligatorios.";
    if (form.billing_mode === "RECURRING" && !form.frequency) found.frequency = "Un servicio recurrente necesita frecuencia.";
    setErrors(found);
    setFormError("");
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<Service>(service ? `/v1/services/${service.id}` : "/v1/services", {
        method: service ? "PUT" : "POST",
        body: JSON.stringify({
          code: form.code.trim(),
          name: form.name.trim(),
          category_id: form.category_id,
          unit: form.unit.trim() || null,
          billing_mode: form.billing_mode,
          frequency: form.billing_mode === "RECURRING" ? form.frequency : null,
          warranty_months: Number(form.warranty_months) || 0,
          description: form.description.trim() || null,
          ...(service ? {} : { price: form.price === "" ? null : Number(form.price) }),
        }),
      });
      onSaved(saved);
    } catch (err) {
      readErrors(err, "No se pudo guardar el servicio.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal title={service ? "Editar servicio" : "Nuevo servicio"} size="lg" onClose={onClose}>
      <form onSubmit={submit}>
        <FormGrid>
          <Field label="Código" required error={errors.code}>
            <input className={INPUT} value={form.code} onChange={(e) => set("code")(e.target.value)} required maxLength={100} />
          </Field>
          <Field label="Nombre" required span={2} error={errors.name}>
            <input className={INPUT} value={form.name} onChange={(e) => set("name")(e.target.value)} required maxLength={255} autoFocus />
          </Field>

          <Field label="Categoría" required error={errors.category_id ?? errors.category_name}>
            <select className={INPUT} value={form.category_id} onChange={(e) => set("category_id")(e.target.value)}>
              <option value="">Seleccionar…</option>
              {categories.map((category) => (
                <option key={category.id} value={category.id}>{category.name}</option>
              ))}
              <option value={NEW_CATEGORY}>+ Nueva categoría…</option>
            </select>
            {form.category_id === NEW_CATEGORY && (
              <span className="flex gap-2">
                <input
                  className={INPUT}
                  value={newCategory}
                  onChange={(e) => setNewCategory(e.target.value)}
                  onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); createCategory(); } }}
                  placeholder="Nombre de la categoría"
                  aria-label="Nombre de la nueva categoría"
                  maxLength={255}
                />
                <Btn variant="secondary" onClick={createCategory} disabled={creatingCategory}>
                  {creatingCategory ? "Creando…" : "Crear"}
                </Btn>
              </span>
            )}
          </Field>
          <Field label="Unidad" error={errors.unit}>
            <input className={INPUT} value={form.unit} onChange={(e) => set("unit")(e.target.value)} maxLength={100} />
          </Field>
          <Field label="Modalidad de facturación" required error={errors.billing_mode}>
            <select className={INPUT} value={form.billing_mode} onChange={(e) => set("billing_mode")(e.target.value)}>
              <option value="ONE_TIME">{BILLING_MODES.ONE_TIME}</option>
              <option value="RECURRING">{BILLING_MODES.RECURRING}</option>
              <option value="PER_EVENT">Por evento / visita / hora</option>
            </select>
          </Field>

          <Field label="Frecuencia (si es recurrente)" error={errors.frequency}>
            <select
              className={INPUT}
              value={form.billing_mode === "RECURRING" ? form.frequency : ""}
              onChange={(e) => set("frequency")(e.target.value)}
              disabled={form.billing_mode !== "RECURRING"}
            >
              <option value="">Seleccionar…</option>
              <option value="MONTHLY">Mensual</option>
              <option value="QUARTERLY">Trimestral</option>
              <option value="YEARLY">Anual</option>
            </select>
          </Field>
          <Field label="Garantía (meses)" error={errors.warranty_months}>
            <input className={INPUT} type="number" min="0" max="120" step="1" value={form.warranty_months} onChange={(e) => set("warranty_months")(e.target.value)} />
          </Field>
          {!service && (
            <Field
              label="Precio inicial (S/, sin IGV)"
              hint="Se crea la versión 1 del precio. Sin precio no se puede activar."
              error={errors.price}
            >
              <input className={INPUT} type="number" min="0" step="0.01" value={form.price} onChange={(e) => set("price")(e.target.value)} />
            </Field>
          )}

          <Field label="Descripción" span={3} error={errors.description}>
            <textarea className={INPUT} rows={3} value={form.description} onChange={(e) => set("description")(e.target.value)} />
          </Field>
        </FormGrid>

        <p className="app-muted mt-3 text-[12.5px]">
          Un servicio nace en BORRADOR. Solo los servicios ACTIVOS se pueden cotizar. El catálogo no factura por sí solo.
        </p>
        {formError && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{formError}</p>}

        <div className="mt-4 flex flex-wrap justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}
