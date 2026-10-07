"use client";

import { useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type DragEvent, type FocusEvent, type ReactNode } from "react";
import { ArrowDown, ArrowUp, Building2, Eye, EyeOff, Filter, GripVertical, Loader2, MoreVertical, Pin, PinOff, Plus, Trash2, User } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { ACTOR_ENTITY, ACTOR_STATES, DOCUMENT_TYPES, ROLE_LABELS, ROLE_TONES, actorDocument, actorRelation, actorSubtitle, rolesText } from "@/lib/actors";
import { Badge, Chip, TD, TH } from "@/components/ui/proto";
import type { Actor } from "@/types/actor";
import type { CustomFieldDefinition } from "@/types/custom-field";

// Las mismas funciones de las tablas de Clientes, Proveedores y Empleados: ordenar,
// inmovilizar y mover columnas, filtrar por columna, columnas personalizadas
// (agregar, ocultar, mostrar, eliminar) y edición en la celda con "Deshacer".

type FilterType = "text" | "number" | "select" | "date";

interface ActorsTableProps {
  actors: Actor[];
  customFieldDefinitions: CustomFieldDefinition[];
  /** Valores de las columnas personalizadas por actor; los carga la pantalla. */
  customValues: Record<string, Record<string, string>>;
  onCustomValue: (actorId: string, fieldId: string, value: string) => void;
  selectedIds: string[];
  /** Sin permiso de gestión las celdas no se editan. */
  editable: boolean;
  onToggleSelection: (id: string) => void;
  onSelectAll: (ids: string[]) => void;
  onOpen: (id: string) => void;
  onActorChange: (actor: Actor) => void;
  onAddCustomField: () => void;
  onRequestDeleteCustomField: (definition: CustomFieldDefinition) => void;
  onAddFilter: (columnId: string, label: string, type: FilterType) => void;
  onError: (message: string) => void;
}

const FROZEN_KEY = "actors-table-frozen-columns";
const HIDDEN_KEY = "actors-table-hidden-custom-columns";
const ORDER_KEY = "actors-table-column-order";

const STATIC_COLUMNS = [
  { key: "name", title: "Nombre" },
  { key: "document", title: "Documento" },
  { key: "roles", title: "Roles en CORE" },
  { key: "relation", title: "Sector / relación" },
  { key: "status", title: "Estado" },
];

const CELL_INPUT = "app-surface app-text w-full rounded-md border border-[#1B8FC9] px-2 py-1.5 text-[13px] outline-none";
const MENU = "app-surface app-text absolute z-[200] rounded-xl border border-[color:var(--app-border)] p-1.5 text-left text-xs font-normal normal-case tracking-normal shadow-[0_20px_50px_rgba(8,25,55,.28)]";
const MENU_ITEM = "flex w-full cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-[var(--app-surface-muted)] disabled:cursor-default disabled:opacity-60";
const ROW_BG = "bg-[var(--app-surface)] group-hover:bg-[var(--app-surface-muted)]";
const ROW_BG_SELECTED = "bg-[color-mix(in_srgb,#1B8FC9_13%,var(--app-surface))]";
const CHECKBOX = "h-4 w-4 cursor-pointer rounded border-[#CFD5DE] accent-[#1B8FC9]";

