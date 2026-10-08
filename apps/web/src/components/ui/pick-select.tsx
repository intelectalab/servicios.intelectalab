"use client";

import { useEffect, useId, useLayoutEffect, useRef, useState, type CSSProperties, type KeyboardEvent as ReactKeyboardEvent } from "react";
import { createPortal } from "react-dom";
import { Check, ChevronDown } from "lucide-react";
import { poppins } from "@/lib/fonts";

/**
 * Desplegable de una opción cuya lista se dibuja sobre toda la página (no dentro
 * del modal o de la tabla donde está), así nunca queda recortada. Se maneja con
 * el ratón o con el teclado (flechas, Enter, Escape).
 */
export function PickSelect({
  value, onChange, options, className = "", ariaLabel, autoOpen = false, disabled = false, onClose,
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
  /** Se cerró sin elegir (Escape o clic fuera). */
  onClose?: () => void;
}) {
  const [open, setOpen] = useState(autoOpen);
  const [active, setActive] = useState(() => Math.max(0, options.findIndex(([key]) => key === value)));
  const [place, setPlace] = useState<CSSProperties | null>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const listRef = useRef<HTMLDivElement>(null);
  const listId = useId();

  const current = options.find(([key]) => key === value);

  const close = (chosen = false) => {
    setOpen(false);
    if (!chosen) onClose?.();
  };

  const choose = (key: string) => {
    close(true);
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
      const height = Math.min(options.length * 34 + 12, 260);
      const width = Math.max(rect.width, 150);
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
  }, [open, options.length]);

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
    };
    document.addEventListener("mousedown", onMouseDown);
    document.addEventListener("keydown", onEscape, true);
    return () => {
      document.removeEventListener("mousedown", onMouseDown);
      document.removeEventListener("keydown", onEscape, true);
    };
  }, [open, onClose]);

  function onKeyDown(event: ReactKeyboardEvent<HTMLButtonElement>) {
    if (!open) {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        setOpen(true);
      }
      return;
    }
    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
      event.preventDefault();
      setActive((index) => (index + (event.key === "ArrowDown" ? 1 : options.length - 1)) % options.length);
    } else if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      if (options[active]) choose(options[active][0]);
    } else if (event.key === "Tab") {
      close();
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
        onClick={() => {
          setActive(Math.max(0, options.findIndex(([key]) => key === value)));
          setOpen(!open);
        }}
        onKeyDown={onKeyDown}
        className={`flex cursor-pointer items-center justify-between gap-2 text-left disabled:cursor-not-allowed ${className}`}
      >
        <span className="min-w-0 truncate">{current?.[1] ?? "—"}</span>
        <ChevronDown size={14} className="app-muted shrink-0" />
      </button>

      {open && place && createPortal(
        <div
          ref={listRef}
          id={listId}
          role="listbox"
          style={place}
          className={`${poppins.className} app-surface app-text fixed z-[350] max-h-[260px] overflow-y-auto rounded-[10px] border border-[color:var(--app-border)] p-1 text-[13px] shadow-[0_20px_50px_rgba(8,25,55,.28)]`}
        >
          {options.map(([key, label], index) => {
            const selected = key === value;
            return (
              <div
                key={key}
                role="option"
                aria-selected={selected}
                // El foco se queda en el botón: así una celda en edición no se cierra al elegir.
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActive(index)}
                onClick={() => choose(key)}
                className={`flex cursor-pointer items-center justify-between gap-2 rounded-[7px] px-2.5 py-[7px] ${
                  index === active ? "bg-[#1B8FC9] text-[#fff]" : selected ? "font-semibold text-[#1679AB]" : ""
                }`}
              >
                <span className="min-w-0 truncate">{label}</span>
                {selected && <Check size={14} className="shrink-0" />}
              </div>
            );
          })}
        </div>,
        document.body,
      )}
    </>
  );
}
