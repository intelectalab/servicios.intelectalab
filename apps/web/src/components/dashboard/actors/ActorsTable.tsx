"use client";

import { useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type DragEvent, type FocusEvent, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { ArrowDown, ArrowUp, Building2, Eye, EyeOff, Filter, GripVertical, Loader2, Lock, MoreVertical, Pencil, Pin, PinOff, Plus, Trash2, User, X } from "lucide-react";
import { ApiError, apiFetch } from "@/lib/api";
import { stateOf } from "@/lib/commercial";
import { poppins } from "@/lib/fonts";
import { ACTOR_ENTITY, ACTOR_STATES, DOCUMENT_TYPES, ROLE_LABELS, ROLE_TONES, actorDocument, actorRelation, actorSubtitle } from "@/lib/actors";
import { Badge, Chip, TD, TH, deniedText, type SortState } from "@/components/ui/proto";
import { PickSelect } from "@/components/ui/pick-select";
import type { Actor } from "@/types/actor";
import type { CustomFieldDefinition } from "@/types/custom-field";

// Las mismas funciones de las tablas de Clientes, Proveedores y Empleados: ordenar,
// inmovilizar y mover columnas, filtrar por columna, columnas personalizadas
// (agregar, ocultar, mostrar, eliminar) y edición en la celda con "Deshacer".

type FilterType = "text" | "number" | "select" | "date";

interface ActorsTableProps {
  actors: Actor[];
  /** Orden actual y cómo cambiarlo: lo aplica la API sobre todo el listado, no solo sobre esta página. */
  sort: SortState;
  onSort: (sort: SortState) => void;
  customFieldDefinitions: CustomFieldDefinition[];
  /** Valores de las columnas personalizadas por actor; los carga la pantalla. */
  customValues: Record<string, Record<string, string>>;
  onCustomValue: (actorId: string, fieldId: string, value: string) => void;
  selectedIds: string[];
  /** Sin permiso de gestión las celdas no se editan. */
  editable: boolean;
  /** Puede eliminar columnas personalizadas (permiso aparte de gestionar). */
  canDelete: boolean;
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
const MENU = "app-surface app-text fixed z-[250] rounded-xl border border-[color:var(--app-border)] p-1.5 text-left text-xs font-normal normal-case tracking-normal shadow-[0_20px_50px_rgba(8,25,55,.28)]";
/**
 * Dónde dibujar un menú que cuelga de un botón: debajo y alineado a su izquierda,
 * sin salirse de la ventana; hacia arriba si abajo no cabe.
 */
function menuPlace(button: HTMLElement, width: number, height: number): CSSProperties {
  const rect = button.getBoundingClientRect();
  const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
  const below = window.innerHeight - rect.bottom;
  return below < height && rect.top > below ? { left, bottom: window.innerHeight - rect.top + 6 } : { left, top: rect.bottom + 6 };
}

const MENU_ITEM = "flex w-full cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-[var(--app-surface-muted)] disabled:cursor-default disabled:opacity-60";
const ROW_BG = "bg-[var(--app-surface)] group-hover:bg-[var(--app-surface-muted)]";
const ROW_BG_SELECTED = "bg-[color-mix(in_srgb,#1B8FC9_13%,var(--app-surface))]";
const CHECKBOX = "h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9]";

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
  actors, sort, onSort, customFieldDefinitions, customValues, onCustomValue, selectedIds, editable, canDelete, onToggleSelection, onSelectAll, onOpen,
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
  const openTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Orden, columnas inmovilizadas, ocultas y su posición (se recuerdan en el navegador)
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
  const addListRef = useRef<HTMLDivElement>(null);
  const [place, setPlace] = useState<CSSProperties>({});
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
    if (openTimer.current) clearTimeout(openTimer.current);
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
      if (!addMenuRef.current?.contains(target) && !addListRef.current?.contains(target)) setAddMenuOpen(false);
    }
    // Los menús están en posición fija: al desplazar o cambiar el tamaño se cierran.
    function closeMenus(event: Event) {
      const target = event.target as Node | null;
      if (target && (menuRef.current?.contains(target) || addListRef.current?.contains(target))) return;
      setOpenMenu(null);
      setAddMenuOpen(false);
    }
    document.addEventListener("mousedown", onClickOutside);
    window.addEventListener("scroll", closeMenus, true);
    window.addEventListener("resize", closeMenus);
    return () => {
      document.removeEventListener("mousedown", onClickOutside);
      window.removeEventListener("scroll", closeMenus, true);
      window.removeEventListener("resize", closeMenus);
    };
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

  // Abrir la ficha con una pequeña espera, que un doble clic (editar) cancela.
  const cancelOpen = () => {
    if (openTimer.current) clearTimeout(openTimer.current);
    openTimer.current = null;
  };
  const openSoon = (id: string) => {
    cancelOpen();
    openTimer.current = setTimeout(() => {
      openTimer.current = null;
      setEdit(null);
      onOpen(id);
    }, 230);
  };

  function beginEdit(actor: Actor, key: string) {
    if (!editable || (editing?.id === actor.id && editing.key === key)) return;
    // "Contacto de …" se cambia en el formulario, no en la celda.
    if (key === "relation" && actor.contact_of) return;
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

  // El orden lo aplica la API (abarca todo el listado); las filas llegan ya ordenadas.
  const sortedActors = actors;
  const sortColumn = sort?.key ?? null;
  const sortDirection = sort?.dir ?? "asc";

  const handleSort = (key: string, direction: "asc" | "desc" | null) => {
    onSort(direction ? { key, dir: direction } : null);
    setOpenMenu(null);
  };

  const columns = useMemo(() => [
    ...STATIC_COLUMNS.slice(0, 4),
    ...customFieldDefinitions.filter((definition) => !hiddenColumns.includes(definition.id)).map((definition) => ({ key: definition.id, title: definition.label })),
    ...STATIC_COLUMNS.slice(4),
  ], [customFieldDefinitions, hiddenColumns]);

  // Nombre no se mueve: va siempre primero. Se inmoviliza si se pide, o junto con cualquier otra
  // columna inmovilizada (que queda a su lado).
  const frozen = useMemo(
    () => (frozenColumns.length ? ["name", ...frozenColumns.filter((key) => key !== "name")] : []),
    [frozenColumns],
  );

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
    // Liberar Nombre libera también las que estaban inmovilizadas a su lado.
    if (key === "name") {
      setFrozenColumns((current) => (current.length ? [] : ["name"]));
      return;
    }
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
    const canEdit = editable && key !== "roles" && !(key === "relation" && actor.contact_of);
    const className = `${TD} group/cell ${selected ? ROW_BG_SELECTED : ROW_BG} ${divider(key)}`;
    const startEdit = () => {
      cancelOpen();
      beginEdit(actor, key);
    };
    // Un clic abre la ficha (como en los demás listados); se edita con el lápiz o con doble clic.
    // En una celda editable el clic espera un instante, por si es el primero de un doble clic.
    const cell = (content: ReactNode) => (
      <td
        key={key}
        data-label={labelOf(key)}
        className={className}
        style={frozenStyle(key)}
        onClick={isEditing ? (event) => event.stopPropagation() : canEdit ? (event) => {
          event.stopPropagation();
          openSoon(actor.id);
        } : undefined}
        onDoubleClick={canEdit && !isEditing ? startEdit : undefined}
      >
        {!editable && key !== "roles" ? (
          // Sin permiso de edición el lápiz no desaparece: sale con candado y su motivo.
          <div className="flex items-center justify-between gap-2">
            <div className="min-w-0">{content}</div>
            <span
              title={deniedText("core.manage")}
              aria-label={deniedText("core.manage")}
              className="app-muted shrink-0 cursor-not-allowed rounded p-1 opacity-0 group-hover/cell:opacity-60 max-sm:opacity-60"
            >
              <Lock size={12} aria-hidden="true" />
            </span>
          </div>
        ) : canEdit && !isEditing ? (
          <div className="flex items-center justify-between gap-2">
            <div className="min-w-0">{content}</div>
            <button
              type="button"
              title="Editar (o doble clic en la celda)"
              aria-label={`Editar ${labelOf(key)}`}
              onClick={(event) => {
                event.stopPropagation();
                startEdit();
              }}
              className="shrink-0 cursor-pointer rounded p-1 text-[color:var(--p-brand-text)] opacity-0 hover:bg-[rgb(27_143_201/0.16)] focus:opacity-100 group-hover/cell:opacity-100 max-sm:opacity-100"
            >
              <Pencil size={13} />
            </button>
          </div>
        ) : content}
      </td>
    );
    const editor = (content: ReactNode, extra = "") => (
      <div className={`flex min-w-44 items-center gap-1.5 ${extra}`} onClick={(event) => event.stopPropagation()} onBlur={(event) => onEditorBlur(event, actor, key)}>
        {content}
        {saving && <Loader2 size={13} className="shrink-0 animate-spin text-[color:var(--p-brand)]" />}
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
          <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]">
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
              className="cursor-pointer text-left font-semibold hover:text-[color:var(--p-brand-text)] hover:underline"
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
            <PickSelect
              className={`${CELL_INPUT} !w-auto shrink-0`}
              value={tempB}
              onChange={setTempB}
              options={DOCUMENT_TYPES.map(([value]): [string, string] => [value, value === "PASSPORT" ? "Pasaporte" : value])}
              ariaLabel="Tipo de documento"
            />
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
        <PickSelect
          autoOpen
          className={`${CELL_INPUT} !w-auto min-w-28`}
          value={tempA}
          onChange={(value) => void saveCell(actor, key, value)}
          onClose={() => setEdit(null)}
          options={ACTOR_STATES.map(([value, text]): [string, string] => [value, text])}
          ariaLabel="Estado"
        />, "min-w-0",
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
            {sorted && <span className="font-bold text-[color:var(--p-brand)]">{sortDirection === "asc" ? "↑" : "↓"}</span>}
          </div>
          <button
            type="button"
            aria-label={`Opciones de la columna ${title}`}
            onClick={(event) => {
              event.stopPropagation();
              setPlace(menuPlace(event.currentTarget, 224, definition ? 310 : 220));
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

        {openMenu === key && createPortal(
          <div ref={menuRef} style={place} className={`${poppins.className} ${MENU} w-56`}>
            <button type="button" onClick={() => handleSort(key, "asc")} className={MENU_ITEM}><ArrowUp size={14} className="app-muted" /> Orden ascendente</button>
            <button type="button" onClick={() => handleSort(key, "desc")} className={MENU_ITEM}><ArrowDown size={14} className="app-muted" /> Orden descendente</button>
            {sorted && <button type="button" onClick={() => handleSort(key, null)} className={MENU_ITEM}><X size={14} className="app-muted" /> Quitar orden</button>}
            <div className="my-1 border-t border-[color:var(--app-border)]" />
            <button type="button" onClick={() => toggleFrozen(key)} className={MENU_ITEM}>
              {isFrozen ? <PinOff size={14} className="app-muted" /> : <Pin size={14} className="app-muted" />}
              {isFrozen ? "Desinmovilizar columna" : "Inmovilizar columna"}
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
                  disabled={!canDelete}
                  title={canDelete ? undefined : deniedText("core.delete")}
                  onClick={() => {
                    onRequestDeleteCustomField(definition);
                    setOpenMenu(null);
                  }}
                  className={`${MENU_ITEM} text-[color:var(--p-danger-text)] disabled:cursor-not-allowed disabled:opacity-45`}
                >
                  <Trash2 size={14} /> Eliminar columna
                  {!canDelete && <Lock size={13} className="ml-auto shrink-0" aria-hidden="true" />}
                </button>
              </>
            )}
          </div>,
          document.body,
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
            <th ref={selectionRef} style={{ left: 0, position: "sticky", zIndex: 70 }} className={`${TH} top-0 w-12 min-w-12 bg-[var(--app-surface)] !px-0 !text-center ${edge}`}>
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
                  onClick={(event) => {
                    setPlace(menuPlace(event.currentTarget, 240, 130 + hidden.length * 34));
                    setAddMenuOpen((open) => !open);
                  }}
                  className="mx-auto grid h-7 w-7 cursor-pointer place-items-center rounded-full border border-[color:var(--app-border)] hover:bg-[#1B8FC9] hover:text-[#fff]"
                  title="Gestionar columnas"
                  aria-label="Gestionar columnas"
                >
                  <Plus size={16} />
                </button>
                {addMenuOpen && createPortal(
                  <div ref={addListRef} style={place} className={`${poppins.className} ${MENU} w-60`}>
                    <button
                      type="button"
                      disabled={!editable}
                      title={editable ? undefined : deniedText("core.manage")}
                      onClick={() => {
                        setAddMenuOpen(false);
                        onAddCustomField();
                      }}
                      className={`${MENU_ITEM} disabled:cursor-not-allowed disabled:opacity-45`}
                    >
                      <Plus size={14} className="app-muted" /> Agregar columna nueva
                      {!editable && <Lock size={13} className="ml-auto shrink-0" aria-hidden="true" />}
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
                  </div>,
                  document.body,
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
              <tr
                key={actor.id}
                tabIndex={0}
                onClick={() => {
                  // Mientras se edita una celda de esta fila, un clic fuera del editor solo la guarda.
                  if (editingRef.current?.id !== actor.id) onOpen(actor.id);
                }}
                onKeyDown={(event) => {
                  if (event.key === "Enter" && event.target === event.currentTarget) onOpen(actor.id);
                }}
                className="group cursor-pointer"
              >
                <td
                  data-label=""
                  style={{ left: 0, position: "sticky", zIndex: 20 }}
                  className={`${TD} w-12 min-w-12 cursor-default !px-0 sm:text-center ${selected ? ROW_BG_SELECTED : ROW_BG} ${edge}`}
                  onClick={(event) => event.stopPropagation()}
                >
                  <input type="checkbox" className={CHECKBOX} checked={selected} onChange={() => onToggleSelection(actor.id)} aria-label="Seleccionar fila" />
                </td>
                {orderedColumns.map((column) => renderCell(actor, column.key, selected))}
                <td
                  data-label=""
                  style={{ right: 0, position: "sticky", zIndex: 20 }}
                  className={`${TD} w-12 cursor-default border-l border-l-[color:var(--app-border)] max-sm:!hidden ${selected ? ROW_BG_SELECTED : ROW_BG}`}
                  onClick={(event) => event.stopPropagation()}
                />
              </tr>
            );
          })}
        </tbody>
      </table>

      {notice && (
        <div className="app-surface app-text fixed bottom-5 left-1/2 z-[90] flex -translate-x-1/2 items-center gap-3 rounded-xl border border-[color:var(--p-success-line)] px-4 py-3 text-sm shadow-[0_20px_50px_rgba(8,25,55,.28)]">
          <span className="grid h-5 w-5 place-items-center rounded-full bg-[var(--p-success-soft)] text-xs font-bold text-[color:var(--p-success-text)]">✓</span>
          <span className="font-medium">{notice.label}</span>
          <button
            type="button"
            onClick={() => {
              notice.undo();
              setNotice(null);
            }}
            className="cursor-pointer font-semibold text-[color:var(--p-brand-text)] hover:underline"
          >
            Deshacer
          </button>
        </div>
      )}
    </div>
  );
}
