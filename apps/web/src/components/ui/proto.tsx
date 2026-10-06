"use client";

import {
  useCallback, useEffect, useId, useRef, useState, type ButtonHTMLAttributes, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent,
  type ReactNode,
} from "react";
import { createPortal } from "react-dom";
import {
  AlertCircle, Check, ChevronDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, GripVertical, Info, Search, Upload, X,
} from "lucide-react";
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
  title, children, flush = false, className = "",
}: { title?: ReactNode; children: ReactNode; flush?: boolean; className?: string }) {
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

type BtnVariant = "primary" | "secondary" | "ghost" | "danger" | "danger-ghost";

const BTN_VARIANTS: Record<BtnVariant, string> = {
  primary: "bg-[#1B8FC9] text-[#fff] hover:bg-[#1679AB]",
  secondary: "app-surface app-text border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]",
  ghost: "app-muted bg-transparent hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]",
  danger: "bg-[#E5484D] text-[#fff] hover:bg-[#D93A40]",
  "danger-ghost": "border-[#F3C2C4] bg-transparent text-[#D93A40] hover:bg-[#FDECEC]",
};

export function btnClass(variant: BtnVariant = "primary", size: "md" | "sm" = "md", block = false) {
  const sizing = size === "sm" ? "h-[30px] rounded-[7px] px-[11px] text-xs" : "h-[38px] rounded-[9px] px-4 text-[13px]";
  return `inline-flex cursor-pointer items-center gap-[7px] whitespace-nowrap border border-transparent font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-45 ${sizing} ${BTN_VARIANTS[variant]} ${block ? "w-full justify-center" : ""}`;
}

export function Btn({
  variant = "primary", size = "md", block = false, className = "", type = "button", ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: BtnVariant; size?: "md" | "sm"; block?: boolean }) {
  return <button type={type} className={`${btnClass(variant, size, block)} ${className}`} {...props} />;
}

export type Tone = "neutral" | "info" | "success" | "warning" | "danger" | "muted" | "accent";

const TONES: Record<Tone, string> = {
  neutral: "bg-[#E6EAF0] text-[#4B5566]",
  info: "bg-[#E3F2FA] text-[#1679AB]",
  success: "bg-[#E4F6EB] text-[#1B8E4B]",
  warning: "bg-[#FFF4DB] text-[#B26E00]",
  danger: "bg-[#FDECEC] text-[#D93A40]",
  muted: "bg-[#EEF0F4] text-[#7B8494]",
  accent: "bg-[#E3F2FA] text-[#1679AB]",
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
      <span aria-hidden="true" className="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full bg-[#E3F2FA] text-[9.5px] font-semibold text-[#0F5A82]">
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

export function SearchInput({
  value, onChange, placeholder,
}: { value: string; onChange: (value: string) => void; placeholder: string }) {
  return (
    <label className="app-muted flex h-10 flex-[1_1_260px] items-center sm:max-w-[360px] gap-2 rounded-[9px] border border-transparent bg-[var(--app-surface-muted)] px-2.5 focus-within:border-[#1B8FC9] focus-within:bg-[var(--app-surface)]">
      <Search size={18} className="shrink-0" />
      <input
        type="search"
        value={value}
        onChange={(event) => onChange(event.target.value)}
        placeholder={placeholder}
        aria-label={placeholder}
        className="app-text h-[34px] min-w-0 flex-1 border-0 bg-transparent text-[13px] outline-none"
      />
    </label>
  );
}

export function FilterSelect({
  label, value, onChange, options,
}: { label: string; value: string; onChange: (value: string) => void; options: [string, string][] }) {
  return (
    <label className="flex-[1_1_140px] sm:flex-none">
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
              on ? "border-[#1B8FC9] text-[#1679AB]" : "app-muted border-transparent hover:text-[var(--app-text)]"
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
  brand: ["border-l-[#1B8FC9]", "bg-[#E3F2FA] text-[#1B8FC9]"],
  warning: ["border-l-[#B26E00]", "bg-[#FFF4DB] text-[#B26E00]"],
  success: ["border-l-[#22A55A]", "bg-[#E4F6EB] text-[#22A55A]"],
  danger: ["border-l-[#E5484D]", "bg-[#FDECEC] text-[#E5484D]"],
};

export function Kpis({ children }: { children: ReactNode }) {
  return <div className="mb-4 grid grid-cols-[repeat(auto-fit,minmax(210px,1fr))] gap-3">{children}</div>;
}

export function Kpi({
  label, value, sub, icon, tone = "brand",
}: { label: string; value: ReactNode; sub?: ReactNode; icon: ReactNode; tone?: KpiTone }) {
  const [border, iconTone] = KPI_TONES[tone];
  return (
    <div className={`app-surface flex min-w-0 items-center gap-2.5 rounded-xl border-l-4 p-4 ${border} ${SHADOW}`}>
      <span className={`grid h-[46px] w-[46px] shrink-0 place-items-center rounded-xl ${iconTone}`}>{icon}</span>
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
  render: (row: T) => ReactNode;
};

export const TH = "app-muted whitespace-nowrap border-b border-[color:var(--app-border)] px-3.5 pb-3 pt-3.5 text-left text-[10.5px] font-semibold uppercase tracking-[.06em]";
export const TD = "border-b border-[color:var(--app-border)] px-3.5 py-[13px] align-middle";
const NUM = "text-right tabular-nums whitespace-nowrap";

export type Selection<T> = {
  ids: ReadonlySet<string>;
  onToggle: (row: T) => void;
  /** Marca o desmarca todas las filas visibles. */
  onToggleAll: (rows: T[], checked: boolean) => void;
};

const CHECKBOX = "h-4 w-4 cursor-pointer rounded border-[#CFD5DE] accent-[#1B8FC9]";

export function DataTable<T>({
  cols, rows, rowKey, onRowClick, selection, loading = false, empty = "No hay registros con estos filtros.", dense = false,
}: {
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
  count, busy, onExport, onClear,
}: { count: number; busy: boolean; onExport: (format: "xlsx" | "csv") => void; onClear: () => void }) {
  if (!count) return null;

  return (
    <div className="flex flex-wrap items-center gap-2 border-b border-[#BFE6F8] bg-[#E3F2FA] px-3.5 py-2 text-[13px] text-[#0F5A82]">
      <span className="grid h-6 min-w-6 place-items-center rounded-full bg-[#1B8FC9] px-1.5 text-xs font-semibold text-[#fff]">{count}</span>
      <span className="mr-auto font-medium">{count === 1 ? "fila seleccionada" : "filas seleccionadas"}</span>
      <Btn size="sm" disabled={busy} onClick={() => onExport("xlsx")}>
        <Upload size={14} />
        <span>{busy ? "Exportando…" : "Exportar selección a Excel"}</span>
      </Btn>
      <Btn variant="secondary" size="sm" disabled={busy} onClick={() => onExport("csv")}>CSV</Btn>
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
const PAGE_BTN_IDLE = "app-surface app-text border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]";

/** Pie de un listado: exportar, "Mostrando X a Y de N", filas por página y páginas numeradas. */
export function Pager({
  page, lastPage, total, perPage, onPage, onPerPage, left,
}: {
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPage: (page: number) => void;
  onPerPage: (perPage: number) => void;
  left?: ReactNode;
}) {
  if (!total) return null;
  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);
  const jump = (target: number, label: string, icon: ReactNode, disabled: boolean) => (
    <button type="button" onClick={() => onPage(target)} disabled={disabled} title={label} aria-label={label} className={`${PAGE_BTN} ${PAGE_BTN_IDLE}`}>
      {icon}
    </button>
  );

  return (
    <div className="app-muted flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-[color:var(--app-border)] px-3.5 py-3 text-[12.5px]">
      {left}
      <span className={left ? "" : "mr-auto"}>
        Mostrando <strong className="app-text font-semibold">{from}</strong> a <strong className="app-text font-semibold">{to}</strong> de{" "}
        <strong className="app-text font-semibold">{total}</strong>
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
        {jump(1, "Primera página", <ChevronsLeft size={15} />, page <= 1)}
        {jump(page - 1, "Página anterior", <ChevronLeft size={15} />, page <= 1)}
        {pageNumbers(page, lastPage).map((n, index) =>
          n === "gap" ? (
            <span key={`gap-${index}`} className="grid h-8 w-6 place-items-center">…</span>
          ) : (
            <button
              key={n}
              type="button"
              onClick={() => onPage(n)}
              aria-current={n === page ? "page" : undefined}
              className={`${PAGE_BTN} ${n === page ? "border-[#1B8FC9] bg-[#1B8FC9] text-[#fff]" : PAGE_BTN_IDLE}`}
            >
              {n}
            </button>
          ),
        )}
        {jump(page + 1, "Página siguiente", <ChevronRight size={15} />, page >= lastPage)}
        {jump(lastPage, "Última página", <ChevronsRight size={15} />, page >= lastPage)}
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

export const INPUT = "app-surface app-text min-h-10 w-full rounded-[9px] border border-[#CFD5DE] px-2.5 py-2 text-[13.5px] outline-none focus:border-[#1B8FC9] focus:shadow-[0_0_0_3px_rgba(27,143,201,.18)] disabled:bg-[var(--app-surface-muted)]";

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
                  } ${index === active ? "bg-[var(--app-surface-muted)]" : ""} ${option.value === value ? "font-semibold text-[#1679AB]" : ""}`}
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
        {label} {required && <em className="not-italic text-[#D93A40]">*</em>}
      </span>
      {children}
      {error ? <small className="text-xs text-[#D93A40]">{error}</small> : hint ? <small className="app-muted text-xs">{hint}</small> : null}
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
  total, busy, onExport,
}: { total: number; busy: boolean; onExport: (format: "xlsx" | "csv") => void }) {
  return (
    <div role="group" aria-label="Exportar listado" className="mr-auto flex gap-1.5">
      <Btn variant="ghost" size="sm" disabled={busy} onClick={() => onExport("xlsx")} title={`Exportar las ${total} filas filtradas a Excel`}>
        <Upload size={14} />
        <span>{busy ? "Exportando…" : "Exportar a Excel"}</span>
      </Btn>
      <Btn variant="ghost" size="sm" disabled={busy} onClick={() => onExport("csv")} title={`Exportar las ${total} filas filtradas a CSV`}>
        CSV
      </Btn>
    </div>
  );
}

export function Notice({ tone = "info", children }: { tone?: "info" | "warn"; children: ReactNode }) {
  const style = tone === "warn" ? "border-[#F6DE9C] bg-[#FFF6DE] text-[#6B4300]" : "border-[#BFE6F8] bg-[#E3F2FA] text-[#0F5A82]";
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
      <AlertCircle size={28} className="mb-2 text-[#D93A40]" />
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
        <div key={item.id} role="status" className="pointer-events-auto flex items-start gap-2.5 rounded-[10px] bg-[#1C2533] px-3.5 py-[11px] text-[#fff] shadow-[0_20px_50px_rgba(8,25,55,.28)]">
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
