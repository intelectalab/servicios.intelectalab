"use client";

import {
  useCallback, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type ButtonHTMLAttributes, type CSSProperties, type DragEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";
import {
  AlertCircle, ArrowDown, ArrowUp, Check, Filter as FilterIcon, ChevronDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, GripVertical, Info, Loader2, Lock, MoreVertical, Pin, PinOff,
  Search, Upload, X,
} from "lucide-react";
import Link from "next/link";
import { useAccess } from "@/lib/access-context";
import { poppins } from "@/lib/fonts";
import { useCardDrag } from "./sortable-cards";

/**
 * Piezas de interfaz con las medidas y la estructura del prototipo (page-head,
 * card, tbl, badge, tabs, kpi, modal…), con los colores del logo en lugar del
 * azul marino y el naranja. Los fondos y bordes salen de las variables --app-*
 * para que el tema oscuro siga funcionando.
 */

const SHADOW = "shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)]";

export function Screen({ children }: { children: ReactNode }) {
  return <div className={`${poppins.className} app-text text-sm leading-[1.45]`}>{children}</div>;
}

/** Como en el prototipo, el título vive en la cabecera: aquí solo se muestra si `showTitle`. */
export function PageHead({
  title, sub, actions, showTitle = false,
}: { title: string; sub?: ReactNode; actions?: ReactNode; showTitle?: boolean }) {
  return (
    <div className="mb-[18px] flex flex-wrap items-center justify-between gap-4">
      <div className="min-w-0 flex-[1_1_380px]">
        <h1 className={showTitle ? "text-[19px] font-semibold leading-tight tracking-[-0.01em] sm:text-[22px]" : "sr-only"}>{title}</h1>
        {sub && <p className={`app-muted max-w-[78ch] text-[13px] ${showTitle ? "mt-1" : ""}`}>{sub}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Card({
  title, children, flush = false, className = "", actions,
}: { title?: ReactNode; children: ReactNode; flush?: boolean; className?: string; actions?: ReactNode }) {
  // Dentro de un SortableBoard la tarjeta lleva un asa para moverla.
  const drag = useCardDrag();
  return (
    <section
      className={`app-surface mb-[18px] min-w-0 overflow-hidden rounded-xl outline outline-2 transition-[box-shadow,outline-color,scale] duration-200 ease-out ${
        drag?.dragging ? "scale-[1.012] outline-[#1B8FC9] shadow-[0_14px_34px_rgba(8,25,55,.22)]" : `outline-transparent ${SHADOW}`
      } ${className}`}
    >
      {title && (
        <div className="flex items-start justify-between gap-2 px-5 pt-4">
          <h2 className="min-w-0 text-[15px] font-semibold">{title}</h2>
          {actions && <div className="ml-auto flex flex-wrap items-center justify-end gap-1.5">{actions}</div>}
          {drag && (
            <button
              type="button"
              {...drag.handleProps}
              title="Arrastra para mover esta tarjeta (o usa las flechas del teclado)"
              aria-label="Mover tarjeta"
              className={`app-muted -mr-2 -mt-1 relative grid h-8 w-8 shrink-0 touch-none place-items-center rounded-lg hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)] ${drag.dragging ? "cursor-grabbing" : "cursor-grab"}`}
            >
              {/* Agranda el área táctil a 44 px sin cambiar el tamaño visible del asa. */}
              <span aria-hidden="true" className="absolute -inset-1.5" />
              <GripVertical size={16} />
            </button>
          )}
        </div>
      )}
      <div className={flush ? "" : "px-5 pb-[18px] pt-4"}>{children}</div>
    </section>
  );
}

export type BtnVariant = "primary" | "secondary" | "ghost" | "danger" | "danger-ghost";

// Fondo, texto y borde van como utilidades (no con .app-surface / .app-muted): esas clases
// ganan a las de "hover:" y el botón se quedaba sin reacción al pasar el cursor.
const BTN_VARIANTS: Record<BtnVariant, string> = {
  primary: "border-transparent bg-[#1B8FC9] text-[#fff] hover:bg-[#1679AB]",
  secondary: "border-[color:var(--app-border)] bg-[var(--app-surface)] text-[color:var(--app-text)] hover:border-[#1B8FC9] hover:bg-[var(--app-surface-muted)]",
  ghost: "border-transparent bg-transparent text-[color:var(--app-muted)] hover:bg-[var(--app-surface-muted)] hover:text-[color:var(--app-text)]",
  danger: "border-transparent bg-[#E5484D] text-[#fff] hover:bg-[#D93A40]",
  "danger-ghost": "border-[color:var(--p-danger-line)] bg-transparent text-[color:var(--p-danger-text)] hover:bg-[var(--p-danger-soft)]",
};

export function btnClass(variant: BtnVariant = "primary", size: "md" | "sm" = "md", block = false) {
  const sizing = size === "sm" ? "h-[30px] rounded-[7px] px-[11px] text-xs" : "h-[38px] rounded-[9px] px-4 text-[13px]";
  return `inline-flex cursor-pointer items-center gap-[7px] whitespace-nowrap border font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-45 ${sizing} ${BTN_VARIANTS[variant]} ${block ? "w-full justify-center" : ""}`;
}

/** Mensaje que ve quien pasa el cursor por una acción que su rol no permite. */
export const deniedText = (permission: string) => `Tu rol no tiene permiso ${permission}`;

/**
 * Si se indica `permission` y el rol del usuario no lo tiene, devuelve el mensaje a mostrar.
 * Como en el prototipo, la acción no se esconde: queda a la vista, deshabilitada, con candado y su motivo.
 */
export function useDenied(permission?: string) {
  const { can, loading } = useAccess();
  return permission && !loading && !can(permission) ? deniedText(permission) : undefined;
}

/** Enlace con aspecto de botón; sin el permiso queda deshabilitado, con candado y su motivo. */
export function LinkBtn({
  href, permission, variant = "primary", size = "md", children,
}: { href: string; permission?: string; variant?: BtnVariant; size?: "md" | "sm"; children: ReactNode }) {
  const denied = useDenied(permission);
  if (!denied) return <Link href={href} className={btnClass(variant, size)}>{children}</Link>;

  return (
    <span className={`${btnClass(variant, size)} cursor-not-allowed opacity-45`} aria-disabled="true" title={denied}>
      {children}
      <Lock size={13} className="-ml-0.5 shrink-0" aria-hidden="true" />
    </span>
  );
}

export function Btn({
  variant = "primary", size = "md", block = false, className = "", type = "button", permission, disabled, title, children, ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: BtnVariant;
  size?: "md" | "sm";
  block?: boolean;
  /** Permiso que exige la acción: sin él el botón queda deshabilitado, con candado y el motivo al pasar el cursor. */
  permission?: string;
}) {
  const denied = useDenied(permission);
  if (denied) {
    return (
      <button type={type} className={`${btnClass(variant, size, block)} ${className}`} {...props} disabled aria-disabled="true" title={denied}>
        {children}
        <Lock size={13} className="-ml-0.5 shrink-0" aria-hidden="true" />
      </button>
    );
  }


  return (
    <button type={type} className={`${btnClass(variant, size, block)} ${className}`} {...props} disabled={disabled} title={title}>
      {children}
    </button>
  );
}

export type Tone = "neutral" | "info" | "success" | "warning" | "danger" | "muted" | "accent";

const TONES: Record<Tone, string> = {
  neutral: "bg-[var(--p-neutral-soft)] text-[color:var(--p-neutral-text)]",
  info: "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]",
  success: "bg-[var(--p-success-soft)] text-[color:var(--p-success-text)]",
  warning: "bg-[var(--p-warning-soft)] text-[color:var(--p-warning-text)]",
  danger: "bg-[var(--p-danger-soft)] text-[color:var(--p-danger-text)]",
  muted: "bg-[var(--p-muted-soft)] text-[color:var(--p-muted-text)]",
  accent: "bg-[var(--p-brand-soft)] text-[color:var(--p-brand-text)]",
};

/** Estado de un registro: píldora con punto. */
export function Badge({ tone, children }: { tone: Tone; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center gap-[5px] whitespace-nowrap rounded-full py-0.5 pl-2 pr-2.5 text-[11.5px] font-semibold leading-normal ${TONES[tone]}`}>
      <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />
      {children}
    </span>
  );
}

/** Etiqueta informativa (no es un estado): rectángulo sin punto. */
export function Chip({ tone = "neutral", children }: { tone?: Tone; children: ReactNode }) {
  return (
    <span className={`inline-flex items-center whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold leading-normal ${TONES[tone]}`}>
      {children}
    </span>
  );
}

/** Persona con su avatar de iniciales, como en las columnas "Responsable" del prototipo. */
export function Who({ name }: { name?: string | null }) {
  if (!name) return <span className="app-muted">Sin asignar</span>;
  const initials = name.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
      <span aria-hidden="true" className="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full bg-[var(--p-brand-soft)] text-[9.5px] font-semibold text-[color:var(--p-brand-soft-text)]">
        {initials}
      </span>
      <span>{name}</span>
    </span>
  );
}

export function Filters({ children }: { children: ReactNode }) {
  return (
    <div role="search" className={`app-surface mb-4 flex flex-wrap items-center gap-2.5 rounded-xl px-3.5 py-3 ${SHADOW}`}>
      {children}
    </div>
  );
}

/**
 * Buscador de un listado. "/" lleva el cursor aquí desde cualquier parte de la pantalla (salvo
 * al escribir en otro campo o con un modal abierto). Escape borra lo escrito y, ya vacío, suelta
 * el campo.
 */
export function SearchInput({
  value, onChange, placeholder,
}: { value: string; onChange: (value: string) => void; placeholder: string }) {
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if (event.key !== "/" || event.defaultPrevented || event.ctrlKey || event.metaKey || event.altKey) return;
      const target = event.target as HTMLElement | null;
      if (target?.closest("input, textarea, select, [contenteditable='true'], [role='combobox'], [role='listbox']")) return;
      const input = inputRef.current;
      // Oculto (otra pestaña de la pantalla) o tapado por un modal: no le toca.
      if (!input || !input.offsetParent || document.querySelector('[aria-modal="true"]')) return;
      event.preventDefault();
      input.focus();
      input.select();
    }
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);

  return (
    <label className="app-muted flex h-10 flex-[1_1_260px] items-center sm:max-w-[360px] gap-2 rounded-[9px] border border-transparent bg-[var(--app-surface-muted)] px-2.5 focus-within:border-[#1B8FC9] focus-within:bg-[var(--app-surface)]">
      <Search size={18} className="shrink-0" />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        title="Pulsa / para buscar; Esc para salir"
        ref={inputRef}
        onKeyDown={(event) => {
          if (event.key !== "Escape") return;
          // Sin esto el navegador decide: unos borran el texto y otros no hacen nada.
          event.preventDefault();
          if (value) onChange("");
          else event.currentTarget.blur();
        }}
        className="app-text h-[34px] min-w-0 flex-1 border-0 bg-transparent text-[13px] outline-none"
      />
    </label>
  );
}

export function FilterSelect({
  label, value, onChange, options,
}: { label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
  return (
    // `relative`: la etiqueta oculta se coloca respecto del filtro; si no, bajo el pliegue añade una franja vacía a la página.
    <label className="relative flex-[1_1_140px] sm:flex-none">
      <span className="sr-only">{label}</span>
      <select
        value={value}
        onChange={(event) => onChange(event.target.value)}
        aria-label={label}
        className="app-surface app-text h-10 w-full cursor-pointer rounded-[9px] sm:w-auto sm:max-w-[220px] border border-[color:var(--app-border)] pl-2.5 pr-7 text-[13px] outline-none focus:border-[#1B8FC9]"
      >
        <option value="">{label}: todos</option>
        {options.map(([optionValue, optionLabel]) => (
          <option key={optionValue} value={optionValue}>{optionLabel}</option>
        ))}
      </select>
    </label>
  );
}

export function Tabs({
  tabs, value, onChange,
}: { tabs: [string, string, number | null][]; value: string; onChange: (value: string) => void }) {
  return (
    <div role="tablist" className="mb-3.5 flex flex-wrap gap-0.5 overflow-x-auto border-b sm:flex-nowrap border-[color:var(--app-border)] [scrollbar-width:none]">
      {tabs.map(([key, label, count]) => {
        const on = key === value;
        return (
          <button
            key={key}
            role="tab"
            type="button"
            aria-selected={on}
            onClick={() => onChange(key)}
            className={`-mb-px cursor-pointer whitespace-nowrap border-b-2 px-3 py-[9px] font-medium ${
              on ? "border-[#1B8FC9] text-[color:var(--p-brand-text)]" : "app-muted border-transparent hover:text-[var(--app-text)]"
            }`}
          >
            {label}
            {count != null && (
              <span className={`ml-[5px] rounded-[9px] px-1.5 text-[11px] ${on ? "bg-[#1B8FC9] text-[#fff]" : "bg-[var(--app-surface-muted)]"}`}>
                {count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}

type KpiTone = "brand" | "warning" | "success" | "danger";

const KPI_TONES: Record<KpiTone, [string, string]> = {
  brand: ["border-l-[#1B8FC9]", "bg-[var(--p-brand-soft)] text-[color:var(--p-brand)]"],
  warning: ["border-l-[#B26E00]", "bg-[var(--p-warning-soft)] text-[color:var(--p-warning-text)]"],
  success: ["border-l-[#22A55A]", "bg-[var(--p-success-soft)] text-[color:var(--p-success)]"],
  danger: ["border-l-[#E5484D]", "bg-[var(--p-danger-soft)] text-[#E5484D]"],
};

/** `row`: la fila compacta de las fichas del prototipo (indicadores sin ícono, todos en una línea). */
export function Kpis({ children, row = false }: { children: ReactNode; row?: boolean }) {
  return <div className={`mb-4 grid gap-3 ${row ? "grid-cols-[repeat(auto-fit,minmax(150px,1fr))]" : "grid-cols-[repeat(auto-fit,minmax(210px,1fr))]"}`}>{children}</div>;
}

export function Kpi({
  label, value, sub, icon, tone = "brand",
}: { label: string; value: ReactNode; sub?: ReactNode; icon?: ReactNode; tone?: KpiTone }) {
  const [border, iconTone] = KPI_TONES[tone];
  return (
    <div className={`app-surface flex min-w-0 items-center gap-2.5 rounded-xl border-l-4 p-4 ${border} ${SHADOW}`}>
      {icon && <span className={`grid h-[46px] w-[46px] shrink-0 place-items-center rounded-xl ${iconTone}`}>{icon}</span>}
      <div className="grid min-w-0 flex-1 gap-0.5">
        <span className="app-muted text-[10.5px] font-semibold uppercase tracking-[.04em]">{label}</span>
        <span className="text-[21px] font-semibold leading-[1.15] tabular-nums sm:text-2xl">{value}</span>
        {sub && <span className="app-muted text-[11.5px]">{sub}</span>}
      </div>
    </div>
  );
}

export type Column<T> = {
  label: string;
  num?: boolean;
  /** Clave con la que el listado pide el orden a la API; sin ella la columna no se ordena. */
  sortKey?: string;
  /**
   * "Filtrar por esta columna": el tipo de filtro que agrega (lo aplica la API, con `sortKey` como
   * nombre de la columna), o `{ top }` si la columna ya tiene su filtro en la barra de arriba.
   */
  filter?: "text" | "number" | "date" | "select" | { top: string };
  render: (row: T) => ReactNode;
};

export const TH = "app-muted whitespace-nowrap border-b border-[color:var(--app-border)] px-3.5 pb-3 pt-3.5 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
export const TD = "border-b border-[color:var(--app-border)] px-3.5 py-[13px] align-middle";
const NUM = "text-right tabular-nums whitespace-nowrap";

/** Orden pedido desde la cabecera de una tabla; `null` es el orden habitual del listado. */
export type SortState = { key: string; dir: "asc" | "desc" } | null;

/** Añade el orden a los parámetros de la petición del listado. */
export function applySort(params: URLSearchParams, sort: SortState) {
  if (sort) {
    params.set("sort", sort.key);
    params.set("dir", sort.dir);
  }
  return params;
}

export type Selection<T> = {
  ids: ReadonlySet<string>;
  onToggle: (row: T) => void;
  /** Marca o desmarca todas las filas visibles. */
  onToggleAll: (rows: T[], checked: boolean) => void;
};

const CHECKBOX = "h-4 w-4 cursor-pointer rounded border-[color:var(--p-field-border)] accent-[#1B8FC9]";

export function DataTable<T>({
  cols, rows, rowKey, onRowClick, selection, loading = false, empty = "No hay registros con estos filtros.", dense = false, tableId,
  sort = null, onSort, onFilter, refreshing = false,
}: {
  /** Se eligió "Filtrar por esta columna" en una columna que declara `filter`. */
  onFilter?: (column: Column<T>) => void;
  /** Orden actual y cómo cambiarlo (lo aplica la API, sobre todo el listado). */
  sort?: SortState;
  onSort?: (sort: SortState) => void;
  /** Hay una carga en curso con filas ya a la vista: se atenúan hasta que llegue la respuesta. */
  refreshing?: boolean;
  /** Con un nombre propio, la tabla es la de los listados: cabecera fija y columnas que se mueven e inmovilizan. */
  tableId?: string;
  cols: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  onRowClick?: (row: T) => void;
  selection?: Selection<T>;
  loading?: boolean;
  empty?: string;
  dense?: boolean;
}) {
  if (loading) {
    return (
      <div className="space-y-3 p-5" aria-busy="true">
        {[0, 1, 2, 3].map((n) => (
          <div key={n} className="h-5 animate-pulse rounded bg-[var(--app-surface-muted)]" />
        ))}
      </div>
    );
  }

  if (!rows.length) return <Empty text={empty} />;

  if (tableId) {
    return (
      <ColumnTable
        tableId={tableId} cols={cols} rows={rows} rowKey={rowKey} onRowClick={onRowClick} selection={selection} dense={dense}
        sort={sort} onSort={onSort} onFilter={onFilter} refreshing={refreshing}
      />
    );
  }

  const allSelected = selection ? rows.every((row) => selection.ids.has(rowKey(row))) : false;

  return (
    <div className="proto-tbl-wrap overflow-x-auto">
      <table className="proto-tbl w-full border-collapse text-[13px]">
        <thead>
          <tr>
            {selection && (
              <th className={`${TH} w-10 !pr-0`}>
                <input
                  type="checkbox"
                  className={CHECKBOX}
                  checked={allSelected}
                  onChange={(event) => selection.onToggleAll(rows, event.target.checked)}
                  aria-label="Seleccionar todas las filas de esta página"
                />
              </th>
            )}
            {cols.map((col) => (
              <th key={col.label} className={`${TH} ${col.num ? "text-right" : ""}`}>{col.label}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const key = rowKey(row);
            const selected = selection?.ids.has(key) ?? false;
            return (
              <tr
                key={key}
                tabIndex={onRowClick ? 0 : undefined}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                onKeyDown={onRowClick ? (event) => { if (event.key === "Enter" && event.target === event.currentTarget) onRowClick(row); } : undefined}
                className={`[&:last-child>td]:border-b-0 ${selected ? "bg-[rgb(27_143_201/0.08)]" : ""} ${onRowClick ? "cursor-pointer hover:bg-[var(--app-surface-muted)]" : ""}`}
              >
                {selection && (
                  // El clic en la casilla no abre la ficha.
                  <td className={`${TD} w-10 !pr-0`} data-label="" onClick={(event) => event.stopPropagation()}>
                    <input
                      type="checkbox"
                      className={CHECKBOX}
                      checked={selected}
                      onChange={() => selection.onToggle(row)}
                      aria-label="Seleccionar fila"
                    />
                  </td>
                )}
                {cols.map((col) => (
                  <td key={col.label} data-label={col.label} className={`${TD} ${dense ? "!py-[7px]" : ""} ${col.num ? NUM : ""}`}>{col.render(row)}</td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

// --- Tabla de listado con columnas que se mueven e inmovilizan ----------------------

const COL_MENU = "app-surface app-text fixed z-[250] w-56 rounded-xl border border-[color:var(--app-border)] p-1.5 text-left text-xs font-normal normal-case tracking-normal shadow-[0_20px_50px_rgba(8,25,55,.28)]";
const COL_MENU_ITEM = "flex w-full cursor-pointer items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-[var(--app-surface-muted)]";
const CELL_BG = "bg-[var(--app-surface)]";
const CELL_BG_HOVER = "bg-[var(--app-surface)] group-hover:bg-[var(--app-surface-muted)]";
const CELL_BG_SELECTED = "bg-[color-mix(in_srgb,#1B8FC9_13%,var(--app-surface))]";
const COL_SEP = "\u0000";

function readStoredList(key: string): string[] {
  try {
    const parsed = JSON.parse(window.localStorage.getItem(key) ?? "[]");
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

/**
 * La tabla de los listados, con el mismo manejo que la de Clientes y actores:
 * cabecera y casillas siempre a la vista, columnas que se mueven arrastrando
 * (la primera no) y que se inmovilizan desde su menú. Inmovilizar cualquiera
 * inmoviliza también la primera, y queda a su lado. Se recuerda en el navegador.
 */
function ColumnTable<T>({
  tableId, cols, rows, rowKey, onRowClick, selection, dense, sort, onSort, onFilter, refreshing,
}: {
  onFilter?: (column: Column<T>) => void;
  sort: SortState;
  onSort?: (sort: SortState) => void;
  refreshing: boolean;
  tableId: string;
  cols: Column<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  onRowClick?: (row: T) => void;
  selection?: Selection<T>;
  dense: boolean;
}) {
  const orderKey = `table-${tableId}-column-order`;
  const frozenKey = `table-${tableId}-frozen-columns`;
  const first = cols[0]?.label ?? "";

  const [columnOrder, setColumnOrder] = useState<string[]>([]);
  const [frozenColumns, setFrozenColumns] = useState<string[]>([]);
  const [frozenOffsets, setFrozenOffsets] = useState<Record<string, number>>({});
  const [dragged, setDragged] = useState<string | null>(null);
  const [dropIndicator, setDropIndicator] = useState<{ key: string; side: "left" | "right" } | null>(null);
  const [openMenu, setOpenMenu] = useState<string | null>(null);
  const [place, setPlace] = useState<CSSProperties>({});
  const preferencesLoaded = useRef(false);
  const menuRef = useRef<HTMLDivElement>(null);
  const headerRefs = useRef<Record<string, HTMLTableCellElement | null>>({});
  const selectionRef = useRef<HTMLTableCellElement>(null);

  useEffect(() => {
    const frame = window.requestAnimationFrame(() => {
      setColumnOrder(readStoredList(orderKey));
      setFrozenColumns(readStoredList(frozenKey));
      preferencesLoaded.current = true;
    });
    return () => window.cancelAnimationFrame(frame);
  }, [orderKey, frozenKey]);

  useEffect(() => {
    if (!preferencesLoaded.current) return;
    try {
      window.localStorage.setItem(orderKey, JSON.stringify(columnOrder));
      window.localStorage.setItem(frozenKey, JSON.stringify(frozenColumns));
    } catch { /* sin almacenamiento, no se recuerda */ }
  }, [orderKey, frozenKey, columnOrder, frozenColumns]);

  useEffect(() => {
    function onClickOutside(event: MouseEvent) {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) setOpenMenu(null);
    }
    // El menú está en posición fija: al desplazar o cambiar el tamaño se cierra.
    const close = () => setOpenMenu(null);
    document.addEventListener("mousedown", onClickOutside);
    window.addEventListener("scroll", close, true);
    window.addEventListener("resize", close);
    return () => {
      document.removeEventListener("mousedown", onClickOutside);
      window.removeEventListener("scroll", close, true);
      window.removeEventListener("resize", close);
    };
  }, []);

  const labels = cols.map((col) => col.label).join(COL_SEP);

  // La primera columna no se mueve. Se inmoviliza si se pide, o junto con cualquier otra.
  const frozen = useMemo(
    () => (frozenColumns.length ? [first, ...frozenColumns.filter((key) => key !== first)] : []),
    [frozenColumns, first],
  );

  // Orden en que se dibujan: la primera, las inmovilizadas y el resto como lo dejó el usuario.
  const orderedLabels = useMemo(() => {
    const all = labels.split(COL_SEP);
    // Una columna sin título es la de botones de la fila: se queda al final y no se mueve.
    const rest = all.filter((key) => key !== first && key !== "");
    const saved = columnOrder.filter((key) => rest.includes(key));
    const order = [...saved, ...rest.filter((key) => !saved.includes(key))];
    const pinned = frozen.filter((key) => rest.includes(key));
    return [first, ...pinned, ...order.filter((key) => !pinned.includes(key)), ...(all.includes("") ? [""] : [])];
  }, [labels, columnOrder, frozen, first]);

  const byLabel = new Map(cols.map((col) => [col.label, col]));
  const orderedCols = orderedLabels.map((key) => byLabel.get(key)).filter((col): col is Column<T> => Boolean(col));

  const lastFrozen = frozen.findLast((key) => orderedLabels.includes(key));
  const divider = (key: string) => (key === lastFrozen ? "sm:border-r-2 sm:border-r-[color:var(--app-border)]" : "");

  useLayoutEffect(() => {
    const update = () => {
      const offsets: Record<string, number> = {};
      let offset = selectionRef.current?.getBoundingClientRect().width ?? 0;
      for (const key of orderedLabels) {
        if (!frozen.includes(key)) continue;
        offsets[key] = offset;
        offset += headerRefs.current[key]?.getBoundingClientRect().width ?? 0;
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
  }, [orderedLabels, frozen]);

  const frozenStyle = (key: string, header = false): CSSProperties | undefined =>
    frozen.includes(key) ? { left: frozenOffsets[key] ?? 0, position: "sticky", zIndex: header ? 70 : 20 } : undefined;

  function toggleFrozen(key: string) {
    // Liberar la primera libera también las que estaban inmovilizadas a su lado.
    if (key === first) setFrozenColumns((current) => (current.length ? [] : [first]));
    else setFrozenColumns((current) => (current.includes(key) ? current.filter((item) => item !== key) : [...current, key]));
    setOpenMenu(null);
  }

  function onColumnDrop(target: string) {
    if (dragged && dragged !== target && dragged !== first && target !== first) {
      const next = orderedLabels.filter((key) => key !== first && key !== dragged && key !== "");
      const index = next.indexOf(target);
      next.splice(index < 0 ? next.length : index + (dropIndicator?.side === "right" ? 1 : 0), 0, dragged);
      setColumnOrder(next);
    }
    setDragged(null);
    setDropIndicator(null);
  }

  function onColumnDragOver(event: DragEvent<HTMLTableCellElement>, key: string) {
    if (!dragged || dragged === key || key === first || key === "") return;
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

  const allSelected = selection ? rows.every((row) => selection.ids.has(rowKey(row))) : false;
  const edge = frozen.length === 0 ? "sm:border-r sm:border-r-[color:var(--app-border)]" : "";

  return (
    <div
      data-table-scroll
      aria-busy={refreshing}
      className={`proto-tbl-wrap isolate max-h-[calc(100vh-290px)] overflow-auto transition-opacity duration-150 max-sm:max-h-none ${refreshing ? "pointer-events-none opacity-50" : ""}`}
    >
      <table className="proto-tbl w-full border-separate border-spacing-0 text-left text-[13px]">
        <thead>
          <tr>
            {selection && (
              <th ref={selectionRef} style={{ left: 0, position: "sticky", zIndex: 70 }} className={`${TH} top-0 w-12 min-w-12 bg-[var(--app-surface)] !px-0 !text-center ${edge}`}>
                <input
                  type="checkbox"
                  className={CHECKBOX}
                  checked={allSelected}
                  onChange={(event) => selection.onToggleAll(rows, event.target.checked)}
                  aria-label="Seleccionar todas las filas de esta página"
                />
              </th>
            )}
            {orderedCols.map((col) => {
              const key = col.label;
              const isFrozen = frozen.includes(key);
              if (key === "") return <th key={key} className={`${TH} sticky top-0 z-40 bg-[var(--app-surface)]`} />;
              const sortKey = onSort ? col.sortKey : undefined;
              const sorted = sortKey && sort?.key === sortKey ? sort.dir : null;
              const sortBy = (dir: "asc" | "desc" | null) => {
                if (sortKey) onSort?.(dir ? { key: sortKey, dir } : null);
                setOpenMenu(null);
              };
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
                  // Una cabecera larga baja a dos líneas en vez de ensanchar la tabla (a 1366 px se desplazaba de lado).
                  className={`${TH} group/th sticky top-0 !whitespace-normal bg-[var(--app-surface)] ${divider(key)} ${openMenu === key ? "z-[100]" : "z-40"} ${dragged === key ? "opacity-50" : ""}`}
                >
                  <div className={`flex items-center gap-2 pr-3 ${col.num ? "justify-end" : "justify-between"}`}>
                    <div
                      className={`flex select-none items-center gap-1.5 ${sortKey ? "cursor-pointer" : ""}`}
                      title={sortKey ? "Ordenar" : undefined}
                      onClick={sortKey ? () => sortBy(sorted === "asc" ? "desc" : "asc") : undefined}
                    >
                      {key !== first && (
                        <span
                          draggable
                          onDragStart={(event) => {
                            event.stopPropagation();
                            setDragged(key);
                          }}
                          onDragEnd={() => {
                            setDragged(null);
                            setDropIndicator(null);
                          }}
                          className="flex shrink-0 cursor-grab touch-none active:cursor-grabbing"
                          title="Mover columna"
                        >
                          <GripVertical size={13} className="opacity-50 group-hover/th:opacity-100" aria-hidden="true" />
                        </span>
                      )}
                      <span>{col.label}</span>
                      {sorted && <span className="font-bold text-[color:var(--p-brand)]">{sorted === "asc" ? "↑" : "↓"}</span>}
                    </div>
                    <button
                      type="button"
                      aria-label={`Opciones de la columna ${col.label}`}
                      onClick={(event) => {
                        event.stopPropagation();
                        const rect = event.currentTarget.getBoundingClientRect();
                        setPlace({ left: Math.max(8, Math.min(rect.left, window.innerWidth - 232)), top: rect.bottom + 6 });
                        setOpenMenu(openMenu === key ? null : key);
                      }}
                      // Sobre el borde de la cabecera, sin ocupar ancho: el espacio que deja el pr-3 del título.
                      className={`absolute right-1 top-1/2 -translate-y-1/2 cursor-pointer rounded p-1 hover:bg-[var(--app-surface-muted)] ${sorted || openMenu === key ? "opacity-100" : "opacity-0 group-hover/th:opacity-100 focus:opacity-100"}`}
                    >
                      <MoreVertical size={14} />
                    </button>
                  </div>
                  {dropIndicator?.key === key && (
                    <span aria-hidden="true" className={`pointer-events-none absolute top-0 z-[210] h-full w-1 rounded-full bg-[#1B8FC9] ${dropIndicator.side === "left" ? "left-0" : "right-0"}`} />
                  )}
                  {openMenu === key && createPortal(
                    <div ref={menuRef} style={place} className={`${poppins.className} ${COL_MENU}`}>
                      {sortKey && (
                        <>
                          <button type="button" onClick={() => sortBy("asc")} className={COL_MENU_ITEM}><ArrowUp size={14} className="app-muted" /> Orden ascendente</button>
                          <button type="button" onClick={() => sortBy("desc")} className={COL_MENU_ITEM}><ArrowDown size={14} className="app-muted" /> Orden descendente</button>
                          {sorted && <button type="button" onClick={() => sortBy(null)} className={COL_MENU_ITEM}><X size={14} className="app-muted" /> Quitar orden</button>}
                          <div className="my-1 border-t border-[color:var(--app-border)]" />
                        </>
                      )}
                      <button type="button" onClick={() => toggleFrozen(key)} className={COL_MENU_ITEM}>
                        {isFrozen ? <PinOff size={14} className="app-muted" /> : <Pin size={14} className="app-muted" />}
                        {isFrozen ? "Desinmovilizar columna" : "Inmovilizar columna"}
                      </button>
                      {col.filter && onFilter && (
                        <button
                          type="button"
                          onClick={() => {
                            onFilter(col);
                            setOpenMenu(null);
                          }}
                          className={COL_MENU_ITEM}
                        >
                          <FilterIcon size={14} className="app-muted" /> Filtrar por esta columna
                        </button>
                      )}
                    </div>,
                    document.body,
                  )}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const key = rowKey(row);
            const selected = selection?.ids.has(key) ?? false;
            const background = selected ? CELL_BG_SELECTED : onRowClick ? CELL_BG_HOVER : CELL_BG;
            return (
              <tr
                key={key}
                tabIndex={onRowClick ? 0 : undefined}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                onKeyDown={onRowClick ? (event) => { if (event.key === "Enter" && event.target === event.currentTarget) onRowClick(row); } : undefined}
                className={`group [&:last-child>td]:border-b-0 ${onRowClick ? "cursor-pointer" : ""}`}
              >
                {selection && (
                  // El clic en la casilla no abre la ficha.
                  <td
                    data-label=""
                    style={{ left: 0, position: "sticky", zIndex: 20 }}
                    className={`${TD} w-12 min-w-12 !px-0 sm:text-center ${background} ${edge}`}
                    onClick={(event) => event.stopPropagation()}
                  >
                    <input
                      type="checkbox"
                      className={CHECKBOX}
                      checked={selected}
                      onChange={() => selection.onToggle(row)}
                      aria-label="Seleccionar fila"
                    />
                  </td>
                )}
                {orderedCols.map((col) => (
                  <td
                    key={col.label}
                    data-label={col.label}
                    style={frozenStyle(col.label)}
                    className={`${TD} ${background} ${divider(col.label)} ${dense ? "!py-[7px]" : ""} ${col.num ? NUM : ""}`}
                  >
                    {col.render(row)}
                  </td>
                ))}
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

/**
 * Selección de filas que se conserva al cambiar de página o de filtro: guarda
 * el registro completo para poder exportarlo aunque ya no esté a la vista.
 */
export function useRowSelection<T>(rowKey: (row: T) => string) {
  const [selected, setSelected] = useState<Map<string, T>>(new Map());

  const selection: Selection<T> = {
    ids: new Set(selected.keys()),
    onToggle: (row) =>
      setSelected((current) => {
        const next = new Map(current);
        const key = rowKey(row);
        if (!next.delete(key)) next.set(key, row);
        return next;
      }),
    onToggleAll: (rows, checked) =>
      setSelected((current) => {
        const next = new Map(current);
        rows.forEach((row) => (checked ? next.set(rowKey(row), row) : next.delete(rowKey(row))));
        return next;
      }),
  };

  return { selection, selectedRows: [...selected.values()], clear: () => setSelected(new Map()) };
}

/** Barra que aparece sobre la tabla cuando hay filas marcadas. */
export function SelectionBar({
  count, busy, onExport, onClear, permission,
}: {
  count: number;
  busy: boolean;
  onExport: (format: "xlsx" | "csv") => void;
  onClear: () => void;
  /** Permiso de exportar del listado: sin él los botones quedan con candado y su motivo. */
  permission?: string;
}) {
  if (!count) return null;

  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-[color:var(--p-brand-line)] bg-[var(--p-brand-soft)] px-3.5 py-2 text-[13px] text-[color:var(--p-brand-soft-text)]">
      <span className="grid h-6 min-w-6 place-items-center rounded-full bg-[#1B8FC9] px-1.5 text-xs font-semibold text-[#fff]">{count}</span>
      <span className="mr-auto font-medium">{count === 1 ? "fila seleccionada" : "filas seleccionadas"}</span>
      <Btn size="sm" disabled={busy} onClick={() => onExport("xlsx")} permission={permission}>
        <Upload size={14} />
        <span>{busy ? "Exportando…" : "Exportar selección a Excel"}</span>
      </Btn>
      <Btn variant="secondary" size="sm" disabled={busy} onClick={() => onExport("csv")} permission={permission}>CSV</Btn>
      <button type="button" onClick={onClear} className="cursor-pointer px-1.5 text-xs font-medium underline-offset-2 hover:underline">
        Quitar selección
      </button>
    </div>
  );
}

export function Empty({ text }: { text: string }) {
  return (
    <div className="app-muted px-4 py-7 text-center">
      <Search size={28} className="mx-auto mb-1.5" />
      <p>{text}</p>
    </div>
  );
}

export const PAGE_SIZES = [10, 25, 50, 100];

/** Números de página a mostrar, con "…" donde se saltan páginas. */
function pageNumbers(page: number, lastPage: number) {
  const visible: number[] = [];
  for (let n = 1; n <= lastPage; n++) {
    if (n === 1 || n === lastPage || Math.abs(n - page) <= 2) visible.push(n);
  }

  const withGaps: (number | "gap")[] = [];
  visible.forEach((n, index) => {
    const previous = visible[index - 1];
    if (previous !== undefined) {
      if (n - previous === 2) withGaps.push(previous + 1);
      else if (n - previous > 2) withGaps.push("gap");
    }
    withGaps.push(n);
  });
  return withGaps;
}

const PAGE_BTN = "grid h-8 min-w-8 cursor-pointer place-items-center rounded-lg border px-1.5 text-[12.5px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-40";
// Sin "app-surface": esa clase gana a las de hover y el botón no cambiaba al pasar el cursor.
const PAGE_BTN_IDLE = "border-[color:var(--app-border)] bg-[var(--app-surface)] text-[color:var(--app-text)] enabled:hover:border-[#1B8FC9] enabled:hover:bg-[rgb(27_143_201/0.14)] enabled:hover:text-[color:var(--p-brand)] enabled:active:scale-90";
const PAGE_BTN_ON = "border-[#1B8FC9] bg-[#1B8FC9] text-[#fff]";

/** Pie de un listado: exportar, "Mostrando X a Y de N", filas por página y páginas numeradas. */
export function Pager({
  page, lastPage, total, perPage, onPage, onPerPage, left, loading,
}: {
  /** Carga en curso: la página pulsada se marca al instante, con un indicador, hasta que llega. */
  loading?: boolean;
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPage: (page: number) => void;
  onPerPage: (perPage: number) => void;
  left?: ReactNode;
}) {
  // Página recién pulsada: se muestra como elegida sin esperar a la respuesta.
  const [pending, setPending] = useState<{ target: number; from: number } | null>(null);
  const waiting = pending && pending.from === page && loading !== false ? pending.target : null;
  const shown = waiting ?? page;

  if (!total) return null;
  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);
  const go = (target: number) => {
    if (target === shown) return;
    setPending({ target, from: page });
    onPage(target);
  };
  const jump = (target: number, label: string, icon: ReactNode, disabled: boolean) => (
    <button type="button" onClick={() => go(target)} disabled={disabled} title={label} aria-label={label} className={`${PAGE_BTN} ${PAGE_BTN_IDLE}`}>
      {icon}
    </button>
  );

  return (
    <div className="app-muted flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-[color:var(--app-border)] px-3.5 py-3 text-[12.5px]">
      {left}
      <span className={left ? "" : "mr-auto"}>
        Mostrando <strong className="app-text font-semibold">{from}</strong> a <strong className="app-text font-semibold">{to}</strong> de{" "}
        <strong className="app-text font-semibold">{total}</strong>
        {loading && <Loader2 size={13} className="ml-2 inline animate-spin text-[color:var(--p-brand)]" aria-label="Cargando" />}
      </span>
      <label className="flex items-center gap-1.5">
        Filas por página:
        <select
          value={perPage}
          onChange={(event) => onPerPage(Number(event.target.value))}
          className="app-surface app-text h-8 cursor-pointer rounded-lg border border-[color:var(--app-border)] px-1.5 outline-none focus:border-[#1B8FC9]"
        >
          {PAGE_SIZES.map((size) => (
            <option key={size} value={size}>{size}</option>
          ))}
        </select>
      </label>
      <nav aria-label="Paginación" className="flex items-center gap-1">
        {jump(1, "Primera página", <ChevronsLeft size={15} />, shown <= 1)}
        {jump(shown - 1, "Página anterior", <ChevronLeft size={15} />, shown <= 1)}
        {pageNumbers(shown, lastPage).map((n, index) =>
          n === "gap" ? (
            <span key={`gap-${index}`} className="grid h-8 w-6 place-items-center">…</span>
          ) : (
            <button
              key={n}
              type="button"
              onClick={() => go(n)}
              aria-current={n === shown ? "page" : undefined}
              className={`${PAGE_BTN} ${n === shown ? PAGE_BTN_ON : PAGE_BTN_IDLE}`}
            >
              {n === waiting ? <Loader2 size={13} className="animate-spin" /> : n}
            </button>
          ),
        )}
        {jump(shown + 1, "Página siguiente", <ChevronRight size={15} />, shown >= lastPage)}
        {jump(lastPage, "Última página", <ChevronsRight size={15} />, shown >= lastPage)}
      </nav>
    </div>
  );
}

export function Modal({
  title, size = "md", onClose, children, footer,
}: { title: string; size?: "sm" | "md" | "lg"; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") onClose(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [onClose]);

  const width = size === "sm" ? "w-[min(440px,calc(100vw-32px))]" : size === "lg" ? "w-[min(820px,calc(100vw-32px))]" : "w-[min(640px,calc(100vw-32px))]";

  return (
    <div className={`${poppins.className} fixed inset-0 z-[300] text-sm`}>
      <div className="proto-fade-in absolute inset-0 bg-[rgb(5_20_45/0.55)]" onClick={onClose} />
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className={`proto-pop-in app-surface app-text absolute left-1/2 top-[6vh] flex max-h-[88vh] -translate-x-1/2 flex-col overflow-hidden rounded-[14px] shadow-[0_20px_50px_rgba(8,25,55,.28)] ${width}`}
      >
        <header className="flex items-center justify-between gap-2.5 bg-[#1B8FC9] px-[18px] py-3.5 text-[#fff]">
          <h3 className="text-base font-medium">{title}</h3>
          <button
            type="button"
            onClick={onClose}
            aria-label="Cerrar"
            className="grid h-9 w-9 cursor-pointer place-items-center rounded-[10px] hover:bg-[rgb(255_255_255/0.12)]"
          >
            <X size={18} />
          </button>
        </header>
        <div className="overflow-y-auto px-[18px] py-4">{children}</div>
        {footer && (
          <footer className="flex flex-wrap justify-end gap-2 border-t border-[color:var(--app-border)] px-[18px] py-3">{footer}</footer>
        )}
      </div>
    </div>
  );
}

export const INPUT = "app-surface app-text min-h-10 w-full rounded-[9px] border border-[color:var(--p-field-border)] px-2.5 py-2 text-[13.5px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)] disabled:bg-[var(--app-surface-muted)]";

export type SelectOption = { value: string; label: string; hint?: string; disabled?: boolean };

/** Sin tildes ni mayúsculas, para que "garcia" encuentre "García". */
const plain = (text: string) => text.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase();

/**
 * Desplegable con buscador, para listas largas (clientes, servicios). Se usa como un
 * `select`: `value` + `onChange`. La lista se pinta fuera de la tarjeta para que no se recorte.
 */
export function SearchSelect({
  value, onChange, options, placeholder = "Seleccionar…", searchPlaceholder = "Buscar…", empty = "Sin resultados.", disabled = false,
}: {
  value: string;
  onChange: (value: string) => void;
  options: SelectOption[];
  placeholder?: string;
  searchPlaceholder?: string;
  empty?: string;
  disabled?: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(-1);
  const [box, setBox] = useState<CSSProperties>({});
  const trigger = useRef<HTMLButtonElement>(null);
  const panel = useRef<HTMLDivElement>(null);
  const listId = useId();

  const selected = options.find((option) => option.value === value);
  const needle = plain(query.trim());
  const shown = needle ? options.filter((option) => plain(`${option.label} ${option.hint ?? ""}`).includes(needle)) : options;

  // La lista se abre hacia donde haya más sitio y sigue al campo si la página se mueve.
  const place = useCallback(() => {
    const rect = trigger.current?.getBoundingClientRect();
    if (!rect) return;
    const below = window.innerHeight - rect.bottom - 12;
    const above = rect.top - 12;
    const up = below < 240 && above > below;
    setBox({
      left: rect.left,
      width: rect.width,
      maxHeight: Math.max(160, Math.min(340, up ? above : below)),
      ...(up ? { bottom: window.innerHeight - rect.top + 4 } : { top: rect.bottom + 4 }),
    });
  }, []);

  useEffect(() => {
    if (!open) return;
    const outside = (event: MouseEvent) => {
      const target = event.target as Node;
      if (!trigger.current?.contains(target) && !panel.current?.contains(target)) setOpen(false);
    };
    const follow = (event: Event) => {
      // El desplazamiento de la propia lista no la mueve de sitio.
      if (event.type === "scroll" && panel.current?.contains(event.target as Node)) return;
      place();
    };
    document.addEventListener("mousedown", outside);
    window.addEventListener("scroll", follow, true);
    window.addEventListener("resize", follow);
    return () => {
      document.removeEventListener("mousedown", outside);
      window.removeEventListener("scroll", follow, true);
      window.removeEventListener("resize", follow);
    };
  }, [open, place]);

  function toggle() {
    if (open) {
      setOpen(false);
      return;
    }
    place();
    setQuery("");
    setActive(-1);
    setOpen(true);
  }

  function pick(option: SelectOption) {
    if (option.disabled) return;
    onChange(option.value);
    setOpen(false);
    trigger.current?.focus();
  }

  function move(step: 1 | -1) {
    let index = active;
    for (let tries = 0; tries < shown.length; tries++) {
      index = (index + step + shown.length) % shown.length;
      if (!shown[index].disabled) break;
    }
    if (!shown[index] || shown[index].disabled) return;
    setActive(index);
    window.requestAnimationFrame(() => panel.current?.querySelector(`[data-index="${index}"]`)?.scrollIntoView({ block: "nearest" }));
  }

  function onKeyDown(event: ReactKeyboardEvent<HTMLInputElement>) {
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      move(event.key === "ArrowDown" ? 1 : -1);
    } else if (event.key === "Enter") {
      event.preventDefault();
      const option = shown[active] ?? (shown.length === 1 ? shown[0] : undefined);
      if (option) pick(option);
    } else if (event.key === "Escape") {
      event.preventDefault();
      setOpen(false);
      trigger.current?.focus();
    } else if (event.key === "Tab") {
      setOpen(false);
    }
  }

  return (
    <>
      <button
        ref={trigger}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        disabled={disabled}
        onClick={toggle}
        className={`${INPUT} flex cursor-pointer items-center justify-between gap-2 text-left disabled:cursor-default`}
      >
        <span className={`min-w-0 truncate ${selected ? "" : "app-muted"}`}>
          {selected ? <>{selected.label}{selected.hint && <span className="app-muted"> · {selected.hint}</span>}</> : placeholder}
        </span>
        <ChevronDown size={16} className={`app-muted shrink-0 transition-transform duration-200 ${open ? "rotate-180" : ""}`} />
      </button>
      {open && createPortal(
        <div
          ref={panel}
          style={box}
          className={`${poppins.className} app-surface app-text fixed z-[350] flex flex-col overflow-hidden rounded-[10px] border border-[color:var(--app-border)] text-[13.5px] shadow-[0_14px_34px_rgba(8,25,55,.22)]`}
        >
          <div className="app-muted flex items-center gap-2 border-b border-[color:var(--app-border)] px-2.5">
            <Search size={16} className="shrink-0" />
            <input
              autoFocus
              type="search"
              value={query}
              onChange={(event) => {
                setQuery(event.target.value);
                setActive(-1);
              }}
              onKeyDown={onKeyDown}
              placeholder={searchPlaceholder}
              aria-label={searchPlaceholder}
              className="app-text h-10 min-w-0 flex-1 border-0 bg-transparent outline-none"
            />
          </div>
          <div id={listId} role="listbox" className="min-h-0 flex-1 overflow-y-auto [scrollbar-width:thin]">
            {shown.length ? (
              shown.map((option, index) => (
                <div
                  key={option.value}
                  role="option"
                  data-index={index}
                  aria-selected={option.value === value}
                  aria-disabled={option.disabled}
                  onMouseEnter={() => !option.disabled && setActive(index)}
                  onClick={() => pick(option)}
                  title={option.label}
                  className={`grid gap-px border-b border-[color:color-mix(in_srgb,var(--app-border)_55%,transparent)] px-3 py-2 last:border-b-0 ${
                    option.disabled ? "app-muted cursor-not-allowed opacity-60" : "cursor-pointer"
                  } ${index === active ? "bg-[var(--app-surface-muted)]" : ""} ${option.value === value ? "font-semibold text-[color:var(--p-brand-text)]" : ""}`}
                >
                  <span className="line-clamp-2 min-w-0 leading-snug">{option.label}</span>
                  {option.hint && <span className="app-muted truncate text-xs font-normal">{option.hint}</span>}
                </div>
              ))
            ) : (
              <p className="app-muted px-3 py-3">{empty}</p>
            )}
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}

export function Field({
  label, required = false, hint, error, span, children,
}: { label: string; required?: boolean; hint?: ReactNode; error?: string; span?: 2 | 3; children: ReactNode }) {
  return (
    <label className={`flex min-w-0 flex-col gap-[5px] ${span === 2 ? "md:col-span-2" : span === 3 ? "md:col-span-3" : ""}`}>
      <span className="text-[12.5px] font-medium">
        {label} {required && <em className="not-italic text-[color:var(--p-danger-text)]">*</em>}
      </span>
      {children}
      {error ? <small className="text-xs text-[color:var(--p-danger-text)]">{error}</small> : hint ? <small className="app-muted text-xs">{hint}</small> : null}
    </label>
  );
}

export function FormGrid({ children }: { children: ReactNode }) {
  return <div className="grid grid-cols-1 gap-x-3.5 gap-y-3 md:grid-cols-3">{children}</div>;
}

/** Lista "etiqueta → valor" de las fichas. */
// Línea suave entre datos, como la del prototipo (más tenue que el borde de las tarjetas).
const KV_LINE = "border-[color:color-mix(in_srgb,var(--app-border)_55%,transparent)]";

export function Kv({ rows }: { rows: ([string, ReactNode] | null)[] }) {
  return (
    <dl className="mb-2 grid grid-cols-1 sm:grid-cols-[minmax(110px,38%)_1fr]">
      {rows.filter((row): row is [string, ReactNode] => row !== null).map(([label, value]) => (
        <div key={label} className="contents">
          <dt className={`app-muted pt-1.5 pr-2.5 text-[12.5px] sm:border-b sm:py-1.5 ${KV_LINE}`}>{label}</dt>
          <dd className={`min-w-0 border-b py-1.5 [overflow-wrap:anywhere] ${KV_LINE}`}>{value}</dd>
        </div>
      ))}
    </dl>
  );
}

/** Recorrido de estados de un registro, con el actual resaltado. */
export function StateTrack({
  states, current, visited = [],
}: { states: [string, string, Tone][]; current: string; visited?: string[] }) {
  return (
    <div className="flex flex-wrap items-center gap-1">
      {states.map(([key, label, tone], index) => (
        <span key={key} className="contents">
          {index > 0 && <i className="h-px w-2.5 bg-[var(--app-border)]" />}
          <span
            title={key}
            className={`rounded-[10px] px-2 py-[3px] text-[11.5px] ${
              key === current
                ? `font-bold shadow-[inset_0_0_0_2px_currentColor] ${TONES[tone]}`
                : visited.includes(key)
                  ? "app-text border border-solid border-[color:var(--app-border)]"
                  : "app-muted border border-dashed border-[color:var(--app-border)]"
            }`}
          >
            {label}
          </span>
        </span>
      ))}
    </div>
  );
}

export type TimelineItem = { key: string; head: ReactNode; meta: string; note?: string | null };

/** Historial de movimientos, del más reciente al más antiguo. */
export function Timeline({ items, empty = "Sin movimientos." }: { items: TimelineItem[]; empty?: string }) {
  if (!items.length) return <p className="app-muted">{empty}</p>;

  return (
    <ol className="mt-2 pl-1">
      {items.map((item, index) => (
        <li key={item.key} className="relative flex gap-2.5 pb-3">
          {index < items.length - 1 && <span className="absolute bottom-[-2px] left-1 top-3 w-px bg-[var(--app-border)]" />}
          <span className="relative mt-1.5 h-[9px] w-[9px] shrink-0 rounded-full bg-[#1B8FC9]" />
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-1.5">{item.head}</div>
            <div className="app-muted text-[12.5px]">{item.meta}</div>
            {item.note && <div className="mt-1 rounded-[5px] bg-[var(--app-surface-muted)] px-2 py-1 text-[12.5px]">{item.note}</div>}
          </div>
        </li>
      ))}
    </ol>
  );
}

/** Botones "Exportar a Excel" y "CSV" del pie de un listado. */
export function ExportButtons({
  total, busy, onExport, permission,
}: {
  total: number;
  busy: boolean;
  onExport: (format: "xlsx" | "csv") => void;
  /** Permiso de exportar del listado: sin él los botones quedan con candado y su motivo. */
  permission?: string;
}) {
  return (
    <div role="group" aria-label="Exportar listado" className="mr-auto flex gap-1.5">
      <Btn variant="ghost" size="sm" disabled={busy} onClick={() => onExport("xlsx")} title={`Exportar las ${total} filas filtradas a Excel`} permission={permission}>
        <Upload size={14} />
        <span>{busy ? "Exportando…" : "Exportar a Excel"}</span>
      </Btn>
      <Btn variant="ghost" size="sm" disabled={busy} onClick={() => onExport("csv")} title={`Exportar las ${total} filas filtradas a CSV`} permission={permission}>
        CSV
      </Btn>
    </div>
  );
}

export function Notice({ tone = "info", children }: { tone?: "info" | "warn"; children: ReactNode }) {
  const style = tone === "warn" ? "border-[color:var(--p-notice-line)] bg-[var(--p-notice-bg)] text-[color:var(--p-notice-text)]" : "border-[color:var(--p-brand-line)] bg-[var(--p-brand-soft)] text-[color:var(--p-brand-soft-text)]";
  return (
    <div className={`mb-3.5 flex items-start gap-2.5 rounded-xl border px-3.5 py-[11px] ${style}`}>
      {tone === "warn" ? <AlertCircle size={18} className="mt-px shrink-0" /> : <Info size={18} className="mt-px shrink-0" />}
      <div className="min-w-0">{children}</div>
    </div>
  );
}

export function LoadError({ text, onRetry }: { text: string; onRetry: () => void }) {
  return (
    <div className="flex flex-col items-center px-4 py-10 text-center">
      <AlertCircle size={28} className="mb-2 text-[color:var(--p-danger-text)]" />
      <p className="app-muted">{text}</p>
      <Btn variant="secondary" size="sm" className="mt-3" onClick={onRetry}>Reintentar</Btn>
    </div>
  );
}

type Toast = { id: number; text: string; tone: "success" | "danger" | "warning"; detail?: string };

/** Avisos breves abajo a la derecha, como los del prototipo. */
export function useToasts() {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const nextId = useRef(0);

  const toast = useCallback((text: string, tone: Toast["tone"] = "success", detail?: string) => {
    const id = ++nextId.current;
    setToasts((list) => [...list, { id, text, tone, detail }]);
    window.setTimeout(() => setToasts((list) => list.filter((item) => item.id !== id)), tone === "success" ? 3500 : 6000);
  }, []);

  const node = (
    <div className={`${poppins.className} pointer-events-none fixed bottom-4 right-[18px] z-[400] grid w-[min(380px,calc(100vw-36px))] gap-2 text-sm`}>
      {toasts.map((item) => (
        <div key={item.id} role="status" className="pointer-events-auto flex items-start gap-2.5 rounded-[10px] bg-[var(--p-toast-bg)] px-3.5 py-[11px] text-[#fff] shadow-[var(--p-toast-shadow)] outline outline-1 outline-[color:var(--p-toast-line)]">
          {item.tone === "success" ? <Check size={18} className="shrink-0 text-[#6fd6a0]" /> : item.tone === "danger" ? <AlertCircle size={18} className="shrink-0 text-[#ff8b82]" /> : <Info size={18} className="shrink-0 text-[#f2bd6e]" />}
          <div>
            <strong className="font-semibold">{item.text}</strong>
            {item.detail && <small className="mt-0.5 block text-xs opacity-75">{item.detail}</small>}
          </div>
        </div>
      ))}
    </div>
  );

  return { toast, toasts: node };
}
