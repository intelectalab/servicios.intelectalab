"use client";

import { useEffect, useState, type ReactNode } from "react";
import { Plus } from "lucide-react";
import { ApiError, apiFetch, apiFetchLater } from "@/lib/api";
import { fieldErrors, firstError } from "@/lib/crm";
import { HR_MASTERED_NOTE, isMastered, money } from "@/lib/personal";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, Field, FilterSelect, Filters, FormGrid, INPUT, LoadError, Modal, Notice, PAGE_SIZES, PageHead,
  Pager, SearchInput, SelectionBar, applySort, useRowSelection, type Column, type SortState,
} from "@/components/ui/proto";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { ifMatch, type Toast } from "@/components/dashboard/personal/EmployeeModals";
import type { HrEmployeeOptions, HrMode, HrPage, HrPosition } from "@/types/personal";

const positionKey = (position: HrPosition) => position.id;

const EXPORT_COLUMNS: ExportColumn<HrPosition>[] = [
  { label: "Código", value: (p) => p.code, width: 14, kind: "center" },
  { label: "Puesto", value: (p) => p.name, width: 32 },
  { label: "Área", value: (p) => p.department ?? "", width: 24 },
  { label: "Costo por hora sugerido", value: (p) => p.default_hourly_cost ?? "", width: 16, kind: "number" },
  { label: "Moneda", value: (p) => p.currency, width: 10, kind: "center" },
  { label: "Trabajadores activos", value: (p) => p.employees, width: 14, kind: "number" },
  { label: "Activo", value: (p) => (p.active ? "Sí" : "No"), width: 10, kind: "center" },
  { label: "Origen", value: (p) => (p.source === "HR_SAAS" ? "SaaS de RRHH" : "ERP"), width: 14, kind: "center" },
  { label: "Código en RRHH", value: (p) => p.external_id ?? "", width: 18 },
];

const ACTIVE_OPTIONS: [string, string][] = [["true", "Activos"], ["false", "Inactivos"]];

type TopFilters = { search: string; active: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.active) params.set("active", top.active);
  return params;
}

/** Pestaña Puestos: catálogo de cargos con su costo por hora sugerido. */
export default function PositionsList({ toast, tabs }: { toast: Toast; tabs: ReactNode }) {
  const [exporting, setExporting] = useState(false);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [active, setActive] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(positionKey);

  const [data, setData] = useState<HrPage<HrPosition> | null>(null);
  const [mode, setMode] = useState<HrMode | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [form, setForm] = useState<{ position?: HrPosition } | null>(null);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  // El modo (ERP o RRHH maestro) viene con las opciones del formulario de trabajadores.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<HrEmployeeOptions>("/v1/hr/employees/options").then((next) => { if (!cancelled) setMode(next.mode); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, active });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<HrPage<HrPosition>>(`/v1/hr/positions?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los puestos.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, active, columnFilters.param, sort, reload]);

  async function runExport(format: "xlsx" | "csv", picked?: HrPosition[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<HrPosition>("/v1/hr/positions", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), { search, active })), sort)));
      await recordExport("employees", format, rows.length, Boolean(picked), "list=positions");
      if (format === "xlsx") await exportXlsx("puestos", "Puestos", EXPORT_COLUMNS, rows);
      else exportCsv("puestos", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const masteredMode = mode === "HR_SAAS_MASTER";

  const cols: Column<HrPosition>[] = [
    { label: "Código", sortKey: "code", filter: "text", render: (p) => <strong className="whitespace-nowrap font-semibold">{p.code}</strong> },
    { label: "Puesto", sortKey: "name", filter: "text", render: (p) => <span className="block min-w-[160px]">{p.name}</span> },
    { label: "Área", sortKey: "department", filter: "text", render: (p) => p.department ?? <span className="app-muted">—</span> },
    {
      label: "Costo por hora sugerido", num: true, sortKey: "cost", filter: "number",
      render: (p) => (p.default_hourly_cost == null ? <span className="app-muted">—</span> : money(p.default_hourly_cost, p.currency)),
    },
    { label: "Trabajadores", num: true, sortKey: "employees", render: (p) => p.employees },
    {
      label: "Estado", sortKey: "active", filter: { top: "Activo" },
      render: (p) => (
        <div className="flex flex-wrap items-center gap-1">
          <Badge tone={p.active ? "success" : "muted"}>{p.active ? "Activo" : "Inactivo"}</Badge>
          {p.source === "HR_SAAS" && <Chip tone="accent">RRHH</Chip>}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHead
        title="Trabajadores y puestos"
        actions={
          <Btn
            onClick={() => setForm({})}
            permission="employees.create"
            disabled={masteredMode}
            title={masteredMode ? "Los puestos nuevos se crean en el SaaS de RRHH" : undefined}
          >
            <Plus size={16} />
            <span>Nuevo puesto</span>
          </Btn>
        }
      />
      {tabs}

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar código, puesto o área" />
        <FilterSelect label="Activo" value={active} onChange={(value) => { setActive(value); setPage(1); }} options={ACTIVE_OPTIONS} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || active)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setActive("");
            setPage(1);
          }}
        />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="employees.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="hr-positions" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols}
            rows={data?.data ?? []}
            rowKey={positionKey}
            selection={selection}
            onRowClick={(position) => setForm({ position })}
            loading={loading && !data}
            empty="No hay registros con estos filtros."
          />
          {data && (
            <Pager
              loading={loading}
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={(size) => { setPerPage(size); setPage(1); }}
              left={<ExportButtons permission="employees.export" total={data.total} busy={exporting} onExport={(format) => runExport(format)} />}
            />
          )}
        </Card>
      )}

      {form && (
        <PositionFormModal
          position={form.position}
          mode={mode}
          toast={toast}
          onClose={() => setForm(null)}
          onSaved={(created) => {
            setForm(null);
            toast(created ? "Puesto creado" : "Puesto actualizado");
            setReload((n) => n + 1);
          }}
          onConflict={() => setReload((n) => n + 1)}
        />
      )}
    </>
  );
}

