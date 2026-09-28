"use client";

import { useEffect, useState, useRef, useMemo, useLayoutEffect } from "react";
import { motion } from "framer-motion";
import { Building2, User, MoreVertical, ArrowUp, ArrowDown, Filter, Pin, PinOff, Plus, Loader2, Trash2, Eye, EyeOff, GripVertical } from "lucide-react";
import { type Supplier, getSupplierDisplayName, getSupplierDocument } from "@/types/supplier";
import { type CustomFieldDefinition } from "@/types/custom-field";
import { StatusBadge } from "@/components/StatusBadge";
import { apiFetch } from "@/lib/api";

interface SupplierTableProps {
  suppliers: Supplier[];
  selectedIds: string[];
  customFieldDefinitions: CustomFieldDefinition[];
  onToggleSelection: (id: string) => void;
  onSelectAll: (ids: string[]) => void;
  onSelectSupplier: (id: string) => void;
  onAddCustomField: () => void;
  onRequestDeleteCustomField: (definition: CustomFieldDefinition) => void;
  onAddFilter?: (columnId: string, label: string, type: "text" | "number" | "select" | "date") => void;
}

export function SupplierTable({
  suppliers,
  customFieldDefinitions,
  selectedIds,
  onToggleSelection,
  onSelectAll,
  onSelectSupplier,
  onAddCustomField,
  onRequestDeleteCustomField,
  onAddFilter,
}: SupplierTableProps) {
  const [tableSuppliers, setTableSuppliers] = useState(suppliers);
  const [supplierValuesMap, setSupplierValuesMap] = useState<Record<string, Record<string, string>>>({});
  const [loadingCustom, setLoadingCustom] = useState(true);

  // Estados para la Edición en Línea (Inline Editing)
  const [editingCell, setEditingCell] = useState<{ supplierId: string; fieldKey: string } | null>(null);
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
    const timer = window.setTimeout(() => setTableSuppliers(suppliers), 0);
    return () => window.clearTimeout(timer);
  }, [suppliers]);

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
      if (suppliers.length === 0) {
        setLoadingCustom(false);
        return;
      }

      setLoadingCustom(true);
      try {
        const valuesPromise = (async () => {
          const loadedValues: Record<string, Record<string, string>> = {};
          await Promise.all(
            suppliers.map(async (supplier) => {
              const vals = await apiFetch<Array<{ field_id: string; value: string }>>(
                `/v1/custom-fields/values?entity_type=Supplier&entity_id=${supplier.id}`
              ).catch(() => []);

              loadedValues[supplier.id] = {};
              vals.forEach((v) => {
                loadedValues[supplier.id][v.field_id] = v.value;
              });
            })
          );
          return loadedValues;
        })();

        const valuesMap = await valuesPromise;

        if (cancelled) return;
        setSupplierValuesMap(valuesMap);
      } catch (err) {
        console.error("No se pudieron cargar los campos personalizados para la tabla", err);
      } finally {
        if (!cancelled) setLoadingCustom(false);
      }
    }

    loadTableCustomData();
    return () => { cancelled = true; };
  }, [suppliers]);

  const handleSaveInlineCell = async (supplierId: string, fieldId: string) => {
    if (isSavingCell) return;
    const previousValue = supplierValuesMap[supplierId]?.[fieldId] || "";
    const nextValue = tempCellValue;
    setIsSavingCell(true);
    setSupplierValuesMap(prev => ({
      ...prev,
      [supplierId]: { ...(prev[supplierId] || {}), [fieldId]: nextValue },
    }));
    try {
      await apiFetch("/v1/custom-fields/values", {
        method: "POST",
        body: JSON.stringify({
          field_id: fieldId,
          entity_type: "Supplier",
          entity_id: supplierId,
          value: nextValue,
        }),
      });

      setEditingCell(null);
      const definition = customFieldDefinitions.find((item) => item.id === fieldId);
      showSaveNotice(`"${definition?.label || "Campo"}" cambios guardados`, () => {
        setSupplierValuesMap(prev => ({
          ...prev,
          [supplierId]: { ...(prev[supplierId] || {}), [fieldId]: previousValue },
        }));
        void apiFetch("/v1/custom-fields/values", {
          method: "POST",
          body: JSON.stringify({
            field_id: fieldId,
            entity_type: "Supplier",
            entity_id: supplierId,
            value: previousValue,
          }),
        }).catch(() => setSupplierValuesMap(prev => ({
          ...prev,
          [supplierId]: { ...(prev[supplierId] || {}), [fieldId]: nextValue },
        })));
      });
    } catch (err) {
      setSupplierValuesMap(prev => ({
        ...prev,
        [supplierId]: { ...(prev[supplierId] || {}), [fieldId]: previousValue },
      }));
      console.error("No se pudo actualizar el campo en línea", err);
    } finally {
      setIsSavingCell(false);
    }
  };

  const getSupplierUpdatePayload = (supplier: Supplier) => {
    const isOrganization = supplier.party.party_type === "ORGANIZATION";
    const person = supplier.party.person;
    const organization = supplier.party.organization;
    return {
      party_type: supplier.party.party_type,
      status: supplier.status,
      document_number: isOrganization ? organization?.tax_identifier || "" : person?.document_number || "",
      ...(isOrganization
        ? { legal_name: organization?.legal_name || "", trade_name: organization?.trade_name || "" }
        : {
            document_type: person?.document_type || "DNI",
            given_names: person?.given_names || "",
            family_name: person?.family_name || "",
          }),
    };
  };

  const applyInlineSupplierChange = (supplier: Supplier, fieldKey: string): Supplier => {
    const nextSupplier: Supplier = {
      ...supplier,
      status: fieldKey === "status" ? (tempCellValue as "ACTIVE" | "INACTIVE") : supplier.status,
      party: {
        ...supplier.party,
        person: supplier.party.person ? { ...supplier.party.person } : null,
        organization: supplier.party.organization ? { ...supplier.party.organization } : null,
      },
    };

    if (nextSupplier.party.person) {
      nextSupplier.party.person = {
        ...nextSupplier.party.person,
        given_names: fieldKey === "client" ? tempGivenNames : nextSupplier.party.person.given_names,
        family_name: fieldKey === "client" ? tempFamilyName : nextSupplier.party.person.family_name,
        document_type: fieldKey === "document" ? tempDocumentType : nextSupplier.party.person.document_type,
        document_number: fieldKey === "document" ? tempCellValue : nextSupplier.party.person.document_number,
      };
    }
    if (nextSupplier.party.organization) {
      nextSupplier.party.organization = {
        ...nextSupplier.party.organization,
        legal_name: fieldKey === "client" ? tempCellValue : nextSupplier.party.organization.legal_name,
        tax_identifier: fieldKey === "document" ? tempCellValue : nextSupplier.party.organization.tax_identifier,
      };
    }
    return nextSupplier;
  };

  const handleSaveSupplierCell = async (supplier: Supplier, fieldKey: string) => {
    if (isSavingCell) return;
    setIsSavingCell(true);
    const isOrganization = supplier.party.party_type === "ORGANIZATION";
    const person = supplier.party.person;
    const organization = supplier.party.organization;
    const payload = {
      party_type: supplier.party.party_type,
      document_number: isOrganization ? organization?.tax_identifier || "" : person?.document_number || "",
      ...(isOrganization
        ? {
            legal_name: fieldKey === "client" ? tempCellValue : organization?.legal_name || "",
            trade_name: organization?.trade_name || "",
          }
        : {
            document_type: fieldKey === "document" ? tempDocumentType : person?.document_type || "DNI",
            given_names: fieldKey === "client" ? tempGivenNames : person?.given_names || "",
            family_name: fieldKey === "client" ? tempFamilyName : person?.family_name || "",
          }),
      ...(fieldKey === "document" ? { document_number: tempCellValue } : {}),
      ...(fieldKey === "status" ? { status: tempCellValue } : {}),
    };
    const previousSupplier = tableSuppliers.find((item) => item.id === supplier.id);
    const updatedSupplier = previousSupplier ? applyInlineSupplierChange(previousSupplier, fieldKey) : null;

    if (updatedSupplier) {
      setTableSuppliers((current) => current.map((item) => item.id === supplier.id ? updatedSupplier : item));
    }

    try {
      await apiFetch(`/v1/suppliers/${supplier.id}`, {
        method: "PUT",
        body: JSON.stringify(payload),
      });
      setEditingCell(null);
      if (previousSupplier && updatedSupplier) {
        showSaveNotice(`"${getColumnLabel(fieldKey)}" cambios guardados`, () => {
          setTableSuppliers((current) => current.map((item) => item.id === supplier.id ? previousSupplier : item));
          void apiFetch(`/v1/suppliers/${supplier.id}`, {
            method: "PUT",
            body: JSON.stringify(getSupplierUpdatePayload(previousSupplier)),
          }).catch(() => setTableSuppliers((current) => current.map((item) => item.id === supplier.id ? updatedSupplier : item)));
        });
      }
    } catch (err) {
      if (previousSupplier) {
        setTableSuppliers((current) => current.map((item) => item.id === supplier.id ? previousSupplier : item));
      }
      console.error("No se pudo actualizar el proveedor en línea", err);
    } finally {
      setIsSavingCell(false);
    }
  };

  const startEditingCell = (supplier: Supplier, fieldKey: string, value: string) => {
    setEditingCell({ supplierId: supplier.id, fieldKey });
    setTempCellValue(value);
    if (fieldKey === "document") {
      setTempDocumentType(supplier.party.person?.document_type || "DNI");
    }
    if (fieldKey === "client" && supplier.party.person) {
      setTempGivenNames(supplier.party.person.given_names);
      setTempFamilyName(supplier.party.person.family_name);
    }
  };

  const handleEditorBlur = (
    event: React.FocusEvent<HTMLDivElement>,
    supplier: Supplier,
    fieldKey: string,
  ) => {
    const nextFocusedElement = event.relatedTarget as Node | null;
    if (nextFocusedElement && event.currentTarget.contains(nextFocusedElement)) return;
    window.setTimeout(() => {
      if (editingCell?.supplierId === supplier.id && editingCell.fieldKey === fieldKey) {
        void handleSaveSupplierCell(supplier, fieldKey);
      }
    }, 0);
  };

  const getInlineInputProps = (supplier: Supplier, fieldKey: string) => ({
    value: tempCellValue,
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setTempCellValue(event.target.value),
    onKeyDown: (event: React.KeyboardEvent<HTMLInputElement | HTMLSelectElement>) => {
      if (event.key === "Enter") void handleSaveSupplierCell(supplier, fieldKey);
      if (event.key === "Escape") setEditingCell(null);
    },
  });

  const sortedSuppliers = useMemo(() => {
    if (!sortColumn) return tableSuppliers;

    return [...tableSuppliers].sort((a, b) => {
      let valA: string | number = "";
      let valB: string | number = "";

      if (sortColumn === "client") {
        valA = getSupplierDisplayName(a).toLowerCase();
        valB = getSupplierDisplayName(b).toLowerCase();
      } else if (sortColumn === "document") {
        valA = getSupplierDocument(a);
        valB = getSupplierDocument(b);
      } else if (sortColumn === "status") {
        valA = a.status;
        valB = b.status;
      } else {
        valA = String(supplierValuesMap[a.id]?.[sortColumn] ?? "");
        valB = String(supplierValuesMap[b.id]?.[sortColumn] ?? "");
      }

      if (valA < valB) return sortDirection === "asc" ? -1 : 1;
      if (valA > valB) return sortDirection === "asc" ? 1 : -1;
      return 0;
    });
  }, [tableSuppliers, sortColumn, sortDirection, supplierValuesMap]);

  const handleSort = (columnKey: string, direction: "asc" | "desc") => {
    setSortColumn(columnKey);
    setSortDirection(direction);
    setOpenHeaderMenu(null);
  };

  const handleSelectAll = () => {
    if (selectedIds.length === sortedSuppliers.length && sortedSuppliers.length > 0) {
      onSelectAll([]); 
    } else {
      onSelectAll(sortedSuppliers.map((s) => s.id)); 
    }
  };

  const isAllSelected = sortedSuppliers.length > 0 && selectedIds.length === sortedSuppliers.length;

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

  const renderSupplierCell = (supplier: Supplier, columnKey: string, isSelected: boolean) => {
    const isOrg = supplier.party.party_type === "ORGANIZATION";
    const supplierVals = supplierValuesMap[supplier.id] || {};
    const cellClassName = isSelected
      ? `px-6 py-4 bg-sky-50 text-slate-900 group-hover:bg-sky-50 ${getFrozenDividerClass(columnKey)}`
      : `px-6 py-4 bg-white text-slate-700 group-hover:bg-slate-50 ${getFrozenDividerClass(columnKey)}`;
    const cellStyle = getFrozenStyle(columnKey);
    const isEditing = editingCell?.supplierId === supplier.id && editingCell?.fieldKey === columnKey;
    const beginEdit = (value: string) => {
      if (!isEditing) startEditingCell(supplier, columnKey, value);
    };
    const editableClassName = `${cellClassName} cursor-pointer hover:bg-sky-50 transition-colors`;

    if (columnKey === "client") {
      return (
        <td key={columnKey} className={editableClassName} style={cellStyle} onClick={() => beginEdit(getSupplierDisplayName(supplier))}>
          {isEditing ? (
            <div className="min-w-52 space-y-1" onClick={(event) => event.stopPropagation()} onBlur={(event) => handleEditorBlur(event, supplier, columnKey)}>
              {isOrg ? (
                <input
                  ref={inputRef}
                  type="text"
                  {...getInlineInputProps(supplier, columnKey)}
                  className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-xs focus:outline-none"
                />
              ) : (
                <>
                  <input
                    ref={inputRef}
                    type="text"
                    value={tempGivenNames}
                    onChange={(event) => setTempGivenNames(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === "Enter") void handleSaveSupplierCell(supplier, columnKey);
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
                      if (event.key === "Enter") void handleSaveSupplierCell(supplier, columnKey);
                      if (event.key === "Escape") setEditingCell(null);
                    }}
                    placeholder="Apellidos"
                    className="w-full rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-sm text-slate-900 shadow-xs focus:outline-none"
                  />
                </>
              )}
            </div>
          ) : (
          <div className="flex items-center gap-3 min-w-[200px]">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sky-50 text-[var(--app-brand-deep)] font-semibold">
              {isOrg ? <Building2 size={18} /> : <User size={18} />}
            </div>
            <div>
              <button type="button" onClick={(event) => { event.stopPropagation(); setEditingCell(null); onSelectSupplier(supplier.id); }} className="font-semibold text-slate-900 hover:text-[var(--app-brand-deep)] hover:underline text-left">
                {getSupplierDisplayName(supplier)}
              </button>
              <p className="text-xs text-slate-500">{isOrg ? "Empresa" : "Persona Natural"}</p>
            </div>
          </div>
          )}
        </td>
      );
    }

    if (columnKey === "document") {
      const documentNumber = supplier.party.person?.document_number || supplier.party.organization?.tax_identifier || "";
      return (
        <td key={columnKey} className={`${editableClassName} text-slate-600 font-medium`} style={cellStyle} onClick={() => beginEdit(documentNumber)}>
          {isEditing ? (
            <div className="flex min-w-44 items-center gap-1.5" onClick={(event) => event.stopPropagation()} onBlur={(event) => handleEditorBlur(event, supplier, columnKey)}>
              {supplier.party.party_type === "PERSON" && (
                <select
                  value={tempDocumentType}
                  onChange={(event) => setTempDocumentType(event.target.value)}
                  className="rounded-md border border-[var(--app-brand-deep)] bg-white px-1.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none"
                >
                  <option value="DNI">DNI</option>
                  <option value="CE">CE</option>
                  <option value="PASSPORT">Pasaporte</option>
                </select>
              )}
              <input
                ref={inputRef}
                type="text"
                {...getInlineInputProps(supplier, columnKey)}
                className="w-full min-w-24 rounded-md border border-[var(--app-brand-deep)] bg-white px-2.5 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none"
              />
            </div>
          ) : getSupplierDocument(supplier)}
        </td>
      );
    }

    if (columnKey === "status") {
      return (
        <td key={columnKey} className={editableClassName} style={cellStyle} onClick={() => beginEdit(supplier.status)}>
          {isEditing ? (
            <div onBlur={(event) => handleEditorBlur(event, supplier, columnKey)}><select {...getInlineInputProps(supplier, columnKey)} className="rounded-md border border-[var(--app-brand-deep)] bg-white px-2 py-1.5 text-xs text-slate-900 shadow-xs focus:outline-none">
              <option value="ACTIVE">Activo</option>
              <option value="INACTIVE">Inactivo</option>
            </select></div>
          ) : <StatusBadge status={supplier.status} />}
        </td>
      );
    }

    const isCustomField = customFieldDefinitions.some(d => d.id === columnKey);

    if (isCustomField) {
      const definition = customFieldDefinitions.find(d => d.id === columnKey);
      const isEditing = editingCell?.supplierId === supplier.id && editingCell?.fieldKey === columnKey;
      const value = supplierVals[columnKey] || "";

      return (
        <td 
          key={columnKey} 
          className={`${cellClassName} text-slate-600 text-xs cursor-pointer hover:bg-sky-50 transition-colors`}
          style={cellStyle}
          onClick={() => {
            if (!isEditing) {
              setEditingCell({ supplierId: supplier.id, fieldKey: columnKey });
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
                  onBlur={() => void handleSaveInlineCell(supplier.id, columnKey)}
                  className="h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] focus:ring-[var(--app-brand)]"
                />
              ) : (
              <input
                ref={inputRef}
                type={definition?.field_type || "text"}
                value={tempCellValue}
                onChange={(e) => setTempCellValue(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") handleSaveInlineCell(supplier.id, columnKey);
                  if (e.key === "Escape") setEditingCell(null);
                }}
                onBlur={() => handleSaveInlineCell(supplier.id, columnKey)}
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

    const value = supplierVals[columnKey];
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
          {sortedSuppliers.map((supplier) => {
            const isSelected = selectedIds.includes(supplier.id);

            return (
              <motion.tr
                key={supplier.id}
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
                    onChange={() => onToggleSelection(supplier.id)}
                    className={`h-4 w-4 cursor-pointer rounded border-slate-300 text-[var(--app-brand-deep)] transition-opacity duration-200 focus:ring-[var(--app-brand)] ${
                      isSelected ? "opacity-100" : "opacity-0 focus:opacity-100 group-hover:opacity-100"
                    }`}
                  />
                </td>
                {orderedColumns.map((column) => renderSupplierCell(supplier, column.key, isSelected))}
                
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
    client: "Proveedor",
    document: "Documento / RUC",
    status: "Estado",
  }[columnKey] || "Campo";
}

const FROZEN_COLUMNS_STORAGE_KEY = "suppliers-table-frozen-columns";
const HIDDEN_COLUMNS_STORAGE_KEY = "suppliers-table-hidden-custom-columns";
const COLUMN_ORDER_STORAGE_KEY = "suppliers-table-column-order";
const STATIC_COLUMNS = [
  { key: "client", title: "Proveedor" },
  { key: "document", title: "Documento / RUC" },
  { key: "status", title: "Estado" },
] as const;

type ColumnKey = (typeof STATIC_COLUMNS)[number]["key"] | string;