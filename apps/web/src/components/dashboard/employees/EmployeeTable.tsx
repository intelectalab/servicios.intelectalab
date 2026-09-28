"use client";

import { useEffect, useState, useRef, useMemo, useLayoutEffect } from "react";
import { motion } from "framer-motion";
import { User, MoreVertical, ArrowUp, ArrowDown, Filter, Pin, PinOff, Plus, Loader2, Trash2, Eye, EyeOff, GripVertical } from "lucide-react";
import { type Employee, getEmployeeDisplayName, getEmployeeDocument } from "@/types/employee";
import { type CustomFieldDefinition } from "@/types/custom-field";
import { StatusBadge } from "@/components/StatusBadge";
import { apiFetch } from "@/lib/api";

interface EmployeeTableProps {
  employees: Employee[];
  selectedIds: string[];
  customFieldDefinitions: CustomFieldDefinition[];
  onToggleSelection: (id: string) => void;
  onSelectAll: (ids: string[]) => void;
  onSelectEmployee: (id: string) => void;
  onAddCustomField: () => void;
  onRequestDeleteCustomField: (definition: CustomFieldDefinition) => void;
  onAddFilter?: (columnId: string, label: string, type: "text" | "number" | "select" | "date") => void;
}

export function EmployeeTable({
  employees,
  customFieldDefinitions,
  selectedIds,
  onToggleSelection,
  onSelectAll,
  onSelectEmployee,
  onAddCustomField,
  onRequestDeleteCustomField,
  onAddFilter,
}: EmployeeTableProps) {
  const [tableEmployees, setTableEmployees] = useState(employees);
  const [employeeValuesMap, setEmployeeValuesMap] = useState<Record<string, Record<string, string>>>({});
  const [loadingCustom, setLoadingCustom] = useState(true);

  // Estados para la Edición en Línea (Inline Editing)
  const [editingCell, setEditingCell] = useState<{ employeeId: string; fieldKey: string } | null>(null);
  const [tempCellValue, setTempCellValue] = useState("");
  const [tempDocumentType, setTempDocumentType] = useState("DNI");
  const [tempGivenNames, setTempGivenNames] = useState("");
  const [tempFamilyName, setTempFamilyName] = useState("");
  const [isSavingCell, setIsSavingCell] = useState(false);
  const [saveNotice, setSaveNotice] = useState<{ label: string; undo: () => void } | null>(null);
  const saveNoticeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Estados de ordenamiento
  const [sortColumn, setSortColumn] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<"asc" | "desc">("asc");
  const [columnOrder, setColumnOrder] = useState<string[]>(() => {
    if (typeof window === "undefined") return [];
    try {
      const stored = window.localStorage.getItem(COLUMN_ORDER_STORAGE_KEY);
      const parsed = stored ? JSON.parse(stored) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  });
  const [draggedColumn, setDraggedColumn] = useState<string | null>(null);
  const [dropIndicator, setDropIndicator] = useState<{ columnKey: string; side: "left" | "right" } | null>(null);

  // Estado para controlar qué menú de cabecera está abierto
  const [openHeaderMenu, setOpenHeaderMenu] = useState<string | null>(null);
  const [addColumnMenuOpen, setAddColumnMenuOpen] = useState(false);
  const [hiddenCustomColumns, setHiddenCustomColumns] = useState<string[]>(() => {
    if (typeof window === "undefined") return [];
    try {
      const stored = window.localStorage.getItem(HIDDEN_COLUMNS_STORAGE_KEY);
      const parsed = stored ? JSON.parse(stored) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch {
      return [];
    }
  });
  const menuRef = useRef<HTMLDivElement>(null);
  const addColumnMenuRef = useRef<HTMLDivElement>(null);
  const headerCellRefs = useRef<Record<string, HTMLTableCellElement | null>>({});
  const selectionCellRef = useRef<HTMLTableCellElement>(null);
  const preferencesLoaded = useRef(false);
  const [frozenColumns, setFrozenColumns] = useState<string[]>([]);
  const [frozenOffsets, setFrozenOffsets] = useState<Record<string, number>>({});

  const effectiveFrozenColumns = useMemo(
    () => ["client", ...frozenColumns.filter((columnKey) => columnKey !== "client")],
    [frozenColumns],
  );

  useEffect(() => {
    window.localStorage.setItem(HIDDEN_COLUMNS_STORAGE_KEY, JSON.stringify(hiddenCustomColumns));
  }, [hiddenCustomColumns]);

  useEffect(() => {
    window.localStorage.setItem(COLUMN_ORDER_STORAGE_KEY, JSON.stringify(columnOrder));
  }, [columnOrder]);

  useEffect(() => {
    const timer = window.setTimeout(() => setTableEmployees(employees), 0);
    return () => window.clearTimeout(timer);
  }, [employees]);

  useEffect(() => () => {
    if (saveNoticeTimer.current) clearTimeout(saveNoticeTimer.current);
  }, []);

  const showSaveNotice = (label: string, undo: () => void) => {
    if (saveNoticeTimer.current) clearTimeout(saveNoticeTimer.current);
    setSaveNotice({ label, undo });
    saveNoticeTimer.current = setTimeout(() => setSaveNotice(null), 7000);
  };

  useEffect(() => {
    if (editingCell && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();
    }
  }, [editingCell]);

  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      const target = event.target as Node;
      if (menuRef.current && !menuRef.current.contains(target)) {
        setOpenHeaderMenu(null);
      }
      if (addColumnMenuRef.current && !addColumnMenuRef.current.contains(target)) {
        setAddColumnMenuOpen(false);
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
      // Ignorar
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
      if (employees.length === 0) {
        setLoadingCustom(false);
        return;
      }

      setLoadingCustom(true);
      try {
        const valuesPromise = (async () => {
          const loadedValues: Record<string, Record<string, string>> = {};
          await Promise.all(
            employees.map(async (employee) => {
              const vals = await apiFetch<Array<{ field_id: string; value: string }>>(
                `/v1/custom-fields/values?entity_type=Employee&entity_id=${employee.id}`
              ).catch(() => []);

              loadedValues[employee.id] = {};
              vals.forEach((v) => {
                loadedValues[employee.id][v.field_id] = v.value;
              });
            })
          );
          return loadedValues;
        })();

        const valuesMap = await valuesPromise;

        if (cancelled) return;
        setEmployeeValuesMap(valuesMap);
      } catch (err) {
        console.error("No se pudieron cargar los campos personalizados para la tabla", err);
      } finally {
        if (!cancelled) setLoadingCustom(false);
      }
    }

    loadTableCustomData();
    return () => { cancelled = true; };
  }, [employees]);

  const handleSaveInlineCell = async (employeeId: string, fieldId: string) => {
    if (isSavingCell) return;
    const previousValue = employeeValuesMap[employeeId]?.[fieldId] || "";
    const nextValue = tempCellValue;
    setIsSavingCell(true);
    setEmployeeValuesMap(prev => ({
      ...prev,
      [employeeId]: { ...(prev[employeeId] || {}), [fieldId]: nextValue },
    }));
    try {
      await apiFetch("/v1/custom-fields/values", {
        method: "POST",
        body: JSON.stringify({
          field_id: fieldId,
          entity_type: "Employee",
          entity_id: employeeId,
          value: nextValue,
        }),
      });

      setEditingCell(null);
      const definition = customFieldDefinitions.find((item) => item.id === fieldId);
      showSaveNotice(`"${definition?.label || "Campo"}" cambios guardados`, () => {
        setEmployeeValuesMap(prev => ({
          ...prev,
          [employeeId]: { ...(prev[employeeId] || {}), [fieldId]: previousValue },
        }));
        void apiFetch("/v1/custom-fields/values", {
          method: "POST",
          body: JSON.stringify({
            field_id: fieldId,
            entity_type: "Employee",
            entity_id: employeeId,
            value: previousValue,
          }),
        }).catch(() => setEmployeeValuesMap(prev => ({
          ...prev,
          [employeeId]: { ...(prev[employeeId] || {}), [fieldId]: nextValue },
        })));
      });
    } catch (err) {
      setEmployeeValuesMap(prev => ({
        ...prev,
        [employeeId]: { ...(prev[employeeId] || {}), [fieldId]: previousValue },
      }));
      console.error("No se pudo actualizar el campo en línea", err);
    } finally {
      setIsSavingCell(false);
    }
  };

  const getEmployeeUpdatePayload = (employee: Employee) => {
    const person = employee.party?.person;
    return {
      employee_code: employee.employee_code,
      position: employee.position,
      status: employee.status,
      document_type: person?.document_type || "DNI",
      document_number: person?.document_number || "",
      given_names: person?.given_names || "",
      family_name: person?.family_name || "",
    };
  };

  const applyInlineEmployeeChange = (employee: Employee, fieldKey: string): Employee => {
    const nextEmployee: Employee = {
      ...employee,
      employee_code: fieldKey === "employee_code" ? tempCellValue : employee.employee_code,
      position: fieldKey === "position" ? tempCellValue : employee.position,
      status: fieldKey === "status" ? (tempCellValue as "ACTIVE" | "INACTIVE") : employee.status,
      party: {
        ...employee.party,
        person: employee.party?.person ? { ...employee.party.person } : null,
      },
    };

    if (nextEmployee.party?.person) {
      nextEmployee.party.person = {
        ...nextEmployee.party.person,
        given_names: fieldKey === "client" ? tempGivenNames : nextEmployee.party.person.given_names,
        family_name: fieldKey === "client" ? tempFamilyName : nextEmployee.party.person.family_name,
        document_type: fieldKey === "document" ? tempDocumentType : nextEmployee.party.person.document_type,
        document_number: fieldKey === "document" ? tempCellValue : nextEmployee.party.person.document_number,
      };
    }
    return nextEmployee;
  };

  const handleSaveEmployeeCell = async (employee: Employee, fieldKey: string) => {
    if (isSavingCell) return;
    setIsSavingCell(true);
    const person = employee.party?.person;
    const payload = {
      employee_code: fieldKey === "employee_code" ? tempCellValue : employee.employee_code,
      position: fieldKey === "position" ? tempCellValue : employee.position,
      status: fieldKey === "status" ? tempCellValue : employee.status,
      given_names: fieldKey === "client" ? tempGivenNames : person?.given_names || "",
      family_name: fieldKey === "client" ? tempFamilyName : person?.family_name || "",
      document_type: fieldKey === "document" ? tempDocumentType : person?.document_type || "DNI",
      document_number: fieldKey === "document" ? tempCellValue : person?.document_number || "",
    };

    const previousEmployee = tableEmployees.find((item) => item.id === employee.id);
    const updatedEmployee = previousEmployee ? applyInlineEmployeeChange(previousEmployee, fieldKey) : null;

    if (updatedEmployee) {
      setTableEmployees((current) => current.map((item) => item.id === employee.id ? updatedEmployee : item));
    }

    try {
      await apiFetch(`/v1/employees/${employee.id}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });
      setEditingCell(null);
      if (previousEmployee && updatedEmployee) {
        showSaveNotice(`"${getColumnLabel(fieldKey)}" cambios guardados`, () => {
          setTableEmployees((current) => current.map((item) => item.id === employee.id ? previousEmployee : item));
          void apiFetch(`/v1/employees/${employee.id}`, {
            method: "PUT",
            body: JSON.stringify(getEmployeeUpdatePayload(previousEmployee)),
          }).catch(() => setTableEmployees((current) => current.map((item) => item.id === employee.id ? updatedEmployee : item)));
        });
      }
    } catch (err) {
      if (previousEmployee) {
        setTableEmployees((current) => current.map((item) => item.id === employee.id ? previousEmployee : item));
      }
      console.error("No se pudo actualizar el empleado en línea", err);
    } finally {
      setIsSavingCell(false);
    }
  };

  const startEditingCell = (employee: Employee, fieldKey: string, value: string) => {
    setEditingCell({ employeeId: employee.id, fieldKey });
    setTempCellValue(value);
    if (fieldKey === "document") {
      setTempDocumentType(employee.party?.person?.document_type || "DNI");
    }
    if (fieldKey === "client" && employee.party?.person) {
      setTempGivenNames(employee.party.person.given_names);
      setTempFamilyName(employee.party.person.family_name);
    }
  };

  const handleEditorBlur = (
    event: React.FocusEvent<HTMLDivElement>,
    employee: Employee,
    fieldKey: string,
  ) => {
    const nextFocusedElement = event.relatedTarget as Node | null;
    if (nextFocusedElement && event.currentTarget.contains(nextFocusedElement)) return;
    window.setTimeout(() => {
      if (editingCell?.employeeId === employee.id && editingCell.fieldKey === fieldKey) {
        void handleSaveEmployeeCell(employee, fieldKey);
      }
    }, 0);
  };

  const getInlineInputProps = (employee: Employee, fieldKey: string) => ({
    value: tempCellValue,
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setTempCellValue(event.target.value),
    onKeyDown: (event: React.KeyboardEvent<HTMLInputElement | HTMLSelectElement>) => {
      if (event.key === "Enter") void handleSaveEmployeeCell(employee, fieldKey);
      if (event.key === "Escape") setEditingCell(null);
    },
  });

  const sortedEmployees = useMemo(() => {
    if (!sortColumn) return tableEmployees;

    return [...tableEmployees].sort((a, b) => {
      let valA: string | number = "";
      let valB: string | number = "";

      if (sortColumn === "client") {
        valA = getEmployeeDisplayName(a).toLowerCase();
        valB = getEmployeeDisplayName(b).toLowerCase();
      } else if (sortColumn === "document") {
        valA = getEmployeeDocument(a);
        valB = getEmployeeDocument(b);
      } else if (sortColumn === "employee_code") {
        valA = a.employee_code || "";
        valB = b.employee_code || "";
      } else if (sortColumn === "position") {
        valA = a.position || "";
        valB = b.position || "";
      } else if (sortColumn === "status") {
        valA = a.status;
        valB = b.status;
      } else {
        valA = String(employeeValuesMap[a.id]?.[sortColumn] ?? "");
        valB = String(employeeValuesMap[b.id]?.[sortColumn] ?? "");
      }

      if (valA < valB) return sortDirection === "asc" ? -1 : 1;
      if (valA > valB) return sortDirection === "asc" ? 1 : -1;
      return 0;
    });
  }, [tableEmployees, sortColumn, sortDirection, employeeValuesMap]);

  const handleSort = (columnKey: string, direction: "asc" | "desc") => {
    setSortColumn(columnKey);
    setSortDirection(direction);
    setOpenHeaderMenu(null);
  };

  const handleSelectAll = () => {
    if (selectedIds.length === sortedEmployees.length && sortedEmployees.length > 0) {
      onSelectAll([]); 
    } else {
      onSelectAll(sortedEmployees.map((e) => e.id)); 
    }
  };

  const isAllSelected = sortedEmployees.length > 0 && selectedIds.length === sortedEmployees.length;

  const columns = useMemo(() => [
    ...STATIC_COLUMNS.slice(0, 2),
    ...customFieldDefinitions.filter((def) => !hiddenCustomColumns.includes(def.id)).map((def) => ({ key: def.id, title: def.label, isCustom: true })),
    ...STATIC_COLUMNS.slice(2),
  ], [customFieldDefinitions, hiddenCustomColumns]);

  const orderedColumnKeys = useMemo(() => {
    const availableKeys = new Set(columns.map((column) => column.key));
    const savedOrder = columnOrder.filter((key) => availableKeys.has(key) && key !== "client");
    const missingKeys = columns
      .map((column) => column.key)
      .filter((key) => key !== "client" && !savedOrder.includes(key));
    return ["client", ...savedOrder, ...missingKeys];
  }, [columns, columnOrder]);

  const orderedColumns = useMemo(() => {
    const availableKeys = new Set(columns.map((column) => column.key));
    const baseOrder = orderedColumnKeys
      .filter((key) => availableKeys.has(key))
      .map((key) => columns.find((column) => column.key === key)!);
    const pinned = effectiveFrozenColumns.filter((key) => availableKeys.has(key) && key !== "client");
    return [
      baseOrder.find((column) => column.key === "client")!,
      ...pinned.map((key) => baseOrder.find((column) => column.key === key)! ),
      ...baseOrder.filter((column) => column.key !== "client" && !pinned.includes(column.key)),
    ];
  }, [columns, effectiveFrozenColumns, orderedColumnKeys]);

  const lastFrozenColumnKey = useMemo(
    () => effectiveFrozenColumns.findLast((key) => orderedColumns.some((column) => column.key === key)),
    [effectiveFrozenColumns, orderedColumns]
  );

  const getFrozenDividerClass = (columnKey: string) =>
    columnKey === lastFrozenColumnKey ? "border-r-2 border-slate-300" : "";

  useLayoutEffect(() => {
    const updateOffsets = () => {
      const offsets: Record<string, number> = {};
      let offset = selectionCellRef.current?.getBoundingClientRect().width ?? 0;

      for (const column of orderedColumns) {
        if (!effectiveFrozenColumns.includes(column.key)) continue;
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
  }, [orderedColumns, effectiveFrozenColumns]);

  const toggleFrozenColumn = (columnKey: ColumnKey) => {
    if (columnKey === "client") return;
    setFrozenColumns((current) => current.includes(columnKey)
      ? current.filter((key) => key !== columnKey)
      : [...current, columnKey]);
    setOpenHeaderMenu(null);
  };

  const handleColumnDrop = (targetColumnKey: string) => {
    if (!draggedColumn || draggedColumn === targetColumnKey || draggedColumn === "client" || targetColumnKey === "client") {
      setDraggedColumn(null);
      setDropIndicator(null);
      return;
    }

    setColumnOrder(() => {
      const currentOrder = orderedColumnKeys.filter((key) => key !== "client");
      const nextOrder = currentOrder.filter((key) => key !== draggedColumn);
      const targetIndex = nextOrder.indexOf(targetColumnKey);
      const insertionIndex = targetIndex < 0
        ? nextOrder.length
        : targetIndex + (dropIndicator?.side === "right" ? 1 : 0);
      nextOrder.splice(insertionIndex, 0, draggedColumn);
      return ["client", ...nextOrder];
    });
    setDraggedColumn(null);
    setDropIndicator(null);
  };

  const handleColumnDragOver = (event: React.DragEvent<HTMLTableCellElement>, columnKey: string) => {
    if (!draggedColumn || draggedColumn === columnKey || columnKey === "client") return;
    event.preventDefault();

    const cellRect = event.currentTarget.getBoundingClientRect();
    const side = event.clientX < cellRect.left + cellRect.width / 2 ? "left" : "right";
    setDropIndicator({ columnKey, side });

    const scrollContainer = event.currentTarget.closest(".overflow-auto") as HTMLElement | null;
    if (!scrollContainer) return;
    const edgeSize = 72;
    const pointerOffset = event.clientX - scrollContainer.getBoundingClientRect().left;
    const distanceToRight = scrollContainer.getBoundingClientRect().right - event.clientX;
    if (pointerOffset < edgeSize) {
      scrollContainer.scrollLeft -= Math.max(8, (edgeSize - pointerOffset) / 2);
    } else if (distanceToRight < edgeSize) {
      scrollContainer.scrollLeft += Math.max(8, (edgeSize - distanceToRight) / 2);
    }
  };

  const toggleCustomColumnVisibility = (definitionId: string) => {
    setHiddenCustomColumns((current) => current.includes(definitionId)
      ? current.filter((id) => id !== definitionId)
      : [...current, definitionId]);
  };

  const getFrozenStyle = (columnKey: ColumnKey, isHeader = false) => effectiveFrozenColumns.includes(columnKey)
    ? { left: frozenOffsets[columnKey] ?? 0, position: "sticky" as const, zIndex: isHeader ? 70 : 20 }
    : undefined;

  const renderEmployeeCell = (employee: Employee, columnKey: string, isSelected: boolean) => {
    const employeeVals = employeeValuesMap[employee.id] || {};
    const cellClassName = isSelected
      ? `px-6 py-4 bg-sky-50 text-slate-900 group-hover:bg-sky-50 ${getFrozenDividerClass(columnKey)}`
      : `px-6 py-4 bg-white text-slate-700 group-hover:bg-slate-50 ${getFrozenDividerClass(columnKey)}`;
    const cellStyle = getFrozenStyle(columnKey);
    const isEditing = editingCell?.employeeId === employee.id && editingCell?.fieldKey === columnKey;
    const beginEdit = (value: string) => {
      if (!isEditing) startEditingCell(employee, columnKey, value);
    };
    const editableClassName = `${cellClassName} cursor-pointer hover:bg-sky-50 transition-colors`;

    if (columnKey === "client") {
      return (
        <td key={columnKey} className={editableClassName} style={cellStyle} onClick={() => beginEdit(getEmployeeDisplayName(employee))}>
          {isEditing ? (
            <div className="min-w-52 space-y-1" onClick={(event) => event.stopPropagation()} onBlur={(event) => handleEditorBlur(event, employee, columnKey)}>
              <input
                ref={inputRef}
                type="text"
                value={tempGivenNames}
                onChange={(event) => setTempGivenNames(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === "Enter") void handleSaveEmployeeCell(employee, columnKey);
                  if (event.key === "Escape") setEditingCell(null);
                }}
                placeholder="Nombres"
                className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-xs focus:outline-none"
              />
              <input
                type="text"
                value={tempFamilyName}
                onChange={(event) => setTempFamilyName(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === "Enter") void handleSaveEmployeeCell(employee, columnKey);
                  if (event.key === "Escape") setEditingCell(null);
                }}
                placeholder="Apellidos"
                className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-xs focus:outline-none"
              />
            </div>
          ) : (
          <div className="flex items-center gap-3 min-w-[200px]">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sky-50 text-[var(--app-brand-deep)] font-semibold">
              <User size={18} />
            </div>
            <div>
              <button type="button" onClick={(event) => { event.stopPropagation(); setEditingCell(null); onSelectEmployee(employee.id); }} className="font-semibold text-slate-900 hover:text-[var(--app-brand-deep)] hover:underline text-left">
                {getEmployeeDisplayName(employee)}
              </button>
              <p className="text-xs text-slate-500">Empleado</p>
            </div>
          </div>
          )}
        </td>
      );
    }

    if (columnKey === "employee_code") {
      return (
        <td key={columnKey} className={`${editableClassName} font-mono text-slate-600 font-medium`} style={cellStyle} onClick={() => beginEdit(employee.employee_code || "")}>
          {isEditing ? (
            <div onBlur={(event) => handleEditorBlur(event, employee, columnKey)}>
              <input ref={inputRef} type="text" {...getInlineInputProps(employee, columnKey)} className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none" />
            </div>
          ) : employee.employee_code || "—"}
        </td>
      );
    }

    if (columnKey === "position") {
      return (
        <td key={columnKey} className={`${editableClassName} text-slate-600`} style={cellStyle} onClick={() => beginEdit(employee.position || "")}>
          {isEditing ? (
            <div onBlur={(event) => handleEditorBlur(event, employee, columnKey)}>
              <input ref={inputRef} type="text" {...getInlineInputProps(employee, columnKey)} className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none" />
            </div>
          ) : employee.position || "—"}
        </td>
      );
    }

    if (columnKey === "document") {
      const documentNumber = employee.party?.person?.document_number || "";
      return (
        <td key={columnKey} className={`${editableClassName} text-slate-600 font-medium`} style={cellStyle} onClick={() => beginEdit(documentNumber)}>
          {isEditing ? (
            <div className="flex min-w-44 items-center gap-1.5" onClick={(event) => event.stopPropagation()} onBlur={(event) => handleEditorBlur(event, employee, columnKey)}>
              <select
                value={tempDocumentType}
                onChange={(event) => setTempDocumentType(event.target.value)}
                className="rounded-md border border-[var(--app-brand-deep)] bg-white px-1.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none"
              >
                <option value="DNI">DNI</option>
                <option value="CE">CE</option>
                <option value="PASSPORT">Pasaporte</option>
                <option value="RUC">RUC</option>
              </select>
              <input
                ref={inputRef}
                type="text"
                {...getInlineInputProps(employee, columnKey)}
                className="w-full min-w-24 rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none"
              />
            </div>
          ) : getEmployeeDocument(employee)}
        </td>
      );
    }

    if (columnKey === "status") {
      return (
        <td key={columnKey} className={editableClassName} style={cellStyle} onClick={() => beginEdit(employee.status)}>
          {isEditing ? (
            <div onBlur={(event) => handleEditorBlur(event, employee, columnKey)}><select {...getInlineInputProps(employee, columnKey)} className="rounded-md border border-[var(--app-brand-deep)] bg-white px-2 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none">
              <option value="ACTIVE">Activo</option>
              <option value="INACTIVE">Inactivo</option>
            </select></div>
          ) : <StatusBadge status={employee.status} />}
        </td>
      );
    }

    const isCustomField = customFieldDefinitions.some(d => d.id === columnKey);

    if (isCustomField) {
      const definition = customFieldDefinitions.find(d => d.id === columnKey);
      const isEditing = editingCell?.employeeId === employee.id && editingCell?.fieldKey === columnKey;
      const value = employeeVals[columnKey] || "";

      return (
        <td 
          key={columnKey} 
          className={`${cellClassName} text-slate-600 text-xs cursor-pointer hover:bg-sky-50 transition-colors`}
          style={cellStyle}
          onClick={() => {
            if (!isEditing) {
              setEditingCell({ employeeId: employee.id, fieldKey: columnKey });
              setTempCellValue(value);
            }
          }}
        >
          {loadingCustom ? (
            <Loader2 size={14} className="animate-spin text-slate-300" aria-label="Cargando" />
          ) : isEditing ? (
            <div className="flex items-center gap-1.5" onClick={(e) => e.stopPropagation()}>
              {definition?.field_type === "boolean" ? (
                <input
                  type="checkbox"
                  checked={tempCellValue === "true"}
                  onChange={(event) => setTempCellValue(String(event.target.checked))}
                  onBlur={() => void handleSaveInlineCell(employee.id, columnKey)}
                  className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] focus:ring-[var(--app-brand)]"
                />
              ) : (
              <input
                ref={inputRef}
                type={definition?.field_type || "text"}
                value={tempCellValue}
                onChange={(e) => setTempCellValue(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") handleSaveInlineCell(employee.id, columnKey);
                  if (e.key === "Escape") setEditingCell(null);
                }}
                onBlur={() => handleSaveInlineCell(employee.id, columnKey)}
                className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none"
              />
              )}
              {isSavingCell && <Loader2 size={12} className="animate-spin text-[var(--app-brand-deep)] shrink-0" />}
            </div>
          ) : (
            <span className="block min-h-[20px] py-1 font-medium">{value === "true" ? "Sí" : value === "false" ? "No" : value || <span className="text-slate-300">—</span>}</span>
          )}
        </td>
      );
    }

    const value = employeeVals[columnKey];
    const displayValue = value === "true" ? "Sí" : value === "false" ? "No" : value || "—";
    return <td key={columnKey} className={`${cellClassName} text-slate-600 text-xs`} style={cellStyle}>{loadingCustom ? <Loader2 size={14} className="animate-spin text-slate-300" aria-label="Cargando" /> : displayValue}</td>;
  };

  const renderHeaderWithMenu = (title: string, columnKey: string) => {
    const isSorted = sortColumn === columnKey;
    const isFrozen = effectiveFrozenColumns.includes(columnKey);

    return (
      <th
        key={columnKey}
        ref={(element) => { headerCellRefs.current[columnKey] = element; }}
        data-column-key={columnKey}
        onDragOver={(event) => handleColumnDragOver(event, columnKey)}
        onDrop={(event) => {
          event.preventDefault();
          handleColumnDrop(columnKey);
        }}
        style={getFrozenStyle(columnKey, true)}
        className={`sticky top-0 border-b border-slate-200 bg-slate-50 px-6 py-3.5 group/th relative text-slate-700 font-semibold ${getFrozenDividerClass(columnKey)} ${openHeaderMenu === columnKey ? "z-[100]" : "z-40"} ${draggedColumn === columnKey ? "opacity-50" : ""} ${columnKey !== "client" ? "cursor-grab active:cursor-grabbing" : ""}`}
      >
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2 cursor-pointer select-none" onClick={() => handleSort(columnKey, sortDirection === "asc" ? "desc" : "asc")}>
            {columnKey !== "client" && (
              <span
                draggable
                onDragStart={(event) => {
                  event.stopPropagation();
                  setDraggedColumn(columnKey);
                }}
                onDragEnd={() => { setDraggedColumn(null); setDropIndicator(null); }}
                className="flex shrink-0 cursor-grab touch-none active:cursor-grabbing"
                title="Mover columna"
              >
                <GripVertical size={13} className="text-slate-400 group-hover/th:text-slate-600 transition-colors" aria-hidden="true" />
              </span>
            )}
            <span className="text-xs uppercase tracking-wider">{title}</span>
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
            className={`transition-opacity rounded p-1 hover:bg-slate-200/60 text-slate-400 hover:text-slate-700 ${
              isSorted ? "opacity-150" : "opacity-0 group-hover/th:opacity-100"
            }`}
          >
            <MoreVertical size={14} />
          </button>
        </div>
        {dropIndicator?.columnKey === columnKey && (
          <span
            aria-hidden="true"
            className={`pointer-events-none absolute top-0 z-[210] h-full w-1 rounded-full bg-[var(--app-brand)] shadow-[0_0_0_2px_rgba(12,116,166,0.15)] ${dropIndicator.side === "left" ? "left-0" : "right-0"}`}
          />
        )}

        {/* Menú flotante */}
        {openHeaderMenu === columnKey && (
          <div ref={menuRef} className="absolute left-6 top-12 z-[200] w-56 rounded-xl bg-white p-1.5 shadow-xl border border-slate-200 text-slate-700 text-xs font-normal normal-case">
            <button onClick={() => handleSort(columnKey, "asc")} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              <ArrowUp size={14} className="text-slate-400" /> Orden ascendente
            </button>
            <button onClick={() => handleSort(columnKey, "desc")} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
              <ArrowDown size={14} className="text-slate-400" /> Orden descendente
            </button>
            <div className="my-1 border-t border-slate-100" />
            <button onClick={() => toggleFrozenColumn(columnKey)} disabled={columnKey === "client"} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors disabled:cursor-default disabled:opacity-70">
              {isFrozen ? <PinOff size={14} className="text-slate-400" /> : <Pin size={14} className="text-slate-400" />}
              {columnKey === "client" ? "Siempre fija" : isFrozen ? "Desinmovilizar columna" : "Inmovilizar columna"}
            </button>
            <button 
              onClick={() => { 
                if (onAddFilter) {
                  const customDef = customFieldDefinitions.find(d => d.id === columnKey);
                  const isNumeric = customDef?.field_type === "number";
                  const isDate = customDef?.field_type === "date";
                  const isBoolean = customDef?.field_type === "boolean";
                  
                  const type = isNumeric ? "number" : isDate ? "date" : isBoolean ? "select" : "text";
                  const label = customDef?.label || getColumnLabel(columnKey);
                  
                  onAddFilter(columnKey, label, type);
                }
                setOpenHeaderMenu(null); 
              }} 
              className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors"
            >
              <Filter size={14} className="text-slate-400" /> Filtrar por esta columna
            </button>
            {customFieldDefinitions.some((definition) => definition.id === columnKey) && (
              <>
                <div className="my-1 border-t border-slate-100" />
                <button onClick={() => { toggleCustomColumnVisibility(columnKey); setOpenHeaderMenu(null); }} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50 transition-colors">
                  <EyeOff size={14} className="text-slate-400" /> Ocultar columna
                </button>
                <button onClick={() => { onRequestDeleteCustomField(customFieldDefinitions.find((definition) => definition.id === columnKey)!); setOpenHeaderMenu(null); }} className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-red-600 hover:bg-red-50 transition-colors">
                  <Trash2 size={14} /> Eliminar columna
                </button>
              </>
            )}
          </div>
        )}
      </th>
    );
  };

  return (
    <div className="relative w-full">
      <table className="w-full border-separate border-spacing-0 text-left text-sm">
        <thead className="sticky top-0 z-50 bg-slate-50">
          <tr className="text-xs font-semibold uppercase tracking-wider text-slate-500">
            <th
              ref={selectionCellRef}
              style={{ left: 0, position: "sticky", zIndex: 70 }}
              className={`sticky left-0 top-0 z-[70] w-14 border-b border-slate-200 bg-slate-50 px-6 py-3.5 ${
                frozenColumns.length === 0 ? "border-r border-slate-300" : ""
              }`}
            >
              <input
                type="checkbox"
                checked={isAllSelected}
                onChange={handleSelectAll}
                className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] focus:ring-[var(--app-brand)]"
              />
            </th>
            {orderedColumns.map((column) => renderHeaderWithMenu(column.title, column.key))}
            
            {/* Columna final fija con botón + */}
            <th
              style={{ right: 0, position: "sticky", zIndex: 70 }}
              className="sticky right-0 top-0 z-[70] w-12 border-b border-l border-slate-300 bg-slate-50 px-4 py-3.5 text-center"
              onDragOver={(event) => {
                const lastColumn = orderedColumns[orderedColumns.length - 1];
                if (lastColumn) handleColumnDragOver(event, lastColumn.key);
              }}
              onDrop={(event) => {
                event.preventDefault();
                const lastColumn = orderedColumns[orderedColumns.length - 1];
                if (lastColumn) handleColumnDrop(lastColumn.key);
              }}
            >
              <div ref={addColumnMenuRef} className="relative">
                <button
                  type="button"
                  onClick={() => setAddColumnMenuOpen((value) => !value)}
                  className="flex items-center justify-center w-7 h-7 rounded-full bg-white text-slate-600 hover:bg-[var(--app-brand-deep)] hover:text-white transition-colors mx-auto shadow-xs border border-slate-200"
                  title="Gestionar columnas"
                >
                  <Plus size={16} />
                </button>
                {addColumnMenuOpen && (
                  <div className="absolute right-0 top-9 z-[200] w-60 rounded-xl border border-slate-200 bg-white p-1.5 text-left text-xs font-normal normal-case shadow-xl">
                    <button
                      type="button"
                      onClick={() => { setAddColumnMenuOpen(false); onAddCustomField(); }}
                      className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 transition-colors hover:bg-slate-50"
                    >
                      <Plus size={14} className="text-slate-400" /> Agregar columna nueva
                    </button>
                    <div className="my-1 border-t border-slate-100" />
                    <p className="px-3 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">Columnas ocultas</p>
                    {customFieldDefinitions.filter((definition) => hiddenCustomColumns.includes(definition.id)).length === 0 ? (
                      <p className="px-3 py-2 text-slate-400">No hay columnas ocultas</p>
                    ) : (
                      customFieldDefinitions
                        .filter((definition) => hiddenCustomColumns.includes(definition.id))
                        .map((definition) => (
                          <button
                            key={definition.id}
                            type="button"
                            onClick={() => toggleCustomColumnVisibility(definition.id)}
                            className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-slate-700 transition-colors hover:bg-slate-50"
                          >
                            <Eye size={14} className="text-slate-400" /> {definition.label}
                          </button>
                        ))
                    )}
                  </div>
                )}
              </div>
            </th>
          </tr>
        </thead>
        <tbody className="bg-white">
          {sortedEmployees.map((employee) => {
            const isSelected = selectedIds.includes(employee.id);

            return (
              <motion.tr
                key={employee.id}
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                className={`group border-b border-slate-100 transition-colors last:border-b-0 ${
                  isSelected ? "bg-sky-50" : "hover:bg-slate-50"
                }`}
              >
                <td className={`sticky left-0 z-20 px-6 py-4 group-hover:bg-slate-50 ${
                  isSelected ? "bg-sky-50" : "bg-white"
                } ${frozenColumns.length === 0 ? "border-r border-slate-300" : ""}`}>
                  <input
                    type="checkbox"
                    checked={isSelected}
                    onChange={() => onToggleSelection(employee.id)}
                    className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-opacity duration-200 focus:ring-[var(--app-brand)] ${
                      isSelected ? "opacity-100" : "opacity-0 focus:opacity-100 group-hover:opacity-100"
                    }`}
                  />
                </td>
                {orderedColumns.map((column) => renderEmployeeCell(employee, column.key, isSelected))}
                
                {/* Celda fija final correspondiente al botón + */}
                <td
                  style={{ right: 0, position: "sticky", zIndex: 20 }}
                  className={`sticky right-0 z-20 w-12 border-l border-slate-300 px-4 py-4 ${
                    isSelected ? "bg-sky-50" : "bg-white"
                  } group-hover:bg-slate-50`}
                ></td>
              </motion.tr>
            );
          })}
        </tbody>
      </table>
      {saveNotice && (
        <div className="fixed bottom-5 left-1/2 z-[90] flex -translate-x-1/2 items-center gap-3 rounded-xl border border-emerald-500/30 bg-white px-4 py-3 text-sm text-slate-700 shadow-xl">
          <span className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-100 text-xs font-bold text-emerald-600">✓</span>
          <span className="font-medium">{saveNotice.label}</span>
          <button
            type="button"
            onClick={() => {
              saveNotice.undo();
              setSaveNotice(null);
            }}
            className="font-semibold text-[var(--app-brand-deep)] hover:underline"
          >
            Deshacer
          </button>
        </div>
      )}
    </div>
  );
}

function getColumnLabel(columnKey: string) {
  return {
    client: "Empleado",
    employee_code: "Código",
    position: "Cargo",
    document: "Documento",
    status: "Estado",
  }[columnKey] || "Campo";
}

const FROZEN_COLUMNS_STORAGE_KEY = "employees-table-frozen-columns";
const HIDDEN_COLUMNS_STORAGE_KEY = "employees-table-hidden-custom-columns";
const COLUMN_ORDER_STORAGE_KEY = "employees-table-column-order";
const STATIC_COLUMNS = [
  { key: "client", title: "Empleado" },
  { key: "employee_code", title: "Código" },
  { key: "position", title: "Cargo" },
  { key: "document", title: "Documento" },
  { key: "status", title: "Estado" },
] as const;

type ColumnKey = (typeof STATIC_COLUMNS)[number]["key"] | string;