/** Alta o edición de un puesto; los que mantiene el SaaS de RRHH se muestran sin poder editarse. */
function PositionFormModal({
  position, mode, onClose, onSaved, onConflict, toast,
}: {
  position?: HrPosition;
  mode: HrMode | null;
  onClose: () => void;
  onSaved: (created: boolean) => void;
  onConflict: () => void;
  toast: Toast;
}) {
  const editing = Boolean(position);
  const mastered = isMastered(position?.source, mode);
  const [form, setForm] = useState({
    code: position?.code ?? "",
    name: position?.name ?? "",
    department: position?.department ?? "",
    default_hourly_cost: position?.default_hourly_cost != null ? String(position.default_hourly_cost) : "",
    active: position?.active ?? true,
  });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const set = (key: "code" | "name" | "department" | "default_hourly_cost", value: string) => setForm((current) => ({ ...current, [key]: value }));

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      await apiFetch(editing ? `/v1/hr/positions/${position!.id}` : "/v1/hr/positions", {
        method: editing ? "PUT" : "POST",
        body: JSON.stringify({
          code: form.code.trim(),
          name: form.name.trim(),
          department: form.department.trim() || null,
          default_hourly_cost: form.default_hourly_cost === "" ? null : Number(form.default_hourly_cost),
          active: form.active,
        }),
        headers: editing ? ifMatch(position!.lock_version) : undefined,
      });
      onSaved(!editing);
    } catch (err) {
      setErrors(fieldErrors(err));
      toast(firstError(err, "No se pudo guardar el puesto."), "danger");
      if (err instanceof ApiError && err.status === 409) {
        onConflict();
        onClose();
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal
      title={editing ? `Puesto ${position!.code}` : "Nuevo puesto"}
      onClose={onClose}
      footer={
        <>
          <Btn variant="ghost" onClick={onClose}>{mastered ? "Cerrar" : "Cancelar"}</Btn>
          <Btn onClick={save} disabled={busy || mastered} permission={editing ? "employees.manage" : "employees.create"} title={mastered ? HR_MASTERED_NOTE : undefined}>
            {busy ? "Guardando…" : editing ? "Guardar cambios" : "Crear puesto"}
          </Btn>
        </>
      }
    >
      <div className="grid gap-3">
        {mastered && <Notice>{HR_MASTERED_NOTE}.</Notice>}
        <FormGrid>
          <Field label="Código" required error={errors.code}>
            <input className={INPUT} value={form.code} onChange={(e) => set("code", e.target.value.toUpperCase())} maxLength={50} disabled={mastered} autoComplete="off" />
          </Field>
          <Field label="Nombre" required error={errors.name} span={2}>
            <input className={INPUT} value={form.name} onChange={(e) => set("name", e.target.value)} maxLength={150} disabled={mastered} autoComplete="off" />
          </Field>
          <Field label="Área" error={errors.department} span={2}>
            <input className={INPUT} value={form.department} onChange={(e) => set("department", e.target.value)} maxLength={150} disabled={mastered} autoComplete="off" />
          </Field>
          <Field label="Costo por hora sugerido" error={errors.default_hourly_cost} hint="Se propone al asignar el puesto.">
            <input type="number" className={INPUT} value={form.default_hourly_cost} onChange={(e) => set("default_hourly_cost", e.target.value)} min={0.01} step="0.01" disabled={mastered} />
          </Field>
        </FormGrid>
        <label className="relative inline-flex w-fit cursor-pointer items-center gap-2 text-[13px]">
          <input
            type="checkbox" checked={form.active} disabled={mastered}
            onChange={(e) => setForm((current) => ({ ...current, active: e.target.checked }))}
            className="h-4 w-4 accent-[color:var(--p-accent,#1B8FC9)]"
          />
          <span>Activo</span>
        </label>
        {editing && position!.employees > 0 && (
          <p className="app-muted text-[12.5px]">{position!.employees} {position!.employees === 1 ? "trabajador activo tiene" : "trabajadores activos tienen"} este puesto.</p>
        )}
      </div>
    </Modal>
  );
}
