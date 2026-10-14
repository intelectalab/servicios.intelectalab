"use client";

import { useState } from "react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError, toLocalInput } from "@/lib/crm";
import { PRIORITIES } from "@/lib/operations";
import { useAccess } from "@/lib/access-context";
import { Btn, Field, FormGrid, INPUT, Modal, SearchSelect, deniedText } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { WorkOrder, WorkOrderDetail, WorkOrderOptions } from "@/types/operations";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

/** Mañana a las 9:00, como propone el prototipo al programar. */
function tomorrowMorning() {
  const date = new Date();
  date.setDate(date.getDate() + 1);
  date.setHours(9, 0, 0, 0);
  return toLocalInput(date);
}

const toIso = (local: string) => (local ? new Date(local).toISOString() : null);

const NONE: [string, string] = ["", "Sin asignar"];

/** Alta o edición de una orden de trabajo. Al crear se elige el servicio contratado, el técnico y la plantilla. */
export function WorkOrderFormModal({
  order, options, contractedServiceId, onClose, onSaved, toast,
}: {
  order?: WorkOrderDetail;
  /** Listas del formulario; null mientras cargan. */
  options: WorkOrderOptions | null;
  /** Servicio contratado ya elegido (al crear desde su fila). */
  contractedServiceId?: string;
  onClose: () => void;
  onSaved: (order: WorkOrderDetail) => void;
  toast: Toast;
}) {
  const { can } = useAccess();
  const canAssign = can("work_orders.approve");

  const [serviceId, setServiceId] = useState(contractedServiceId ?? "");
  const [title, setTitle] = useState(order?.title ?? "");
  const [priority, setPriority] = useState<string>(order?.priority ?? "NORMAL");
  const [start, setStart] = useState(order ? (order.scheduled_start ? toLocalInput(order.scheduled_start) : "") : tomorrowMorning());
  const [end, setEnd] = useState(order?.scheduled_end ? toLocalInput(order.scheduled_end) : "");
  const [technician, setTechnician] = useState("");
  // null: aún no se eligió y vale la plantilla asociada al servicio, si hay una.
  const [chosenTemplate, setTemplate] = useState<string | null>(null);
  const [location, setLocation] = useState(order?.location ?? "");
  const [coordinates, setCoordinates] = useState(order && order.latitude !== null && order.longitude !== null ? `${order.latitude}, ${order.longitude}` : "");
  const [description, setDescription] = useState(order?.description ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // La plantilla asociada al servicio del catálogo se propone sola; se puede cambiar.
  const catalogService = options?.contracted_services.find((service) => service.id === serviceId)?.service_id ?? null;
  const suggested = catalogService ? options?.templates.find((item) => item.service_id === catalogService)?.id ?? null : null;
  const template = chosenTemplate ?? suggested ?? "";

  async function save() {
    const found: Record<string, string> = {};
    if (!order && !serviceId) found.contracted_service_id = "Elige el servicio contratado.";
    if (!title.trim()) found.title = "El título es obligatorio.";
    if (start && end && end < start) found.scheduled_end = "El fin no puede ser anterior al inicio.";
    // "‑12.0464, ‑77.0428", como las copia Google Maps.
    const parts = coordinates.trim() ? coordinates.split(/[,;\s]+/).filter(Boolean).map(Number) : [];
    const [latitude, longitude] = parts.length === 2 ? parts : [null, null];
    if (coordinates.trim() && (parts.length !== 2 || parts.some(Number.isNaN) || Math.abs(parts[0]) > 90 || Math.abs(parts[1]) > 180)) {
      found.latitude = "Escribe latitud y longitud separadas por coma: -12.0464, -77.0428";
    }
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<WorkOrderDetail>(order ? `/v1/work-orders/${order.id}` : "/v1/work-orders", {
        method: order ? "PUT" : "POST",
        body: JSON.stringify({
          title: title.trim(),
          priority,
          scheduled_start: toIso(start),
          scheduled_end: start ? toIso(end) : null,
          location: location.trim() || null,
          latitude,
          longitude,
          description: description.trim() || null,
          ...(order ? {} : {
            contracted_service_id: serviceId,
            checklist_id: template || null,
            assigned_user_id: canAssign && technician ? technician : null,
          }),
        }),
      });
      toast(order ? "Orden de trabajo actualizada" : "Orden de trabajo creada");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar la orden de trabajo."), "danger");
    } finally {
      setSaving(false);
    }
  }

  const services = (options?.contracted_services ?? []).map((service) => ({
    value: service.id,
    label: service.service ?? "Servicio",
    hint: service.customer ?? undefined,
  }));

  return (
    <Modal
      title={order ? `Editar ${order.code}` : "Nueva orden de trabajo"}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving} permission={order ? "work_orders.manage" : "work_orders.create"}>{order ? "Guardar" : "Crear OT"}</Btn>
        </>
      }
    >
      <FormGrid>
        {!order && (
          <Field
            label="Servicio contratado" required span={3} error={errors.contracted_service_id}
            hint={options && !services.length ? "No hay servicios contratados activos a tu alcance: actívalos en Servicios contratados." : undefined}
          >
            <SearchSelect
              value={serviceId}
              onChange={setServiceId}
              options={services}
              placeholder={options ? "Seleccionar…" : "Cargando…"}
              searchPlaceholder="Buscar servicio o cliente…"
              disabled={!options}
            />
          </Field>
        )}
        <Field label="Título" required span={2} error={errors.title}>
          <input className={INPUT} value={title} onChange={(e) => setTitle(e.target.value)} maxLength={255} autoFocus />
        </Field>
        <Field label="Prioridad" error={errors.priority}>
          <PickSelect className={INPUT} ariaLabel="Prioridad" value={priority} onChange={setPriority} options={PRIORITIES.map(([key, label]): [string, string] => [key, label])} />
        </Field>
        <Field label="Programada para" error={errors.scheduled_start}>
          <input className={INPUT} type="datetime-local" value={start} onChange={(e) => setStart(e.target.value)} />
        </Field>
        <Field label="Fin (opcional)" error={errors.scheduled_end} hint="Sin fin, ocupa dos horas en la agenda del técnico.">
          <input className={INPUT} type="datetime-local" value={end} min={start || undefined} onChange={(e) => setEnd(e.target.value)} disabled={!start} />
        </Field>
        <Field label="Lugar" error={errors.location}>
          <input className={INPUT} value={location} onChange={(e) => setLocation(e.target.value)} maxLength={255} placeholder="Sede del cliente" />
        </Field>
        <Field label="Coordenadas (opcional)" span={3} error={errors.latitude ?? errors.longitude} hint="Latitud y longitud, como las copia Google Maps. Sirven para el mapa y la ruta de la App del técnico.">
          <input className={INPUT} value={coordinates} onChange={(e) => setCoordinates(e.target.value)} maxLength={40} inputMode="text" placeholder="-12.0464, -77.0428" />
        </Field>
        {!order && (
          <>
            <Field
              label="Técnico (opcional)" span={2} error={errors.assigned_user_id}
              hint={canAssign ? "Se valida que tenga libre ese horario." : `${deniedText("work_orders.approve")}: la orden nace sin asignar.`}
            >
              <PickSelect
                className={INPUT} ariaLabel="Técnico" searchable value={canAssign ? technician : ""} onChange={setTechnician}
                options={[NONE, ...(options?.technicians ?? []).map((user): [string, string] => [user.id, user.name])]}
                disabled={!canAssign || !options}
              />
            </Field>
            <Field
              label="Plantilla de checklist" error={errors.checklist_id}
              hint={suggested && template === suggested ? "Propuesta por el servicio." : undefined}
            >
              <PickSelect
                className={INPUT} ariaLabel="Plantilla de checklist" value={template} onChange={setTemplate}
                options={[["", "Sin checklist"], ...(options?.templates ?? []).map((item): [string, string] => [item.id, `${item.name} (${item.items})`])]}
                disabled={!options}
              />
            </Field>
          </>
        )}
        <Field label="Descripción" span={3} error={errors.description}>
          <textarea className={INPUT} rows={3} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={5000} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Asignar o reasignar el técnico y la fecha de una orden de trabajo. */
