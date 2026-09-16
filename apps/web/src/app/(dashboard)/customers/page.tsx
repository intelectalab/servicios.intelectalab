"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { motion, AnimatePresence } from "framer-motion";
import ExcelJS from "exceljs";
import {
  Search,
  Plus,
  AlertCircle,
  RefreshCw,
  Filter,
  Columns,
  Trash2,
  Download,
  UserPlus,
  CheckCircle,
  X,
} from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { Pagination } from "@/components/Pagination";
import { CustomerTable } from "@/components/dashboard/customers/CustomerTable";
import { CustomerDrawer } from "@/components/dashboard/customers/CustomerDrawer";
import {
  type Customer,
  type PaginatedResponse,
  getCustomerDisplayName,
  getCustomerDocument,
} from "@/types/customer";
import { CustomerOverviewDrawer } from "@/components/dashboard/customers/CustomerOverviewDrawer";
import { BulkActionModal, CreateCustomFieldModal, DeleteCustomFieldModal } from "@/components/dashboard/customers/CustomerModals";
import { type CustomFieldDefinition } from "@/types/custom-field";
import { ImportCustomersModal } from "@/components/dashboard/customers/ImportCustomersModal";

export default function CustomersPage() {
  const [customers, setCustomers] = useState<Customer[]>([]);
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
  const [viewCustomerId, setViewCustomerId] = useState<string | null>(null);

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
          apiFetch<PaginatedResponse<Customer>>(`/v1/customers?${params}`),
          apiFetch<CustomFieldDefinition[]>("/v1/custom-fields/definitions?entity_type=Customer").catch(() => []),
        ]);
        if (cancelled) return;

        setCustomers(res.data);
        setCustomFieldDefinitions(definitions.filter((definition) => definition.field_key !== "internal_notes"));
        setLastPage(res.last_page);
        setTotal(res.total);
        setSelectedIds([]);
      } catch (err) {
        if (cancelled) return;
        setError(err instanceof ApiError ? err.message : "No se pudo cargar la lista de clientes.");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();
    return () => { cancelled = true; };
  }, [page, perPage, activeTab, refreshTrigger]);

  const filtered = useMemo(() => {
    if (!search.trim()) return customers;
    const q = search.trim().toLowerCase();
    return customers.filter(
      (c) =>
        getCustomerDisplayName(c).toLowerCase().includes(q) ||
        getCustomerDocument(c).toLowerCase().includes(q)
    );
  }, [customers, search]);

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
      const customer = customers.find(c => c.id === id);
      return customer && customer.status !== 'INACTIVE';
    });
    if (idsToDeactivate.length === 0) return;
    setConfirmModal({ isOpen: true, type: "deactivate", ids: idsToDeactivate });
  };

  const promptBulkActivate = () => {
    const idsToActivate = selectedIds.filter(id => {
      const customer = customers.find(c => c.id === id);
      return customer && customer.status !== 'ACTIVE';
    });
    if (idsToActivate.length === 0) return;
    setConfirmModal({ isOpen: true, type: "activate", ids: idsToActivate });
  };

  const executeBulkAction = async () => {
    if (!confirmModal.type || confirmModal.ids.length === 0) return;

    setIsProcessing(true);
    const endpoint = confirmModal.type === "deactivate"
      ? "/v1/customers/bulk-deactivate"
      : "/v1/customers/bulk-activate";

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
          entity_type: "Customer",
          field_key,
          label: newFieldForm.label,
          field_type: newFieldForm.field_type,
        }),
      });

      setNewFieldForm({ label: "", field_type: "text" });
      setIsCustomFieldModalOpen(false);
      setRefreshTrigger(prev => prev + 1); // Recarga la tabla para mostrar la nueva columna automáticamente
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
  // Exportar a Excel (.xlsx) con el mismo diseño de la maqueta:
  // título de tabla, encabezado en azul de marca, filas en cebra,
  // badges de color para Estado, formato de moneda y anchos de columna.
  // ---------------------------------------------------------------------
  const handleExport = async () => {
    const dataToExport = selectedIds.length > 0
      ? customers.filter(c => selectedIds.includes(c.id))
      : filtered;

    if (dataToExport.length === 0) {
      alert("No hay clientes para exportar.");
      return;
    }

    setIsExporting(true);
    try {
      const headers = [
        "Tipo Persona",
        "Nombre o Razón Social",
        "Apellidos",
        "Tipo Documento",
        "Número Documento",
        "Días Crédito",
        "Límite Crédito",
        "Estado",
      ];
      const customDefs = customFieldDefinitions;
      const customLabels = customDefs.map((def) => def.label);
      const allHeaders = [...headers, ...customLabels];
      const nCols = allHeaders.length;

      // ---- Paleta de marca (Creativas OS) ----
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
      const ws = workbook.addWorksheet("Clientes");

      const thinBorder = {
        top: { style: "thin" as const, color: { argb: BORDER_COLOR } },
        left: { style: "thin" as const, color: { argb: BORDER_COLOR } },
        bottom: { style: "thin" as const, color: { argb: BORDER_COLOR } },
        right: { style: "thin" as const, color: { argb: BORDER_COLOR } },
      };

      // ---- Fila de título ----
      ws.mergeCells(1, 1, 1, nCols);
      const titleCell = ws.getCell(1, 1);
      titleCell.value = "Listado de Clientes";
      titleCell.font = { name: "Arial", size: 15, bold: true, color: { argb: "FFFFFFFF" } };
      titleCell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: BRAND_DARK } };
      titleCell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
      ws.getRow(1).height = 30;

      // ---- Fila de subtítulo ----
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

      // ---- Fila de encabezado ----
      allHeaders.forEach((h, i) => {
        const cell = ws.getCell(HEADER_ROW, i + 1);
        cell.value = h;
        cell.font = { name: "Arial", size: 10.5, bold: true, color: { argb: "FFFFFFFF" } };
        cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: BRAND } };
        cell.alignment = { vertical: "middle", horizontal: "center", wrapText: true };
        cell.border = thinBorder;
      });
      ws.getRow(HEADER_ROW).height = 32;

      // ---- Filas de datos ----
      dataToExport.forEach((c, idx) => {
        const isPerson = c.party?.party_type === "PERSON";
        const person = c.party?.person;
        const org = c.party?.organization;

        const rowValues: (string | number)[] = [
          c.party?.party_type || "PERSON",
          isPerson ? person?.given_names || "" : org?.legal_name || "",
          isPerson ? person?.family_name || "" : "",
          isPerson ? person?.document_type || "" : "RUC",
          isPerson ? person?.document_number || "" : org?.tax_identifier || "",
          c.payment_terms_days ?? 30,
          c.credit_limit ?? 0,
          c.status || "ACTIVE",
        ];

        customDefs.forEach((def) => {
          const partyAny = c.party as any;
          const valObj = partyAny?.custom_field_values?.find(
            (v: any) => v.field_id === def.id || v.definition?.field_key === def.field_key
          );
          rowValues.push(valObj?.value ?? "");
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
          } else if (headerName === "Días Crédito" || headerName === "Límite Crédito") {
            cell.alignment = { vertical: "middle", horizontal: "right" };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: stripe } };
            if (headerName === "Límite Crédito") cell.numFmt = '"S/ "#,##0.00';
          } else if (headerName === "Tipo Persona" || headerName === "Tipo Documento") {
            cell.alignment = { vertical: "middle", horizontal: "center" };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: stripe } };
          } else {
            cell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
            cell.fill = { type: "pattern", pattern: "solid", fgColor: { argb: stripe } };
          }
        });
        ws.getRow(r).height = 22;
      });

      // ---- Anchos de columna ----
      const widths: Record<string, number> = {
        "Tipo Persona": 16,
        "Nombre o Razón Social": 28,
        "Apellidos": 16,
        "Tipo Documento": 15,
        "Número Documento": 18,
        "Días Crédito": 13,
        "Límite Crédito": 16,
        "Estado": 12,
      };
      allHeaders.forEach((h, i) => {
        ws.getColumn(i + 1).width = widths[h] ?? 18;
      });

      // ---- Encabezado congelado, filtros y sin líneas de cuadrícula ----
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
      link.setAttribute("download", `clientes_${new Date().toISOString().slice(0, 10)}.xlsx`);
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

  const selectedCustomers = customers.filter(c => selectedIds.includes(c.id));
  const hasActiveSelected = selectedCustomers.some(c => c.status === 'ACTIVE');
  const hasInactiveSelected = selectedCustomers.some(c => c.status === 'INACTIVE');

  return (
    <div className="flex h-full min-h-0 flex-col p-0.1 text-slate-900">

      {/* Contenedor principal con esquinas asimétricas */}
      <div className="flex h-full min-h-0 flex-col overflow-hidden bg-white shadow-sm rounded-tl-[40px] rounded-br-[40px]">

        {/* Header */}
        <div className="flex shrink-0 flex-col gap-4 px-8 pt-8 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Clientes</h1>
            <p className="mt-1 text-sm text-slate-500">
              Gestiona la información de tus clientes y controla sus condiciones comerciales.
            </p>
          </div>
          <div ref={newDropdownRef} className="relative">
            <button
              onClick={() => setIsNewDropdownOpen((prev) => !prev)}
              className="flex items-center gap-2 rounded-xl bg-[var(--app-brand-deep)] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-[var(--app-brand)]"
            >
              <Plus size={16} />
              Nuevo cliente
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
                  <Download size={14} className="text-slate-400" /> Importar clientes
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
        <div className="flex shrink-0 items-center justify-between gap-4 border-b border-slate-200 bg-white px-8 py-4 z-20">
          <div className="flex flex-1 items-center gap-3">
            <div className="relative w-full max-w-sm">
              <Search size={16} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                placeholder="Buscar por nombre, RUC o documento... ( / )"
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

            <button className="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
              <Filter size={14} />
              Más filtros
            </button>
          </div>

          <div className="flex items-center gap-2">
            <button className="flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
              <Columns size={14} />
              Columnas
            </button>
          </div>
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
                    cliente(s) seleccionado(s)
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
                  <button className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium text-[var(--app-brand-deep)] transition-colors hover:bg-white/60">
                    <UserPlus size={14} /> Asignar
                  </button>
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
              <h3 className="text-sm font-medium text-slate-900">No se encontraron clientes</h3>
              <p className="mt-1 text-sm text-slate-500">Ajusta tu búsqueda o cambia de pestaña.</p>
            </div>
          ) : (
            <CustomerTable
              customers={filtered}
              customFieldDefinitions={customFieldDefinitions}
              selectedIds={selectedIds}
              onToggleSelection={handleToggleSelection}
              onSelectAll={handleSelectAll}
              onSelectCustomer={(id) => setViewCustomerId(id)}
              onAddCustomField={() => setIsCustomFieldModalOpen(true)}
              onRequestDeleteCustomField={setFieldToDelete}
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

        <CustomerDrawer
          isOpen={isDrawerOpen}
          onClose={() => setIsDrawerOpen(false)}
          onSuccess={() => setRefreshTrigger(prev => prev + 1)}
        />

        <CustomerOverviewDrawer
          customerId={viewCustomerId}
          onClose={() => setViewCustomerId(null)}
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

        <ImportCustomersModal
          isOpen={isImportModalOpen}
          onClose={() => setIsImportModalOpen(false)}
          onSuccess={() => setRefreshTrigger(prev => prev + 1)}
        />

      </div>
    </div>
  );
}
