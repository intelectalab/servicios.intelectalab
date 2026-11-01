"use client";

import { useEffect, useState } from "react";
import { Camera, ChevronLeft, ChevronRight, ExternalLink, Image as ImageIcon, RefreshCw, Trash2 } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError, toLocalInput } from "@/lib/crm";
import { fileSize, PRIORITIES } from "@/lib/operations";
import { useAccess } from "@/lib/access-context";
import { Btn, Chip, Field, FormGrid, INPUT, Modal, SearchSelect, deniedText } from "@/components/ui/proto";
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

export interface EvidencePhotoItem {
  document_id?: string;
  file_name: string;
  size_bytes?: number;
  version?: number;
  /** Foto tomada sin conexión aún guardada en el teléfono. */
  opId?: string;
  blob?: Blob;
  pendingSync?: boolean;
}

/** Modal de previsualización de fotos de evidencia de un ítem del checklist (con galería). */
export function EvidencePreviewModal({
  workOrderId,
  orderCode,
  itemId,
  itemLabel,
  photos,
  initialIndex = 0,
  canUpload = false,
  busy = false,
  onUpload,
  onReplace,
  onRemove,
  onClose,
  toast,
}: {
  workOrderId: string;
  orderCode: string;
  itemId: string;
  itemLabel: string;
  photos: EvidencePhotoItem[];
  initialIndex?: number;
  canUpload?: boolean;
  busy?: boolean;
  onUpload?: (files: File[]) => void;
  onReplace?: (photo: EvidencePhotoItem, file: File) => void;
  onRemove?: (photo: EvidencePhotoItem) => void;
  onClose: () => void;
  toast: Toast;
}) {
  const [index, setIndex] = useState(() => Math.min(Math.max(initialIndex, 0), Math.max(photos.length - 1, 0)));
  const [urls, setUrls] = useState<Record<string, string>>({});
  const [loadingUrl, setLoadingUrl] = useState(false);
  const [urlError, setUrlError] = useState("");
  const [prevCount, setPrevCount] = useState(photos.length);
  const [confirmingRemove, setConfirmingRemove] = useState(false);

  // Al cambiar la cantidad de fotos (se ajusta durante el render, sin efecto): si se subió una
  // nueva salta a ella; si se quitó la que se veía, queda en la última.
  if (photos.length !== prevCount) {
    setPrevCount(photos.length);
    setConfirmingRemove(false);
    if (photos.length > prevCount) setIndex(Math.max(photos.length - 1, 0));
  }
  if (photos.length > 0 && index >= photos.length) setIndex(photos.length - 1);

  // Sin fotos, el modal se cierra.
  useEffect(() => {
    if (photos.length === 0) onClose();
  }, [photos.length, onClose]);

  const current = photos[Math.min(index, Math.max(photos.length - 1, 0))];
  const cacheKey = current
    ? current.blob
      ? `blob-${current.opId ?? index}-${current.file_name}-${current.size_bytes ?? 0}`
      : `${current.document_id ?? "latest"}-${current.version ?? 1}-${current.file_name}-${index}`
    : "";
  const activeUrl = cacheKey ? urls[cacheKey] : undefined;

  useEffect(() => {
    if (!current || activeUrl) return;

    // Una foto aún sin sincronizar se ve desde el propio archivo del teléfono.
    if (current.blob) {
      const objectUrl = URL.createObjectURL(current.blob);
      // eslint-disable-next-line react-hooks/set-state-in-effect -- dirección creada para un archivo del navegador
      setUrls((prev) => ({ ...prev, [cacheKey]: objectUrl }));
      return;
    }

    let cancelled = false;
    setLoadingUrl(true);
    setUrlError("");
    const params = new URLSearchParams();
    if (current.document_id) params.set("document_id", current.document_id);
    else if (current.version) params.set("version", String(current.version));
    const qs = params.toString() ? `?${params.toString()}` : "";

    apiFetch<{ url: string }>(`/v1/work-orders/${workOrderId}/checklist-items/${itemId}/evidence-url${qs}`)
      .then(({ url }) => {
        if (!cancelled) setUrls((prev) => ({ ...prev, [cacheKey]: url }));
      })
      .catch((err) => {
        if (!cancelled) {
          const msg = firstError(err, "No se pudo cargar la vista previa de la foto.");
          setUrlError(msg);
          toast(msg, "danger");
        }
      })
      .finally(() => {
        if (!cancelled) setLoadingUrl(false);
      });

    return () => {
      cancelled = true;
    };
  }, [current, activeUrl, cacheKey, workOrderId, itemId, toast]);

  if (!current) return null;

  return (
    <Modal
      title={`Evidencia · ${itemLabel}`}
      size="lg"
      onClose={onClose}
      footer={
        <>
          <div className="mr-auto flex flex-wrap items-center gap-1.5">
            {canUpload && onUpload && (
              <label
                className={`inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-[9px] border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3 text-[13px] font-medium text-[var(--app-text)] hover:bg-[var(--app-surface-muted)] ${busy ? "pointer-events-none opacity-50" : ""}`}
              >
                <Camera size={15} />
                <span>{busy ? "Subiendo…" : "Subir otra"}</span>
                <input
                  type="file"
                  accept="image/*"
                  multiple
                  className="sr-only"
                  disabled={busy}
                  onChange={(e) => {
                    const files = Array.from(e.target.files ?? []);
                    e.target.value = "";
                    if (files.length) onUpload(files);
                  }}
                />
              </label>
            )}
            {canUpload && onReplace && (
              <label
                title="Reemplazar esta foto por otra (conserva versión anterior)"
                className={`inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-[9px] border border-[color:var(--app-border)] bg-[var(--app-surface)] px-3 text-[13px] font-medium text-[var(--app-text)] hover:bg-[var(--app-surface-muted)] ${busy ? "pointer-events-none opacity-50" : ""}`}
              >
                <RefreshCw size={14} />
                <span>Reemplazar</span>
                <input
                  type="file"
                  accept="image/*"
                  className="sr-only"
                  disabled={busy}
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    e.target.value = "";
                    if (file) onReplace(current, file);
                  }}
                />
              </label>
            )}
            {canUpload && onRemove && (
              confirmingRemove ? (
                <span className="inline-flex items-center gap-1.5 rounded-[9px] border border-[color:var(--p-danger-line)] bg-[var(--p-danger-soft)] px-2.5 py-1 text-xs">
                  <span className="font-medium text-[color:var(--p-danger-text)]">¿Quitar esta foto?</span>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      setConfirmingRemove(false);
                      onRemove(current);
                    }}
                    className="cursor-pointer rounded bg-[color:var(--p-danger-text)] px-2 py-0.5 font-semibold text-white hover:opacity-90 disabled:opacity-50"
                  >
                    Sí, quitar
                  </button>
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => setConfirmingRemove(false)}
                    className="cursor-pointer px-1.5 py-0.5 font-medium text-[var(--app-text)] hover:underline"
                  >
                    No
                  </button>
                </span>
              ) : (
                <Btn
                  variant="danger-ghost"
                  disabled={busy}
                  onClick={() => setConfirmingRemove(true)}
                  title="Quitar esta foto del ítem"
                >
                  <Trash2 size={15} />
                  <span>Quitar</span>
                </Btn>
              )
            )}
          </div>
          <Btn variant="ghost" onClick={onClose}>Cerrar</Btn>
          {activeUrl && (
            <Btn
              variant="secondary"
              onClick={() => window.open(activeUrl, "_blank", "noopener,noreferrer")}
            >
              <ExternalLink size={15} />
              <span>Abrir original</span>
            </Btn>
          )}
        </>
      }
    >
      <div className="grid gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2 text-[13px]">
          <div className="flex flex-wrap items-center gap-2">
            <Chip tone="accent">
              Foto {index + 1} de {photos.length}
            </Chip>
            <strong className="font-semibold [overflow-wrap:anywhere]">{current.file_name}</strong>
            {typeof current.size_bytes === "number" && current.size_bytes > 0 && (
              <span className="app-muted text-xs">({fileSize(current.size_bytes)})</span>
            )}
          </div>
          <div className="flex items-center gap-1.5">
            <span className="app-muted text-xs">{orderCode}</span>
            {current.pendingSync ? (
              <Chip tone="warning">En el teléfono · pendiente de sincronizar</Chip>
            ) : (
              <Chip tone="success">v{current.version ?? 1}</Chip>
            )}
          </div>
        </div>

        <div className="relative flex min-h-[280px] max-h-[440px] items-center justify-center overflow-hidden rounded-xl border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3">
          {loadingUrl && !activeUrl ? (
            <div className="flex flex-col items-center gap-2 py-12 text-center">
              <div className="h-8 w-8 animate-spin rounded-full border-2 border-[#1B8FC9] border-t-transparent" />
              <span className="app-muted text-[13px]">Cargando imagen…</span>
            </div>
          ) : urlError ? (
            <div className="flex flex-col items-center gap-2 py-10 text-center">
              <ImageIcon size={28} className="app-muted" />
              <p className="text-[13px] text-[color:var(--p-danger-text)]">{urlError}</p>
            </div>
          ) : activeUrl ? (
            /* eslint-disable-next-line @next/next/no-img-element */
            <img
              src={activeUrl}
              alt={current.file_name}
              className="max-h-[400px] w-auto max-w-full rounded-lg object-contain shadow-sm"
            />
          ) : null}

          {photos.length > 1 && (
            <>
              <button
                type="button"
                aria-label="Foto anterior"
                onClick={() => setIndex((prev) => (prev - 1 + photos.length) % photos.length)}
                className="absolute left-2.5 top-1/2 grid h-9 w-9 -translate-y-1/2 cursor-pointer place-items-center rounded-full border border-[color:var(--app-border)] bg-[var(--app-surface)]/90 text-[var(--app-text)] shadow-md hover:bg-[var(--app-surface)]"
              >
                <ChevronLeft size={18} />
              </button>
              <button
                type="button"
                aria-label="Foto siguiente"
                onClick={() => setIndex((prev) => (prev + 1) % photos.length)}
                className="absolute right-2.5 top-1/2 grid h-9 w-9 -translate-y-1/2 cursor-pointer place-items-center rounded-full border border-[color:var(--app-border)] bg-[var(--app-surface)]/90 text-[var(--app-text)] shadow-md hover:bg-[var(--app-surface)]"
              >
                <ChevronRight size={18} />
              </button>
            </>
          )}
        </div>

        {photos.length > 1 && (
          <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
            {photos.map((photo, idx) => {
              const selected = idx === index;
              return (
                <button
                  key={`${photo.document_id ?? idx}-${photo.file_name}-${idx}`}
                  type="button"
                  onClick={() => setIndex(idx)}
                  className={`inline-flex max-w-[220px] cursor-pointer items-center gap-1.5 truncate rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors ${
                    selected
                      ? "border-[#1B8FC9] bg-[#1B8FC9] text-white"
                      : "border-[color:var(--app-border)] bg-[var(--app-surface)] text-[var(--app-text)] hover:border-[#1B8FC9]"
                  }`}
                >
                  <ImageIcon size={13} className="shrink-0" />
                  <span className="truncate">
                    {idx + 1}. {photo.file_name}
                  </span>
                </button>
              );
            })}
          </div>
        )}
      </div>
    </Modal>
  );
}