export function AssignWorkOrderModal({
  order, technicians, onClose, onSaved, toast,
}: {
  order: WorkOrder;
  /** null mientras carga la lista. */
  technicians: WorkOrderOptions["technicians"] | null;
  onClose: () => void;
  onSaved: (order: WorkOrderDetail) => void;
  toast: Toast;
}) {
  const [technician, setTechnician] = useState(order.assignee?.id ?? "");
  const [start, setStart] = useState(order.scheduled_start ? toLocalInput(order.scheduled_start) : "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  async function save() {
    if (!technician) {
      setErrors({ assigned_user_id: "Elige el técnico." });
      return;
    }
    setErrors({});
    setSaving(true);
    try {
      // La fecha solo se envía si cambió: así una orden con fin programado lo conserva.
      const moved = start !== (order.scheduled_start ? toLocalInput(order.scheduled_start) : "");
      const saved = await apiFetch<WorkOrderDetail>(`/v1/work-orders/${order.id}/assign`, {
        method: "POST",
        body: JSON.stringify({ assigned_user_id: technician, ...(moved && start ? { scheduled_start: toIso(start) } : {}) }),
      });
      toast("Técnico asignado");
      onSaved(saved);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo asignar la orden."), "danger");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title={`Asignar ${order.code}`}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={saving || !technicians} permission="work_orders.approve">Asignar</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <Field label="Técnico" required error={errors.assigned_user_id}>
          <PickSelect
            className={INPUT} ariaLabel="Técnico" searchable value={technician} onChange={setTechnician}
            options={[["", technicians ? "Seleccionar…" : "Cargando…"], ...(technicians ?? []).map((user): [string, string] => [user.id, user.name])]}
            disabled={!technicians}
          />
        </Field>
        <Field label="Fecha y hora" error={errors.scheduled_start}>
          <input className={INPUT} type="datetime-local" value={start} onChange={(e) => setStart(e.target.value)} />
        </Field>
        <p className="app-muted text-xs">Se valida que el técnico tenga libre ese horario.</p>
      </div>
    </Modal>
  );
}
