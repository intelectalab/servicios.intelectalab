"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { Lock, Plus } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { SCOPE_LABELS, useAccess, type Scope } from "@/lib/access-context";
import { PickSelect } from "@/components/ui/pick-select";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import { Btn, Card, Chip, ExportButtons, Field, INPUT, LoadError, Modal, Notice, PageHead, Screen, TD, TH, deniedText, useToasts } from "@/components/ui/proto";

type Role = {
  id: string;
  code: string;
  name: string;
  description: string | null;
  is_admin: boolean;
  users: number;
  permissions: string[];
  /** Alcance del rol en cada recurso que lo lleva. */
  scopes: Record<string, Scope>;
};

type Permission = { code: string; name: string; description: string | null };

/** "Cliente (Portal)" del prototipo: lo decide el portal y aquí solo se muestra. */
type CustomerRole = {
  enabled: boolean;
  users: number;
  matrix: { resource: string; actions: string[]; note: string }[];
  portal_roles: { code: string; name: string; sections: string[] }[];
};

type RolesResponse = { roles: Role[]; permissions: Permission[]; scope_resources: { code: string; name: string }[]; customer_role?: CustomerRole };

// Pestaña del rol del cliente (no es un rol de la empresa).
const CUSTOMER_TAB = "__customer";

type Settings = { segregation: boolean; direct_order: boolean; require_signature: boolean; match_tolerance: number };

// Reglas de la empresa que se activan o desactivan desde esta pantalla.
const RULES: { key: "segregation" | "direct_order" | "require_signature"; label: string; hint: string; on: string; off: string }[] = [
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
  {
    key: "require_signature",
    label: "Exigir la firma electrónica para activar un contrato",
    hint: "El contrato solo pasa a vigente firmado por el cliente (desde su portal) y por la empresa.",
    on: "Firma electrónica exigida",
    off: "Firma electrónica no exigida",
  },
];

// Cómo se llama cada recurso (prefijo del permiso) en la matriz.
const RESOURCES: Record<string, string> = {
  core: "Clientes, proveedores y empleados",
  services: "Catálogo de servicios",
  quotations: "Cotizaciones",
  contracts: "Contratos",
  orders: "Órdenes",
  portal: "Solicitudes del portal",
  leads: "Leads",
  opportunities: "Oportunidades",
  activities: "Actividades",
  campaigns: "Campañas",
  scoring: "Puntuación de leads",
  contracted_services: "Servicios contratados",
  work_orders: "Órdenes de trabajo",
  checklists: "Plantillas de checklist",
  projects: "Proyectos",
  tasks: "Tareas de proyectos",
  deliverables: "Entregables",
  time_entries: "Horas",
  cost_entries: "Costos",
  agenda: "Agenda",
  operations: "Operaciones",
  documents: "Documentos",
  billing: "Eventos facturables y comprobantes",
  receivables: "Cuentas por cobrar",
  payments: "Cobros",
  fiscal: "Facturación electrónica y tipo de cambio",
  treasury: "Bancos y caja",
  finance: "Finanzas",
  recurring: "Recurrencia",
  purchases: "Órdenes de compra",
  payables: "Comprobantes de proveedor, cuentas por pagar y pagos",
  accounting: "Contabilidad (plan, diario, estados)",
  budgets: "Centros de costo y presupuestos",
  tickets: "Tickets y SLA",
  warranty: "Garantías",
  sla: "Calendario laboral y SLA",
  credentials: "Credential Vault",
  reports: "Reportes",
  audit: "Auditoría",
  users: "Usuarios y roles",
  portal_users: "Usuarios del portal",
  tenant: "Empresa",
};

// Acciones de la matriz, en el orden en que se muestran.
const ACTIONS: Record<string, string> = {
  view: "Ver", create: "Crear", manage: "Gestionar", configure: "Configurar", approve: "Aprobar", pay: "Pagar", export: "Exportar", use: "Usar", reveal: "Revelar", delete: "Eliminar",
};

// Alcances que se pueden elegir, de menor a mayor.
const SCOPE_OPTIONS = (["OWN", "TEAM", "BRANCH", "TENANT"] as Scope[]).map((scope): [string, string] => [scope, SCOPE_LABELS[scope]]);

