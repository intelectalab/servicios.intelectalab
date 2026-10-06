"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Plus } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { useAccess } from "@/lib/access-context";
import { Btn, Card, Field, INPUT, LoadError, Modal, PageHead, Screen, TD, TH, deniedText, useToasts } from "@/components/ui/proto";

type Role = {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_admin: boolean;
  users: number;
  permissions: string[];
};

type Permission = { code: string; name: string; description: string | null };

type RolesResponse = { roles: Role[]; permissions: Permission[] };

type Settings = { segregation: boolean; direct_order: boolean };

// Reglas de la empresa que se activan o desactivan desde esta pantalla.
const RULES: { key: keyof Settings; label: string; hint: string; on: string; off: string }[] = [
  {
    key: "segregation",
    label: "Quien crea una cotización no puede aprobarla",
    hint: "Segregación de funciones: aunque su rol tenga el permiso de aprobar, la decisión debe tomarla otra persona.",
    on: "Segregación de funciones activada",
    off: "Segregación de funciones desactivada",
  },
  {
    key: "direct_order",
    label: "Permitir orden directa desde una cotización aprobada",
    hint: "La cotización aprobada se puede convertir en orden sin pasar por contrato.",
    on: "Orden directa permitida",
    off: "Orden directa no permitida",
  },
];

// Cómo se llama cada recurso (prefijo del permiso) en la matriz.
const RESOURCES: Record<string, string> = {
  core: "Clientes, proveedores y empleados",
  services: "Catálogo de servicios",
  quotations: "Cotizaciones",
  contracts: "Contratos",
  orders: "Órdenes",
  crm: "CRM",
  operations: "Operaciones",
  documents: "Documentos",
  billing: "Facturación y cobros",
  finance: "Finanzas",
  recurring: "Recurrencia",
  support: "Soporte",
  credentials: "Credential Vault",
  reports: "Reportes",
  users: "Usuarios y roles",
  tenant: "Empresa",
};

// Acciones de la matriz, en el orden en que se muestran.
const ACTIONS: Record<string, string> = {
  view: "Ver", manage: "Gestionar", approve: "Aprobar", export: "Exportar", use: "Usar", reveal: "Revelar", delete: "Eliminar",
};

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