function readList(key: string): string[] {
  try {
    const parsed = JSON.parse(window.localStorage.getItem(key) ?? "[]");
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

const errorText = (err: unknown) =>
  err instanceof ApiError ? (err.errors && Object.values(err.errors)[0]?.[0]) || err.message : "No se pudo guardar el cambio.";

export function ActorsTable({
  actors, customFieldDefinitions, customValues, onCustomValue, selectedIds, editable, onToggleSelection, onSelectAll, onOpen,
  onActorChange, onAddCustomField, onRequestDeleteCustomField, onAddFilter, onError,
}: ActorsTableProps) {
  // Edición en la celda
  const [editing, setEditing] = useState<{ id: string; key: string } | null>(null);
  const [tempA, setTempA] = useState("");
  const [tempB, setTempB] = useState("");
  const [saving, setSaving] = useState(false);
  // Copias al día para los manejadores que llegan tarde (p. ej. el blur tras guardar).
  const editingRef = useRef<{ id: string; key: string } | null>(null);
  const savingRef = useRef(false);
  const setEdit = (next: { id: string; key: string } | null) => {
    editingRef.current = next;
    setEditing(next);
  };
  const [notice, setNotice] = useState<{ label: string; undo: () => void } | null>(null);
  const noticeTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Orden, columnas inmovilizadas, ocultas y su posición (se recuerdan en el navegador)
  const [sortColumn, setSortColumn] = useState<string | null>(null);
  const [sortDirection, setSortDirection] = useState<"asc" | "desc">("asc");
  const [columnOrder, setColumnOrder] = useState<string[]>([]);
  const [hiddenColumns, setHiddenColumns] = useState<string[]>([]);
  const [frozenColumns, setFrozenColumns] = useState<string[]>([]);
  const [frozenOffsets, setFrozenOffsets] = useState<Record<string, number>>({});
  const [draggedColumn, setDraggedColumn] = useState<string | null>(null);
  const [dropIndicator, setDropIndicator] = useState<{ key: string; side: "left" | "right" } | null>(null);
  const [openMenu, setOpenMenu] = useState<string | null>(null);
  const [addMenuOpen, setAddMenuOpen] = useState(false);
  const preferencesLoaded = useRef(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const addMenuRef = useRef<HTMLDivElement>(null);
  const headerRefs = useRef<Record<string, HTMLTableCellElement | null>>({});
  const selectionRef = useRef<HTMLTableCellElement>(null);

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => {
      setColumnOrder(readList(ORDER_KEY));
      setHiddenColumns(readList(HIDDEN_KEY));
      setFrozenColumns(readList(FROZEN_KEY));
      preferencesLoaded.current = true;
    });
    return () => window.cancelAnimationFrame(frame);
  }, []);

  useEffect(() => {
    if (!preferencesLoaded.current) return;
    try {
      window.localStorage.setItem(ORDER_KEY, JSON.stringify(columnOrder));
      window.localStorage.setItem(HIDDEN_KEY, JSON.stringify(hiddenColumns));
      window.localStorage.setItem(FROZEN_KEY, JSON.stringify(frozenColumns));
    } catch { /* sin almacenamiento, no se recuerda */ }
  }, [columnOrder, hiddenColumns, frozenColumns]);

  useEffect(() => () => {
    if (noticeTimer.current) clearTimeout(noticeTimer.current);
  }, []);

  useEffect(() => {
    if (editing && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();
    }
  }, [editing]);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      const target = event.target as Node;
      if (menuRef.current && !menuRef.current.contains(target)) setOpenMenu(null);
      if (addMenuRef.current && !addMenuRef.current.contains(target)) setAddMenuOpen(false);
    }
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  const showNotice = (label: string, undo: () => void) => {
    if (noticeTimer.current) clearTimeout(noticeTimer.current);
    setNotice({ label, undo });
    noticeTimer.current = setTimeout(() => setNotice(null), 7000);
  };

  const definitionOf = (key: string) => customFieldDefinitions.find((definition) => definition.id === key);
  const labelOf = (key: string) => definitionOf(key)?.label ?? STATIC_COLUMNS.find((column) => column.key === key)?.title ?? "Campo";

  // --- Guardar la celda -------------------------------------------------------

  /** Datos de la identidad que cambia cada columna editable. */
  function identityPayload(actor: Actor, key: string, a: string, b: string): Record<string, string | null> | null {
    const isOrg = actor.kind === "ORGANIZATION";
    if (key === "name") return isOrg ? { legal_name: a.trim(), trade_name: b.trim() || null } : { given_names: a.trim(), family_name: b.trim() };
    if (key === "document") return isOrg ? { tax_identifier: a.trim() } : { document_number: a.trim() || null, document_type: b || "DNI" };
    if (key === "relation") return isOrg ? { sector: a.trim() || null } : { position: a.trim() || null };
    return null;
  }

  function currentValues(actor: Actor, key: string): [string, string] {
    const isOrg = actor.kind === "ORGANIZATION";
    if (key === "name") {
      return isOrg
        ? [actor.organization?.legal_name ?? "", actor.organization?.trade_name ?? ""]
        : [actor.person?.given_names ?? "", actor.person?.family_name ?? ""];
    }
    if (key === "document") {
      return isOrg ? [actor.organization?.tax_identifier ?? "", ""] : [actor.person?.document_number ?? "", actor.person?.document_type ?? "DNI"];
    }
    if (key === "relation") return [isOrg ? actor.sector ?? "" : actor.position ?? "", ""];
    if (key === "status") return [actor.status, ""];
    return [customValues[actor.id]?.[key] ?? "", ""];
  }

  async function putIdentity(actor: Actor, payload: Record<string, string | null>) {
    const updated = await apiFetch<Actor>(`/v1/parties/${actor.id}`, { method: "PUT", body: JSON.stringify(payload) });
    onActorChange(updated);
    return updated;
  }

  async function postStatus(actor: Actor, status: string) {
    const updated = await apiFetch<Actor>(`/v1/parties/${actor.id}/${status === "ACTIVE" ? "activate" : "deactivate"}`, { method: "POST" });
    onActorChange(updated);
  }

  async function postCustom(actorId: string, fieldId: string, value: string) {
    await apiFetch("/v1/custom-fields/values", {
      method: "POST",
      body: JSON.stringify({ field_id: fieldId, entity_type: ACTOR_ENTITY, entity_id: actorId, value }),
    });
    onCustomValue(actorId, fieldId, value);
  }

  async function saveCell(actor: Actor, key: string, valueA = tempA, valueB = tempB) {
    const current = editingRef.current;
    if (savingRef.current || !current || current.id !== actor.id || current.key !== key) return;
    const [previousA, previousB] = currentValues(actor, key);
    if (valueA === previousA && valueB === previousB) {
      setEdit(null);
      return;
    }

    savingRef.current = true;
    setSaving(true);
    try {
      const payload = identityPayload(actor, key, valueA, valueB);
      if (payload) {
        await putIdentity(actor, payload);
        const before = identityPayload(actor, key, previousA, previousB)!;
        showNotice(`"${labelOf(key)}" cambios guardados`, () => void putIdentity(actor, before).catch((err) => onError(errorText(err))));
      } else if (key === "status") {
        await postStatus(actor, valueA);
        showNotice(`"${labelOf(key)}" cambios guardados`, () => void postStatus(actor, previousA).catch((err) => onError(errorText(err))));
      } else {
        await postCustom(actor.id, key, valueA);
        showNotice(`"${labelOf(key)}" cambios guardados`, () => void postCustom(actor.id, key, previousA).catch((err) => onError(errorText(err))));
      }
      setEdit(null);
    } catch (err) {
      onError(errorText(err));
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  function beginEdit(actor: Actor, key: string) {
    if (!editable || (editing?.id === actor.id && editing.key === key)) return;
    // El cargo es del empleado: una persona sin ese rol no tiene qué editar aquí.
    if (key === "relation" && actor.kind === "PERSON" && !actor.roles.includes("EMPLOYEE")) return;
    const [a, b] = currentValues(actor, key);
    setTempA(a);
    setTempB(b);
    setEdit({ id: actor.id, key });
  }

  /** Al salir del editor se guarda, salvo que el foco pase a otro control del mismo editor. */
  function onEditorBlur(event: FocusEvent<HTMLDivElement>, actor: Actor, key: string) {
    const next = event.relatedTarget as Node | null;
    if (next && event.currentTarget.contains(next)) return;
    void saveCell(actor, key);
  }

  const keyHandlers = (actor: Actor, key: string) => ({
    onKeyDown: (event: { key: string }) => {
      if (event.key === "Enter") void saveCell(actor, key);
      if (event.key === "Escape") setEdit(null);
    },
  });

  // --- Orden y columnas ---------------------------------------------------------

  const sortValue = (actor: Actor, key: string): string => {
    if (key === "name") return actor.name.toLowerCase();
    if (key === "document") return actorDocument(actor);
    if (key === "roles") return rolesText(actor);
    if (key === "relation") return actorRelation(actor).toLowerCase();
    if (key === "status") return actor.status;
    return String(customValues[actor.id]?.[key] ?? "");
  };

  const sortedActors = useMemo(() => {
    if (!sortColumn) return actors;
    const numeric = customFieldDefinitions.find((definition) => definition.id === sortColumn)?.field_type === "number";
    return [...actors].sort((a, b) => {
      const [valueA, valueB] = [sortValue(a, sortColumn), sortValue(b, sortColumn)];
      const result = numeric ? Number(valueA) - Number(valueB) : valueA.localeCompare(valueB, "es");
      return sortDirection === "asc" ? result : -result;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps -- sortValue solo lee customValues
  }, [actors, sortColumn, sortDirection, customValues, customFieldDefinitions]);

  const handleSort = (key: string, direction: "asc" | "desc") => {
    setSortColumn(key);
    setSortDirection(direction);
    setOpenMenu(null);
  };

  const columns = useMemo(() => [
    ...STATIC_COLUMNS.slice(0, 4),
    ...customFieldDefinitions.filter((definition) => !hiddenColumns.includes(definition.id)).map((definition) => ({ key: definition.id, title: definition.label })),
    ...STATIC_COLUMNS.slice(4),
  ], [customFieldDefinitions, hiddenColumns]);

  const frozen = useMemo(() => ["name", ...frozenColumns.filter((key) => key !== "name")], [frozenColumns]);

  const orderedKeys = useMemo(() => {
    const available = new Set(columns.map((column) => column.key));
    const saved = columnOrder.filter((key) => available.has(key) && key !== "name");
    const missing = columns.map((column) => column.key).filter((key) => key !== "name" && !saved.includes(key));
    return ["name", ...saved, ...missing];
  }, [columns, columnOrder]);

  const orderedColumns = useMemo(() => {
    const byKey = new Map(columns.map((column) => [column.key, column]));
    const pinned = frozen.filter((key) => byKey.has(key) && key !== "name");
    return [
      byKey.get("name")!,
      ...pinned.map((key) => byKey.get(key)!),
      ...orderedKeys.filter((key) => key !== "name" && !pinned.includes(key)).map((key) => byKey.get(key)!),
    ];
  }, [columns, frozen, orderedKeys]);

  const lastFrozen = useMemo(() => frozen.findLast((key) => orderedColumns.some((column) => column.key === key)), [frozen, orderedColumns]);
  const divider = (key: string) => (key === lastFrozen ? "sm:border-r-2 sm:border-r-[color:var(--app-border)]" : "");

  useLayoutEffect(() => {
    const update = () => {
      const offsets: Record<string, number> = {};
      let offset = selectionRef.current?.getBoundingClientRect().width ?? 0;
      for (const column of orderedColumns) {
        if (!frozen.includes(column.key)) continue;
        offsets[column.key] = offset;
        offset += headerRefs.current[column.key]?.getBoundingClientRect().width ?? 0;
      }
      setFrozenOffsets(offsets);
    };
    const frame = window.requestAnimationFrame(update);
    const observer = new ResizeObserver(update);
    if (selectionRef.current) observer.observe(selectionRef.current);
    Object.values(headerRefs.current).forEach((element) => element && observer.observe(element));
    return () => {
      window.cancelAnimationFrame(frame);
      observer.disconnect();
    };
  }, [orderedColumns, frozen]);

  const frozenStyle = (key: string, header = false): CSSProperties | undefined =>
    frozen.includes(key) ? { left: frozenOffsets[key] ?? 0, position: "sticky", zIndex: header ? 70 : 20 } : undefined;

  const toggleFrozen = (key: string) => {
    if (key === "name") return;
    setFrozenColumns((current) => (current.includes(key) ? current.filter((item) => item !== key) : [...current, key]));
    setOpenMenu(null);
  };

  const toggleHidden = (id: string) =>
    setHiddenColumns((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

  function onColumnDrop(target: string) {
    if (draggedColumn && draggedColumn !== target && draggedColumn !== "name" && target !== "name") {
      const next = orderedKeys.filter((key) => key !== "name" && key !== draggedColumn);
      const index = next.indexOf(target);
      next.splice(index < 0 ? next.length : index + (dropIndicator?.side === "right" ? 1 : 0), 0, draggedColumn);
      setColumnOrder(["name", ...next]);
    }
    setDraggedColumn(null);
    setDropIndicator(null);
  }

  function onColumnDragOver(event: DragEvent<HTMLTableCellElement>, key: string) {
    if (!draggedColumn || draggedColumn === key || key === "name") return;
    event.preventDefault();
    const rect = event.currentTarget.getBoundingClientRect();
    setDropIndicator({ key, side: event.clientX < rect.left + rect.width / 2 ? "left" : "right" });

    // Al acercarse a un borde la tabla se desplaza sola.
    const scroller = event.currentTarget.closest("[data-table-scroll]") as HTMLElement | null;
    if (!scroller) return;
    const bounds = scroller.getBoundingClientRect();
    const edge = 72;
    if (event.clientX - bounds.left < edge) scroller.scrollLeft -= Math.max(8, (edge - (event.clientX - bounds.left)) / 2);
    else if (bounds.right - event.clientX < edge) scroller.scrollLeft += Math.max(8, (edge - (bounds.right - event.clientX)) / 2);
  }

  function addFilter(key: string) {
    const definition = definitionOf(key);
    const type: FilterType = definition?.field_type === "number" ? "number" : definition?.field_type === "date" ? "date" : definition?.field_type === "boolean" ? "select" : "text";
    onAddFilter(key, labelOf(key), type);
    setOpenMenu(null);
  }

  // --- Celdas -------------------------------------------------------------------

  function renderCell(actor: Actor, key: string, selected: boolean) {
    const isOrg = actor.kind === "ORGANIZATION";
    const isEditing = editing?.id === actor.id && editing.key === key;
    const canEdit = editable && key !== "roles" && !(key === "relation" && !isOrg && !actor.roles.includes("EMPLOYEE"));
    const className = `${TD} ${selected ? ROW_BG_SELECTED : ROW_BG} ${divider(key)} ${canEdit ? "cursor-pointer" : ""}`;
    const cell = (content: ReactNode) => (
      <td key={key} data-label={labelOf(key)} className={className} style={frozenStyle(key)} onClick={() => beginEdit(actor, key)}>
        {content}
      </td>
    );
    const editor = (content: ReactNode, extra = "") => (
      <div className={`flex min-w-44 items-center gap-1.5 ${extra}`} onClick={(event) => event.stopPropagation()} onBlur={(event) => onEditorBlur(event, actor, key)}>
        {content}
        {saving && <Loader2 size={13} className="shrink-0 animate-spin text-[#1B8FC9]" />}
      </div>
    );

    if (key === "name") {
      return cell(isEditing ? editor(
        <div className="grid w-full gap-1">
          <input ref={inputRef} className={CELL_INPUT} value={tempA} onChange={(e) => setTempA(e.target.value)} placeholder={isOrg ? "Razón social" : "Nombres"} {...keyHandlers(actor, key)} />
          <input className={CELL_INPUT} value={tempB} onChange={(e) => setTempB(e.target.value)} placeholder={isOrg ? "Nombre comercial" : "Apellidos"} {...keyHandlers(actor, key)} />
        </div>, "min-w-56",
      ) : (
        <div className="flex min-w-[200px] items-center gap-2.5">
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[#E3F2FA] text-[#1679AB]">
            {isOrg ? <Building2 size={17} /> : <User size={17} />}
          </span>
          <div className="min-w-0">
            <button
              type="button"
              onClick={(event) => {
                event.stopPropagation();
                setEdit(null);
                onOpen(actor.id);
              }}
              className="cursor-pointer text-left font-semibold hover:text-[#1679AB] hover:underline"
            >
              {actor.name}
            </button>
            <small className="app-muted block text-xs">{actorSubtitle(actor)}</small>
          </div>
        </div>
      ));
    }

    if (key === "document") {
      return cell(isEditing ? editor(
        <>
          {!isOrg && (
            <select className={`${CELL_INPUT} !w-auto`} value={tempB} onChange={(e) => setTempB(e.target.value)}>
              {DOCUMENT_TYPES.map(([value]) => <option key={value} value={value}>{value === "PASSPORT" ? "Pasaporte" : value}</option>)}
            </select>
          )}
          <input ref={inputRef} className={`${CELL_INPUT} min-w-24`} value={tempA} onChange={(e) => setTempA(e.target.value)} {...keyHandlers(actor, key)} />
        </>,
      ) : <code className="whitespace-nowrap text-[12.5px]">{actorDocument(actor)}</code>);
    }

    if (key === "roles") {
      return cell(
        <span className="inline-flex flex-wrap gap-1">
          {actor.roles.length ? actor.roles.map((role) => {
            const off = actor.inactive_roles.includes(role);
            return (
              <span key={role} title={off ? `${ROLE_LABELS[role]} inactivo` : undefined} className={off ? "opacity-50" : ""}>
                <Chip tone={off ? "neutral" : ROLE_TONES[role]}>{ROLE_LABELS[role]}</Chip>
              </span>
            );
          }) : "—"}
        </span>,
      );
    }

    if (key === "relation") {
      return cell(isEditing ? editor(
        <input ref={inputRef} className={CELL_INPUT} value={tempA} onChange={(e) => setTempA(e.target.value)} placeholder={isOrg ? "Sector" : "Cargo"} {...keyHandlers(actor, key)} />,
      ) : actorRelation(actor));
    }

    if (key === "status") {
      const [label, tone] = stateOf(ACTOR_STATES, actor.status);
      return cell(isEditing ? editor(
        <select autoFocus className={`${CELL_INPUT} !w-auto`} value={tempA} onChange={(e) => void saveCell(actor, key, e.target.value)} {...keyHandlers(actor, key)}>
          {ACTOR_STATES.map(([value, text]) => <option key={value} value={value}>{text}</option>)}
        </select>, "min-w-0",
      ) : <Badge tone={tone}>{label}</Badge>);
    }

    // Columna personalizada
    const definition = definitionOf(key);
    const value = customValues[actor.id]?.[key] ?? "";
    return cell(isEditing ? editor(
      definition?.field_type === "boolean" ? (
        <input type="checkbox" autoFocus className={CHECKBOX} checked={tempA === "true"} onChange={(e) => void saveCell(actor, key, String(e.target.checked))} />
      ) : (
        <input ref={inputRef} type={definition?.field_type ?? "text"} className={CELL_INPUT} value={tempA} onChange={(e) => setTempA(e.target.value)} {...keyHandlers(actor, key)} />
      ), "min-w-32",
    ) : (
      <span className="block min-h-5">{value === "true" ? "Sí" : value === "false" ? "No" : value || <span className="app-muted">—</span>}</span>
    ));
  }

  function renderHeader(title: string, key: string) {
    const sorted = sortColumn === key;
    const isFrozen = frozen.includes(key);
    const definition = definitionOf(key);

    return (
      <th
        key={key}
        ref={(element) => { headerRefs.current[key] = element; }}
        onDragOver={(event) => onColumnDragOver(event, key)}
        onDrop={(event) => {
          event.preventDefault();
          onColumnDrop(key);
        }}
        style={frozenStyle(key, true)}
        className={`${TH} group/th sticky top-0 bg-[var(--app-surface)] ${divider(key)} ${openMenu === key ? "z-[100]" : "z-40"} ${draggedColumn === key ? "opacity-50" : ""}`}
      >
        <div className="flex items-center justify-between gap-2">
          <div className="flex cursor-pointer select-none items-center gap-1.5" onClick={() => handleSort(key, sorted && sortDirection === "asc" ? "desc" : "asc")}>
            {key !== "name" && (
              <span
                draggable
                onDragStart={(event) => {
                  event.stopPropagation();
                  setDraggedColumn(key);
                }}
                onDragEnd={() => {
                  setDraggedColumn(null);
                  setDropIndicator(null);
                }}
                className="flex shrink-0 cursor-grab touch-none active:cursor-grabbing"
                title="Mover columna"
              >
                <GripVertical size={13} className="opacity-50 group-hover/th:opacity-100" aria-hidden="true" />
              </span>
            )}
            <span>{title}</span>
            {sorted && <span className="font-bold text-[#1B8FC9]">{sortDirection === "asc" ? "↑" : "↓"}</span>}
          </div>
          <button
            type="button"
            aria-label={`Opciones de la columna ${title}`}
            onClick={(event) => {
              event.stopPropagation();
              setOpenMenu(openMenu === key ? null : key);
            }}
            className={`cursor-pointer rounded p-1 hover:bg-[var(--app-surface-muted)] ${sorted || openMenu === key ? "opacity-100" : "opacity-0 group-hover/th:opacity-100 focus:opacity-100"}`}
          >
            <MoreVertical size={14} />
          </button>
        </div>
        {dropIndicator?.key === key && (
          <span aria-hidden="true" className={`pointer-events-none absolute top-0 z-[210] h-full w-1 rounded-full bg-[#1B8FC9] ${dropIndicator.side === "left" ? "left-0" : "right-0"}`} />
        )}

        {openMenu === key && (
          <div ref={menuRef} className={`${MENU} left-3 top-11 w-56`}>
            <button type="button" onClick={() => handleSort(key, "asc")} className={MENU_ITEM}><ArrowUp size={14} className="app-muted" /> Orden ascendente</button>
            <button type="button" onClick={() => handleSort(key, "desc")} className={MENU_ITEM}><ArrowDown size={14} className="app-muted" /> Orden descendente</button>
            <div className="my-1 border-t border-[color:var(--app-border)]" />
            <button type="button" onClick={() => toggleFrozen(key)} disabled={key === "name"} className={MENU_ITEM}>
              {isFrozen ? <PinOff size={14} className="app-muted" /> : <Pin size={14} className="app-muted" />}
              {key === "name" ? "Siempre fija" : isFrozen ? "Desinmovilizar columna" : "Inmovilizar columna"}
            </button>
            <button type="button" onClick={() => addFilter(key)} className={MENU_ITEM}><Filter size={14} className="app-muted" /> Filtrar por esta columna</button>
            {definition && (
              <>
                <div className="my-1 border-t border-[color:var(--app-border)]" />
                <button
                  type="button"
                  onClick={() => {
                    toggleHidden(key);
                    setOpenMenu(null);
                  }}
                  className={MENU_ITEM}
                >
                  <EyeOff size={14} className="app-muted" /> Ocultar columna
                </button>
                <button
                  type="button"
                  disabled={!editable}
                  onClick={() => {
                    onRequestDeleteCustomField(definition);
                    setOpenMenu(null);
                  }}
                  className={`${MENU_ITEM} text-[#D93A40]`}
                >
                  <Trash2 size={14} /> Eliminar columna
                </button>
              </>
            )}
          </div>
        )}
      </th>
    );
  }

  const allSelected = sortedActors.length > 0 && selectedIds.length === sortedActors.length;
  const hidden = customFieldDefinitions.filter((definition) => hiddenColumns.includes(definition.id));
  const edge = frozenColumns.length === 0 ? "sm:border-r sm:border-r-[color:var(--app-border)]" : "";

  return (
    <div className="relative w-full">
      <table className="proto-tbl w-full border-separate border-spacing-0 text-left text-[13px]">
        <thead>
          <tr>
            <th ref={selectionRef} style={{ left: 0, position: "sticky", zIndex: 70 }} className={`${TH} top-0 w-10 bg-[var(--app-surface)] !pr-0 ${edge}`}>
              <input
                type="checkbox"
                className={CHECKBOX}
                checked={allSelected}
                onChange={() => onSelectAll(allSelected ? [] : sortedActors.map((actor) => actor.id))}
                aria-label="Seleccionar todas las filas"
              />
            </th>
            {orderedColumns.map((column) => renderHeader(column.title, column.key))}

            {/* Columna final fija: agregar columna y columnas ocultas */}
            <th
              style={{ right: 0, position: "sticky", zIndex: 70 }}
              className={`${TH} top-0 w-12 border-l border-l-[color:var(--app-border)] bg-[var(--app-surface)] text-center`}
              onDragOver={(event) => {
                const last = orderedColumns[orderedColumns.length - 1];
                if (last) onColumnDragOver(event, last.key);
              }}
              onDrop={(event) => {
                event.preventDefault();
                const last = orderedColumns[orderedColumns.length - 1];
                if (last) onColumnDrop(last.key);
              }}
            >
              <div ref={addMenuRef} className="relative">
                <button
                  type="button"
                  onClick={() => setAddMenuOpen((open) => !open)}
                  className="mx-auto grid h-7 w-7 cursor-pointer place-items-center rounded-full border border-[color:var(--app-border)] hover:bg-[#1B8FC9] hover:text-[#fff]"
                  title="Gestionar columnas"
                  aria-label="Gestionar columnas"
                >
                  <Plus size={16} />
                </button>
                {addMenuOpen && (
                  <div className={`${MENU} right-0 top-9 w-60`}>
                    <button
                      type="button"
                      disabled={!editable}
                      onClick={() => {
                        setAddMenuOpen(false);
                        onAddCustomField();
                      }}
                      className={MENU_ITEM}
                    >
                      <Plus size={14} className="app-muted" /> Agregar columna nueva
                    </button>
                    <div className="my-1 border-t border-[color:var(--app-border)]" />
                    <p className="app-muted px-3 py-1 text-[10px] font-semibold uppercase tracking-wide">Columnas ocultas</p>
                    {hidden.length === 0 ? (
                      <p className="app-muted px-3 py-2">No hay columnas ocultas</p>
                    ) : hidden.map((definition) => (
                      <button key={definition.id} type="button" onClick={() => toggleHidden(definition.id)} className={MENU_ITEM}>
                        <Eye size={14} className="app-muted" /> {definition.label}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          {/* eslint-disable-next-line react-hooks/refs -- los refs solo se leen al guardar, desde manejadores de eventos */}
          {sortedActors.map((actor) => {
            const selected = selectedIds.includes(actor.id);
            return (
              <tr key={actor.id} className="group">
                <td data-label="" style={{ left: 0, position: "sticky", zIndex: 20 }} className={`${TD} w-10 !pr-0 ${selected ? ROW_BG_SELECTED : ROW_BG} ${edge}`}>
                  <input type="checkbox" className={CHECKBOX} checked={selected} onChange={() => onToggleSelection(actor.id)} aria-label="Seleccionar fila" />
                </td>
                {orderedColumns.map((column) => renderCell(actor, column.key, selected))}
                <td
                  data-label=""
                  style={{ right: 0, position: "sticky", zIndex: 20 }}
                  className={`${TD} w-12 border-l border-l-[color:var(--app-border)] max-sm:!hidden ${selected ? ROW_BG_SELECTED : ROW_BG}`}
                />
              </tr>
            );
          })}
        </tbody>
      </table>

      {notice && (
        <div className="app-surface app-text fixed bottom-5 left-1/2 z-[90] flex -translate-x-1/2 items-center gap-3 rounded-xl border border-[#BFE3CD] px-4 py-3 text-sm shadow-[0_20px_50px_rgba(8,25,55,.28)]">
          <span className="grid h-5 w-5 place-items-center rounded-full bg-[#E4F6EB] text-xs font-bold text-[#1B8E4B]">✓</span>
          <span className="font-medium">{notice.label}</span>
          <button
            type="button"
            onClick={() => {
              notice.undo();
              setNotice(null);
            }}
            className="cursor-pointer font-semibold text-[#1679AB] hover:underline"
          >
            Deshacer
          </button>
        </div>
      )}
    </div>
  );
}
