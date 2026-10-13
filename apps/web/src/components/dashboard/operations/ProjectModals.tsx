"use client";

import { useState } from "react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError, toLocalInput } from "@/lib/crm";
import { COST_CATEGORIES, PRIORITIES } from "@/lib/operations";
import { Btn, Field, FormGrid, INPUT, Modal, SearchSelect } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type {
  CostEntry, Deliverable, ProjectDetail, ProjectOptions, ProjectTask, TimeEntry, TimeEntryOptions,
} from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const number = (value: string) => (value === "" ? 0 : Number(value));
const CHECKBOX = "mt-0.5 h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9]";

/** Alta o edición de un proyecto. Al crear se elige el servicio contratado del que nace. */
export function ProjectFormModal({
  project, options, contractedServiceId, defaultName, onClose, onSaved, toast,
}: {
  project?: ProjectDetail;
  /** Listas del formulario; null mientras cargan. */
  options: ProjectOptions | null;
  /** Servicio contratado ya elegido (al crear desde su fila). */
  contractedServiceId?: string;
  defaultName?: string;
  onClose: () => void;
  onSaved: (project: ProjectDetail) => void;
  toast: Toast;
}) {
  const [serviceId, setServiceId] = useState(contractedServiceId ?? "");
  const [name, setName] = useState(project?.name ?? defaultName ?? "");
  const [manager, setManager] = useState(project?.manager?.id ?? "");
  const [start, setStart] = useState(project?.start_date ?? "");
  const [end, setEnd] = useState(project?.end_date ?? "");
  const [hours, setHours] = useState(project?.budget_hours ? String(project.budget_hours) : "");
  const [cost, setCost] = useState(project?.budget_cost ? String(project.budget_cost) : "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    const found: Record<string, string> = {};
    if (!project && !serviceId) found.contracted_service_id = "Elige el servicio contratado.";
    if (!name.trim()) found.name = "El nombre es obligatorio.";
    if (start && end && end < start) found.end_date = "El fin no puede ser anterior al inicio.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<ProjectDetail>(project ? `/v1/projects/${project.id}` : "/v1/projects", {
        method: project ? "PUT" : "POST",
        body: JSON.stringify({
          name: name.trim(),
          manager_user_id: manager || null,
          start_date: start || null,
          end_date: end || null,
          budget_hours: number(hours),
          budget_cost: number(cost),
          ...(project ? {} : { contracted_service_id: serviceId }),
        }),
      });
      toast(project ? "Proyecto actualizado" : "Proyecto creado");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar el proyecto."), "danger");
    } finally {
      setSaving(false);
    }
  }

  const services = (options?.contracted_services ?? []).map((service) => ({
    value: service.id, label: service.service ?? "Servicio", hint: service.customer ?? undefined,
  }));

  return (
    <Modal
      title={project ? `Editar ${project.code}` : "Nuevo proyecto"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission={project ? "projects.manage" : "projects.create"}>{project ? "Guardar" : "Crear proyecto"}</Btn>
        </>
      }
    >
      <FormGrid>
        {!project && (
          <Field
            label="Servicio contratado" required span={3} error={errors.contracted_service_id}
            hint={options && !services.length ? "No hay servicios contratados activos a tu alcance." : "El ingreso del proyecto es lo pactado en este servicio."}
          >
            <SearchSelect
              value={serviceId}
              onChange={(value) => {
                setServiceId(value);
                // El nombre propuesto es "servicio — cliente", como en el prototipo.
                const picked = options?.contracted_services.find((service) => service.id === value);
                if (picked && !name.trim()) setName([picked.service, picked.customer].filter(Boolean).join(" — "));
              }}
              options={services}
              placeholder={options ? "Seleccionar…" : "Cargando…"}
              searchPlaceholder="Buscar servicio o cliente…"
              disabled={!options}
            />
          </Field>
        )}
        <Field label="Nombre" required span={3} error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus={Boolean(project || contractedServiceId)} />
        </Field>
        <Field label="Responsable" span={3} error={errors.manager_user_id} hint={project ? undefined : "Sin elegir, lo llevas tú."}>
          <PickSelect
            className={INPUT} ariaLabel="Responsable" searchable value={manager} onChange={setManager}
            options={[["", project ? "Sin cambios" : "Yo"], ...(options?.people ?? []).map((person): [string, string] => [person.id, person.name])]}
            disabled={!options}
          />
        </Field>
        <Field label="Inicio" error={errors.start_date}>
          <input className={INPUT} type="date" value={start} onChange={(e) => setStart(e.target.value)} />
        </Field>
        <Field label="Fin" error={errors.end_date}>
          <input className={INPUT} type="date" value={end} min={start || undefined} onChange={(e) => setEnd(e.target.value)} />
        </Field>
        <Field label="Horas presupuestadas" error={errors.budget_hours}>
          <input className={INPUT} type="number" min="0" step="0.5" inputMode="decimal" value={hours} onChange={(e) => setHours(e.target.value)} />
        </Field>
        <Field label="Costo presupuestado (S/)" error={errors.budget_cost}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={cost} onChange={(e) => setCost(e.target.value)} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Alta o edición de una tarea del proyecto. */
export function TaskFormModal({
  projectId, task, people, onClose, onSaved, toast,
}: {
  projectId: string;
  task?: ProjectTask;
  people: ProjectOptions["people"] | null;
  onClose: () => void;
  onSaved: (project: ProjectDetail) => void;
  toast: Toast;
}) {
  const [title, setTitle] = useState(task?.title ?? "");
  const [assignee, setAssignee] = useState(task?.assignee?.id ?? "");
  const [priority, setPriority] = useState<string>(task?.priority ?? "NORMAL");
  const [estimate, setEstimate] = useState(task ? String(task.estimate_hours) : "8");
  const [due, setDue] = useState(task?.due_at ?? "");
  const [check, setCheck] = useState(task?.required_check ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!title.trim()) {
      setErrors({ title: "El título es obligatorio." });
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      const saved = await apiFetch<ProjectDetail>(`/v1/projects/${projectId}/tasks${task ? `/${task.id}` : ""}`, {
        method: task ? "PUT" : "POST",
        body: JSON.stringify({
          title: title.trim(),
          assigned_user_id: assignee || null,
          priority,
          estimate_hours: number(estimate),
          due_at: due || null,
          required_check: check.trim() || null,
        }),
      });
      toast(task ? "Tarea actualizada" : "Tarea creada");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar la tarea."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={task ? "Editar tarea" : "Nueva tarea"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="tasks.manage">{task ? "Guardar" : "Crear"}</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Título" required span={3} error={errors.title}>
          <input className={INPUT} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Responsable" span={2} error={errors.assigned_user_id}>
          <PickSelect
            className={INPUT} ariaLabel="Responsable" searchable value={assignee} onChange={setAssignee}
            options={[["", "Sin asignar"], ...(people ?? []).map((person): [string, string] => [person.id, person.name])]}
            disabled={!people}
          />
        </Field>
        <Field label="Prioridad" error={errors.priority}>
          <PickSelect className={INPUT} ariaLabel="Prioridad" value={priority} onChange={setPriority} options={PRIORITIES.map(([key, label]): [string, string] => [key, label])} />
        </Field>
        <Field label="Horas estimadas" error={errors.estimate_hours} hint="Con ellas se pondera el avance.">
          <input className={INPUT} type="number" min="0" step="0.5" inputMode="decimal" value={estimate} onChange={(e) => setEstimate(e.target.value)} />
        </Field>
        <Field label="Vence" error={errors.due_at}>
          <input className={INPUT} type="date" value={due} onChange={(e) => setDue(e.target.value)} />
        </Field>
        <Field label="Control obligatorio antes de cerrar (opcional)" span={3} error={errors.required_check} hint="La tarea no queda hecha sin marcarlo.">
          <input className={INPUT} value={check} onChange={(e) => setCheck(e.target.value)} maxLength={255} placeholder="Acta firmada por el cliente" />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Alta o edición de un entregable, con su documento. */
export function DeliverableFormModal({
  projectId, deliverable, onClose, onSaved, toast,
}: {
  projectId: string;
  deliverable?: Deliverable;
  onClose: () => void;
  onSaved: (project: ProjectDetail) => void;
  toast: Toast;
}) {
  const [name, setName] = useState(deliverable?.name ?? "");
  const [due, setDue] = useState(deliverable?.due_date ?? "");
  const [document, setDocument] = useState(deliverable?.document_ref ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!name.trim()) {
      setErrors({ name: "El nombre es obligatorio." });
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      const saved = await apiFetch<ProjectDetail>(`/v1/projects/${projectId}/deliverables${deliverable ? `/${deliverable.id}` : ""}`, {
        method: deliverable ? "PUT" : "POST",
        body: JSON.stringify({ name: name.trim(), due_date: due || null, document_ref: document.trim() || null }),
      });
      toast(deliverable ? "Entregable actualizado" : "Entregable creado");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar el entregable."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={deliverable ? "Editar entregable" : "Nuevo entregable"}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="deliverables.manage">{deliverable ? "Guardar" : "Crear"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <Field label="Nombre" required error={errors.name}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Vence" error={errors.due_date}>
          <input className={INPUT} type="date" value={due} onChange={(e) => setDue(e.target.value)} />
        </Field>
        {/* El archivo se adjunta desde la tabla ("Adjuntar"); aquí solo se edita el enlace de los entregables que ya lo tenían. */}
        {deliverable?.document_ref && !deliverable.document && (
          <Field label="Documento (enlace)" error={errors.document_ref} hint="Enlace o referencia escrita. Para subir el archivo usa Adjuntar archivo.">
            <input className={INPUT} value={document} onChange={(e) => setDocument(e.target.value)} maxLength={1000} placeholder="https://…" />
          </Field>
        )}
      </div>
    </Modal>
  );
}

/** Hoy a esa hora, para proponer un registro de 9:00 a 11:00. */
function todayAt(hour: number) {
  const date = new Date();
  date.setHours(hour, 0, 0, 0);
  return toLocalInput(date);
}

/** Registrar las horas propias en un proyecto o en una orden de trabajo. Quedan enviadas a aprobación. */
export function TimeEntryFormModal({
  options, projectId, workOrderId, onClose, onSaved, toast,
}: {
  /** Dónde se puede registrar; null mientras carga. */
  options: TimeEntryOptions | null;
  /** Proyecto u orden ya elegidos (al registrar desde su ficha). */
  projectId?: string;
  workOrderId?: string;
  onClose: () => void;
  onSaved: (entry: TimeEntry) => void;
  toast: Toast;
}) {
  const fixed = Boolean(projectId || workOrderId);
  const [project, setProject] = useState(projectId ?? "");
  const [task, setTask] = useState("");
  const [order, setOrder] = useState(workOrderId ?? "");
  const [start, setStart] = useState(todayAt(9));
  const [end, setEnd] = useState(todayAt(11));
  const [description, setDescription] = useState("");
  const [billable, setBillable] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    const found: Record<string, string> = {};
    if (!project && !order) found.project_id = "Asocia las horas a un proyecto o a una orden de trabajo.";
    if (!start) found.started_at = "Indica el inicio.";
    if (!end) found.ended_at = "Indica el fin.";
    else if (start && end <= start) found.ended_at = "La hora de fin debe ser posterior al inicio.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<TimeEntry>("/v1/time-entries", {
        method: "POST",
        body: JSON.stringify({
          project_id: project || null,
          task_id: project && task ? task : null,
          work_order_id: project ? null : order || null,
          started_at: new Date(start).toISOString(),
          ended_at: new Date(end).toISOString(),
          description: description.trim() || null,
          billable,
        }),
      });
      toast("Horas registradas y enviadas a aprobación");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudieron registrar las horas."), "danger");
    } finally {
      setSaving(false);
    }
  }

  const projects = options?.projects ?? [];
  const tasks = projects.find((item) => item.id === project)?.tasks ?? [];

  return (
    <Modal
      title="Registrar horas"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="time_entries.create">Registrar</Btn>
        </>
      }
    >
      <FormGrid>
        {(!fixed || projectId) && (
          <Field label="Proyecto" span={fixed ? 3 : 2} error={errors.project_id}>
            <SearchSelect
              value={project}
              onChange={(value) => {
                setProject(value);
                setTask("");
                if (value) setOrder("");
              }}
              options={projects.map((item) => ({ value: item.id, label: item.name, hint: item.code }))}
              placeholder={options ? "Seleccionar…" : "Cargando…"}
              searchPlaceholder="Buscar proyecto…"
              disabled={!options || fixed}
            />
          </Field>
        )}
        {project && tasks.length > 0 && (
          <Field label="Tarea (opcional)" span={fixed ? 3 : undefined} error={errors.task_id}>
            <PickSelect
              className={INPUT} ariaLabel="Tarea" searchable value={task} onChange={setTask}
              options={[["", "Sin tarea"], ...tasks.map((item): [string, string] => [item.id, item.title])]}
            />
          </Field>
        )}
        {(!fixed || workOrderId) && (
          <Field label={fixed ? "Orden de trabajo" : "o Orden de trabajo"} span={3} error={errors.work_order_id}>
            <SearchSelect
              value={order}
              onChange={(value) => {
                setOrder(value);
                if (value) {
                  setProject("");
                  setTask("");
                }
              }}
              options={(options?.work_orders ?? []).map((item) => ({ value: item.id, label: item.title, hint: item.code }))}
              placeholder={options ? "Seleccionar…" : "Cargando…"}
              searchPlaceholder="Buscar orden de trabajo…"
              disabled={!options || fixed}
            />
          </Field>
        )}
        <Field label="Inicio" required error={errors.started_at}>
          <input className={INPUT} type="datetime-local" value={start} onChange={(e) => setStart(e.target.value)} />
        </Field>
        <Field label="Fin" required error={errors.ended_at}>
          <input className={INPUT} type="datetime-local" value={end} min={start || undefined} onChange={(e) => setEnd(e.target.value)} />
        </Field>
        <div className="flex items-end pb-2">
          <label className="flex cursor-pointer items-start gap-2 text-[13px]">
            <input type="checkbox" checked={billable} onChange={(e) => setBillable(e.target.checked)} className={CHECKBOX} />
            <span>Facturable</span>
          </label>
        </div>
        <Field label="Descripción" span={3} error={errors.description}>
          <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={1000} />
        </Field>
      </FormGrid>
      <p className="app-muted mt-3 text-xs">No pueden solaparse con otras horas tuyas y duran como mucho 12 h por registro. Las aprueba otra persona.</p>
    </Modal>
  );
}

