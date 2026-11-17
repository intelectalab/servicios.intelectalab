"use client";

import { useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Clock, Coins, Plus, UserCheck, UserX, Users, CalendarClock } from "lucide-react";
import { apiFetch, apiFetchLater } from "@/lib/api";
import { excelTone, shortDate, stateOf } from "@/lib/commercial";
import { EMPLOYEE_FILTER_STATES, EMPLOYEE_STATES, HR_SOURCES, hrDateTime, money } from "@/lib/personal";
import { exportCsv, exportXlsx, fetchAllPages, recordExport, type ExportColumn } from "@/lib/export-table";
import {
  Badge, Btn, Card, Chip, DataTable, ExportButtons, FilterSelect, Filters, Kpi, Kpis, LoadError, Notice, PAGE_SIZES, PageHead, Pager, SearchInput,
  SelectionBar, applySort, useRowSelection, type Column, type SortState,
} from "@/components/ui/proto";
import { ScopeNote } from "@/components/dashboard/ScopeNote";
import { ColumnFilterBar, addColumnFilter, applyFilters, useServerColumnFilters } from "@/components/dashboard/ColumnFilters";
import { EmployeeFormModal, type Toast } from "@/components/dashboard/personal/EmployeeModals";
import type { HrEmployee, HrEmployeeList, HrEmployeeOptions } from "@/types/personal";

const employeeKey = (employee: HrEmployee) => employee.id;
const LINK = "text-[color:var(--p-brand-text)] hover:underline";

const EXPORT_COLUMNS: ExportColumn<HrEmployee>[] = [
  { label: "Código", value: (e) => e.code ?? "", width: 14, kind: "center" },
  { label: "Trabajador", value: (e) => e.name, width: 32 },
  { label: "Tipo de documento", value: (e) => e.document_type ?? "", width: 12, kind: "center" },
  { label: "Documento", value: (e) => e.document_number ?? "", width: 16, kind: "center" },
  { label: "Puesto", value: (e) => e.position?.name ?? "", width: 26 },
  { label: "Sede", value: (e) => e.branch?.name ?? "", width: 22 },
  { label: "Contrato", value: (e) => e.contract_label ?? "", width: 16 },
  { label: "Jornada semanal (h)", value: (e) => e.weekly_hours, width: 12, kind: "number" },
  { label: "Costo por hora", value: (e) => e.hourly_cost ?? "", width: 14, kind: "number" },
  { label: "Moneda", value: (e) => e.currency, width: 10, kind: "center" },
  { label: "Ingreso", value: (e) => (e.hire_date ? shortDate(e.hire_date) : ""), width: 16, kind: "center" },
  { label: "Cese", value: (e) => (e.termination_date ? shortDate(e.termination_date) : ""), width: 16, kind: "center" },
  { label: "Motivo del cese", value: (e) => e.termination_reason ?? "", width: 30 },
  { label: "Usuario", value: (e) => e.user?.name ?? "Sin usuario", width: 24 },
  { label: "Correo del usuario", value: (e) => e.user?.email ?? "", width: 28 },
  { label: "Código en planilla", value: (e) => e.payroll_external_id ?? "", width: 18 },
  { label: "Origen", value: (e) => (e.source === "HR_SAAS" ? "SaaS de RRHH" : "ERP"), width: 14, kind: "center" },
  { label: "Sincronizado", value: (e) => (e.synced_at ? hrDateTime(e.synced_at) : ""), width: 22, kind: "center" },
  {
    label: "Estado", value: (e) => stateOf(EMPLOYEE_STATES, e.status)[0], width: 14, kind: "status",
    tone: (e) => excelTone(stateOf(EMPLOYEE_STATES, e.status)[1]),
  },
  { label: "Ausente hoy", value: (e) => (e.on_leave ? `${e.on_leave.type} (${shortDate(e.on_leave.date_from)} – ${shortDate(e.on_leave.date_to)})` : ""), width: 30 },
];

type TopFilters = { search: string; status: string; position: string; branch: string; source: string };

function withTopFilters(params: URLSearchParams, top: TopFilters) {
  if (top.search) params.set("search", top.search);
  if (top.status) params.set("status", top.status);
  if (top.position) params.set("position_id", top.position);
  if (top.branch) params.set("branch_id", top.branch);
  if (top.source) params.set("source", top.source);
  return params;
}

