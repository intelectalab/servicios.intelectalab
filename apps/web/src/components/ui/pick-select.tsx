"use client";

import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent } from "react";
import { createPortal } from "react-dom";
import { Check, ChevronDown, Search } from "lucide-react";
import { poppins } from "@/lib/fonts";

/**
 * Desplegable de una opción cuya lista se dibuja sobre toda la página (no dentro
 * del modal o de la tabla donde está), así nunca queda recortada. Se maneja con
 * el ratón o con el teclado (flechas, Enter, Escape). Con `searchable` trae un
 * buscador, para listas largas.
 */
export function PickSelect({
  value, onChange, options, className = "", ariaLabel, autoOpen = false, disabled = false, searchable = false, onClose,
}: {
  value: string;
  onChange: (value: string) => void;
  options: [string, string][];
  /** Clases del botón (alto, borde, ancho…). */
  className?: string;
  ariaLabel?: string;
  /** Abre la lista al montarse (edición en una celda). */
  autoOpen?: boolean;
  disabled?: boolean;
  searchable?: boolean;
  /** Se cerró sin elegir (Escape o clic fuera). */
  onClose?: () => void;
}) {
  const [open, setOpen] = useState(autoOpen);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(() => Math.max(0, options.findIndex(([key]) => key === value)));
  const [place, setPlace] = useState<CSSProperties | null>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const listId = useId();

  const current = options.find(([key]) => key === value);
  const text = query.trim().toLowerCase();
  const shown = searchable && text ? options.filter(([, label]) => label.toLowerCase().includes(text)) : options;

  const show = () => {
    setQuery("");
    setActive(Math.max(0, options.findIndex(([key]) => key === value)));
    setOpen(true);
  };

  const choose = (key: string) => {
    setOpen(false);
    if (key !== value) onChange(key);
    buttonRef.current?.focus();
  };

  useEffect(() => {
    if (autoOpen) buttonRef.current?.focus();
  }, [autoOpen]);

  // Debajo del botón, o encima si abajo no cabe; sigue al botón al desplazar o cambiar el tamaño.
  useLayoutEffect(() => {
    if (!open) return;
    const update = () => {
      const rect = buttonRef.current?.getBoundingClientRect();
      if (!rect) return;
      const height = Math.min(options.length * 34 + 12, 260) + (searchable ? 44 : 0);
      const width = Math.max(rect.width, searchable ? 240 : 150);
      const left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8));
      const below = window.innerHeight - rect.bottom;
      setPlace(below < height + 10 && rect.top > below
        ? { left, width, bottom: window.innerHeight - rect.top + 4 }
        : { left, width, top: rect.bottom + 4 });
    };
    update();
    window.addEventListener("resize", update);
    window.addEventListener("scroll", update, true);
    return () => {
      window.removeEventListener("resize", update);
      window.removeEventListener("scroll", update, true);
    };
  }, [open, options.length, searchable]);

  useEffect(() => {
    if (!open) return;
    const onMouseDown = (event: MouseEvent) => {
      const target = event.target as Node;
      if (!buttonRef.current?.contains(target) && !listRef.current?.contains(target)) {
        setOpen(false);
        onClose?.();
      }
    };
    // Escape cierra solo la lista (no el modal que la contiene), tenga o no el foco el botón.
    const onEscape = (event: KeyboardEvent) => {
      if (event.key !== "Escape") return;
      event.stopPropagation();
      event.preventDefault();
      setOpen(false);
      onClose?.();
      buttonRef.current?.focus();
    };
    document.addEventListener("mousedown", onMouseDown);
    document.addEventListener("keydown", onEscape, true);
    return () => {
      document.removeEventListener("mousedown", onMouseDown);
      document.removeEventListener("keydown", onEscape, true);
    };
  }, [open, onClose]);

  /** Flechas y Enter, tanto en el botón como en el buscador. */
  function onKeyDown(event: ReactKeyboardEvent<HTMLElement>) {
    if (!open) {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        show();
      }
      return;
    }
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      if (shown.length) setActive((index) => (Math.min(index, shown.length - 1) + (event.key === "ArrowDown" ? 1 : shown.length - 1)) % shown.length);
    } else if (event.key === "Enter" || (event.key === " " && !searchable)) {
      event.preventDefault();
      const option = shown[Math.min(active, shown.length - 1)];
      if (option) choose(option[0]);
    } else if (event.key === "Tab") {
      setOpen(false);
      onClose?.();
    }
  }

  return (
    <>
      <button
        ref={buttonRef}
        type="button"
        role="combobox"
        aria-haspopup="listbox"
        aria-expanded={open}
        aria-controls={listId}
        aria-label={ariaLabel}
        disabled={disabled}
        onClick={() => (open ? setOpen(false) : show())}
        onKeyDown={onKeyDown}
        className={`flex cursor-pointer items-center justify-between gap-2 text-left disabled:cursor-not-allowed ${className}`}
      >
        <span className="min-w-0 truncate">{current?.[1] ?? "—"}</span>
        <ChevronDown size={14} className="app-muted shrink-0" />
      </button>

      {open && place && createPortal(
        <div
          ref={listRef}
          style={place}
          className={`${poppins.className} app-surface app-text fixed z-[350] rounded-[10px] border border-[color:var(--app-border)] p-1 text-[13px] shadow-[0_20px_50px_rgba(8,25,55,.28)]`}
        >
          {searchable && (
            <label className="app-muted mb-1 flex h-9 items-center gap-2 rounded-lg bg-[var(--app-surface-muted)] px-2.5">
              <Search size={14} className="shrink-0" />
              <input
                className="app-text min-w-0 flex-1 bg-transparent text-xs outline-none"
                value={query}
                onChange={(event) => {
                  setQuery(event.target.value);
                  setActive(0);
                }}
                onKeyDown={onKeyDown}
                placeholder="Buscar…"
                aria-label="Buscar"
                autoFocus
              />
            </label>
          )}
          <div id={listId} role="listbox" className="max-h-[250px] overflow-y-auto">
            {shown.length === 0 && <p className="app-muted px-2.5 py-2">Sin resultados</p>}
            {shown.map(([key, label], index) => {
              const selected = key === value;
              return (
                <div
                  key={key}
                  role="option"
                  aria-selected={selected}
                  // El foco no se mueve a la lista: así una celda en edición no se cierra al elegir.
                  onMouseDown={(event) => event.preventDefault()}
                  onMouseEnter={() => setActive(index)}
                  onClick={() => choose(key)}
                  className={`flex cursor-pointer items-center justify-between gap-2 rounded-[7px] px-2.5 py-[7px] ${
                    index === active ? "bg-[#1B8FC9] text-[#fff]" : selected ? "font-semibold text-[color:var(--p-brand-text)]" : ""
                  }`}
                >
                  <span className="min-w-0 truncate">{label}</span>
                  {selected && <Check size={14} className="shrink-0" />}
                </div>
              );
            })}
          </div>
        </div>,
        document.body,
      )}
    </>
  );
}
