"use client";

import { useState } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { excelTone, shortDate, stateOf } from "@/lib/commercial";
import type { ExportColumn } from "@/lib/export-table";
import { PORTAL_USER_STATES, relTime, type PortalRole, type PortalRoleInfo, type PortalUserRow } from "@/lib/portal";
import { Badge, Btn, Chip, type Column, Field, INPUT, Modal, SearchSelect } from "@/components/ui/proto";

type Toast = (text: string, tone?: "success" | "danger" | "warning") => void;

function errorText(err: unknown, fallback: string) {
  return err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : fallback;
}

export const PORTAL_USER_EXPORT = (backoffice: boolean): ExportColumn<PortalUserRow>[] => [
  { label: "Usuario", value: (row) => row.name, width: 28 },
  { label: "Correo", value: (row) => row.email, width: 32 },
  ...(backoffice ? [{ label: "Cliente", value: (row: PortalUserRow) => row.customer_name ?? "", width: 32 }] : []),
  { label: "Rol", value: (row) => row.role_name, width: 20, kind: "center" },
  { label: "Último acceso", value: (row) => (row.last_login_at ? shortDate(row.last_login_at) : ""), width: 16, kind: "center" },
  { label: "Estado", value: (row) => stateOf(PORTAL_USER_STATES, row.status)[0], width: 14, kind: "status", tone: (row) => excelTone(stateOf(PORTAL_USER_STATES, row.status)[1]) },
];

/**
 * Columnas de la tabla de usuarios del portal (la `userTable` del prototipo), con sus acciones:
 * reenviar la invitación (en lugar del "Simular aceptación" de la demo), cambiar rol y desactivar o reactivar.
 */
export function portalUserColumns({
  backoffice, permission, busy, onAction,
}: {
  backoffice: boolean;
  /** Permiso de la empresa para las acciones (en el portal no se usa). */
  permission?: string;
  busy: boolean;
  onAction: (action: { kind: "role" | "status" | "resend"; row: PortalUserRow; status?: "ACTIVE" | "INACTIVE" }) => void;
}): Column<PortalUserRow>[] {
  return [
    {
      label: "Usuario", sortKey: backoffice ? "user" : undefined, filter: backoffice ? "text" : undefined,
      render: (row) => (
        <div className="min-w-[170px]">
          <strong className="font-semibold">{row.name}{row.is_me ? " (tú)" : ""}</strong>
          <small className="app-muted block text-xs">{row.email}</small>
        </div>
      ),
    },
    ...(backoffice ? [{ label: "Cliente", sortKey: "customer", filter: "text" as const, render: (row: PortalUserRow) => row.customer_name ?? "—" }] : []),
    { label: "Rol", sortKey: backoffice ? "role" : undefined, render: (row) => <Chip tone={row.role === "PORTAL_ADMIN" ? "accent" : "neutral"}>{row.role_name}</Chip> },
    { label: "Último acceso", sortKey: backoffice ? "last_login" : undefined, filter: backoffice ? "date" : undefined, render: (row) => relTime(row.last_login_at) },
    {
      label: "Estado", sortKey: backoffice ? "status" : undefined, filter: backoffice ? { top: "Estado" } : undefined,
      render: (row) => {
        const [label, tone] = stateOf(PORTAL_USER_STATES, row.status);
        return (
          <div className="grid justify-items-start gap-0.5">
            <Badge tone={tone}>{label}</Badge>
            {row.status === "INVITED" && row.invite_expires_at && (
              <small className="app-muted text-xs">Invitación hasta {shortDate(row.invite_expires_at)}</small>
            )}
          </div>
        );
      },
    },
    {
      label: "",
      render: (row) => (
        <div className="flex flex-wrap items-center gap-1.5 sm:flex-nowrap sm:justify-end" onClick={(e) => e.stopPropagation()}>
          {row.status === "INVITED" && (
            <Btn variant="secondary" size="sm" permission={permission} disabled={busy} onClick={() => onAction({ kind: "resend", row })}>Reenviar invitación</Btn>
          )}
          <Btn variant="ghost" size="sm" permission={permission} disabled={busy || row.status === "INACTIVE" || row.is_me} title={row.is_me ? "No puedes cambiar tu propio rol" : undefined} onClick={() => onAction({ kind: "role", row })}>Cambiar rol</Btn>
          {row.status === "ACTIVE" || row.status === "INVITED" ? (
            <Btn variant="danger-ghost" size="sm" permission={permission} disabled={busy || row.is_me} title={row.is_me ? "No puedes desactivar tu propio usuario" : undefined} onClick={() => onAction({ kind: "status", row, status: "INACTIVE" })}>Desactivar</Btn>
          ) : (
            <Btn variant="ghost" size="sm" permission={permission} disabled={busy} onClick={() => onAction({ kind: "status", row, status: "ACTIVE" })}>Reactivar</Btn>
          )}
        </div>
      ),
    },
  ];
}

