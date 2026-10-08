"use client";

import { useEffect, useState, type FormEvent } from "react";
import { Plus } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { exportCsv, exportXlsx, type ExportColumn } from "@/lib/export-table";
import {
  Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, FormGrid, INPUT, LoadError, Modal, PAGE_SIZES,
  PageHead, Pager, Screen, SearchInput, SearchSelect, SelectionBar, useRowSelection, useToasts, type Column,
} from "@/components/ui/proto";

type CompanyUser = {
  id: string;
  name: string;
  email: string;
  status: string;
  role: { id: string; name: string; is_admin: boolean } | null;
  branch: { id: string; name: string } | null;
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
  employees: Employee[];
  /** Usuarios activos que permite el plan; null si no hay límite. */
  limit: number | null;
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

function UserModal({
  user, options, onClose, onSaved,
}: { user: CompanyUser | null; options: UsersResponse; onClose: () => void; onSaved: (created: boolean) => void }) {
  const [name, setName] = useState(user?.name ?? "");
  const [email, setEmail] = useState(user?.email ?? "");
  const [roleId, setRoleId] = useState(user?.role?.id ?? "");
  const [branchId, setBranchId] = useState(user?.branch?.id ?? "");
  const [employeeId, setEmployeeId] = useState(user?.employee_id ?? "");
  const [mfa, setMfa] = useState(user?.mfa_enabled ?? false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

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
      await apiFetch(user ? `/v1/users/${user.id}` : "/v1/users", {
        method: user ? "PUT" : "POST",
        body: JSON.stringify({
          name: name.trim(),
          email: email.trim(),
          role_id: roleId,
          branch_id: branchId || null,
          employee_id: employeeId || null,
          mfa,
        }),
      });
      onSaved(!user);
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
            <select className={INPUT} value={roleId} onChange={(e) => setRoleId(e.target.value)}>
              <option value="">Seleccionar…</option>
              {options.roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
            </select>
          </Field>
          {options.branches.length > 0 && (
            <Field label="Sede principal" error={errors.branch_id}>
              <select className={INPUT} value={branchId} onChange={(e) => setBranchId(e.target.value)}>
                <option value="">Sin sede</option>
                {options.branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
              </select>
            </Field>
          )}
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
          <label className="flex cursor-pointer items-center gap-2 md:col-span-3">
            <input type="checkbox" className="h-[17px] w-[17px] accent-[#1B8FC9]" checked={mfa} onChange={(e) => setMfa(e.target.checked)} />
            <span>Exigir verificación en dos pasos</span>
          </label>
        </FormGrid>
        {!user && (
          <p className="app-muted mt-3 text-[12.5px]">
            Al guardar le enviamos por correo un código para que cree su contraseña. Si se le vence, puede pedir otro con
            «¿Olvidó su contraseña?» en el inicio de sesión.
          </p>
        )}
        {errors.form && <p className="mt-2 text-[12.5px] text-[#D93A40]">{errors.form}</p>}
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
  const [search, setSearch] = useState("");
  const [role, setRole] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [editing, setEditing] = useState<CompanyUser | "new" | null>(null);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);
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
      toast("Usuario actualizado");
      setReload((n) => n + 1);
    } catch (err) {
      toast(firstError(err, "No se pudo actualizar el usuario."), "danger");
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
  const lastPage = Math.max(1, Math.ceil(filtered.length / perPage));
  const current = Math.min(page, lastPage);
  const rows = filtered.slice((current - 1) * perPage, current * perPage);
  const showBranch = (data?.branches.length ?? 0) > 0;

  const columns: Column<CompanyUser>[] = [
    {
      label: "Usuario",
      render: (user) => (
        <div className="flex min-w-[200px] items-center gap-2.5">
          <span aria-hidden="true" className="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-full bg-[#E3F2FA] text-[11.5px] font-semibold text-[#0F5A82]">
            {initials(user.name)}
          </span>
          <div className="min-w-0">
            <strong className="block font-semibold">{user.name}</strong>
            <small className="app-muted block text-xs">{user.email}</small>
          </div>
        </div>
      ),
    },
    { label: "Rol", render: (user) => (user.role ? <Chip tone="accent">{user.role.name}</Chip> : <span className="app-muted">Sin rol</span>) },
    ...(showBranch ? [{ label: "Sede", render: (user: CompanyUser) => user.branch?.name ?? "—" }] : []),
    { label: "MFA", render: (user) => <Chip tone={user.mfa ? "success" : "muted"}>{user.mfa ? "Activo" : "No"}</Chip> },
    { label: "Estado", render: (user) => <Chip tone={isActive(user) ? "success" : "muted"}>{isActive(user) ? "Activo" : "Inactivo"}</Chip> },
    {
      label: "Acciones",
      render: (user) => (
        <div className="flex flex-wrap gap-1.5">
          <Btn variant="ghost" size="sm" onClick={() => setEditing(user)} permission="users.manage" disabled={busy}>Editar</Btn>
          <Btn variant={isActive(user) ? "danger-ghost" : "secondary"} size="sm" onClick={() => toggleStatus(user)} permission="users.manage" disabled={busy}>
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
          <Btn onClick={() => setEditing("new")} permission="users.manage" disabled={!data}>
            <Plus size={16} />
            <span>Nuevo usuario</span>
          </Btn>
        }
      />

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
              count={selectedRows.length}
              busy={exporting}
              onExport={(format) => runExport(format, selectedRows)}
              onClear={clearSelection}
            />
            <DataTable tableId="users" cols={columns} rows={rows} rowKey={userKey} selection={selection} loading={!data} empty="No hay usuarios con estos filtros." />
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
                left={<ExportButtons total={filtered.length} busy={exporting} onExport={(format) => runExport(format, filtered)} />}
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

      {editing && data && (
        <UserModal
          user={editing === "new" ? null : editing}
          options={data}
          onClose={() => setEditing(null)}
          onSaved={(created) => {
            setEditing(null);
            setReload((n) => n + 1);
            if (created) toast("Usuario creado", "success", "Le enviamos un código para crear su contraseña.");
            else toast("Usuario guardado");
          }}
        />
      )}
      {toasts}
    </Screen>
  );
}
