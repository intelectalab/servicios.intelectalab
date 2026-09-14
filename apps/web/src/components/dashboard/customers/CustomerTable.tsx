"use client";

import { useEffect, useState, useRef, useMemo } from "react";
import { motion } from "framer-motion";
import { Building2, User, MoreHorizontal, MoreVertical, ArrowUp, ArrowDown, Filter } from "lucide-react";
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
      let valA: any = "";
      let valB: any = "";

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

  // Renderizador de cabecera con menú y soporte de key obligatoria
  const renderHeaderWithMenu = (title: string, columnKey: string) => {
    const isSorted = sortColumn === columnKey;

    return (
      <th key={columnKey} className="sticky top-0 z-20 bg-white px-6 py-4 group/th relative">
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
            <button onClick={() => setOpenHeaderMenu(null)} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              <Filter size={14} className="text-slate-400" /> Filtrar por esta columna
            </button>
          </div>
        )}
      </th>
    );
  };

  return (
    <div className="overflow-x-auto relative">
      <table className="w-full border-separate border-spacing-0 text-left text-sm">
        <thead className="sticky top-0 z-30 bg-white">
          <tr className="border-y border-slate-200 text-xs font-semibold uppercase tracking-wider text-slate-500">
            <th className="sticky top-0 z-20 w-14 bg-white px-6 py-4">
              <input
                type="checkbox"
                checked={isAllSelected}
                onChange={handleSelectAll}
                className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-colors focus:ring-[var(--app-brand)]"
              />
            </th>
            {renderHeaderWithMenu("Cliente", "client")}
            {renderHeaderWithMenu("Documento", "document")}
            
            {definitions.map((def) => renderHeaderWithMenu(def.label, def.id))}

            {renderHeaderWithMenu("Días de Crédito", "payment_terms")}
            {renderHeaderWithMenu("Límite", "credit_limit")}
            {renderHeaderWithMenu("Estado", "status")}
            <th className="sticky top-0 z-20 bg-white px-6 py-4 text-right">Acciones</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 bg-white">
          {sortedCustomers.map((customer) => {
            const isOrg = customer.party.party_type === "ORGANIZATION";
            const isSelected = selectedIds.includes(customer.id);
            const customerVals = customerValuesMap[customer.id] || {};

            return (
              <motion.tr
                key={customer.id}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                className={`group transition-colors hover:bg-slate-50 ${
                  isSelected ? "bg-slate-50/80" : ""
                }`}
              >
                <td className="px-6 py-4">
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => onToggleSelection(customer.id)}
                    className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-opacity duration-200 focus:ring-[var(--app-brand)] ${
                      isSelected ? "opacity-100" : "opacity-0 focus:opacity-100 group-hover:opacity-100"
                    }`}
                  />
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-3 min-w-[200px]">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                      {isOrg ? <Building2 size={18} /> : <User size={18} />}
                    </div>
                    <div>
                      <button
                        type="button"
                        onClick={() => onSelectCustomer(customer.id)}
                        className="font-medium text-slate-900 hover:text-[var(--app-brand-deep)] hover:underline text-left"
                      >
                        {getCustomerDisplayName(customer)}
                      </button>
                      <p className="text-xs text-slate-500">
                        {isOrg ? "Empresa" : "Persona Natural"}
                      </p>
                    </div>
                  </div>
                </td>
                <td className="px-6 py-4 text-slate-600">{getCustomerDocument(customer)}</td>
                
                {definitions.map((def) => {
                  const val = customerVals[def.id];
                  const displayVal = val === "true" ? "Sí" : val === "false" ? "No" : val || "—";
                  return (
                    <td key={def.id} className="px-6 py-4 text-slate-600 text-xs">
                      {loadingCustom ? <span className="text-slate-300">...</span> : displayVal}
                    </td>
                  );
                })}

                <td className="px-6 py-4 text-slate-600">{customer.payment_terms_days} días</td>
                <td className="px-6 py-4 font-medium text-slate-700">
                  {formatCurrency(customer.credit_limit)}
                </td>
                <td className="px-6 py-4">
                  <StatusBadge status={customer.status} />
                </td>
                <td className="px-6 py-4 text-right">
                  <button 
                    type="button" 
                    onClick={() => onSelectCustomer(customer.id)}
                    className="text-slate-400 transition-colors hover:text-slate-900"
                  >
                    <MoreHorizontal size={18} />
                  </button>
                </td>
              </motion.tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}