/** Pestaña Trabajadores: indicadores, filtros, tabla con exportación al pie y alta. */
export default function EmployeesList({ toast, tabs }: { toast: Toast; tabs: ReactNode }) {
  const router = useRouter();
  const [exporting, setExporting] = useState(false);

  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [position, setPosition] = useState("");
  const [branch, setBranch] = useState("");
  const [source, setSource] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(PAGE_SIZES[0]);
  const [reload, setReload] = useState(0);
  const [sort, setSort] = useState<SortState>(null);
  const columnFilters = useServerColumnFilters(() => setPage(1));
  const { selection, selectedRows, clear: clearSelection } = useRowSelection(employeeKey);

  const [data, setData] = useState<HrEmployeeList | null>(null);
  const [options, setOptions] = useState<HrEmployeeOptions | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creating, setCreating] = useState(false);

  const top = { search, status, position, branch, source };

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setSearch(searchInput.trim());
      setPage(1);
    }, 350);
    return () => window.clearTimeout(timer);
  }, [searchInput]);

  // Puestos y sedes para los filtros: después del listado.
  useEffect(() => {
    let cancelled = false;
    apiFetchLater<HrEmployeeOptions>("/v1/hr/employees/options").then((next) => { if (!cancelled) setOptions(next); }).catch(() => undefined);
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      const params = withTopFilters(new URLSearchParams({ per_page: String(perPage), page: String(page) }), { search, status, position, branch, source });
      applySort(applyFilters(params, columnFilters.param), sort);
      try {
        const response = await apiFetch<HrEmployeeList>(`/v1/hr/employees?${params}`);
        if (!cancelled) {
          setData(response);
          setError("");
        }
      } catch {
        if (!cancelled) setError("No se pudieron cargar los trabajadores.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => { cancelled = true; };
  }, [page, perPage, search, status, position, branch, source, columnFilters.param, sort, reload]);

  async function runExport(format: "xlsx" | "csv", picked?: HrEmployee[]) {
    setExporting(true);
    try {
      const rows = picked ?? (await fetchAllPages<HrEmployee>("/v1/hr/employees", applySort(columnFilters.apply(withTopFilters(new URLSearchParams(), top)), sort)));
      await recordExport("employees", format, rows.length, Boolean(picked));
      if (format === "xlsx") await exportXlsx("trabajadores", "Trabajadores", EXPORT_COLUMNS, rows);
      else exportCsv("trabajadores", EXPORT_COLUMNS, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar el listado.", "danger");
    } finally {
      setExporting(false);
    }
  }

  const mode = data?.mode ?? options?.mode;
  const masteredMode = mode === "HR_SAAS_MASTER";
  const kpis = data?.kpis;

  const cols: Column<HrEmployee>[] = [
    {
      label: "Trabajador", sortKey: "name", filter: "text",
      render: (e) => (
        <div className="min-w-[180px] max-w-[300px]">
          <strong className="font-semibold">{e.name}</strong>
          <small className="app-muted block text-xs">
            {[e.code, e.document_number ? `${e.document_type ?? ""} ${e.document_number}`.trim() : null].filter(Boolean).join(" · ") || "—"}
          </small>
        </div>
      ),
    },
    { label: "Puesto", sortKey: "position", filter: "text", render: (e) => e.position?.name ?? <span className="app-muted">—</span> },
    { label: "Sede", sortKey: "branch", filter: "text", render: (e) => e.branch?.name ?? <span className="app-muted">—</span> },
    { label: "Contrato", sortKey: "contract", render: (e) => e.contract_label ?? <span className="app-muted">—</span> },
    { label: "Jornada", num: true, sortKey: "weekly_hours", filter: "number", render: (e) => `${e.weekly_hours.toLocaleString("es-PE")} h` },
    {
      label: "Costo por hora", num: true, sortKey: "hourly_cost", filter: "number",
      render: (e) => (e.hourly_cost == null ? <Chip tone="warning">Sin costo</Chip> : money(e.hourly_cost, e.currency)),
    },
    { label: "Ingreso", sortKey: "hire_date", filter: "date", render: (e) => <span className="whitespace-nowrap">{shortDate(e.hire_date)}</span> },
    { label: "Usuario", sortKey: "user", render: (e) => e.user?.name ?? <span className="app-muted">Sin usuario</span> },
    {
      label: "Estado", sortKey: "status", filter: { top: "Estado" },
      render: (e) => {
        const [label, tone] = stateOf(EMPLOYEE_STATES, e.status);
        return (
          <div className="flex flex-wrap items-center gap-1">
            <Badge tone={tone}>{label}</Badge>
            {e.on_leave && <span title={`${shortDate(e.on_leave.date_from)} – ${shortDate(e.on_leave.date_to)}`}><Badge tone="warning">{e.on_leave.type}</Badge></span>}
            {e.source === "HR_SAAS" && <Chip tone="accent">RRHH</Chip>}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHead
        title="Trabajadores y puestos"
        actions={
          <Btn
            onClick={() => setCreating(true)}
            permission="employees.create"
            disabled={masteredMode}
            title={masteredMode ? "Las altas se hacen en el SaaS de RRHH" : undefined}
          >
            <Plus size={16} />
            <span>Nuevo trabajador</span>
          </Btn>
        }
      />
      {tabs}
      {masteredMode && (
        <Notice>
          Con la conexión con el SaaS de RRHH activa, el personal se edita allí. Aquí solo cambias el usuario vinculado y la sede.{" "}
          <Link href="/hr/sync" className="font-medium underline underline-offset-2">Ver la sincronización</Link>
        </Notice>
      )}

      <Kpis>
        <Kpi label="Activos" value={kpis ? kpis.active : "—"} icon={<Users size={22} />} />
        <Kpi label="Ausentes hoy" value={kpis ? kpis.on_leave : "—"} icon={<CalendarClock size={22} />} tone="warning" />
        <Kpi label="Sin costo por hora" value={kpis ? kpis.without_cost : "—"} icon={<Coins size={22} />} tone={kpis?.without_cost ? "danger" : "success"} />
        <Kpi label="Sin usuario" value={kpis ? kpis.without_user : "—"} icon={<UserX size={22} />} tone="warning" />
        <Kpi label="Costo promedio por hora" value={kpis ? money(kpis.average_cost) : "—"} icon={<Clock size={22} />} />
        <Kpi
          label="Ausencias por aprobar"
          value={kpis ? kpis.pending_leaves : "—"}
          icon={<UserCheck size={22} />}
          tone="warning"
          sub={<Link href="/hr/leaves" className={LINK}>Ver bandeja</Link>}
        />
      </Kpis>

      <Filters>
        <SearchInput value={searchInput} onChange={setSearchInput} placeholder="Buscar nombre, código o documento" />
        <FilterSelect label="Estado" value={status} onChange={(value) => { setStatus(value); setPage(1); }} options={EMPLOYEE_FILTER_STATES} />
        <FilterSelect label="Puesto" value={position} onChange={(value) => { setPosition(value); setPage(1); }} options={(options?.positions ?? []).map((p) => [p.id, p.name])} />
        <FilterSelect label="Sede" value={branch} onChange={(value) => { setBranch(value); setPage(1); }} options={(options?.branches ?? []).map((b) => [b.id, b.name])} />
        <FilterSelect label="Origen" value={source} onChange={(value) => { setSource(value); setPage(1); }} options={HR_SOURCES} />
        <ColumnFilterBar
          filters={columnFilters}
          hasOtherFilters={Boolean(searchInput || status || position || branch || source)}
          onClearAll={() => {
            setSearchInput("");
            setSearch("");
            setStatus("");
            setPosition("");
            setBranch("");
            setSource("");
            setPage(1);
          }}
        />
        <ScopeNote resource="employees" />
      </Filters>

      {error ? (
        <Card><LoadError text={error} onRetry={() => setReload((n) => n + 1)} /></Card>
      ) : (
        <Card flush>
          <SelectionBar permission="employees.export" count={selectedRows.length} busy={exporting} onExport={(format) => runExport(format, selectedRows)} onClear={clearSelection} />
          <DataTable
            tableId="hr-employees" sort={sort} onSort={(next) => { setSort(next); setPage(1); }} refreshing={loading}
            onFilter={(column) => addColumnFilter(columnFilters, column)}
            cols={cols}
            rows={data?.data ?? []}
            rowKey={employeeKey}
            selection={selection}
            onRowClick={(e) => router.push(`/hr/employees/${e.id}`)}
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

      {creating && (
        <EmployeeFormModal
          toast={toast}
          onClose={() => setCreating(false)}
          onSaved={(employee) => {
            setCreating(false);
            toast(`Trabajador ${employee.name} registrado`);
            router.push(`/hr/employees/${employee.id}`);
          }}
        />
      )}
    </>
  );
}
