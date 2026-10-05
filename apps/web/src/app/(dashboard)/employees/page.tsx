"use client";

import { onOpenRecord } from "@/lib/open-record";
import { useEffect, useMemo, useRef, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import ExcelJS from "exceljs";
import {
  Search,
  Plus,
  AlertCircle,
  RefreshCw,
  Filter,
  Trash2,
  Download,
  CheckCircle,
  X,
} from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { Pagination } from "@/components/Pagination";
import { EmployeeTable } from "@/components/dashboard/employees/EmployeeTable";
import { EmployeeDrawer } from "@/components/dashboard/employees/EmployeeDrawer";
import {
  type Employee,
  type PaginatedResponse,
  getEmployeeDisplayName,
  getEmployeeDocument,
} from "@/types/employee";
import { EmployeeOverviewDrawer } from "@/components/dashboard/employees/EmployeeOverviewDrawer";
import { BulkActionModal, CreateCustomFieldModal, DeleteCustomFieldModal } from "@/components/dashboard/employees/EmployeeModals";
import { type CustomFieldDefinition } from "@/types/custom-field";
import { ImportEmployeesModal } from "@/components/dashboard/employees/ImportEmployeesModal";
import { useColumnFilters } from "@/hooks/useColumnFilters";

export default function EmployeesPage() {
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [customFieldDefinitions, setCustomFieldDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const [activeTab, setActiveTab] = useState<string>("");
  const [search, setSearch] = useState("");
  const searchInputRef = useRef<HTMLInputElement>(null);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [refreshTrigger, setRefreshTrigger] = useState(0);

  const [selectedIds, setSelectedIds] = useState<string[]>([]);
  const [isProcessing, setIsProcessing] = useState(false);
  const [isExporting, setIsExporting] = useState(false);

  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [viewEmployeeId, setViewEmployeeId] = useState<string | null>(null);

  // El buscador global de la cabecera puede pedir que se abra una ficha.
  useEffect(() => onOpenRecord("employee", setViewEmployeeId), []);

  // Estado para el Modal de Crear Campo Personalizado al vuelo
  const [isCustomFieldModalOpen, setIsCustomFieldModalOpen] = useState(false);
  const [newFieldForm, setNewFieldForm] = useState({
    label: "",
    field_type: "text" as "text" | "number" | "date" | "boolean",
  });
  const [isCreatingField, setIsCreatingField] = useState(false);
  const [fieldToDelete, setFieldToDelete] = useState<CustomFieldDefinition | null>(null);
  const [isDeletingField, setIsDeletingField] = useState(false);

  const [isNewDropdownOpen, setIsNewDropdownOpen] = useState(false);
  const [isImportModalOpen, setIsImportModalOpen] = useState(false);
  const [employeeValuesMap, setEmployeeValuesMap] = useState<Record<string, Record<string, string>>>({});
  const newDropdownRef = useRef<HTMLDivElement>(null);

  // Estados para el Modal de Confirmación Masiva
  const [confirmModal, setConfirmModal] = useState<{
    isOpen: boolean;
    type: "activate" | "deactivate" | null;
    ids: string[];
  }>({
    isOpen: false,
    type: null,
    ids: [],
  });

  // Integración del Hook Reutilizable de Filtros por Columna (entity_type: Employee)
  const {
    showAdvancedFilters,
    setShowAdvancedFilters,
    activeFilters,
    setActiveFilters,
    handleAddFilter,
    handleRemoveFilter,
    handleUpdateFilter,
    filteredItems: columnFilteredEmployees,
  } = useColumnFilters(employees, (employee, columnId) => {
    const employeeVals = employeeValuesMap[employee.id] || {};
    const def = customFieldDefinitions.find(d => d.id === columnId);

    if (columnId === "client") return getEmployeeDisplayName(employee);
    if (columnId === "employee_code") return employee.employee_code ?? "";
    if (columnId === "position") return employee.position ?? "";
    if (columnId === "document") return getEmployeeDocument(employee);
    if (columnId === "status") return employee.status ?? "";

    const rawVal = employeeVals[columnId];
    return rawVal !== undefined && rawVal !== null
      ? String(rawVal)
      : (def?.field_type === "boolean" ? "false" : "");
  });

  useEffect(() => {
    function handleSearchShortcut(event: KeyboardEvent) {
      const target = event.target as HTMLElement | null;
      const isEditingControl = target?.matches("input, textarea, select, button, [contenteditable='true']");
      if (event.key !== "/" || isEditingControl) return;

      event.preventDefault();
      searchInputRef.current?.focus();
    }

    window.addEventListener("keydown", handleSearchShortcut);
    return () => window.removeEventListener("keydown", handleSearchShortcut);
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams({
          page: String(page),
          per_page: String(perPage),
        });
        if (activeTab) params.set("status", activeTab);

        const [res, definitions] = await Promise.all([
          apiFetch<PaginatedResponse<Employee>>(`/v1/employees?${params}`),
          apiFetch<CustomFieldDefinition[]>("/v1/custom-fields/definitions?entity_type=Employee").catch(() => []),
        ]);
        if (cancelled) return;

        // Cargar los valores personalizados (EAV) para cada empleado de la página actual
        const loadedValues: Record<string, Record<string, string>> = {};
        await Promise.all(
          res.data.map(async (employee) => {
            const vals = await apiFetch<Array<{ field_id: string; value: string }>>(
              `/v1/custom-fields/values?entity_type=Employee&entity_id=${employee.id}`
            ).catch(() => []);

            loadedValues[employee.id] = {};
            vals.forEach((v) => {
              loadedValues[employee.id][v.field_id] = v.value;
            });
          })
        );

        if (cancelled) return;

        setEmployees(res.data);
        setCustomFieldDefinitions(definitions.filter((definition) => definition.field_key !== "internal_notes"));
        setEmployeeValuesMap(loadedValues);
        setLastPage(res.last_page);
        setTotal(res.total);
        setSelectedIds([]);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : "No se pudo cargar la lista de empleados.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();
    return () => { cancelled = true; };
  }, [page, perPage, activeTab, refreshTrigger]);

  // Filtrado final combinando la barra de búsqueda general y el hook de columnas
  const filtered = useMemo(() => {
    let result = columnFilteredEmployees;

    if (search.trim()) {
      const q = search.trim().toLowerCase();
      result = result.filter(
        (e) =>
          getEmployeeDisplayName(e).toLowerCase().includes(q) ||
          getEmployeeDocument(e).toLowerCase().includes(q) ||
          (e.employee_code && e.employee_code.toLowerCase().includes(q))
      );
    }

    return result;
  }, [columnFilteredEmployees, search]);

  const handlePerPageChange = (newPerPage: number) => {
    setPerPage(newPerPage);
    setPage(1);
  };

  const handleToggleSelection = (id: string) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((selectedId) => selectedId !== id) : [...prev, id]
    );
  };

  const handleSelectAll = (ids: string[]) => {
    setSelectedIds(ids);
  };

  const promptBulkDeactivate = () => {
    const idsToDeactivate = selectedIds.filter(id => {
      const employee = employees.find(e => e.id === id);
      return employee && employee.status !== 'INACTIVE';
    });
    if (idsToDeactivate.length === 0) return;
    setConfirmModal({ isOpen: true, type: "deactivate", ids: idsToDeactivate });
  };

  const promptBulkActivate = () => {
    const idsToActivate = selectedIds.filter(id => {
      const employee = employees.find(e => e.id === id);
      return employee && employee.status !== 'ACTIVE';
    });
    if (idsToActivate.length === 0) return;
    setConfirmModal({ isOpen: true, type: "activate", ids: idsToActivate });
  };

  const executeBulkAction = async () => {
    if (!confirmModal.type || confirmModal.ids.length === 0) return;

    setIsProcessing(true);
    const endpoint = confirmModal.type === "deactivate"
      ? "/v1/employees/bulk-deactivate"
      : "/v1/employees/bulk-activate";

    try {
      await apiFetch(endpoint, {
        method: "POST",
        body: JSON.stringify({ ids: confirmModal.ids })
      });

      setRefreshTrigger(prev => prev + 1);
      setSelectedIds([]);
      setConfirmModal({ isOpen: false, type: null, ids: [] });
    } catch (err) {
      console.error(err);
      alert(err instanceof ApiError ? err.message : "Ocurrió un error al procesar la solicitud.");
    } finally {
      setIsProcessing(false);
    }
  };

  // Guardar nuevo campo personalizado desde el modal flotante
  const handleCreateCustomFieldSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!newFieldForm.label.trim()) return;

    setIsCreatingField(true);
    try {
      const field_key = newFieldForm.label
        .toLowerCase()
        .trim()
        .replace(/[^\w\s]/gi, '')
        .replace(/\s+/g, '_');

      await apiFetch("/v1/custom-fields/definitions", {
        method: "POST",
        body: JSON.stringify({
          entity_type: "Employee",
          field_key,
          label: newFieldForm.label,
          field_type: newFieldForm.field_type,
        }),
      });

      setNewFieldForm({ label: "", field_type: "text" });
      setIsCustomFieldModalOpen(false);
      setRefreshTrigger(prev => prev + 1);
    } catch (err) {
      alert(err instanceof ApiError ? err.message : "No se pudo crear la columna personalizada.");
    } finally {
      setIsCreatingField(false);
    }
  };

  const handleDeleteCustomField = async () => {
    if (!fieldToDelete) return;
    setIsDeletingField(true);
    try {
      await apiFetch(`/v1/custom-fields/definitions/${fieldToDelete.id}`, { method: "DELETE" });
      setFieldToDelete(null);
      setRefreshTrigger(prev => prev + 1);
    } catch (err) {
      alert(err instanceof ApiError ? err.message : "No se pudo eliminar la columna personalizada.");
    } finally {
      setIsDeletingField(false);
    }
  };

  // ---------------------------------------------------------------------
  // Exportar a Excel (.xlsx) para Empleados
  // ---------------------------------------------------------------------
  const handleExport = async () => {
    const requestedEmployees = selectedIds.length > 0
      ? employees.filter(e => selectedIds.includes(e.id))
      : filtered;

    if (requestedEmployees.length === 0) {
      alert("No hay empleados para exportar.");
      return;
    }

    setIsExporting(true);
    try {
      const dataToExport = await Promise.all(
        requestedEmployees.map(async (employee) => {
          return apiFetch<Employee>(`/v1/employees/${employee.id}`).catch(() => employee);
        })
      );
      const customValuesByEmployee: Record<string, Record<string, string>> = {};
      await Promise.all(
        dataToExport.map(async (employee) => {
          const values = await apiFetch<Array<{ field_id: string; value: string }>>(
            `/v1/custom-fields/values?entity_type=Employee&entity_id=${employee.id}`
          ).catch(() => []);
          customValuesByEmployee[employee.id] = Object.fromEntries(
            values.map((value) => [value.field_id, value.value])
          );
        })
      );

      const headers = [
        "Código",
        "Nombres",
        "Apellidos",
        "Tipo Documento",
        "Número Documento",
        "Cargo",
        "Estado",
      ];
      const customDefs = customFieldDefinitions;
      const customLabels = customDefs.map((def) => def.label);
      const allHeaders = [...headers, ...customLabels];
      const nCols = allHeaders.length;

      const BRAND = "FF2A4FE0";
      const BRAND_DARK = "FF12225E";
      const INK = "FF12162A";
      const SUBTITLE = "FF5B6178";
      const BORDER_COLOR = "FFD8DCE9";
      const STRIPE = "FFF7F8FC";
      const SUCCESS = "FF1E9E6B";
      const SUCCESS_TINT = "FFE7F7F0";
      const DANGER = "FFD64545";
      const DANGER_TINT = "FFFBEAEA";

      const workbook = new ExcelJS.Workbook();
      workbook.creator = "Creativas OS";
      workbook.created = new Date();
      const ws = workbook.addWorksheet("Empleados");

      const thinBorder = {
        top: { style: "thin" as const, color: { argb: BORDER_COLOR } },
        left: { style: "thin" as const, color: { argb: BORDER_COLOR } },
        bottom: { style: "thin" as const, color: { argb: BORDER_COLOR } },
        right: { style: "thin" as const, color: { argb: BORDER_COLOR } },
      };

      ws.mergeCells(1, 1, 1, nCols);
      const titleCell = ws.getCell(1, 1);
      titleCell.value = "Listado de Empleados";
      titleCell.font = { name: "Arial", size: 15, bold: true, color: { argb: "FFFFFFFF" } };
      titleCell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: BRAND_DARK } };
      titleCell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
      ws.getRow(1).height = 30;

      ws.mergeCells(2, 1, 2, nCols);
      const subCell = ws.getCell(2, 1);
      const today = new Date().toLocaleDateString("es-PE", { day: "numeric", month: "long", year: "numeric" });
      subCell.value = `Exportado el ${today}`;
      subCell.font = { name: "Arial", size: 9, italic: true, color: { argb: SUBTITLE } };
      subCell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: "FFF5F6FA" } };
      subCell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
      ws.getRow(2).height = 18;

      ws.getRow(3).height = 6;

      const HEADER_ROW = 4;
      const FIRST_DATA_ROW = 5;

      allHeaders.forEach((h, i) => {
        const cell = ws.getCell(HEADER_ROW, i + 1);
        cell.value = h;
        cell.font = { name: "Arial", size: 10.5, bold: true, color: { argb: "FFFFFFFF" } };
        cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: BRAND } };
        cell.alignment = { vertical: "middle", horizontal: "center", wrapText: true };
        cell.border = thinBorder;
      });
      ws.getRow(HEADER_ROW).height = 32;

      dataToExport.forEach((e, idx) => {
        const person = e.party?.person;

        const rowValues: (string | number)[] = [
          e.employee_code || "",
          person?.given_names || "",
          person?.family_name || "",
          person?.document_type || "DNI",
          person?.document_number || "",
          e.position || "",
          e.status || "ACTIVE",
        ];

        customDefs.forEach((def) => {
          rowValues.push(customValuesByEmployee[e.id]?.[def.id] ?? "");
        });

        const r = FIRST_DATA_ROW + idx;
        const stripe = idx % 2 === 0 ? "FFFFFFFF" : STRIPE;

        rowValues.forEach((val, colIdx) => {
          const cell = ws.getCell(r, colIdx + 1);
          cell.value = val;
          cell.font = { name: "Arial", size: 10.5, color: { argb: INK } };
          cell.border = thinBorder;
          const headerName = allHeaders[colIdx];

          if (headerName === "Estado") {
            const isActive = val === "ACTIVE";
            cell.font = { name: "Arial", size: 10.5, bold: true, color: { argb: isActive ? SUCCESS : DANGER } };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: isActive ? SUCCESS_TINT : DANGER_TINT } };
            cell.alignment = { vertical: "middle", horizontal: "center" };
          } else if (headerName === "Tipo Documento" || headerName === "Código") {
            cell.alignment = { vertical: "middle", horizontal: "center" };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: stripe } };
          } else {
            cell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: stripe } };
          }
        });
        ws.getRow(r).height = 22;
      });

      const widths: Record<string, number> = {
        "Código": 16,
        "Nombres": 22,
        "Apellidos": 22,
        "Tipo Documento": 15,
        "Número Documento": 18,
        "Cargo": 22,
        "Estado": 12,
      };
      allHeaders.forEach((h, i) => {
        ws.getColumn(i + 1).width = widths[h] ?? 18;
      });

      ws.views = [{ state: "frozen", ySplit: HEADER_ROW, showGridLines: false }];
      const lastRow = FIRST_DATA_ROW + dataToExport.length - 1;
      ws.autoFilter = {
        from: { row: HEADER_ROW, column: 1 },
        to: { row: lastRow, column: nCols },
      };

      const buffer = await workbook.xlsx.writeBuffer();
      const blob = new Blob([buffer], {
        type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.setAttribute("download", `empleados_${new Date().toISOString().slice(0, 10)}.xlsx`);
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);
    } catch (err) {
      console.error(err);
      alert("No se pudo generar el archivo Excel.");
    } finally {
      setIsExporting(false);
    }
  };

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (newDropdownRef.current && !newDropdownRef.current.contains(event.target as Node)) {
        setIsNewDropdownOpen(false);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  const TABS = [
    { id: "", label: "Todos" },
    { id: "ACTIVE", label: "Activos" },
    { id: "INACTIVE", label: "Inactivos" },
  ];

  const selectedEmployees = employees.filter(e => selectedIds.includes(e.id));
  const hasActiveSelected = selectedEmployees.some(e => e.status === 'ACTIVE');
  const hasInactiveSelected = selectedEmployees.some(e => e.status === 'INACTIVE');

  return (
    <div className="flex h-full min-h-0 flex-col p-0.1 text-slate-900">
      <div className="flex h-full min-h-0 flex-col overflow-hidden bg-white shadow-sm rounded-tl-[40px] rounded-br-[40px]">

        {/* Header */}
        <div className="flex shrink-0 flex-col gap-4 px-8 pt-8 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Empleados</h1>
            <p className="mt-1 text-sm text-slate-500">
              Gestiona el personal, códigos internos y cargos de la empresa.
            </p>
          </div>
          <div ref={newDropdownRef} className="relative">
            <button
              onClick={() => setIsNewDropdownOpen((prev) => !prev)}
              className="flex items-center gap-2 rounded-xl bg-[var(--app-brand-deep)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[var(--app-brand)]"
            >
              <Plus size={16} />
              Nuevo empleado
            </button>

            {isNewDropdownOpen && (
              <div className="absolute right-0 top-12 z-50 w-48 rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl text-xs font-normal">
                <button
                  onClick={() => {
                    setIsNewDropdownOpen(false);
                    setIsDrawerOpen(true);
                  }}
                  className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50 transition-colors font-medium"
                >
                  <Plus size={14} className="text-slate-400" /> Crear nuevo
                </button>
                <button
                  onClick={() => {
                    setIsNewDropdownOpen(false);
                    setIsImportModalOpen(true);
                  }}
                  className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 hover:bg-slate-50 transition-colors font-medium"
                >
                  <Download size={14} className="text-slate-400" /> Importar empleados
                </button>
              </div>
            )}
          </div>
        </div>

        {/* Tabs */}
        <div className="mt-4 flex shrink-0 gap-6 border-b border-slate-200 px-8">
          {TABS.map((tab) => (
            <button
              key={tab.id}
              onClick={() => { setActiveTab(tab.id); setPage(1); }}
              className={`border-b-2 pb-3 text-sm font-medium transition-colors ${
                activeTab === tab.id
                  ? "border-[var(--app-brand-deep)] text-[var(--app-brand-deep)]"
                  : "border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700"
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Toolbar */}
        <div className="flex shrink-0 flex-col border-b border-slate-200 bg-white px-8 py-4 z-20 gap-3">
          <div className="flex items-center justify-between">
            <div className="flex flex-1 items-center gap-3">
              <div className="relative w-full max-w-sm">
                <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input
                  type="text"
                  placeholder="Buscar por nombre, código o documento... ( / )"
                  ref={searchInputRef}
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="w-full rounded-md border border-slate-300 bg-white py-1.5 pl-9 pr-9 text-sm transition-colors focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
                />
                {search && (
                  <button
                    onClick={() => setSearch("")}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-full p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none"
                    aria-label="Limpiar búsqueda"
                  >
                    <X size={14} />
                  </button>
                )}
              </div>

              <button
                onClick={() => setShowAdvancedFilters(!showAdvancedFilters)}
                className={`flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors ${
                  activeFilters.length > 0
                    ? "border-[var(--app-brand-deep)] bg-blue-50 text-[var(--app-brand-deep)]"
                    : "border-slate-300 bg-white text-slate-700 hover:bg-slate-50"
                }`}
              >
                <Filter size={14} />
                Más filtros
                {activeFilters.length > 0 && (
                  <span className="flex h-5 w-5 items-center justify-center rounded-full bg-[var(--app-brand-deep)] text-[10px] font-bold text-white">
                    {activeFilters.length}
                  </span>
                )}
              </button>
            </div>
          </div>

          {/* Panel interactivo de Filtros Activos */}
          <AnimatePresence>
            {showAdvancedFilters && (
              <motion.div
                initial={{ height: 0, opacity: 0 }}
                animate={{ height: "auto", opacity: 1 }}
                exit={{ height: 0, opacity: 0 }}
                className="overflow-hidden pt-2"
              >
                <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
                  <span className="text-xs font-semibold text-slate-500 mr-1">Filtros activos:</span>
                  
                  {activeFilters.length === 0 ? (
                    <span className="text-xs text-slate-400 italic">
                      No hay filtros activos. Haz clic en los tres puntos de cualquier columna de la tabla para filtrar.
                    </span>
                  ) : (
                    activeFilters.map((filter) => (
                      <div
                        key={filter.id}
                        className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-1.5 shadow-2xs text-xs"
                      >
                        <span className="font-semibold text-slate-700">{filter.label}:</span>
                        
                        <select
                          value={filter.operator}
                          onChange={(e) => {
                            const newOp = e.target.value;
                            handleUpdateFilter(filter.id, { 
                              operator: newOp, 
                              value: newOp === "range" ? "|" : (["today", "all"].includes(newOp) ? "" : filter.value) 
                            });
                          }}
                          className="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs text-slate-600 focus:outline-none"
                        >
                          {filter.id === "status" ? (
                            <option value="equals">es igual a</option>
                          ) : filter.type === "select" ? (
                            <option value="equals">es igual a</option>
                          ) : filter.type === "date" ? (
                            <>
                              <option value="all">Todos</option>
                              <option value="today">Hoy</option>
                              <option value="yesterday">Ayer</option>
                              <option value="this_week">Al principio de esta semana</option>
                              <option value="last_week">La semana pasada</option>
                              <option value="last_month">El mes pasado</option>
                              <option value="year_start">Al principio de este año</option>
                              <option value="range">Periodo personalizado</option>
                            </>
                          ) : (
                            <>
                              <option value="contains">contiene</option>
                              <option value="equals">es igual a</option>
                              {filter.type === "number" && (
                                <>
                                  <option value="greater">mayor que</option>
                                  <option value="less">menor que</option>
                                </>
                              )}
                            </>
                          )}
                        </select>

                        {filter.id === "status" ? (
                          <select
                            value={filter.value}
                            onChange={(e) => handleUpdateFilter(filter.id, { value: e.target.value })}
                            className="w-28 rounded border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-800 focus:outline-none"
                          >
                            <option value="ACTIVE">Activo</option>
                            <option value="INACTIVE">Inactivo</option>
                          </select>
                        ) : filter.type === "select" ? (
                          <select
                            value={filter.value}
                            onChange={(e) => handleUpdateFilter(filter.id, { value: e.target.value })}
                            className="w-28 rounded border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-800 focus:outline-none"
                          >
                            <option value="true">Sí</option>
                            <option value="false">No</option>
                          </select>
                        ) : filter.type === "date" && filter.operator === "range" ? (
                          <div className="flex items-center gap-1 bg-white border border-slate-200 rounded px-2 py-0.5 text-xs">
                            <span className="text-slate-400">Desde:</span>
                            <input
                              type="date"
                              value={filter.value.split("|")[0] || ""}
                              onChange={(e) => {
                                const [, hasta = ""] = filter.value.split("|");
                                handleUpdateFilter(filter.id, { value: `${e.target.value}|${hasta}` });
                              }}
                              className="bg-transparent text-slate-800 focus:outline-none text-xs w-24"
                            />
                            <span className="text-slate-300">|</span>
                            <span className="text-slate-400">Hasta:</span>
                            <input
                              type="date"
                              value={filter.value.split("|")[1] || ""}
                              onChange={(e) => {
                                const [desde = ""] = filter.value.split("|");
                                handleUpdateFilter(filter.id, { value: `${desde}|${e.target.value}` });
                              }}
                              className="bg-transparent text-slate-800 focus:outline-none text-xs w-24"
                            />
                          </div>
                        ) : filter.type === "date" ? (
                          <span className="text-slate-400 text-[11px] italic px-1">Se aplica al período</span>
                        ) : (
                          <input
                            type={filter.type === "number" ? "number" : "text"}
                            value={filter.value}
                            onChange={(e) => handleUpdateFilter(filter.id, { value: e.target.value })}
                            placeholder="Valor..."
                            className="w-28 rounded border border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-800 focus:border-[var(--app-brand)] focus:outline-none"
                          />
                        )}

                        <button
                          onClick={() => handleRemoveFilter(filter.id)}
                          className="text-slate-400 hover:text-red-500 ml-1"
                        >
                          <X size={13} />
                        </button>
                      </div>
                    ))
                  )}

                  {activeFilters.length > 0 && (
                    <button
                      onClick={() => setActiveFilters([])}
                      className="text-xs font-medium text-red-600 hover:underline ml-auto"
                    >
                      Limpiar todos
                    </button>
                  )}
                </div>
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        {/* Barra de Acciones Masivas */}
        <AnimatePresence>
          {selectedIds.length > 0 && (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: "auto", opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              className="shrink-0 border-b border-slate-200 bg-[var(--app-brand-soft)] z-10 overflow-hidden"
            >
              <div className="flex items-center justify-between gap-4 px-8 py-3">
                <div className="flex items-center gap-2">
                  <span className="flex h-6 w-6 items-center justify-center rounded bg-[var(--app-brand-deep)] text-xs font-bold text-white">
                    {selectedIds.length}
                  </span>
                  <span className="text-sm font-medium text-[var(--app-brand-deep)]">
                    empleado(s) seleccionado(s)
                  </span>

                  <button
                    onClick={() => setSelectedIds([])}
                    className="ml-1 rounded-full p-1 text-[var(--app-brand-deep)] transition-colors hover:bg-white/60 focus:outline-none"
                    aria-label="Deseleccionar todos"
                  >
                    <X size={16} />
                  </button>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    onClick={handleExport}
                    disabled={isExporting}
                    className="flex items-center gap-2 rounded-lg bg-emerald-50 px-3.5 py-1.5 text-sm font-semibold text-emerald-700 border border-emerald-200 transition-all hover:bg-emerald-100 shadow-xs disabled:opacity-60"
                  >
                    <Download size={14} className="text-emerald-600" />
                    {isExporting ? "Generando..." : "Exportar Excel"}
                  </button>
                  <div className="mx-1 h-4 w-px bg-slate-300" />

                  {hasInactiveSelected && (
                    <button
                      onClick={promptBulkActivate}
                      disabled={isProcessing}
                      className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-emerald-600 transition-colors hover:bg-white/60 disabled:opacity-50"
                    >
                      <CheckCircle size={14} /> Activar
                    </button>
                  )}

                  {hasActiveSelected && (
                    <button
                      onClick={promptBulkDeactivate}
                      disabled={isProcessing}
                      className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-red-600 transition-colors hover:bg-white/60 disabled:opacity-50"
                    >
                      <Trash2 size={14} /> Desactivar
                    </button>
                  )}
                </div>
              </div>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Table Area */}
        <div className="flex-1 min-h-0 bg-white px-2">
          <div className="h-full min-w-0 overflow-auto">
          {error ? (
            <div className="flex flex-col items-center justify-center py-20 text-center">
              <AlertCircle size={32} className="mb-3 text-red-500" />
              <p className="text-sm text-slate-600">{error}</p>
              <button
                onClick={() => setRefreshTrigger(prev => prev + 1)}
                className="mt-4 flex items-center gap-2 text-sm font-medium text-[var(--app-brand-deep)] hover:underline"
              >
                <RefreshCw size={14} /> Reintentar
              </button>
            </div>
          ) : loading ? (
            <div className="divide-y divide-slate-100 px-6">
              {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="flex items-center gap-4 py-4">
                  <div className="h-4 w-4 animate-pulse rounded bg-slate-200" />
                  <div className="h-10 w-10 animate-pulse rounded-full bg-slate-200" />
                  <div className="flex-1 space-y-2">
                    <div className="h-4 w-48 animate-pulse rounded bg-slate-200" />
                    <div className="h-3 w-24 animate-pulse rounded bg-slate-200" />
                  </div>
                </div>
              ))}
            </div>
          ) : filtered.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-20 text-center">
              <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                <Search size={24} className="text-slate-400" />
              </div>
              <h3 className="text-sm font-medium text-slate-900">No se encontraron empleados</h3>
              <p className="mt-1 text-sm text-slate-500">Ajusta tu búsqueda o cambia de pestaña.</p>
            </div>
          ) : (
            <EmployeeTable
              employees={filtered}
              customFieldDefinitions={customFieldDefinitions}
              selectedIds={selectedIds}
              onToggleSelection={handleToggleSelection}
              onSelectAll={handleSelectAll}
              onSelectEmployee={(id) => setViewEmployeeId(id)}
              onAddCustomField={() => setIsCustomFieldModalOpen(true)}
              onRequestDeleteCustomField={setFieldToDelete}
              onAddFilter={handleAddFilter}
            />
          )}
          </div>
        </div>

        {/* Pagination */}
        {!loading && !error && (
          <div className="shrink-0">
            <Pagination
              currentPage={page}
              lastPage={lastPage}
              total={total}
              perPage={perPage}
              onPageChange={setPage}
              onPerPageChange={handlePerPageChange}
            />
          </div>
        )}

        <EmployeeDrawer
          isOpen={isDrawerOpen}
          onClose={() => setIsDrawerOpen(false)}
          onSuccess={() => setRefreshTrigger(prev => prev + 1)}
        />

        <EmployeeOverviewDrawer
          employeeId={viewEmployeeId}
          onClose={() => setViewEmployeeId(null)}
          onUpdate={() => setRefreshTrigger(prev => prev + 1)}
        />

        <CreateCustomFieldModal
          isOpen={isCustomFieldModalOpen}
          form={newFieldForm}
          isSaving={isCreatingField}
          onChange={setNewFieldForm}
          onClose={() => setIsCustomFieldModalOpen(false)}
          onSubmit={handleCreateCustomFieldSubmit}
        />
        <BulkActionModal
          type={confirmModal.type}
          ids={confirmModal.ids}
          isProcessing={isProcessing}
          onCancel={() => setConfirmModal({ isOpen: false, type: null, ids: [] })}
          onConfirm={executeBulkAction}
        />
        <DeleteCustomFieldModal
          definition={fieldToDelete}
          isDeleting={isDeletingField}
          onCancel={() => setFieldToDelete(null)}
          onConfirm={handleDeleteCustomField}
        />

        <ImportEmployeesModal
          isOpen={isImportModalOpen}
          onClose={() => setIsImportModalOpen(false)}
          onSuccess={() => setRefreshTrigger(prev => prev + 1)}
        />

      </div>
    </div>
  );
}