function RoleModal({ onClose, onSaved }: { onClose: () => void; onSaved: (id: string) => void }) {
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Indica el nombre del rol.");
      return;
    }

    setSaving(true);
    setError("");
    try {
      const created = await apiFetch<{ id: string }>("/v1/roles", {
        method: "POST",
        body: JSON.stringify({ name: name.trim(), description: description.trim() || null }),
      });
      onSaved(created.id);
    } catch (err) {
      setError(firstError(err, "No se pudo crear el rol."));
      setSaving(false);
    }
  }

  return (
    <Modal title="Nuevo rol" size="sm" onClose={onClose}>
      <form onSubmit={submit}>
        <div className="grid gap-3">
          <Field label="Nombre" required>
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={100} required autoFocus />
          </Field>
          <Field label="Descripción">
            <textarea className={INPUT} rows={2} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} />
          </Field>
        </div>
        <p className="app-muted mt-3 text-[12.5px]">El rol nace sin permisos: márcalos después en la matriz.</p>
        {error && <p className="mt-2 text-[12.5px] text-[#D93A40]">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Creando…" : "Crear rol"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

export default function RolesPermissions() {
  const { can } = useAccess();
  const { toast, toasts } = useToasts();

  const [data, setData] = useState<RolesResponse | null>(null);
  const [selected, setSelected] = useState("");
  const [settings, setSettings] = useState<Settings | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [modal, setModal] = useState(false);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      try {
        const [roles, current] = await Promise.all([
          apiFetch<RolesResponse>("/v1/roles"),
          apiFetch<Settings>("/v1/tenant/settings").catch(() => null),
        ]);
        if (cancelled) return;
        setData(roles);
        setSettings(current);
        // Como el prototipo, se abre en un rol editable (no en el Administrador).
        setSelected((current) => current || (roles.roles.find((role) => !role.is_admin) ?? roles.roles[0])?.id || "");
        setError("");
      } catch {
        if (!cancelled) setError("No se pudieron cargar los roles.");
      }
    }
    load();
    return () => { cancelled = true; };
  }, [reload]);

  const role = data?.roles.find((item) => item.id === selected) ?? null;
  const canEdit = can("users.manage") && role !== null && !role.is_admin;

  // Filas (recursos) y columnas (acciones) salen del catálogo de permisos de la empresa.
  const codes = new Set(data?.permissions.map((permission) => permission.code));
  const resources = [...new Set(data?.permissions.map((permission) => permission.code.split(".")[0]))].sort(
    (a, b) => (Object.keys(RESOURCES).indexOf(a) + 100) % 100 - (Object.keys(RESOURCES).indexOf(b) + 100) % 100,
  );
  const actions = Object.keys(ACTIONS).filter((action) => resources.some((resource) => codes.has(`${resource}.${action}`)));

  /** Marca o desmarca un permiso y lo guarda al instante, como el prototipo. */
  async function toggle(code: string, on: boolean) {
    if (!role || !data) return;
    const before = role.permissions;
    const next = on ? [...before, code] : before.filter((item) => item !== code);
    const apply = (permissions: string[]) =>
      setData((current) => current && {
        ...current,
        roles: current.roles.map((item) => (item.id === role.id ? { ...item, permissions } : item)),
      });

    apply(next);
    setSaving(true);
    try {
      // Solo viajan los permisos de la matriz; los de módulos que la empresa no tiene se conservan en el servidor.
      await apiFetch(`/v1/roles/${role.id}/permissions`, {
        method: "PUT",
        body: JSON.stringify({ permissions: next.filter((item) => codes.has(item)) }),
      });
      toast("Permiso actualizado");
    } catch (err) {
      apply(before);
      toast(firstError(err, "No se pudo guardar el permiso."), "danger");
    } finally {
      setSaving(false);
    }
  }

  async function toggleRule(rule: (typeof RULES)[number], on: boolean) {
    const before = settings;
    setSettings((current) => current && { ...current, [rule.key]: on });
    try {
      await apiFetch("/v1/tenant/settings", { method: "PUT", body: JSON.stringify({ [rule.key]: on }) });
      toast(on ? rule.on : rule.off);
    } catch (err) {
      setSettings(before);
      toast(firstError(err, "No se pudo guardar el ajuste."), "danger");
    }
  }

  if (error) {
    return (
      <Screen>
        <PageHead title="Roles y permisos" />
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      </Screen>
    );
  }

  return (
    <Screen>
      <PageHead
        title="Roles y permisos"
        actions={
          <Btn variant="secondary" onClick={() => setModal(true)} permission="users.manage">
            <Plus size={16} />
            <span>Nuevo rol</span>
          </Btn>
        }
      />

      {!data ? (
        <Card><div className="h-48 animate-pulse rounded bg-[var(--app-surface-muted)]" aria-busy="true" /></Card>
      ) : (
        <>
          <div role="tablist" aria-label="Roles" className="mb-3.5 flex flex-wrap gap-1.5">
            {data.roles.map((item) => {
              const on = item.id === selected;
              return (
                <button
                  key={item.id}
                  type="button"
                  role="tab"
                  aria-selected={on}
                  onClick={() => setSelected(item.id)}
                  className={`grid cursor-pointer rounded-[7px] border px-3 py-[7px] text-left transition-colors duration-200 ${
                    on
                      ? "border-[#1B8FC9] bg-[#E3F2FA] text-[#0F5A82]"
                      : "app-surface border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"
                  }`}
                >
                  <strong className="font-semibold">{item.name}</strong>
                  <small className={`text-[10.5px] ${on ? "" : "app-muted"}`}>
                    {item.users} {item.users === 1 ? "usuario" : "usuarios"}
                  </small>
                </button>
              );
            })}
          </div>

          {role && (
            <Card title={role.name}>
              {role.description && <p className="app-muted mb-2 text-[12.5px]">{role.description}</p>}
              <div className="proto-tbl-wrap -mx-3 overflow-x-auto sm:-mx-5">
                <table className="proto-tbl w-full border-collapse text-[13px]">
                  <thead>
                    <tr>
                      <th className={TH}>Recurso</th>
                      {actions.map((action) => <th key={action} className={`${TH} text-center`}>{ACTIONS[action]}</th>)}
                    </tr>
                  </thead>
                  <tbody>
                    {resources.map((resource) => (
                      <tr key={resource}>
                        <td className={TD} data-label="Recurso"><strong className="font-semibold">{RESOURCES[resource] ?? resource}</strong></td>
                        {actions.map((action) => {
                          const code = `${resource}.${action}`;
                          return (
                            <td key={action} className={`${TD} text-center`} data-label={ACTIONS[action]}>
                              {codes.has(code) ? (
                                <input
                                  type="checkbox"
                                  className="h-[17px] w-[17px] cursor-pointer accent-[#1B8FC9] disabled:cursor-not-allowed"
                                  checked={role.permissions.includes(code)}
                                  disabled={!canEdit || saving}
                                  onChange={(event) => toggle(code, event.target.checked)}
                                  aria-label={`${ACTIONS[action]} ${RESOURCES[resource] ?? resource}`}
                                  title={
                                    can("users.manage")
                                      ? data.permissions.find((permission) => permission.code === code)?.name
                                      : deniedText("users.manage")
                                  }
                                />
                              ) : (
                                <span className="app-muted">—</span>
                              )}
                            </td>
                          );
                        })}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {role.is_admin && (
                <p className="app-muted mt-3 text-[12.5px]">
                  El rol Administrador no se recorta desde aquí para no dejar la empresa sin administrador.
                </p>
              )}
            </Card>
          )}

          {settings && (
            <Card title="Reglas de la empresa">
              <div className="grid gap-3">
                {RULES.map((rule) => (
                  <label key={rule.key} className="flex cursor-pointer items-start gap-2.5">
                    <input
                      type="checkbox"
                      className="mt-0.5 h-[17px] w-[17px] accent-[#1B8FC9] disabled:cursor-not-allowed"
                      checked={settings[rule.key]}
                      disabled={!can("tenant.manage")}
                      title={can("tenant.manage") ? undefined : deniedText("tenant.manage")}
                      onChange={(event) => toggleRule(rule, event.target.checked)}
                    />
                    <span>
                      {rule.label}
                      <small className="app-muted block text-[12.5px]">{rule.hint}</small>
                    </span>
                  </label>
                ))}
              </div>
            </Card>
          )}
        </>
      )}

      {modal && (
        <RoleModal
          onClose={() => setModal(false)}
          onSaved={(id) => {
            setModal(false);
            setSelected(id);
            setReload((n) => n + 1);
            toast("Rol creado");
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
