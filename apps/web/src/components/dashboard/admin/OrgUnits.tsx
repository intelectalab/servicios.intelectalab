"use client";

import { useEffect, useState, type FormEvent } from "react";
import { ApiError, apiFetch } from "@/lib/api";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import { Btn, Card, Chip, DataTable, ExportButtons, Field, INPUT, LoadError, Modal, type Column } from "@/components/ui/proto";

export type OrgUnitKind = "branches" | "teams";

type OrgUnit = { id: string; name: string; status: string; users: number };
type OrgUnitsResponse = Record<OrgUnitKind, OrgUnit[]>;

// Textos de cada tipo: de las sedes y los equipos depende el alcance "Sede" y "Equipo" de los roles.
export const ORG_UNIT_TEXT: Record<OrgUnitKind, {
  tab: string; create: string; edit: string; created: string; saved: string; activated: string; deactivated: string; empty: string;
}> = {
  branches: {
    tab: "Sedes", create: "Nueva sede", edit: "Editar sede", created: "Sede creada", saved: "Sede guardada",
    activated: "Sede activada", deactivated: "Sede desactivada", empty: "Aún no hay sedes registradas.",
  },
  teams: {
    tab: "Equipos", create: "Nuevo equipo", edit: "Editar equipo", created: "Equipo creado", saved: "Equipo guardado",
    activated: "Equipo activado", deactivated: "Equipo desactivado", empty: "Aún no hay equipos registrados.",
  },
};

const unitKey = (unit: OrgUnit) => unit.id;

const EXPORT_COLUMNS: ExportColumn<OrgUnit>[] = [
  { label: "Nombre", value: (unit) => unit.name, width: 34 },
  { label: "Usuarios", value: (unit) => unit.users, width: 12, kind: "number" },
  { label: "Estado", value: (unit) => (unit.status === "ACTIVE" ? "Activo" : "Inactivo"), width: 14, kind: "status", tone: (unit) => (unit.status === "ACTIVE" ? "success" : "neutral") },
];
const isActive = (unit: OrgUnit) => unit.status === "ACTIVE";

function firstError(err: unknown, fallback: string) {
  if (err instanceof ApiError) return (err.errors && Object.values(err.errors)[0]?.[0]) || err.message;
  return fallback;
}

function OrgUnitModal({
  kind, unit, onClose, onSaved,
}: { kind: OrgUnitKind; unit: OrgUnit | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(unit?.name ?? "");
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const text = ORG_UNIT_TEXT[kind];

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      setError("Indica el nombre.");
      return;
    }

    setSaving(true);
    setError("");
    try {
      await apiFetch(unit ? `/v1/org-units/${kind}/${unit.id}` : `/v1/org-units/${kind}`, {
        method: unit ? "PUT" : "POST",
        body: JSON.stringify({ name: name.trim() }),
      });
      onSaved();
    } catch (err) {
      setError(firstError(err, "No se pudo guardar."));
      setSaving(false);
    }
  }

  return (
    <Modal title={unit ? text.edit : text.create} size="sm" onClose={onClose}>
      <form onSubmit={submit}>
        <Field label="Nombre" required error={error}>
          <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoFocus />
        </Field>
        <div className="mt-4 flex justify-end gap-2">
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn type="submit" disabled={saving}>{saving ? "Guardando…" : "Guardar"}</Btn>
        </div>
      </form>
    </Modal>
  );
}

/**
 * Sedes o equipos de la empresa: se crean, se renombran y se desactivan (no se borran).
 * A cada persona se le asignan en su usuario.
 */
export function OrgUnits({
  kind, creating, onCloseCreate, onChanged, toast,
}: {
  kind: OrgUnitKind;
  /** El botón "Nueva sede / Nuevo equipo" de la cabecera pidió abrir el alta. */
  creating: boolean;
  onCloseCreate: () => void;
  /** Cambió una sede o un equipo: los formularios de usuario deben volver a leerlos. */
  onChanged: () => void;
  toast: (text: string, tone?: "success" | "danger") => void;
}) {
  const [data, setData] = useState<OrgUnitsResponse | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState<OrgUnit | null>(null);
  const [busy, setBusy] = useState(false);
  const [exporting, setExporting] = useState(false);
  const text = ORG_UNIT_TEXT[kind];

  useEffect(() => {
    let cancelled = false;
    apiFetch<OrgUnitsResponse>("/v1/org-units")
      .then((response) => {
        if (cancelled) return;
        setData(response);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar las sedes y los equipos."); });
    return () => { cancelled = true; };
  }, [reload]);

  const refresh = () => {
    setReload((n) => n + 1);
    onChanged();
  };

  async function toggleStatus(unit: OrgUnit) {
    setBusy(true);
    try {
      await apiFetch(`/v1/org-units/${kind}/${unit.id}`, {
        method: "PUT",
        body: JSON.stringify({ status: isActive(unit) ? "INACTIVE" : "ACTIVE" }),
      });
      toast(isActive(unit) ? text.deactivated : text.activated);
      refresh();
    } catch (err) {
      toast(firstError(err, "No se pudo actualizar."), "danger");
    } finally {
      setBusy(false);
    }
  }

  /** Exporta la tabla completa (sedes o equipos): pide `users.export` y queda en auditoría. */
  async function runExport(format: "xlsx" | "csv") {
    const rows = data?.[kind] ?? [];
    setExporting(true);
    try {
      await recordExport("users", format, rows.length, true);
      const name = kind === "branches" ? "sedes" : "equipos";
      if (format === "xlsx") await exportXlsx(name, text.tab, EXPORT_COLUMNS, rows);
      else exportCsv(name, EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar la tabla.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const columns: Column<OrgUnit>[] = [
    { label: "Nombre", render: (unit) => <strong className="font-semibold">{unit.name}</strong> },
    { label: "Usuarios", render: (unit) => unit.users },
    { label: "Estado", render: (unit) => <Chip tone={isActive(unit) ? "success" : "muted"}>{isActive(unit) ? "Activo" : "Inactivo"}</Chip> },
    {
      label: "Acciones",
      render: (unit) => (
        <div className="flex flex-wrap gap-1.5">
          <Btn variant="ghost" size="sm" onClick={() => setEditing(unit)} permission="users.manage" disabled={busy}>Editar</Btn>
          <Btn variant={isActive(unit) ? "danger-ghost" : "secondary"} size="sm" onClick={() => toggleStatus(unit)} permission="users.manage" disabled={busy}>
            {isActive(unit) ? "Desactivar" : "Activar"}
          </Btn>
        </div>
      ),
    },
  ];

  return (
    <>
      <Card flush>
        {error ? (
          <LoadError text={error} onRetry={() => setReload((n) => n + 1)} />
        ) : (
          <>
            <DataTable cols={columns} rows={data?.[kind] ?? []} rowKey={unitKey} loading={!data} empty={text.empty} />
            {data && data[kind].length > 0 && (
              <div className="border-t border-[color:var(--app-border)] px-3.5 py-2.5">
                <ExportButtons permission="users.export" total={data[kind].length} busy={exporting} onExport={runExport} />
              </div>
            )}
          </>
        )}
      </Card>

      {(creating || editing) && (
        <OrgUnitModal
          kind={kind}
          unit={editing}
          onClose={() => {
            setEditing(null);
            onCloseCreate();
          }}
          onSaved={() => {
            toast(editing ? text.saved : text.created);
            setEditing(null);
            onCloseCreate();
            refresh();
          }}
        />
      )}
    </>
  );
}
