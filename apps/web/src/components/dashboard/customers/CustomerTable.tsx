"use client";

import { useEffect, useState, useRef, useMemo, useLayoutEffect } from "react";
import { motion } from "framer-motion";
import { Building2, User, MoreHorizontal, MoreVertical, ArrowUp, ArrowDown, Filter, Pin, PinOff } from "lucide-react";
import { type Customer, getCustomerDisplayName, getCustomerDocument } from "@/types/customer";
import { type CustomFieldDefinition } from "@/types/custom-field";
import { StatusBadge } from "@/components/StatusBadge";
import { apiFetch } from "@/lib/api";

interface CustomerTableProps {
  customers: Customer[];
  selectedIds: string[];
  onToggleSelection: (id: string) => void;
  onSelectAll: (ids: string[]) => void;
  onSelectCustomer: (id: string) => void;
}

function formatCurrency(value: string | number | null) {
  if (value === null || value === undefined) return "—";
  const num = typeof value === "string" ? parseFloat(value) : value;
  if (Number.isNaN(num)) return "—";
  return num.toLocaleString("es-PE", { style: "currency", currency: "PEN" });
}

export function CustomerTable({ customers, selectedIds, onToggleSelection, onSelectAll, onSelectCustomer }: CustomerTableProps) {
  const [definitions, setDefinitions] = useState<CustomFieldDefinition[]>([]);
  const [customerValuesMap, setCustomerValuesMap] = useState<Record<string, Record<string, string>>>({});
  const [loadingCustom, setLoadingCustom] = useState(true);

  // Estados de ordenamiento
  const [sortColumn, setSortColumn] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<"asc" | "desc">("asc");

  // Estado para controlar qué menú de cabecera está abierto
  const [openHeaderMenu, setOpenHeaderMenu] = useState<string | null>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const headerCellRefs = useRef<Record<string, HTMLTableCellElement | null>>({});
  const selectionCellRef = useRef<HTMLTableCellElement>(null);
  const preferencesLoaded = useRef(false);
  const [frozenColumns, setFrozenColumns] = useState<string[]>([]);
  const [frozenOffsets, setFrozenOffsets] = useState<Record<string, number>>({});

  // Cerrar menú al hacer clic fuera
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) {
        setOpenHeaderMenu(null);
      }
    }
    document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
  }, []);

  useEffect(() => {
    let frameId: number | undefined;
    try {
      const stored = window.localStorage.getItem(FROZEN_COLUMNS_STORAGE_KEY);
      if (stored) {
        const parsed = JSON.parse(stored);
        if (Array.isArray(parsed)) {
          frameId = window.requestAnimationFrame(() => {
            setFrozenColumns(parsed);
            preferencesLoaded.current = true;
          });
        }
      }
    } catch {
      // Ignorar preferencias dañadas del navegador.
    } finally {
      if (!frameId) preferencesLoaded.current = true;
    }
    return () => {
      if (frameId) window.cancelAnimationFrame(frameId);
    };
  }, []);

  useEffect(() => {
    if (!preferencesLoaded.current) return;
    window.localStorage.setItem(FROZEN_COLUMNS_STORAGE_KEY, JSON.stringify(frozenColumns));
  }, [frozenColumns]);

  useEffect(() => {
    let cancelled = false;

    async function loadTableCustomData() {
      if (customers.length === 0) {
        setLoadingCustom(false);
        return;
      }

      setLoadingCustom(true);
      try {
        const defs = await apiFetch<CustomFieldDefinition[]>(
          "/v1/custom-fields/definitions?entity_type=Customer"
        ).catch(() => []);

        const visibleDefs = defs.filter(d => d.field_key !== "internal_notes");

        if (cancelled) return;
        setDefinitions(visibleDefs);

        const valuesMap: Record<string, Record<string, string>> = {};
        
        await Promise.all(
          customers.map(async (customer) => {
            const vals = await apiFetch<Array<{ field_id: string; value: string }>>(
              `/v1/custom-fields/values?entity_type=Customer&entity_id=${customer.id}`
            ).catch(() => []);

            valuesMap[customer.id] = {};
            vals.forEach((v) => {
              valuesMap[customer.id][v.field_id] = v.value;
            });
          })
        );

        if (cancelled) return;
        setCustomerValuesMap(valuesMap);
      } catch (err) {
        console.error("No se pudieron cargar los campos personalizados para la tabla", err);
      } finally {
        if (!cancelled) setLoadingCustom(false);
      }
    }

    loadTableCustomData();
    return () => { cancelled = true; };
  }, [customers]);

  // Lógica para ordenar los clientes en tiempo real
  const sortedCustomers = useMemo(() => {
    if (!sortColumn) return customers;

    return [...customers].sort((a, b) => {
      let valA: string | number = "";
      let valB: string | number = "";

      if (sortColumn === "client") {
        valA = getCustomerDisplayName(a).toLowerCase();
        valB = getCustomerDisplayName(b).toLowerCase();
      } else if (sortColumn === "document") {
        valA = getCustomerDocument(a);
        valB = getCustomerDocument(b);
      } else if (sortColumn === "payment_terms") {
        valA = a.payment_terms_days;
        valB = b.payment_terms_days;
      } else if (sortColumn === "credit_limit") {
        valA = a.credit_limit ?? 0;
        valB = b.credit_limit ?? 0;
      } else if (sortColumn === "status") {
        valA = a.status;
        valB = b.status;
      } else {
        // Es un campo dinámico EAV
        valA = customerValuesMap[a.id]?.[sortColumn] || "";
        valB = customerValuesMap[b.id]?.[sortColumn] || "";
      }

      if (valA < valB) return sortDirection === "asc" ? -1 : 1;
      if (valA > valB) return sortDirection === "asc" ? 1 : -1;
      return 0;
    });
  }, [customers, sortColumn, sortDirection, customerValuesMap]);

  const handleSort = (columnKey: string, direction: "asc" | "desc") => {
    setSortColumn(columnKey);
    setSortDirection(direction);
    setOpenHeaderMenu(null);
  };

  const handleSelectAll = () => {
    if (selectedIds.length === sortedCustomers.length && sortedCustomers.length > 0) {
      onSelectAll([]); 
    } else {
      onSelectAll(sortedCustomers.map((c) => c.id)); 
    }
  };

  const isAllSelected = sortedCustomers.length > 0 && selectedIds.length === sortedCustomers.length;

  const columns = useMemo(() => [
    ...STATIC_COLUMNS.slice(0, 2),
    ...definitions.map((def) => ({ key: def.id, title: def.label })),
    ...STATIC_COLUMNS.slice(2),
  ], [definitions]);

  const orderedColumns = useMemo(() => {
    const availableKeys = new Set(columns.map((column) => column.key));
    const pinned = frozenColumns.filter((key) => availableKeys.has(key));
    return [
      ...pinned.map((key) => columns.find((column) => column.key === key)! ),
      ...columns.filter((column) => !pinned.includes(column.key)),
    ];
  }, [columns, frozenColumns]);

  const lastFrozenColumnKey = useMemo(
    () => frozenColumns.findLast((key) => orderedColumns.some((column) => column.key === key)),
    [frozenColumns, orderedColumns]
  );

  const getFrozenDividerClass = (columnKey: string) =>
    columnKey === lastFrozenColumnKey ? "border-r-2 border-slate-300" : "";

  useLayoutEffect(() => {
    const updateOffsets = () => {
      const offsets: Record<string, number> = {};
      let offset = selectionCellRef.current?.getBoundingClientRect().width ?? 0;

      for (const column of orderedColumns) {
        if (!frozenColumns.includes(column.key)) continue;
        offsets[column.key] = offset;
        offset += headerCellRefs.current[column.key]?.getBoundingClientRect().width ?? 0;
      }

      setFrozenOffsets(offsets);
    };

    const frameId = window.requestAnimationFrame(updateOffsets);
    const observer = new ResizeObserver(updateOffsets);
    if (selectionCellRef.current) observer.observe(selectionCellRef.current);
    Object.values(headerCellRefs.current).forEach((element) => {
      if (element) observer.observe(element);
    });

    return () => {
      window.cancelAnimationFrame(frameId);
      observer.disconnect();
    };
  }, [orderedColumns, frozenColumns]);

  const toggleFrozenColumn = (columnKey: ColumnKey) => {
    setFrozenColumns((current) => current.includes(columnKey)
      ? current.filter((key) => key !== columnKey)
      : [...current, columnKey]);
    setOpenHeaderMenu(null);
  };

  const getFrozenStyle = (columnKey: ColumnKey, isHeader = false) => frozenColumns.includes(columnKey)
    ? { left: frozenOffsets[columnKey] ?? 0, position: "sticky" as const, zIndex: isHeader ? 70 : 20 }
    : undefined;

  const renderCustomerCell = (customer: Customer, columnKey: string, isSelected: boolean) => {
    const isOrg = customer.party.party_type === "ORGANIZATION";
    const customerVals = customerValuesMap[customer.id] || {};
    const cellClassName = isSelected
      ? `px-6 py-4 bg-slate-50 group-hover:bg-slate-50 ${getFrozenDividerClass(columnKey)}`
      : `px-6 py-4 bg-white group-hover:bg-slate-50 ${getFrozenDividerClass(columnKey)}`;
    const cellStyle = getFrozenStyle(columnKey);

    if (columnKey === "client") {
      return (
        <td key={columnKey} className={cellClassName} style={cellStyle}>
          <div className="flex items-center gap-3 min-w-[200px]">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
              {isOrg ? <Building2 size={18} /> : <User size={18} />}
            </div>
            <div>
              <button type="button" onClick={() => onSelectCustomer(customer.id)} className="font-medium text-slate-900 hover:text-[var(--app-brand-deep)] hover:underline text-left">
                {getCustomerDisplayName(customer)}
              </button>
              <p className="text-xs text-slate-500">{isOrg ? "Empresa" : "Persona Natural"}</p>
            </div>
          </div>
        </td>
      );
    }

    if (columnKey === "document") {
      return <td key={columnKey} className={`${cellClassName} text-slate-600`} style={cellStyle}>{getCustomerDocument(customer)}</td>;
    }
    if (columnKey === "payment_terms") {
      return <td key={columnKey} className={`${cellClassName} text-slate-600`} style={cellStyle}>{customer.payment_terms_days} días</td>;
    }
    if (columnKey === "credit_limit") {
      return <td key={columnKey} className={`${cellClassName} font-medium text-slate-700`} style={cellStyle}>{formatCurrency(customer.credit_limit)}</td>;
    }
    if (columnKey === "status") {
      return <td key={columnKey} className={cellClassName} style={cellStyle}><StatusBadge status={customer.status} /></td>;
    }
    if (columnKey === "actions") {
      return <td key={columnKey} className={`${cellClassName} text-right`} style={cellStyle}><button type="button" onClick={() => onSelectCustomer(customer.id)} className="text-slate-400 transition-colors hover:text-slate-900"><MoreHorizontal size={18} /></button></td>;
    }

    const value = customerVals[columnKey];
    const displayValue = value === "true" ? "Sí" : value === "false" ? "No" : value || "—";
    return <td key={columnKey} className={`${cellClassName} text-slate-600 text-xs`} style={cellStyle}>{loadingCustom ? <span className="text-slate-300">...</span> : displayValue}</td>;
  };

  // Renderizador de cabecera con menú y soporte de key obligatoria
  const renderHeaderWithMenu = (title: string, columnKey: string) => {
    const isSorted = sortColumn === columnKey;
    const isFrozen = frozenColumns.includes(columnKey);

    return (
      <th
        key={columnKey}
        ref={(element) => { headerCellRefs.current[columnKey] = element; }}
        style={getFrozenStyle(columnKey, true)}
        className={`sticky top-0 z-40 bg-white px-6 py-4 group/th relative ${getFrozenDividerClass(columnKey)}`}
      >
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-1.5 cursor-pointer select-none" onClick={() => handleSort(columnKey, sortDirection === "asc" ? "desc" : "asc")}>
            <span>{title}</span>
            {isSorted && (
              <span className="text-[var(--app-brand-deep)] font-bold">
                {sortDirection === "asc" ? "↑" : "↓"}
              </span>
            )}
          </div>
          <button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              setOpenHeaderMenu(openHeaderMenu === columnKey ? null : columnKey);
            }}
            className={`transition-opacity rounded p-1 hover:bg-slate-100 text-slate-400 hover:text-slate-700 ${
              isSorted ? "opacity-150" : "opacity-0 group-hover/th:opacity-100"
            }`}
          >
            <MoreVertical size={14} />
          </button>
        </div>

        {/* Menú flotante */}
        {openHeaderMenu === columnKey && (
          <div ref={menuRef} className="absolute left-6 top-12 z-50 w-52 rounded-xl bg-white p-1.5 shadow-xl border border-slate-200 text-slate-700 text-xs font-normal normal-case">
            <button onClick={() => handleSort(columnKey, "asc")} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              <ArrowUp size={14} className="text-slate-400" /> Orden ascendente
            </button>
            <button onClick={() => handleSort(columnKey, "desc")} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              <ArrowDown size={14} className="text-slate-400" /> Orden descendente
            </button>
            <div className="my-1 border-t border-slate-100" />
            <button onClick={() => toggleFrozenColumn(columnKey)} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              {isFrozen ? <PinOff size={14} className="text-slate-400" /> : <Pin size={14} className="text-slate-400" />}
              {isFrozen ? "Desinmovilizar columna" : "Inmovilizar columna"}
            </button>
            <button onClick={() => setOpenHeaderMenu(null)} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              <Filter size={14} className="text-slate-400" /> Filtrar por esta columna
            </button>
          </div>
        )}
      </th>
    );
  };

  return (
    <div className="relative">
      <table className="w-full border-separate border-spacing-0 text-left text-sm">
        <thead className="sticky top-0 z-50 bg-white">
          <tr className="border-y border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500">
            <th
              ref={selectionCellRef}
              style={{ left: 0, position: "sticky", zIndex: 70 }}
              className={`sticky top-0 w-14 bg-white px-6 py-4 ${
                frozenColumns.length === 0 ? "border-r-2 border-slate-300" : ""
              }`}
            >
              <input
                type="checkbox"
                checked={isAllSelected}
                onChange={handleSelectAll}
                className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-colors focus:ring-[var(--app-brand)]"
              />
            </th>
            {orderedColumns.map((column) => renderHeaderWithMenu(column.title, column.key))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {sortedCustomers.map((customer) => {
            const isSelected = selectedIds.includes(customer.id);

            return (
              <motion.tr
                key={customer.id}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                className={`group transition-colors hover:bg-slate-50 ${
                  isSelected ? "bg-slate-50/80" : ""
                }`}
              >
                <td className={`sticky left-0 z-20 px-6 py-4 group-hover:bg-slate-50 ${
                  isSelected ? "bg-slate-50" : "bg-white"
                } ${frozenColumns.length === 0 ? "border-r-2 border-slate-300" : ""}`}>
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => onToggleSelection(customer.id)}
                    className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-opacity duration-200 focus:ring-[var(--app-brand)] ${
                      isSelected ? "opacity-100" : "opacity-0 focus:opacity-100 group-hover:opacity-100"
                    }`}
                  />
                </td>
                {orderedColumns.map((column) => renderCustomerCell(customer, column.key, isSelected))}
              </motion.tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

const FROZEN_COLUMNS_STORAGE_KEY = "customers-table-frozen-columns";
const STATIC_COLUMNS = [
  { key: "client", title: "Cliente" },
  { key: "document", title: "Documento" },
  { key: "payment_terms", title: "Días de Crédito" },
  { key: "credit_limit", title: "Límite" },
  { key: "status", title: "Estado" },
  { key: "actions", title: "Acciones" },
] as const;
type ColumnKey = (typeof STATIC_COLUMNS)[number]["key"] | string;