/** Ventanas de las acciones: invitar, cambiar rol y confirmar desactivar o reactivar. */
export function PortalUserModals({
  base, roles, customers, maxUsers, invite, action, onClose, onDone, toast,
}: {
  /** "/v1/portal-users" (empresa) o "/v1/portal/users" (Portal Admin). */
  base: string;
  roles: PortalRoleInfo[];
  /** Solo en la empresa: el cliente al que se invita. */
  customers?: { id: string; name: string; users: number }[];
  maxUsers: number;
  invite: boolean;
  action: { kind: "role" | "status" | "resend"; row: PortalUserRow; status?: "ACTIVE" | "INACTIVE" } | null;
  onClose: () => void;
  onDone: () => void;
  toast: Toast;
}) {
  const [customerId, setCustomerId] = useState("");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<PortalRole | "">(action?.row.role ?? "");
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const roleOptions = roles.map((item) => ({ value: item.code, label: item.name, hint: `${item.sections.length} secciones` }));

  async function run(path: string, init: RequestInit, done: string) {
    setBusy(true);
    setErrors({});
    try {
      await apiFetch(path, init);
      toast(done);
      onDone();
    } catch (err) {
      if (err instanceof ApiError && err.errors) setErrors(Object.fromEntries(Object.entries(err.errors).map(([key, list]) => [key, list[0]])));
      else toast(errorText(err, "No se pudo completar la acción."), "danger");
    } finally {
      setBusy(false);
    }
  }

  if (invite) {
    const chosen = customers?.find((customer) => customer.id === customerId);
    return (
      <Modal
        title="Invitar usuario al portal"
        size="sm"
        onClose={onClose}
        footer={
          <>
            <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
            <Btn
              disabled={busy}
              onClick={() => run(base, {
                method: "POST",
                body: JSON.stringify({ ...(customers ? { customer_id: customerId } : {}), name: name.trim(), email: email.trim(), role }),
              }, "Invitación enviada")}
            >
              {busy ? "Enviando…" : "Enviar invitación"}
            </Btn>
          </>
        }
      >
        <div className="grid gap-3">
          {customers && (
            <Field label="Cliente" required error={errors.customer_id} hint={chosen ? `${chosen.users} de ${maxUsers} usuarios del portal` : undefined}>
              <SearchSelect value={customerId} onChange={setCustomerId} placeholder="Selecciona el cliente" options={customers.map((customer) => ({ value: customer.id, label: customer.name, hint: `${customer.users}/${maxUsers}` }))} />
            </Field>
          )}
          <Field label="Nombre completo" required error={errors.name}>
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus={!customers} />
          </Field>
          <Field label="Correo" required error={errors.email}>
            <input type="email" className={INPUT} value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} />
          </Field>
          <Field label="Rol" required error={errors.role}>
            <SearchSelect value={role} onChange={(value) => setRole(value as PortalRole)} placeholder="Selecciona el rol" options={roleOptions} />
          </Field>
          <p className="app-muted text-xs">Se envía un correo con un código de activación válido por 7 días. El acceso exige verificación en dos pasos.</p>
        </div>
      </Modal>
    );
  }

  if (!action) return null;

  if (action.kind === "role") {
    return (
      <Modal
        title={`Rol de ${action.row.name}`}
        size="sm"
        onClose={onClose}
        footer={
          <>
            <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
            <Btn disabled={busy || !role || role === action.row.role} onClick={() => run(`${base}/${action.row.id}`, { method: "PATCH", body: JSON.stringify({ role }) }, "Rol actualizado")}>Guardar</Btn>
          </>
        }
      >
        <Field label="Rol" error={errors.role}>
          <SearchSelect value={role} onChange={(value) => setRole(value as PortalRole)} options={roleOptions} />
        </Field>
        {role && <p className="app-muted mt-2 text-xs">{roles.find((item) => item.code === role)?.sections.join(" · ")}</p>}
      </Modal>
    );
  }

  if (action.kind === "resend") {
    return (
      <Modal
        title="Reenviar invitación"
        size="sm"
        onClose={onClose}
        footer={
          <>
            <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
            <Btn disabled={busy} onClick={() => run(`${base}/${action.row.id}/resend`, { method: "POST" }, "Invitación reenviada")}>Reenviar</Btn>
          </>
        }
      >
        <p>Enviaremos a <strong>{action.row.email}</strong> un código nuevo, válido por 7 días. El anterior deja de servir.</p>
      </Modal>
    );
  }

  const deactivate = action.status === "INACTIVE";
  return (
    <Modal
      title={deactivate ? "Desactivar acceso" : "Reactivar acceso"}
      size="sm"
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn variant={deactivate ? "danger" : "primary"} disabled={busy} onClick={() => run(`${base}/${action.row.id}`, { method: "PATCH", body: JSON.stringify({ status: action.status }) }, "Usuario actualizado")}>
            {deactivate ? "Desactivar" : "Reactivar"}
          </Btn>
        </>
      }
    >
      <p>{deactivate ? "La persona no podrá ingresar al portal. Su historial se conserva." : "La persona podrá volver a ingresar."}</p>
      {(errors.status || errors.role) && <p className="mt-2 text-xs text-[color:var(--p-danger-text)]">{errors.status || errors.role}</p>}
    </Modal>
  );
}
