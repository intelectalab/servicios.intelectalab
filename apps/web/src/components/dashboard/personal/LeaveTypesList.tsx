"use client";

import { useEffect, useState } from "react";
import { Plus } from "lucide-react";
import { apiFetch } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { exportCsv, exportXlsx, recordExport, type ExportColumn } from "@/lib/export-table";
import { Badge, Btn, Card, DataTable, ExportButtons, Field, FormGrid, INPUT, LoadError, Modal, type Column } from "@/components/ui/proto";
import type { Toast } from "@/components/dashboard/personal/EmployeeModals";
import type { LeaveType } from "@/types/personal";

const yesNo = (value: boolean) => (value ? "Sí" : "No");

const EXPORT_COLUMNS: ExportColumn<LeaveType>[] = [
  { label: "Código", value: (t) => t.code, width: 14, kind: "center" },
  { label: "Tipo de ausencia", value: (t) => t.name, width: 30 },
  { label: "Con goce", value: (t) => yesNo(t.paid), width: 12, kind: "center" },
  { label: "Requiere aprobación", value: (t) => yesNo(t.requires_approval), width: 16, kind: "center" },
  { label: "Días por año", value: (t) => t.days_per_year ?? "", width: 12, kind: "number" },
  { label: "Activo", value: (t) => yesNo(t.active), width: 10, kind: "center" },
];

/** Pestaña Tipos de ausencia: catálogo de la empresa (vacaciones, descanso médico, permisos…). */
export default function LeaveTypesList({ toast, onChanged }: { toast: Toast; onChanged: () => void }) {
  const [types, setTypes] = useState<LeaveType[] | null>(null);
  const [error, setError] = useState("");
  const [reload, setReload] = useState(0);
  const [exporting, setExporting] = useState(false);
  const [form, setForm] = useState<{ type?: LeaveType } | null>(null);

  useEffect(() => {
    let cancelled = false;
    apiFetch<{ data: LeaveType[] }>("/v1/hr/leave-types")
      .then((next) => {
        if (cancelled) return;
        setTypes(next.data);
        setError("");
      })
      .catch(() => { if (!cancelled) setError("No se pudieron cargar los tipos de ausencia."); });
    return () => { cancelled = true; };
  }, [reload]);

  async function runExport(format: "xlsx" | "csv") {
    if (!types) return;
    setExporting(true);
    try {
      await recordExport("leaves", format, types.length, false, "list=leave-types");
      if (format === "xlsx") await exportXlsx("tipos-de-ausencia", "Tipos de ausencia", EXPORT_COLUMNS, types);
      else exportCsv("tipos-de-ausencia", EXPORT_COLUMNS, types);
      toast(`${types.length} ${types.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const cols: Column<LeaveType>[] = [
    { label: "Código", render: (t) => <strong className="whitespace-nowrap font-semibold">{t.code}</strong> },
    { label: "Tipo de ausencia", render: (t) => t.name },
    { label: "Con goce", render: (t) => yesNo(t.paid) },
    { label: "Requiere aprobación", render: (t) => yesNo(t.requires_approval) },
    { label: "Días por año", num: true, render: (t) => t.days_per_year ?? <span className="app-muted">—</span> },
    { label: "Estado", render: (t) => <Badge tone={t.active ? "success" : "muted"}>{t.active ? "Activo" : "Inactivo"}</Badge> },
  ];

  return (
    <>
      <div className="mb-3 flex justify-end">
        <Btn onClick={() => setForm({})} permission="leaves.manage" variant="secondary">
          <Plus size={16} />
          <span>Nuevo tipo</span>
        </Btn>
      </div>
      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <DataTable cols={cols} rows={types ?? []} rowKey={(t) => t.id} onRowClick={(type) => setForm({ type })} loading={!types} empty="No hay registros con estos filtros." />
          {types && types.length > 0 && (
            <div className="app-muted flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-[color:var(--app-border)] px-3.5 py-3 text-[12.5px]">
              <ExportButtons permission="leaves.export" total={types.length} busy={exporting} onExport={runExport} />
              <span>{types.length} {types.length === 1 ? "tipo" : "tipos"}</span>
            </div>
          )}
        </Card>
      )}
      {form && (
        <LeaveTypeFormModal
          type={form.type}
          toast={toast}
          onClose={() => setForm(null)}
          onSaved={(created) => {
            setForm(null);
            toast(created ? "Tipo de ausencia creado" : "Tipo de ausencia actualizado");
            setReload((n) => n + 1);
            onChanged();
          }}
        />
      )}
    </>
  );
}

function LeaveTypeFormModal({
  type, onClose, onSaved, toast,
}: { type?: LeaveType; onClose: () => void; onSaved: (created: boolean) => void; toast: Toast }) {
  const editing = Boolean(type);
  const [code, setCode] = useState(type?.code ?? "");
  const [name, setName] = useState(type?.name ?? "");
  const [days, setDays] = useState(type?.days_per_year != null ? String(type.days_per_year) : "");
  const [flags, setFlags] = useState({ paid: type?.paid ?? true, requires_approval: type?.requires_approval ?? true, active: type?.active ?? true });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      await apiFetch(editing ? `/v1/hr/leave-types/${type!.id}` : "/v1/hr/leave-types", {
        method: editing ? "PUT" : "POST",
        body: JSON.stringify({ code: code.trim(), name: name.trim(), days_per_year: days === "" ? null : Number(days), ...flags }),
      });
      onSaved(!editing);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar el tipo de ausencia."), "danger");
    } finally {
      setBusy(false);
    }
  }

  const check = (key: keyof typeof flags, label: string) => (
    <label className="relative inline-flex w-fit cursor-pointer items-center gap-2 text-[13px]">
      <input
        type="checkbox" checked={flags[key]} onChange={(e) => setFlags((current) => ({ ...current, [key]: e.target.checked }))}
        className="h-4 w-4 accent-[color:var(--p-accent,#1B8FC9)]"
      />
      <span>{label}</span>
    </label>
  );

  return (
    <Modal
      title={editing ? `Tipo ${type!.code}` : "Nuevo tipo de ausencia"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>Cancelar</Btn>
          <Btn onClick={save} disabled={busy} permission="leaves.manage">{busy ? "Guardando…" : editing ? "Guardar cambios" : "Crear tipo"}</Btn>
        </>
      }
    >
      <div className="grid gap-3">
        <FormGrid>
          <Field label="Código" required error={errors.code}>
            <input className={INPUT} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={30} autoComplete="off" />
          </Field>
          <Field label="Nombre" required error={errors.name} span={2}>
            <input className={INPUT} value={name} onChange={(e) => setName(e.target.value)} maxLength={120} autoComplete="off" />
          </Field>
          <Field label="Días por año" error={errors.days_per_year} hint="Opcional: el tope anual.">
            <input type="number" className={INPUT} value={days} onChange={(e) => setDays(e.target.value)} min={1} max={366} step={1} />
          </Field>
        </FormGrid>
        <div className="flex flex-wrap gap-x-5 gap-y-2">
          {check("paid", "Con goce")}
          {check("requires_approval", "Requiere aprobación")}
          {check("active", "Activo")}
        </div>
      </div>
    </Modal>
  );
}
