"use client";

import { useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { shortDate, stateOf } from "@/lib/commercial";
import { fieldErrors, firstError } from "@/lib/crm";
import { HR_MASTERED_NOTE, LEAVE_STATES, calendarDays, hrDateTime, isoDay } from "@/lib/personal";
import { Badge, Btn, Chip, Field, FormGrid, INPUT, Kv, Modal, Notice, SearchSelect } from "@/components/ui/proto";
import { ReasonModal } from "@/components/dashboard/crm/CrmModals";
import { ifMatch, type Toast } from "@/components/dashboard/personal/EmployeeModals";
import type { LeaveOptions, LeaveRequest } from "@/types/personal";

export type LeaveConflicts = { employee: string; items: NonNullable<LeaveRequest["conflicts"]> };

/** Lo de RRHH (con la conexión activa) se pide y aprueba en el SaaS de RRHH. */
export function leaveMastered(leave: LeaveRequest, options: LeaveOptions | null) {
  if (options?.mode !== "HR_SAAS_MASTER") return false;
  return leave.source === "HR_SAAS" || Boolean(options.employees.find((e) => e.id === leave.employee.id)?.mastered);
}

/** Nueva solicitud de ausencia: trabajador, tipo, fechas, días y motivo. */
export function LeaveFormModal({
  options, onClose, onSaved, toast,
}: { options: LeaveOptions | null; onClose: () => void; onSaved: (leave: LeaveRequest) => void; toast: Toast }) {
  const [employee, setEmployee] = useState("");
  const [type, setType] = useState("");
  const [from, setFrom] = useState(isoDay(new Date()));
  const [to, setTo] = useState(isoDay(new Date()));
  const [days, setDays] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  // Se propone la persona que mira, si es trabajador y su ficha se gestiona aquí.
  const mine = options?.employees.find((e) => e.mine && !e.mastered)?.id ?? "";
  const chosenEmployee = employee || mine;
  const types = (options?.types ?? []).filter((t) => t.active);
  const chosenType = types.find((t) => t.id === type);
  const span = calendarDays(from, to);
  // Por defecto, los días de calendario; se pueden bajar (p. ej. sin contar el domingo).
  const shownDays = days === "" ? (span ? String(span) : "") : days;

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      onSaved(await apiFetch<LeaveRequest>("/v1/hr/leaves", {
        method: "POST",
        body: JSON.stringify({
          employee_id: chosenEmployee || null,
          leave_type_id: type || null,
          date_from: from || null,
          date_to: to || null,
          days: shownDays === "" ? null : Number(shownDays),
          reason: reason.trim() || null,
        }),
      }));
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo registrar la solicitud."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const masteredCount = options?.employees.filter((e) => e.mastered).length ?? 0;

  return (
    <Modal
      title="Nueva solicitud de ausencia"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy || !options} permission="leaves.create">{busy ? "Registrando…" : "Registrar solicitud"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        {masteredCount > 0 && <Notice>Las ausencias del personal de RRHH se piden en el SaaS de RRHH.</Notice>}
        <FormGrid>
          <Field label="Trabajador" required error={errors.employee_id} span={2}>
            <SearchSelect
              value={chosenEmployee}
              onChange={setEmployee}
              options={(options?.employees ?? []).map((e) => ({
                value: e.id,
                label: e.mine ? `${e.name} (tú)` : e.name,
                hint: e.mastered ? HR_MASTERED_NOTE : undefined,
                disabled: e.mastered,
              }))}
              placeholder={options ? "Seleccionar…" : "Cargando…"}
              searchPlaceholder="Buscar trabajador…"
              empty="No hay trabajadores activos a tu alcance."
            />
          </Field>
          <Field
            label="Tipo"
            required
            error={errors.leave_type_id}
            hint={chosenType ? [chosenType.paid ? "Con goce" : "Sin goce", chosenType.requires_approval ? "requiere aprobación" : "se aprueba al registrarla"].join(" · ") : undefined}
          >
            <select className={INPUT} value={type} onChange={(e) => setType(e.target.value)} disabled={!options}>
              <option value="">Seleccionar…</option>
              {types.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </Field>
          <Field label="Desde" required error={errors.date_from}>
            <input
              type="date" className={INPUT} value={from}
              onChange={(e) => {
                setFrom(e.target.value);
                if (to && e.target.value > to) setTo(e.target.value);
                setDays("");
              }}
            />
          </Field>
          <Field label="Hasta" required error={errors.date_to}>
            <input type="date" className={INPUT} value={to} min={from || undefined} onChange={(e) => { setTo(e.target.value); setDays(""); }} />
          </Field>
          <Field label="Días" error={errors.days} hint={span ? `${span} ${span === 1 ? "día" : "días"} de calendario` : undefined}>
            <input type="number" className={INPUT} value={shownDays} onChange={(e) => setDays(e.target.value)} min={1} max={span || undefined} step={1} />
          </Field>
          <Field label="Motivo" error={errors.reason} span={3}>
            <textarea className={INPUT} rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={2000} />
          </Field>
        </FormGrid>
      </div>
    </Modal>
  );
}

export type LeaveAction = "approve" | "reject" | "cancel";

/** Aprobar, rechazar (con motivo) o anular (con confirmación) una solicitud. */
export function useLeaveActions(toast: Toast, onDone: (leave: LeaveRequest) => void, onConflicts: (conflicts: LeaveConflicts) => void) {
  const [busy, setBusy] = useState(false);
  const [asking, setAsking] = useState<{ leave: LeaveRequest; action: "reject" | "cancel" } | null>(null);
  const [note, setNote] = useState("");

  async function run(leave: LeaveRequest, action: LeaveAction, body?: Record<string, string>) {
    setBusy(true);
    try {
      const next = await apiFetch<LeaveRequest>(`/v1/hr/leaves/${leave.id}/${action}`, {
        method: "POST",
        body: body ? JSON.stringify(body) : undefined,
        headers: ifMatch(leave.lock_version),
      });
      toast(action === "approve" ? "Ausencia aprobada" : action === "reject" ? "Solicitud rechazada" : "Ausencia anulada");
      if (action === "approve" && next.conflicts?.length) {
        onConflicts({ employee: leave.employee.name, items: next.conflicts });
        toast("Tiene trabajo agendado en esas fechas", "warning", "Reasígnalo desde la agenda o la orden de trabajo.");
      }
      setAsking(null);
      onDone(next);
    } catch (err) {
      toast(firstError(err, "No se pudo completar la acción."), "danger");
      if (err instanceof ApiError && err.status === 409) onDone(leave);
    } finally {
      setBusy(false);
    }
  }

  function start(leave: LeaveRequest, action: LeaveAction) {
    if (action === "approve") run(leave, action);
    else {
      setNote("");
      setAsking({ leave, action });
    }
  }

  const describe = (leave: LeaveRequest) => `${leave.employee.name} · ${leave.type.name} del ${shortDate(leave.date_from)} al ${shortDate(leave.date_to)}`;

  const actionModal = asking && (asking.action === "reject" ? (
    <ReasonModal
      title="Rechazar solicitud"
      text={describe(asking.leave)}
      label="Motivo del rechazo"
      confirmLabel="Rechazar"
      busy={busy}
      onClose={() => setAsking(null)}
      onConfirm={(reason) => run(asking.leave, "reject", { note: reason })}
    />
  ) : (
    <Modal
      title="Anular ausencia"
      size="sm"
      onClose={() => setAsking(null)}
      footer={
        <>
          <Btn variant="ghost" onClick={() => setAsking(null)}>Volver</Btn>
          <Btn variant="danger" disabled={busy} onClick={() => run(asking.leave, "cancel", note.trim() ? { note: note.trim() } : undefined)}>Anular</Btn>
        </>
      }
    >
      <p className="text-[15px]">{describe(asking.leave)}</p>
      <div className="mt-3">
        <Field label="Nota">
          <textarea className={INPUT} rows={2} value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} placeholder="Opcional" />
        </Field>
      </div>
    </Modal>
  ));

  return { busy, start, actionModal };
}

/** Botones de una solicitud según su estado. */
export function LeaveActions({
  leave, mastered, busy, onAction, size = "sm",
}: { leave: LeaveRequest; mastered: boolean; busy: boolean; onAction: (action: LeaveAction) => void; size?: "sm" | "md" }) {
  const locked = mastered ? HR_MASTERED_NOTE : undefined;
  const actions = [];
  if (leave.status === "REQUESTED") {
    const own = leave.mine ? "No puedes aprobar tu propia solicitud" : undefined;
    actions.push(
      <Btn key="approve" size={size} variant="secondary" onClick={() => onAction("approve")} permission="leaves.approve" disabled={busy || leave.mine || mastered} title={locked ?? own}>
        Aprobar
      </Btn>,
      <Btn key="reject" size={size} variant="danger-ghost" onClick={() => onAction("reject")} permission="leaves.approve" disabled={busy || leave.mine || mastered} title={locked ?? own}>
        Rechazar
      </Btn>,
    );
  }
  if (leave.status === "REQUESTED" || leave.status === "APPROVED") {
    actions.push(
      <Btn key="cancel" size={size} variant="ghost" onClick={() => onAction("cancel")} permission="leaves.create" disabled={busy || mastered} title={locked}>
        Anular
      </Btn>,
    );
  }
  return actions.length ? <div className="flex flex-wrap gap-1.5">{actions}</div> : null;
}

/** Detalle de una solicitud, con sus acciones. */
export function LeaveDetailModal({
  leave, mastered, busy, onAction, onClose,
}: { leave: LeaveRequest; mastered: boolean; busy: boolean; onAction: (action: LeaveAction) => void; onClose: () => void }) {
  const [label, tone] = stateOf(LEAVE_STATES, leave.status);

  return (
    <Modal title={`Ausencia de ${leave.employee.name}`} onClose={onClose} footer={<Btn variant="ghost" onClick={onClose}>Cerrar</Btn>}>
      <div className="grid gap-4">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="inline-flex flex-wrap items-center gap-1.5">
            <Badge tone={tone}>{label}</Badge>
            {!leave.type.paid && <Chip tone="warning">Sin goce</Chip>}
            {leave.source === "HR_SAAS" && <Chip tone="accent">RRHH</Chip>}
          </span>
          <LeaveActions leave={leave} mastered={mastered} busy={busy} onAction={onAction} size="md" />
        </div>
        {mastered && <p className="app-muted text-[12.5px]">{HR_MASTERED_NOTE}.</p>}
        <Kv
          rows={[
            ["Trabajador", leave.employee.name],
            ["Tipo", `${leave.type.name} · ${leave.type.paid ? "con goce" : "sin goce"}`],
            ["Desde", shortDate(leave.date_from)],
            ["Hasta", shortDate(leave.date_to)],
            ["Días", String(leave.days)],
            ["Motivo", leave.reason ? <span key="reason" className="whitespace-pre-line">{leave.reason}</span> : "—"],
            ["Solicitada", hrDateTime(leave.created_at)],
            leave.decided_at ? ["Decidida", `${leave.decided_by?.name ?? "Automática"} · ${hrDateTime(leave.decided_at)}`] : null,
            leave.decision_note ? [leave.status === "REJECTED" ? "Motivo del rechazo" : "Nota", leave.decision_note] : null,
            ["Origen", leave.source === "HR_SAAS" ? "SaaS de RRHH" : "ERP"],
          ]}
        />
      </div>
    </Modal>
  );
}