/** Registrar un costo directo del proyecto. Queda enviado a aprobación. */
export function CostFormModal({
  projectId, onClose, onSaved, toast,
}: {
  projectId: string;
  onClose: () => void;
  onSaved: (cost: CostEntry) => void;
  toast: Toast;
}) {
  const [description, setDescription] = useState("");
  const [category, setCategory] = useState(COST_CATEGORIES[0]);
  const [quantity, setQuantity] = useState("1");
  const [unitCost, setUnitCost] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    const found: Record<string, string> = {};
    if (!description.trim()) found.description = "Describe el costo.";
    if (!(Number(quantity) > 0)) found.quantity = "La cantidad debe ser mayor a cero.";
    if (!(Number(unitCost) > 0)) found.unit_cost = "El costo unitario debe ser mayor a cero.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<CostEntry>("/v1/cost-entries", {
        method: "POST",
        body: JSON.stringify({ project_id: projectId, description: description.trim(), category, quantity: Number(quantity), unit_cost: Number(unitCost) }),
      });
      toast("Costo registrado y enviado a aprobación");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo registrar el costo."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Registrar costo"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission="cost_entries.create">Registrar</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Descripción" required span={3} error={errors.description}>
          <input className={INPUT} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={1000} autoFocus />
        </Field>
        <Field label="Categoría" error={errors.category}>
          <PickSelect className={INPUT} ariaLabel="Categoría" value={category} onChange={setCategory} options={COST_CATEGORIES.map((item): [string, string] => [item, item])} />
        </Field>
        <Field label="Cantidad" required error={errors.quantity}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
        </Field>
        <Field label="Costo unitario (S/)" required error={errors.unit_cost}>
          <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={unitCost} onChange={(e) => setUnitCost(e.target.value)} />
        </Field>
      </FormGrid>
    </Modal>
  );
}
