"use client";

import { useMemo, useState } from "react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { CATEGORIES, PRIORITY_LABELS, minutesText } from "@/lib/support";
import { Btn, Field, FormGrid, INPUT, Modal, SearchSelect } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { TicketCategory, TicketDetail, TicketOptions, TicketPriority } from "@/types/support";

type Toast = (text: string, tone?: "success" | "danger" | "warning", detail?: string) => void;

const NEW_CHANNELS: [string, string][] = [["PHONE", "Teléfono"], ["EMAIL", "Correo"], ["BACKOFFICE", "Backoffice"]];
const CATEGORY_OPTIONS = Object.entries(CATEGORIES) as [TicketCategory, string][];

/** Nuevo ticket (modal del prototipo): cliente, canal, asunto, categoría y descripción; contrato y servicio opcionales. */
export function NewTicketModal({
  options, onClose, onSaved, toast,
}: {
  options: TicketOptions | null;
  onClose: () => void;
  onSaved: (ticket: TicketDetail) => void;
  toast: Toast;
}) {
  const [customer, setCustomer] = useState("");
  const [channel, setChannel] = useState("PHONE");
  const [subject, setSubject] = useState("");
  const [category, setCategory] = useState<TicketCategory>("INCIDENT");
  const [description, setDescription] = useState("");
  const [contract, setContract] = useState("");
  const [service, setService] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  // Contratos vigentes del cliente elegido; sin elegir, el ticket toma el más reciente.
  const contracts = useMemo(() => (options?.contracts ?? []).filter((item) => item.customer_id === customer), [options, customer]);

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      const ticket = await apiFetch<TicketDetail>("/v1/tickets", {
        method: "POST",
        body: JSON.stringify({
          customer_id: customer, channel, subject, category, description: description || null,
          contract_id: contract || null, service_id: service || null,
        }),
      });
      onSaved(ticket);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo crear el ticket."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Nuevo ticket"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy || !options} permission="tickets.create">Crear ticket</Btn>
        </>
      }
    >
      <FormGrid>
        <Field label="Cliente" required span={2} error={errors.customer_id}>
          <SearchSelect
            value={customer}
            onChange={(value) => { setCustomer(value); setContract(""); }}
            options={(options?.customers ?? []).map((item) => ({ value: item.id, label: item.name }))}
            placeholder={options ? "Seleccionar cliente…" : "Cargando…"}
            searchPlaceholder="Buscar cliente…"
          />
        </Field>
        <Field label="Canal">
          <PickSelect className={INPUT} ariaLabel="Canal" value={channel} onChange={setChannel} options={NEW_CHANNELS} />
        </Field>
        <Field label="Asunto" required span={3} error={errors.subject}>
          <input className={INPUT} value={subject} onChange={(e) => setSubject(e.target.value)} maxLength={255} />
        </Field>
        <Field label="Categoría">
          <PickSelect className={INPUT} ariaLabel="Categoría" value={category} onChange={(value) => setCategory(value as TicketCategory)} options={CATEGORY_OPTIONS} />
        </Field>
        <Field label="Contrato" hint={customer && !contracts.length ? "El cliente no tiene contratos vigentes." : "Sin elegir, el vigente más reciente."} error={errors.contract_id}>
          <PickSelect
            className={INPUT}
            ariaLabel="Contrato"
            value={contract}
            onChange={setContract}
            disabled={!customer || !contracts.length}
            options={[["", "Automático"], ...contracts.map((item): [string, string] => [item.id, `${item.number}${item.sla_level === "PREMIUM" ? " · Premium" : ""}`])]}
          />
        </Field>
        <Field label="Servicio" hint="Sin elegir, el primero del contrato." error={errors.service_id}>
          <SearchSelect
            value={service}
            onChange={setService}
            options={[{ value: "", label: "Automático" }, ...(options?.services ?? []).map((item) => ({ value: item.id, label: item.name, hint: item.code }))]}
            placeholder="Automático"
            searchPlaceholder="Buscar servicio…"
          />
        </Field>
        <Field label="Descripción" span={3} hint="Abre la conversación del ticket.">
          <textarea className={INPUT} rows={4} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={5000} />
        </Field>
      </FormGrid>
    </Modal>
  );
}

