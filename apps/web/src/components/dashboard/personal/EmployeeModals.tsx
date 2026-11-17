"use client";

import { useEffect, useMemo, useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { CONTRACT_TYPES, DOCUMENT_TYPES, HR_MASTERED_NOTE, isMastered, isoDay, money } from "@/lib/personal";
import { Btn, Field, FormGrid, INPUT, Modal, Notice, SearchSelect } from "@/components/ui/proto";
import type { HrEmployee, HrEmployeeOptions } from "@/types/personal";

export type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Versión del registro que se tenía a la vista: si otra persona lo cambió, la API responde 409. */
export const ifMatch = (version: number | undefined) => (version !== undefined ? { "If-Match": String(version) } : undefined);

/** Opciones del formulario de trabajadores (puestos, sedes, usuarios, personas). */
export function useEmployeeOptions(toast: Toast) {
  const [options, setOptions] = useState<HrEmployeeOptions | null>(null);
  useEffect(() => {
    let cancelled = false;
    apiFetch<HrEmployeeOptions>("/v1/hr/employees/options")
      .then((next) => { if (!cancelled) setOptions(next); })
      .catch(() => { if (!cancelled) toast("No se pudieron cargar los puestos, sedes y usuarios.", "danger"); });
    return () => { cancelled = true; };
  }, [toast]);
  return options;
}

type PersonMode = "existing" | "new";

/**
 * Alta o edición de un trabajador. En el alta, la persona se elige de Clientes y actores o se
 * escribe una nueva. Si lo mantiene el SaaS de RRHH, aquí solo se cambian el usuario y la sede.
 */
export function EmployeeFormModal({
  employee, onClose, onSaved, toast,
}: {
  employee?: HrEmployee;
  onClose: () => void;
  onSaved: (employee: HrEmployee) => void;
  toast: Toast;
}) {
  const options = useEmployeeOptions(toast);
  const editing = Boolean(employee);
  const mastered = isMastered(employee?.source, options?.mode);

  const [personMode, setPersonMode] = useState<PersonMode>("existing");
  const [partyId, setPartyId] = useState("");
  const [person, setPerson] = useState({ given_names: "", family_name: "", document_type: "DNI", document_number: "", email: "", phone: "" });
  const [form, setForm] = useState({
    position_id: employee?.position?.id ?? "",
    branch_id: employee?.branch?.id ?? "",
    user_id: employee?.user?.id ?? "",
    hire_date: employee?.hire_date?.slice(0, 10) ?? isoDay(new Date()),
    contract_type: employee?.contract_type ?? "INDEFINIDO",
    weekly_hours: String(employee?.weekly_hours ?? 48),
    hourly_cost: employee?.hourly_cost != null ? String(employee.hourly_cost) : "",
    currency: employee?.currency ?? "PEN",
    payroll_external_id: employee?.payroll_external_id ?? "",
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  // Sin personas pendientes en Clientes y actores, se escribe una nueva.
  const people = options?.people ?? [];
  const effectiveMode: PersonMode = options && !people.length ? "new" : personMode;

  const set = (key: keyof typeof form, value: string) => setForm((current) => ({ ...current, [key]: value }));
  const setP = (key: keyof typeof person, value: string) => setPerson((current) => ({ ...current, [key]: value }));

  const positions = useMemo(
    () => (options?.positions ?? []).filter((position) => position.active || position.id === form.position_id),
    [options, form.position_id],
  );
  const users = (options?.users ?? []).filter((user) => !user.employee_id || user.employee_id === employee?.id);

  function pickPosition(id: string) {
    const chosen = options?.positions.find((position) => position.id === id);
    setForm((current) => ({
      ...current,
      position_id: id,
      hourly_cost: !current.hourly_cost && chosen?.default_hourly_cost ? String(chosen.default_hourly_cost) : current.hourly_cost,
    }));
  }

  async function save() {
    setBusy(true);
    setErrors({});
    let body: Record<string, unknown>;
    if (mastered) {
      // Con RRHH maestro la API solo acepta estos dos (si llega otro campo responde HR_MASTERED).
      body = { user_id: form.user_id || null, branch_id: form.branch_id || null };
    } else {
      body = {
        position_id: form.position_id || null,
        branch_id: form.branch_id || null,
        user_id: form.user_id || null,
        hire_date: form.hire_date,
        contract_type: form.contract_type,
        weekly_hours: form.weekly_hours === "" ? null : Number(form.weekly_hours),
        hourly_cost: form.hourly_cost === "" ? null : Number(form.hourly_cost),
        currency: form.currency,
        payroll_external_id: form.payroll_external_id.trim() || null,
      };
      if (!editing) {
        if (effectiveMode === "existing") body.party_id = partyId || null;
        else Object.assign(body, { ...person, email: person.email.trim() || null, phone: person.phone.trim() || null });
      }
    }
    try {
      const saved = await apiFetch<HrEmployee>(editing ? `/v1/hr/employees/${employee!.id}` : "/v1/hr/employees", {
        method: editing ? "PUT" : "POST",
        body: JSON.stringify(body),
        headers: editing ? ifMatch(employee!.lock_version) : undefined,
      });
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar el trabajador."), "danger");
      if (err instanceof ApiError && err.status === 409) onClose();
    } finally {
      setBusy(false);
    }
  }

  const locked = mastered || !options;
  const creatingBlocked = !editing && options?.mode === "HR_SAAS_MASTER";

  return (
    <Modal
      title={editing ? `Editar ${employee!.name}` : "Nuevo trabajador"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy || !options || creatingBlocked} permission={editing ? "employees.manage" : "employees.create"}>
            {busy ? "Guardando…" : editing ? "Guardar cambios" : "Registrar trabajador"}
          </Btn>
        </>
      }
    >
      <div className="grid gap-4">
        {mastered && <Notice>{HR_MASTERED_NOTE}: aquí solo cambias el usuario vinculado y la sede.</Notice>}
        {creatingBlocked && <Notice tone="warn">Con la conexión con el SaaS de RRHH activa, las altas se hacen en el SaaS de RRHH.</Notice>}

        {!editing ? (
          <section className="grid gap-3">
            <div role="radiogroup" aria-label="Persona" className="flex flex-wrap gap-x-4 gap-y-1.5 text-[13px]">
              <label className="relative inline-flex cursor-pointer items-center gap-2">
                <input
                  type="radio" name="person-mode" checked={effectiveMode === "existing"} disabled={!people.length}
                  onChange={() => setPersonMode("existing")} className="h-4 w-4 accent-[color:var(--p-accent,#1B8FC9)]"
                />
                <span>Persona de Clientes y actores</span>
              </label>
              <label className="relative inline-flex cursor-pointer items-center gap-2">
                <input
                  type="radio" name="person-mode" checked={effectiveMode === "new"}
                  onChange={() => setPersonMode("new")} className="h-4 w-4 accent-[color:var(--p-accent,#1B8FC9)]"
                />
                <span>Persona nueva</span>
              </label>
            </div>
            {effectiveMode === "existing" ? (
              <Field label="Persona de Clientes y actores" required error={errors.party_id}>
                <SearchSelect
                  value={partyId}
                  onChange={setPartyId}
                  options={people.map((item) => ({
                    value: item.id,
                    label: item.name,
                    hint: item.document_number ? `${item.document_type ?? "Doc."} ${item.document_number}` : undefined,
                  }))}
                  placeholder={options ? "Seleccionar…" : "Cargando…"}
                  searchPlaceholder="Buscar por nombre o documento…"
                />
              </Field>
            ) : (
              <FormGrid>
                <Field label="Nombres" required error={errors.given_names}>
                  <input className={INPUT} value={person.given_names} onChange={(e) => setP("given_names", e.target.value)} maxLength={255} autoComplete="off" />
                </Field>
                <Field label="Apellidos" required error={errors.family_name}>
                  <input className={INPUT} value={person.family_name} onChange={(e) => setP("family_name", e.target.value)} maxLength={255} autoComplete="off" />
                </Field>
                <Field label="Tipo de documento" required error={errors.document_type}>
                  <select className={INPUT} value={person.document_type} onChange={(e) => setP("document_type", e.target.value)}>
                    {DOCUMENT_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                  </select>
                </Field>
                <Field label="Número de documento" required error={errors.document_number}>
                  <input
                    className={INPUT} value={person.document_number} onChange={(e) => setP("document_number", e.target.value.trim())}
                    maxLength={person.document_type === "DNI" ? 8 : 30} inputMode={person.document_type === "DNI" ? "numeric" : undefined} autoComplete="off"
                  />
                </Field>
                <Field label="Correo" error={errors.email}>
                  <input type="email" className={INPUT} value={person.email} onChange={(e) => setP("email", e.target.value)} maxLength={250} autoComplete="off" />
                </Field>
                <Field label="Teléfono" error={errors.phone}>
                  <input type="tel" className={INPUT} value={person.phone} onChange={(e) => setP("phone", e.target.value)} maxLength={30} autoComplete="off" />
                </Field>
              </FormGrid>
            )}
          </section>
        ) : (
          <p className="text-[13px]">
            <strong className="font-semibold">{employee!.name}</strong>
            {employee!.document_number && <span className="app-muted"> · {employee!.document_type} {employee!.document_number}</span>}
          </p>
        )}

        <FormGrid>
          <Field label="Puesto" error={errors.position_id} hint={mastered ? HR_MASTERED_NOTE : undefined}>
            <SearchSelect
              value={form.position_id}
              onChange={pickPosition}
              disabled={locked}
              options={[
                { value: "", label: "Sin puesto" },
                ...positions.map((position) => ({
                  value: position.id,
                  label: position.name,
                  hint: [position.code, position.default_hourly_cost ? `${money(position.default_hourly_cost)}/h` : null].filter(Boolean).join(" · "),
                })),
              ]}
              placeholder="Sin puesto"
              searchPlaceholder="Buscar puesto…"
            />
          </Field>
          <Field label="Sede" error={errors.branch_id}>
            <select className={INPUT} value={form.branch_id} onChange={(e) => set("branch_id", e.target.value)} disabled={!options}>
              <option value="">Sin sede</option>
              {(options?.branches ?? []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
            </select>
          </Field>
          <Field label="Usuario del sistema" error={errors.user_id} hint="Opcional: con usuario, sus horas van a la planilla.">
            <SearchSelect
              value={form.user_id}
              onChange={(value) => set("user_id", value)}
              disabled={!options}
              options={[{ value: "", label: "Sin usuario" }, ...users.map((user) => ({ value: user.id, label: user.name, hint: user.email }))]}
              placeholder="Sin usuario"
              searchPlaceholder="Buscar usuario…"
            />
          </Field>
          <Field label="Fecha de ingreso" required error={errors.hire_date}>
            <input type="date" className={INPUT} value={form.hire_date} onChange={(e) => set("hire_date", e.target.value)} disabled={locked} />
          </Field>
          <Field label="Tipo de contrato" required error={errors.contract_type}>
            <select className={INPUT} value={form.contract_type} onChange={(e) => set("contract_type", e.target.value)} disabled={locked}>
              {(options?.contract_types.map((item): [string, string] => [item.value, item.label]) ?? CONTRACT_TYPES).map(([value, label]) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </select>
          </Field>
          <Field label="Jornada semanal (horas)" required error={errors.weekly_hours} hint="Hasta 48 horas.">
            <input type="number" className={INPUT} value={form.weekly_hours} onChange={(e) => set("weekly_hours", e.target.value)} min={1} max={48} step="0.5" disabled={locked} />
          </Field>
          <Field label="Costo por hora" required error={errors.hourly_cost}>
            <input type="number" className={INPUT} value={form.hourly_cost} onChange={(e) => set("hourly_cost", e.target.value)} min={0.01} step="0.01" disabled={locked} />
          </Field>
          <Field label="Moneda" error={errors.currency}>
            <select className={INPUT} value={form.currency} onChange={(e) => set("currency", e.target.value as "PEN" | "USD")} disabled={locked}>
              <option value="PEN">Soles (S/)</option>
              <option value="USD">Dólares (US$)</option>
            </select>
          </Field>
          <Field label="Código en planilla" error={errors.payroll_external_id}>
            <input className={INPUT} value={form.payroll_external_id} onChange={(e) => set("payroll_external_id", e.target.value)} maxLength={100} disabled={locked} autoComplete="off" />
          </Field>
        </FormGrid>
      </div>
    </Modal>
  );
}

/** Registrar el cese: fecha y motivo. */
export function TerminateEmployeeModal({
  employee, onClose, onDone, toast,
}: { employee: HrEmployee; onClose: () => void; onDone: (employee: HrEmployee) => void; toast: Toast }) {
  const [date, setDate] = useState(isoDay(new Date()));
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      onDone(await apiFetch<HrEmployee>(`/v1/hr/employees/${employee.id}/terminate`, {
        method: "POST",
        body: JSON.stringify({ termination_date: date, reason: reason.trim() }),
        headers: ifMatch(employee.lock_version),
      }));
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo registrar el cese."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Registrar cese"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Volver</Btn>
          <Btn variant="danger" onClick={save} disabled={busy} permission="employees.manage">{busy ? "Registrando…" : "Registrar cese"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <p className="text-[15px]">{employee.name}</p>
        <Field label="Fecha de cese" required error={errors.termination_date}>
          <input type="date" className={INPUT} value={date} onChange={(e) => setDate(e.target.value)} min={employee.hire_date?.slice(0, 10) ?? undefined} />
        </Field>
        <Field label="Motivo" required error={errors.reason}>
          <textarea className={INPUT} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} placeholder="Queda registrado en auditoría" />
        </Field>
      </div>
    </Modal>
  );
}

