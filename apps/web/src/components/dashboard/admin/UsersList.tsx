"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Lock, Plus } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, FormGrid, INPUT, LoadError, Modal, PAGE_SIZES,
  PageHead, Pager, Screen, SearchInput, SearchSelect, SelectionBar, Tabs, useRowSelection, useToasts, type Column,
  type SortState,
} from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import { ORG_UNIT_TEXT, OrgUnits, type OrgUnitKind } from "@/components/dashboard/admin/OrgUnits";

type CompanyUser = {
  id: string;
  name: string;
  email: string;
  status: string;
  role: { id: string; name: string; is_admin: boolean } | null;
  branch: { id: string; name: string } | null;
  /** Sedes adicionales: amplían lo que la persona ve con el alcance "Sede". */
  extra_branches: { id: string; name: string }[];
  hourly_cost?: number;
  team: { id: string; name: string } | null;
  employee_id: string | null;
  /** Entra con verificación en dos pasos (por su rol o porque se le exige). */
  mfa: boolean;
  /** Se le exige expresamente. */
  mfa_enabled: boolean;
  last_login_at: string | null;
};

type Option = { id: string; name: string };
type Employee = { id: string; user_id: string | null; name: string; code: string | null };

type UsersResponse = {
  users: CompanyUser[];
  roles: (Option & { code: string })[];
  branches: Option[];
  teams: Option[];
  employees: Employee[];
  /** Usuarios activos que permite el plan; null si no hay límite. */
  limit: number | null;
  /** El rol Administrador exige verificación en dos pasos (ADMIN_MFA_REQUIRED en la API). */
  admin_mfa_required?: boolean;
};

const userKey = (user: CompanyUser) => user.id;
const isActive = (user: CompanyUser) => user.status === "ACTIVE";
const plain = (text: string) => text.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
const initials = (name: string) => name.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
const lastLogin = (value: string | null) =>
  value ? new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" }) : "Nunca";

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

/** Opciones activas, más la que la persona ya tiene aunque se haya desactivado. */
function withCurrent(options: Option[], current?: Option | null): [string, string][] {
  const list = current && !options.some((option) => option.id === current.id) ? [...options, current] : options;
  return list.map((option) => [option.id, option.name]);
}