const SCOPE_SELECT =
  "h-8 w-full min-w-[150px] rounded-lg border border-[color:var(--p-field-border)] bg-[var(--app-surface)] px-2.5 text-[12.5px] outline-none focus:border-[#1B8FC9] disabled:bg-[var(--app-surface-muted)] disabled:opacity-70";

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
        <p className="app-muted mt-3 text-[12.5px]">El rol nace sin permisos y alcanzando solo lo propio: ajústalo después en la matriz.</p>
        {error && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{error}</p>}
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
  const customer = selected === CUSTOMER_TAB ? data?.customer_role ?? null : null;
  const canEdit = can("users.manage") && role !== null && !role.is_admin;

  // Filas (recursos) y columnas (acciones) salen del catálogo de permisos de la empresa.
  const codes = new Set(data?.permissions.map((permission) => permission.code));
  const resources = [...new Set(data?.permissions.map((permission) => permission.code.split(".")[0]))].sort(
    (a, b) => (Object.keys(RESOURCES).indexOf(a) + 100) % 100 - (Object.keys(RESOURCES).indexOf(b) + 100) % 100,
  );
  const actions = Object.keys(ACTIONS).filter((action) => resources.some((resource) => codes.has(`${resource}.${action}`)));
  // Recursos cuyo alcance se elige (en los demás, quien tiene el permiso ve toda la empresa).
  const scoped = new Set(data?.scope_resources.map((resource) => resource.code));
  const [exporting, setExporting] = useState(false);

  /** Exporta la matriz del rol abierto (un recurso por fila): pide `users.export` y queda en auditoría. */
  async function runExport(format: "xlsx" | "csv") {
    if (!role) return;
    const columns: ExportColumn<string>[] = [
      { label: "Recurso", value: (resource) => RESOURCES[resource] ?? resource, width: 30 },
      ...actions.map((action): ExportColumn<string> => ({
        label: ACTIONS[action],
        value: (resource) => (codes.has(`${resource}.${action}`) ? (role.permissions.includes(`${resource}.${action}`) ? "Sí" : "No") : "—"),
        width: 12, kind: "center",
      })),
      {
        label: "Alcance",
        value: (resource) => (scoped.has(resource) ? SCOPE_LABELS[(role.scopes[resource] ?? "OWN") as Scope] : SCOPE_LABELS.TENANT),
        width: 18,
      },
    ];
    setExporting(true);
    try {
      await recordExport("users", format, resources.length, true);
      const name = `permisos-${role.name.toLowerCase().replace(/[^a-z0-9]+/gi, "-")}`;
      if (format === "xlsx") await exportXlsx(name, `Permisos · ${role.name}`, columns, resources);
      else exportCsv(name, columns, resources);
      toast(`${resources.length} ${resources.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar la matriz.", "danger");
    } finally {
      setExporting(false);
    }
  }

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

  /** Cambia qué registros de ese recurso alcanza el rol y lo guarda al instante. */
  async function changeScope(resource: string, scope: Scope) {
    if (!role || !data) return;
    const before = role.scopes;
    const apply = (scopes: Record<string, Scope>) =>
      setData((current) => current && {
        ...current,
        roles: current.roles.map((item) => (item.id === role.id ? { ...item, scopes } : item)),
      });

    apply({ ...before, [resource]: scope });
    setSaving(true);
    try {
      await apiFetch(`/v1/roles/${role.id}/permissions`, {
        method: "PUT",
        body: JSON.stringify({ permissions: role.permissions.filter((item) => codes.has(item)), scopes: { [resource]: scope } }),
      });
      toast("Alcance actualizado");
    } catch (err) {
      apply(before);
      toast(firstError(err, "No se pudo guardar el alcance."), "danger");
    } finally {
      setSaving(false);
    }
  }

  // Tolerancia del cruce de 3 vías (Compras): se escribe y se guarda con su botón.
  const [tolerance, setTolerance] = useState<string | null>(null);
  const [savingTolerance, setSavingTolerance] = useState(false);
  const toleranceValue = tolerance ?? (settings ? String(settings.match_tolerance) : "");

  async function saveTolerance() {
    const value = Number(toleranceValue.replace(",", "."));
    if (!Number.isFinite(value) || value < 0 || value > 10) {
      toast("La tolerancia del cruce va de 0 % a 10 %.", "danger");
      return;
    }
    setSavingTolerance(true);
    try {
      const next = await apiFetch<Settings>("/v1/tenant/settings", { method: "PUT", body: JSON.stringify({ match_tolerance: value }) });
      setSettings(next);
      setTolerance(null);
      toast(`Tolerancia del cruce: ${next.match_tolerance} %`);
    } catch (err) {
      toast(firstError(err, "No se pudo guardar el ajuste."), "danger");
    } finally {
      setSavingTolerance(false);
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
                      ? "border-[#1B8FC9] bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]"
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
            {data.customer_role && (
              <button
                type="button"
                role="tab"
                aria-selected={selected === CUSTOMER_TAB}
                onClick={() => setSelected(CUSTOMER_TAB)}
                className={`grid cursor-pointer rounded-[7px] border px-3 py-[7px] text-left transition-colors duration-200 ${
                  selected === CUSTOMER_TAB
                    ? "border-[#1B8FC9] bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]"
                    : "app-surface border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"
                }`}
              >
                <strong className="inline-flex items-center gap-1 font-semibold">Cliente (Portal) <Lock size={12} aria-hidden="true" /></strong>
                <small className={`text-[10.5px] ${selected === CUSTOMER_TAB ? "" : "app-muted"}`}>
                  {data.customer_role.users} {data.customer_role.users === 1 ? "usuario" : "usuarios"} · solo lectura
                </small>
              </button>
            )}
          </div>

          {customer && <CustomerRoleCard customer={customer} actions={actions} />}

          {role && (
            <Card
              title={role.name}
              actions={!can("users.manage") ? (
                <span title={deniedText("users.manage")}>
                  <Chip tone="neutral"><Lock size={11} className="mr-1" aria-hidden="true" />Solo lectura</Chip>
                </span>
              ) : undefined}
            >
              {role.description && <p className="app-muted mb-2 text-[12.5px]">{role.description}</p>}
              <div className="proto-tbl-wrap -mx-3 overflow-x-auto sm:-mx-5">
                <table className="proto-tbl w-full border-collapse text-[13px]">
                  <thead>
                    <tr>
                      <th className={TH}>Recurso</th>
                      {actions.map((action) => <th key={action} className={`${TH} text-center`}>{ACTIONS[action]}</th>)}
                      <th className={TH}>Alcance</th>
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
                        <td className={TD} data-label="Alcance">
                          {scoped.has(resource) ? (
                            <span
                              className="block sm:max-w-[190px]"
                              title={
                                !can("users.manage")
                                  ? deniedText("users.manage")
                                  : role.is_admin || role.permissions.includes(`${resource}.view`)
                                    ? undefined
                                    : "Marca «Ver» para elegir el alcance."
                              }
                            >
                              <PickSelect
                                ariaLabel={`Alcance ${RESOURCES[resource] ?? resource}`}
                                className={SCOPE_SELECT}
                                value={role.scopes[resource] ?? "OWN"}
                                onChange={(scope) => changeScope(resource, scope as Scope)}
                                options={SCOPE_OPTIONS}
                                disabled={!canEdit || saving || !role.permissions.includes(`${resource}.view`)}
                              />
                            </span>
                          ) : (
                            <span className="app-muted">{SCOPE_LABELS.TENANT}</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="-mx-3 border-t border-[color:var(--app-border)] px-3 pt-2.5 sm:-mx-5 sm:px-5">
                <ExportButtons permission="users.export" total={resources.length} busy={exporting} onExport={runExport} />
              </div>
              <p className="app-muted mt-3 text-[12.5px]">
                El alcance decide qué registros ve el rol: los propios, los de su equipo, los de su sede o los de toda la empresa.
                La sede y el equipo de cada persona se asignan en Usuarios.
              </p>
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
                      {!can("tenant.manage") && <Lock size={12} className="app-muted ml-1.5 inline align-[-1px]" aria-label={deniedText("tenant.manage")} />}
                      <small className="app-muted block text-[12.5px]">{rule.hint}</small>
                    </span>
                  </label>
                ))}
                <div className="flex flex-wrap items-start gap-2.5 border-t border-[color:var(--app-border)] pt-3">
                  <label className="relative min-w-0 flex-[1_1_260px]">
                    Tolerancia del cruce de 3 vías en Compras
                    <small className="app-muted block text-[12.5px]">
                      Diferencia máxima entre lo facturado por el proveedor y lo recibido de su orden de compra para aprobar el comprobante. De 0 % a 10 %; si no se cambia, 2 %.
                    </small>
                  </label>
                  <span className="inline-flex items-center gap-1.5">
                    <input
                      type="number" min={0} max={10} step={0.5} inputMode="decimal" aria-label="Tolerancia del cruce de 3 vías (%)"
                      className={`${INPUT} h-9 w-[86px] text-right tabular-nums`} value={toleranceValue}
                      disabled={!can("tenant.manage")} title={can("tenant.manage") ? undefined : deniedText("tenant.manage")}
                      onChange={(event) => setTolerance(event.target.value)}
                      onKeyDown={(event) => { if (event.key === "Enter") saveTolerance(); }}
                    />
                    <span>%</span>
                    {!can("tenant.manage") && <Lock size={12} className="app-muted" aria-label={deniedText("tenant.manage")} />}
                    <Btn size="sm" variant="secondary" permission="tenant.manage" disabled={savingTolerance || tolerance === null} onClick={saveTolerance}>
                      {savingTolerance ? "Guardando…" : "Guardar"}
                    </Btn>
                  </span>
                </div>
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

/**
 * "Cliente (Portal)": el usuario externo del cliente, como en la matriz del prototipo, pero de solo
 * lectura. Lo que ve cada persona lo deciden los roles del portal y siempre solo lo de su empresa:
 * así nadie le da por error a un cliente un permiso interno.
 */
function CustomerRoleCard({ customer, actions }: { customer: CustomerRole; actions: string[] }) {
  // Columnas: las de la matriz de la empresa y, si el portal usa otra, también esa.
  const columns = [...new Set([...actions, ...customer.matrix.flatMap((row) => row.actions)])].filter((action) => ACTIONS[action]);
  const locked = "Solo lectura: lo deciden los roles del portal";

  return (
    <>
      <Card title={<span className="inline-flex items-center gap-2">Cliente (Portal) <Chip tone="neutral"><Lock size={11} className="mr-1" aria-hidden="true" />Solo lectura</Chip></span>}>
        {!customer.enabled && <Notice tone="warn">La empresa no tiene el módulo Portal de clientes: sus clientes no pueden entrar al portal.</Notice>}
        <Notice>
          Este rol no se edita aquí. Lo que ve cada persona lo decide su rol del portal (abajo), siempre solo lo de su empresa. Las cuentas se
          invitan, cambian de rol o desactivan en{" "}
          <Link href="/portal-users" className="font-semibold underline">Usuarios del portal</Link>.
        </Notice>
        <div className="proto-tbl-wrap -mx-3 overflow-x-auto sm:-mx-5">
          <table className="proto-tbl w-full border-collapse text-[13px]">
            <thead>
              <tr>
                <th className={TH}>Recurso</th>
                {columns.map((action) => <th key={action} className={`${TH} text-center`}>{ACTIONS[action]}</th>)}
                <th className={TH}>Alcance</th>
              </tr>
            </thead>
            <tbody>
              {customer.matrix.map((row) => (
                <tr key={row.resource}>
                  <td className={TD} data-label="Recurso">
                    <strong className="font-semibold">{RESOURCES[row.resource] ?? row.resource}</strong>
                    <small className="app-muted block max-w-[360px] text-[11.5px]">{row.note}</small>
                  </td>
                  {columns.map((action) => (
                    <td key={action} className={`${TD} text-center`} data-label={ACTIONS[action]}>
                      <input
                        type="checkbox"
                        className="h-[17px] w-[17px] cursor-not-allowed accent-[#1B8FC9]"
                        checked={row.actions.includes(action)}
                        disabled
                        readOnly
                        aria-label={`${ACTIONS[action]} ${RESOURCES[row.resource] ?? row.resource}`}
                        title={locked}
                      />
                    </td>
                  ))}
                  <td className={TD} data-label="Alcance">
                    <span className={`whitespace-nowrap ${row.actions.length ? "" : "app-muted"}`}>{row.actions.length ? "Solo su empresa" : "—"}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <Card title="Roles del portal">
        <p className="app-muted mb-3 text-[12.5px]">Dentro del portal, cada persona tiene uno de estos roles; una sección se abre si su rol la incluye y si la empresa tiene el módulo del que salen sus datos.</p>
        <ul className="grid gap-2.5">
          {customer.portal_roles.map((portalRole) => (
            <li key={portalRole.code}>
              <strong className="font-semibold">{portalRole.name}</strong>
              <small className="app-muted block text-xs">{portalRole.sections.join(" · ")}</small>
            </li>
          ))}
        </ul>
      </Card>
    </>
  );
}