/** Clasificar (triage): prioridad → SLA. */
export function TriageModal({
  ticket, options, onClose, onSaved, toast,
}: {
  ticket: TicketDetail;
  options: TicketOptions | null;
  onClose: () => void;
  onSaved: (ticket: TicketDetail) => void;
  toast: Toast;
}) {
  const [priority, setPriority] = useState<string>(ticket.priority ?? "");
  const [category, setCategory] = useState<TicketCategory>(ticket.category);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const premium = ticket.contract?.sla_level === "PREMIUM";

  const policies = Object.values(options?.policies ?? {});

  async function save() {
    if (!priority) {
      setError("Selecciona la prioridad.");
      return;
    }
    setBusy(true);
    try {
      onSaved(await apiFetch<TicketDetail>(`/v1/tickets/${ticket.id}/triage`, { method: "POST", body: JSON.stringify({ priority, category }) }));
    } catch (err) {
      toast(firstError(err, "No se pudo clasificar el ticket."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Clasificar ticket"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy} permission="tickets.manage">Clasificar</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <Field label="Prioridad" required error={error}>
          <PickSelect
            className={INPUT}
            ariaLabel="Prioridad"
            value={priority}
            onChange={(value) => { setPriority(value); setError(""); }}
            options={[
              ["", options ? "Seleccionar…" : "Cargando…"],
              ...policies.map((policy): [string, string] => [policy.priority, `${policy.priority} · ${policy.label} (${minutesText(policy.resolution_minutes)})`]),
            ]}
          />
        </Field>
        <Field label="Categoría">
          <PickSelect className={INPUT} ariaLabel="Categoría" value={category} onChange={(value) => setCategory(value as TicketCategory)} options={CATEGORY_OPTIONS} />
        </Field>
        <p className="app-muted text-[12.5px]">
          {premium ? "Contrato Premium: los tiempos se reducen. " : "Con contrato Premium los tiempos se reducen a la mitad. "}
          P1 dispara el escalamiento a Gerencia (el aviso llegará con Notificaciones).
        </p>
      </div>
    </Modal>
  );
}

/** Asignar o reasignar el responsable del ticket. */
export function AssignTicketModal({
  ticket, options, onClose, onSaved, toast,
}: {
  ticket: TicketDetail;
  options: TicketOptions | null;
  onClose: () => void;
  onSaved: (ticket: TicketDetail) => void;
  toast: Toast;
}) {
  const [user, setUser] = useState(ticket.assignee?.id ?? "");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);

  async function save() {
    if (!user) {
      setError("Elige el responsable.");
      return;
    }
    setBusy(true);
    try {
      onSaved(await apiFetch<TicketDetail>(`/v1/tickets/${ticket.id}/assign`, { method: "POST", body: JSON.stringify({ assigned_user_id: user }) }));
    } catch (err) {
      toast(firstError(err, "No se pudo asignar el ticket."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={ticket.assignee ? "Reasignar ticket" : "Asignar ticket"}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy} permission="tickets.manage">Asignar</Btn>
        </>
      }
    >
      <Field label="Responsable" required error={error} hint="Personas cuyo rol puede atender tickets.">
        <SearchSelect
          value={user}
          onChange={(value) => { setUser(value); setError(""); }}
          options={(options?.agents ?? []).map((agent) => ({ value: agent.id, label: agent.name }))}
          placeholder={options ? "Seleccionar…" : "Cargando…"}
          searchPlaceholder="Buscar persona…"
        />
      </Field>
    </Modal>
  );
}

/** Crear OT desde el ticket: sobre un servicio contratado activo del cliente. */
export function TicketWorkOrderModal({
  ticket, onClose, onSaved, toast,
}: {
  ticket: TicketDetail;
  onClose: () => void;
  onSaved: (result: { work_order: { id: string; code: string }; ticket: TicketDetail }) => void;
  toast: Toast;
}) {
  const [service, setService] = useState(ticket.contracted_services[0]?.id ?? "");
  const [busy, setBusy] = useState(false);
  const priority = ticket.priority as TicketPriority | null;

  async function save() {
    setBusy(true);
    try {
      onSaved(await apiFetch(`/v1/tickets/${ticket.id}/work-order`, { method: "POST", body: JSON.stringify({ contracted_service_id: service || null }) }));
    } catch (err) {
      toast(firstError(err, "No se pudo crear la orden de trabajo."), "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title="Crear OT desde el ticket"
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy || !ticket.contracted_services.length} permission="work_orders.create">Crear OT</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <Field label="Servicio contratado" required hint={ticket.contracted_services.length ? "La OT toma la plantilla de checklist del servicio, si tiene." : undefined}>
          {ticket.contracted_services.length ? (
            <PickSelect
              className={INPUT}
              ariaLabel="Servicio contratado"
              value={service}
              onChange={setService}
              options={ticket.contracted_services.map((item): [string, string] => [item.id, `${item.service ?? "Servicio"}${item.contract ? ` · ${item.contract}` : ""}`])}
            />
          ) : (
            <p className="app-muted text-[13px]">El cliente no tiene un servicio contratado activo: la OT nace siempre de uno.</p>
          )}
        </Field>
        <p className="app-muted text-[12.5px]">
          Título: «Atención ticket {ticket.number}: {ticket.subject}». Prioridad {priority && ["P1", "P2"].includes(priority) ? "alta" : "media"}
          {priority ? ` (ticket ${priority} ${PRIORITY_LABELS[priority].toLowerCase()})` : ""}.
        </p>
      </div>
    </Modal>
  );
}