function UserModal({
  user, options, onClose, onSaved,
}: { user: CompanyUser | null; options: UsersResponse; onClose: () => void; onSaved: (created: boolean, invited: boolean) => void }) {
  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [roleId, setRoleId] = useState(user?.role?.id ?? "");
  const [branchId, setBranchId] = useState(user?.branch?.id ?? "");
  const [extraBranchIds, setExtraBranchIds] = useState<string[]>(user?.extra_branches.map((branch) => branch.id) ?? []);
  const [teamId, setTeamId] = useState(user?.team?.id ?? "");
  const [hourlyCost, setHourlyCost] = useState(user?.hourly_cost ? String(user.hourly_cost) : "");
  const [employeeId, setEmployeeId] = useState(user?.employee_id ?? "");
  const [mfa, setMfa] = useState(user?.mfa_enabled ?? false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // Sedes que se pueden marcar como adicionales: todas menos la principal (y las que ya tenga, aunque estén desactivadas).
  const extraOptions = [
    ...options.branches,
    ...(user?.extra_branches.filter((branch) => !options.branches.some((option) => option.id === branch.id)) ?? []),
  ].filter((branch) => branch.id !== branchId).map((branch): [string, string] => [branch.id, branch.name]);

  // Con el rol Administrador la API exige el MFA aunque la casilla esté vacía: se muestra marcada y bloqueada.
  const mfaForced = options.admin_mfa_required !== false
    && options.roles.find((role) => role.id === roleId)?.code.toUpperCase() === "ADMIN";

  // Empleados sin cuenta, más el que ya esté vinculado a este usuario.
  const employees = options.employees.filter((employee) => !employee.user_id || employee.user_id === user?.id);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};
    if (!name.trim()) found.name = "Indica el nombre completo.";
    if (!email.trim()) found.email = "Indica el correo.";
    if (!roleId) found.role_id = "Selecciona un rol.";
    setErrors(found);
    if (Object.keys(found).length) return;

    setSaving(true);
    try {
      const saved = await apiFetch<{ id: string; invited?: boolean }>(user ? `/v1/users/${user.id}` : "/v1/users", {
        method: user ? "PUT" : "POST",
        body: JSON.stringify({
          name: name.trim(),
          email: email.trim(),
          role_id: roleId,
          branch_id: branchId || null,
          // La principal no se repite como adicional; sin principal no hay adicionales.
          extra_branch_ids: branchId ? extraBranchIds.filter((id) => id !== branchId) : [],
          team_id: teamId || null,
          hourly_cost: hourlyCost === "" ? 0 : Number(hourlyCost),
          employee_id: employeeId || null,
          mfa,
        }),
      });
      onSaved(!user, saved?.invited !== false);
    } catch (err) {
      if (err instanceof ApiError && err.errors) {
        const [field, messages] = Object.entries(err.errors)[0];
        setErrors({ [field]: messages[0] });
      } else {
        setErrors({ form: firstError(err, "No se pudo guardar el usuario.") });
      }
      setSaving(false);
    }
  }

  return (
    <Modal title={user ? "Editar usuario" : "Nuevo usuario"} onClose={onClose}>
      <form onSubmit={submit}>
        <FormGrid>
          <Field label="Nombre completo" required span={2} error={errors.name}>
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={255} autoFocus />
          </Field>
          <Field label="Correo" required error={errors.email}>
            <input className={INPUT} type="email" value={email} onChange={(e) => setEmail(e.target.value)} maxLength={255} />
          </Field>
          <Field label="Rol" required error={errors.role_id}>
            <PickSelect
              className={INPUT}
              value={roleId}
              onChange={setRoleId}
              options={[["", "Seleccionar…"], ...options.roles.map((role): [string, string] => [role.id, role.name])]}
            />
          </Field>
          {(options.branches.length > 0 || user?.branch) && (
            <Field label="Sede principal" error={errors.branch_id}>
              <PickSelect
                className={INPUT}
                value={branchId}
                onChange={setBranchId}
                options={[["", "Sin sede"], ...withCurrent(options.branches, user?.branch)]}
              />
            </Field>
          )}
          {extraOptions.length > 0 && (
            <div className="flex min-w-0 flex-col gap-[5px] md:col-span-3">
              <span className="text-[12.5px] font-medium">Sedes adicionales</span>
              <div className={`flex flex-wrap gap-x-4 gap-y-2 rounded-[9px] border border-[color:var(--p-field-border)] px-3 py-2.5 ${branchId ? "" : "opacity-55"}`}>
                {extraOptions.map(([id, label]) => (
                  <label key={id} className={`flex items-center gap-2 ${branchId ? "cursor-pointer" : "cursor-not-allowed"}`}>
                    <input
                      type="checkbox"
                      className="h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9] disabled:cursor-not-allowed"
                      checked={Boolean(branchId) && extraBranchIds.includes(id)}
                      disabled={!branchId}
                      onChange={(e) => setExtraBranchIds((list) => (e.target.checked ? [...list, id] : list.filter((item) => item !== id)))}
                    />
                    <span>{label}</span>
                  </label>
                ))}
              </div>
              {errors.extra_branch_ids ? (
                <small className="text-xs text-[color:var(--p-danger-text)]">{errors.extra_branch_ids}</small>
              ) : (
                <small className="app-muted text-xs">
                  {branchId
                    ? "Con el alcance «Sede», la persona ve también lo de estas sedes. Lo que ella crea nace en su sede principal."
                    : "Se guardan con una sede principal: elígela primero."}
                </small>
              )}
            </div>
          )}
          {(options.teams.length > 0 || user?.team) && (
            <Field label="Equipo" error={errors.team_id}>
              <PickSelect
                className={INPUT}
                value={teamId}
                onChange={setTeamId}
                options={[["", "Sin equipo"], ...withCurrent(options.teams, user?.team)]}
              />
            </Field>
          )}
          <Field label="Costo por hora (S/)" error={errors.hourly_cost} hint="Lo toman sus horas al registrarse en Horas y costos.">
            <input className={INPUT} type="number" min="0" step="0.01" inputMode="decimal" value={hourlyCost} onChange={(e) => setHourlyCost(e.target.value)} placeholder="0.00" />
          </Field>
          <Field label="Empleado vinculado" span={2} error={errors.employee_id} hint="La cuenta de acceso y el empleado son registros distintos; aquí se enlazan.">
            <SearchSelect
              value={employeeId}
              onChange={setEmployeeId}
              placeholder="Sin vincular"
              searchPlaceholder="Buscar empleado"
              empty="Ningún empleado sin cuenta coincide."
              options={[
                { value: "", label: "Sin vincular" },
                ...employees.map((employee) => ({ value: employee.id, label: employee.name, hint: employee.code ?? undefined })),
              ]}
            />
          </Field>
          <div className="flex flex-col gap-1 md:col-span-3">
            <label className={`flex items-center gap-2 ${mfaForced ? "cursor-not-allowed" : "cursor-pointer"}`}>
              <input
                type="checkbox"
                className="h-[17px] w-[17px] accent-[#1B8FC9] disabled:cursor-not-allowed"
                checked={mfa || mfaForced}
                disabled={mfaForced}
                onChange={(e) => setMfa(e.target.checked)}
              />
              <span className={mfaForced ? "app-muted" : ""}>Exigir verificación en dos pasos</span>
              {mfaForced && <Lock size={13} className="app-muted shrink-0" aria-hidden="true" />}
            </label>
            {mfaForced && (
              <small className="app-muted text-xs">El rol Administrador siempre exige verificación en dos pasos.</small>
            )}
          </div>
        </FormGrid>
        {!user && (
          <p className="app-muted mt-3 text-[12.5px]">
            Al guardar le enviamos por correo un código para que cree su contraseña. Si se le vence, puede pedir otro con
            «¿Olvidó su contraseña?» en el inicio de sesión.
          </p>
        )}
        {errors.form && <p className="mt-2 text-[12.5px] text-[color:var(--p-danger-text)]">{errors.form}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

export default function UsersList() {
  const { toast, toasts } = useToasts();

  const [data, setData] = useState<UsersResponse | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const [search, setSearch] = useState("");
  const [role, setRole] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [editing, setEditing] = useState<CompanyUser | "new" | null>(null);
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState<CompanyUser | null>(null);
  // Invitación pendiente: usuario activo que nunca ha entrado.
  const [resending, setResending] = useState<CompanyUser | null>(null);
  const [exporting, setExporting] = useState(false);
  // Además de los usuarios, aquí se gestionan las sedes y los equipos a los que pertenecen.
  const [tab, setTab] = useState<"users" | OrgUnitKind>("users");
  const [creatingUnit, setCreatingUnit] = useState(false);
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(userKey);

  useEffect(() => {
    let cancelled = false;
    apiFetch<UsersResponse>("/v1/users")
      .then((response) => {
        if (cancelled) return;
        setData(response);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los usuarios."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function toggleStatus(user: CompanyUser) {
    setBusy(true);
    try {
      await apiFetch(`/v1/users/${user.id}/status`, {
        method: "POST",
        body: JSON.stringify({ status: isActive(user) ? "INACTIVE" : "ACTIVE" }),
      });
      toast(isActive(user) ? "Usuario desactivado" : "Usuario activado");
      setConfirming(null);
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo actualizar el usuario."), "danger");
    } finally {
      setBusy(false);
    }
  }

  async function resendInvitation(user: CompanyUser) {
    setBusy(true);
    try {
      await apiFetch(`/v1/users/${user.id}/invitation`, { method: "POST" });
      toast("Invitación reenviada", "success", `Le enviamos a ${user.email} un código nuevo para crear su contraseña.`);
      setResending(null);
    } catch (err) {
      toast(firstError(err, "No se pudo reenviar la invitación."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const needle = plain(search.trim());
  const filtered = (data?.users ?? []).filter(
    (user) =>
      (!needle || plain(`${user.name} ${user.email}`).includes(needle)) &&
      (!role || user.role?.id === role) &&
      (!status || user.status === status),
  );
  if (sort) {
    const value = (user: CompanyUser) =>
      sort.key === "role" ? user.role?.name ?? "" : sort.key === "branch" ? user.branch?.name ?? "" : sort.key === "team" ? user.team?.name ?? ""
        : sort.key === "mfa" ? (user.mfa ? "1" : "0") : sort.key === "status" ? (isActive(user) ? "Activo" : "Inactivo") : user.name;
    filtered.sort((a, b) => value(a).localeCompare(value(b), "es") * (sort.dir === "asc" ? 1 : -1));
  }
  const lastPage = Math.max(1, Math.ceil(filtered.length / perPage));
  const current = Math.min(page, lastPage);
  const rows = filtered.slice((current - 1) * perPage, current * perPage);
  const showBranch = (data?.branches.length ?? 0) > 0 || (data?.users ?? []).some((user) => user.branch);
  const showTeam = (data?.teams.length ?? 0) > 0 || (data?.users ?? []).some((user) => user.team);

  const columns: Column<CompanyUser>[] = [
    {
      label: "Usuario", sortKey: "name",
      render: (user) => (
        <div className="flex min-w-[200px] items-center gap-2.5">
          <span aria-hidden="true" className="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-full bg-[var(--p-brand-soft)] text-[11.5px] font-semibold text-[color:var(--p-brand-soft-text)]">
            {initials(user.name)}
          </span>
          <div className="min-w-0">
            <strong className="block font-semibold">{user.name}</strong>
            <small className="app-muted block text-xs">{user.email}</small>
          </div>
        </div>
      ),
    },
    { label: "Rol", sortKey: "role", render: (user) => (user.role ? <Chip tone="accent">{user.role.name}</Chip> : <span className="app-muted">Sin rol</span>) },
    ...(showBranch ? [{
      label: "Sede", sortKey: "branch",
      render: (user: CompanyUser) => (
        <span className="inline-flex flex-wrap items-center gap-1.5">
          {user.branch?.name ?? "—"}
          {user.extra_branches.length > 0 && (
            <span title={`Sedes adicionales: ${user.extra_branches.map((branch) => branch.name).join(", ")}`}>
              <Chip tone="neutral">+{user.extra_branches.length}</Chip>
            </span>
          )}
        </span>
      ),
    }] : []),
    ...(showTeam ? [{ label: "Equipo", sortKey: "team", render: (user: CompanyUser) => user.team?.name ?? "—" }] : []),
    { label: "MFA", sortKey: "mfa", render: (user) => <Chip tone={user.mfa ? "success" : "muted"}>{user.mfa ? "Activo" : "No"}</Chip> },
    { label: "Estado", sortKey: "status", render: (user) => <Chip tone={isActive(user) ? "success" : "muted"}>{isActive(user) ? "Activo" : "Inactivo"}</Chip> },
    {
      label: "Acciones",
      render: (user) => (
        <div className="flex flex-wrap gap-1.5">
          <Btn variant="ghost" size="sm" onClick={() => setEditing(user)} permission="users.manage" disabled={busy}>Editar</Btn>
          {isActive(user) && !user.last_login_at && (
            <Btn variant="secondary" size="sm" onClick={() => setResending(user)} permission="users.manage" disabled={busy}>Reenviar invitación</Btn>
          )}
          <Btn variant={isActive(user) ? "danger-ghost" : "secondary"} size="sm" onClick={() => setConfirming(user)} permission="users.manage" disabled={busy}>
            {isActive(user) ? "Desactivar" : "Activar"}
          </Btn>
        </div>
      ),
    },
  ];

  const exportColumns: ExportColumn<CompanyUser>[] = [
    { label: "Nombre", value: (user) => user.name, width: 32 },
    { label: "Correo", value: (user) => user.email, width: 34 },
    { label: "Rol", value: (user) => user.role?.name ?? "", width: 20 },
    { label: "Sede", value: (user) => user.branch?.name ?? "", width: 20 },
    { label: "Sedes adicionales", value: (user) => user.extra_branches.map((branch) => branch.name).join(", "), width: 28 },
    { label: "Equipo", value: (user) => user.team?.name ?? "", width: 20 },
    { label: "Verificación en dos pasos", value: (user) => (user.mfa ? "Activo" : "No"), width: 24, kind: "center" },
    { label: "Último acceso", value: (user) => lastLogin(user.last_login_at), width: 22, kind: "center" },
    {
      label: "Estado",
      value: (user) => (isActive(user) ? "Activo" : "Inactivo"),
      width: 14,
      kind: "status",
      tone: (user) => (isActive(user) ? "success" : "neutral"),
    },
  ];

  async function runExport(format: "xlsx" | "csv", picked: CompanyUser[]) {
    setExporting(true);
    try {
      // La API comprueba el permiso de exportar y deja la exportación en auditoría.
      await recordExport("users", format, picked.length, picked !== filtered);
      if (format === "xlsx") await exportXlsx("usuarios", "Usuarios", exportColumns, picked);
      else exportCsv("usuarios", exportColumns, picked);
      toast(`${picked.length} ${picked.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  return (
    <Screen>
      <PageHead
        title="Usuarios"
        actions={
          tab === "users" ? (
            <Btn onClick={() => setEditing("new")} permission="users.manage" disabled={!data}>
              <Plus size={16} />
              <span>Nuevo usuario</span>
            </Btn>
          ) : (
            <Btn onClick={() => setCreatingUnit(true)} permission="users.manage">
              <Plus size={16} />
              <span>{ORG_UNIT_TEXT[tab].create}</span>
            </Btn>
          )
        }
      />

      <Tabs
        tabs={[["users", "Usuarios", null], ["branches", ORG_UNIT_TEXT.branches.tab, null], ["teams", ORG_UNIT_TEXT.teams.tab, null]]}
        value={tab}
        onChange={(value) => {
          setTab(value as typeof tab);
          setCreatingUnit(false);
        }}
      />

      {tab !== "users" && (
        <OrgUnits
          key={tab}
          kind={tab}
          creating={creatingUnit}
          onCloseCreate={() => setCreatingUnit(false)}
          onChanged={() => setReload((n) => n + 1)}
          toast={toast}
        />
      )}

      {tab === "users" && (<>
      <Filters>
        <SearchInput
          value={search}
          onChange={(value) => {
            setSearch(value);
            setPage(1);
          }}
          placeholder="Buscar nombre o correo"
        />
        <FilterSelect
          label="Rol"
          value={role}
          onChange={(value) => {
            setRole(value);
            setPage(1);
          }}
          options={(data?.roles ?? []).map((item) => [item.id, item.name])}
        />
        <FilterSelect
          label="Estado"
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          options={[["ACTIVE", "Activo"], ["INACTIVE", "Inactivo"]]}
        />
      </Filters>

      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <>
            <SelectionBar
              permission="users.export"
              count={selectedRows.length}
              busy={exporting}
              onExport={(format) => runExport(format, selectedRows)}
              onClear={clearSelection}
            />
            <DataTable tableId="users" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} cols={columns} rows={rows} rowKey={userKey} selection={selection} loading={!data} empty="No hay usuarios con estos filtros." />
            {data && (
              <Pager
                page={current}
                lastPage={lastPage}
                total={filtered.length}
                perPage={perPage}
                onPage={setPage}
                onPerPage={(size) => {
                  setPerPage(size);
                  setPage(1);
                }}
                left={<ExportButtons permission="users.export" total={filtered.length} busy={exporting} onExport={(format) => runExport(format, filtered)} />}
              />
            )}
          </>
        )}
      </Card>

      {data?.limit != null && (
        <p className="app-muted -mt-1.5 text-[12.5px]">
          El plan permite {data.limit} usuarios activos; hay {data.users.filter(isActive).length}.
        </p>
      )}
      </>)}

      {editing && data && (
        <UserModal
          user={editing === "new" ? null : editing}
          options={data}
          onClose={() => setEditing(null)}
          onSaved={(created, invited) => {
            setEditing(null);
            setReload((n) => n + 1);
            if (created && invited) toast("Usuario creado", "success", "Le enviamos por correo un código y un enlace para crear su contraseña.");
            else if (created) toast("Usuario creado, pero no se pudo enviar el correo", "danger", "Usa \"Reenviar invitación\" en su fila.");
            else toast("Usuario guardado");
          }}
        />
      )}
      {resending && (
        <Modal
          title="Reenviar invitación"
          size="sm"
          onClose={() => setResending(null)}
          footer={
            <>
              <Btn variant="secondary" onClick={() => setResending(null)} disabled={busy}>Volver</Btn>
              <Btn onClick={() => resendInvitation(resending)} disabled={busy}>Reenviar</Btn>
            </>
          }
        >
          <p>
            Le enviaremos a <strong>{resending.email}</strong> un código nuevo para crear su contraseña, válido por 7 días. El código anterior deja de servir.
          </p>
        </Modal>
      )}
      {/* Como en Usuarios del portal: quitar o devolver el acceso se confirma. */}
      {confirming && (
        <Modal
          title={isActive(confirming) ? "Desactivar usuario" : "Activar usuario"}
          size="sm"
          onClose={() => setConfirming(null)}
          footer={
            <>
              <Btn variant="secondary" onClick={() => setConfirming(null)} disabled={busy}>Volver</Btn>
              <Btn variant={isActive(confirming) ? "danger" : "primary"} onClick={() => toggleStatus(confirming)} disabled={busy}>
                {isActive(confirming) ? "Desactivar" : "Activar"}
              </Btn>
            </>
          }
        >
          <p>
            {isActive(confirming)
              ? <><strong>{confirming.name}</strong> dejará de entrar a la empresa y se cerrarán sus sesiones. Su cuenta y su historial se conservan.</>
              : <><strong>{confirming.name}</strong> podrá volver a entrar a la empresa con su rol actual.</>}
          </p>
        </Modal>
      )}
      {toasts}
    </Screen>
  );
}
