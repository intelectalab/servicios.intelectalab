"use client";

import { useCallback, useState, useSyncExternalStore, type ReactNode } from "react";
import { List, Lock, SquareKanban } from "lucide-react";
import { deniedText, type Tone } from "@/components/ui/proto";
import { readView, saveView, type ViewMode } from "@/lib/crm";

/**
 * Tablero por columnas del prototipo (kanban): las tarjetas se arrastran de una columna a
 * otra. En pantallas táctiles no hay arrastre: cada tarjeta trae su propio "Mover a…".
 */

const DOT: Record<Tone, string> = {
  neutral: "text-[color:var(--p-neutral-text)]",
  info: "text-[color:var(--p-brand)]",
  accent: "text-[color:var(--p-brand)]",
  success: "text-[color:var(--p-success)]",
  warning: "text-[color:var(--p-warning-text)]",
  danger: "text-[color:var(--p-danger-text)]",
  muted: "text-[color:var(--p-muted-text)]",
};

export type KanbanColumn = { id: string; label: string; tone: Tone; sub?: ReactNode };

export function Kanban<T>({
  columns, cards, cardKey, columnOf, renderCard, canDrag, onDrop, loading = false, refreshing = false, narrow = false,
}: {
  columns: KanbanColumn[];
  cards: T[];
  cardKey: (card: T) => string;
  /** Columna en la que está la tarjeta. */
  columnOf: (card: T) => string;
  renderCard: (card: T) => ReactNode;
  canDrag?: (card: T) => boolean;
  /** Se soltó una tarjeta en otra columna. */
  onDrop?: (card: T, columnId: string) => void;
  loading?: boolean;
  /** Hay una carga en curso con tarjetas ya a la vista: se atenúan hasta que llegue la respuesta. */
  refreshing?: boolean;
  /** Columnas algo más angostas: un tablero de cuatro entra sin barra horizontal en un portátil. */
  narrow?: boolean;
}) {
  const [dragging, setDragging] = useState<string | null>(null);
  const [over, setOver] = useState<string | null>(null);

  return (
    <div
      className={`mb-3 grid auto-cols-[86%] grid-flow-col gap-3 overflow-x-auto pb-2 [scroll-snap-type:x_proximity] ${narrow ? "sm:auto-cols-[minmax(220px,1fr)]" : "sm:auto-cols-[minmax(250px,1fr)]"} ${refreshing ? "opacity-60" : ""}`}
      aria-busy={loading || refreshing}
    >
      {columns.map((column) => {
        const own = cards.filter((card) => columnOf(card) === column.id);
        return (
          <section
            key={column.id}
            aria-label={column.label}
            data-column={column.id}
            onDragOver={(event) => {
              if (!dragging) return;
              event.preventDefault();
              event.dataTransfer.dropEffect = "move";
              if (over !== column.id) setOver(column.id);
            }}
            onDragLeave={(event) => {
              if (!event.currentTarget.contains(event.relatedTarget as Node | null)) setOver((current) => (current === column.id ? null : current));
            }}
            onDrop={(event) => {
              event.preventDefault();
              const card = cards.find((item) => cardKey(item) === dragging);
              setOver(null);
              setDragging(null);
              if (card && columnOf(card) !== column.id) onDrop?.(card, column.id);
            }}
            className={`flex min-h-[200px] flex-col rounded-xl border p-2 transition-colors [scroll-snap-align:start] ${
              over === column.id ? "border-[#1B8FC9] bg-[var(--p-brand-soft)]" : "border-[color:var(--app-border)] bg-[var(--app-surface-muted)]"
            }`}
          >
            <header className="flex flex-wrap items-center gap-[7px] px-1 pb-2 pt-1">
              <span aria-hidden="true" className={`h-2 w-2 shrink-0 rounded-full bg-current ${DOT[column.tone]}`} />
              <h4 className="app-muted text-[12.5px] font-semibold uppercase tracking-[.04em]">{column.label}</h4>
              <span className="app-surface app-muted rounded-[9px] px-1.5 text-[11px] font-semibold tabular-nums">{loading ? "…" : own.length}</span>
              {column.sub != null && <small className="app-muted w-full text-xs tabular-nums">{column.sub}</small>}
            </header>
            <div className="grid flex-1 content-start gap-2">
              {loading ? (
                <div className="h-16 animate-pulse rounded-[10px] bg-[var(--app-surface)]" />
              ) : own.length ? (
                own.map((card) => {
                  const key = cardKey(card);
                  const draggable = Boolean(onDrop) && (canDrag?.(card) ?? true);
                  return (
                    <article
                      key={key}
                      data-card={key}
                      draggable={draggable}
                      onDragStart={(event) => {
                        event.dataTransfer.setData("text/plain", key);
                        event.dataTransfer.effectAllowed = "move";
                        setDragging(key);
                      }}
                      onDragEnd={() => {
                        setDragging(null);
                        setOver(null);
                      }}
                      className={`app-surface grid gap-1.5 rounded-[10px] p-3 shadow-[0_1px_2px_rgba(16,24,40,.05),0_2px_8px_rgba(16,24,40,.06)] transition-shadow hover:shadow-[0_6px_16px_rgba(16,24,40,.12)] ${
                        draggable ? "cursor-grab" : ""
                      } ${dragging === key ? "opacity-50" : ""}`}
                    >
                      {renderCard(card)}
                    </article>
                  );
                })
              ) : (
                <p className="app-muted px-1 py-2 text-[12.5px]">Sin tarjetas</p>
              )}
            </div>
          </section>
        );
      })}
    </div>
  );
}

/** "Mover a…" de una tarjeta que el rol no puede mover: se ve, deshabilitado, con candado y su motivo. */
export function MoveLocked({ permission }: { permission: string }) {
  return (
    <span
      aria-disabled="true"
      title={deniedText(permission)}
      className="app-muted flex h-[30px] w-full cursor-not-allowed items-center justify-between rounded-md border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] px-2 text-xs opacity-60"
    >
      Mover a…
      <Lock size={12} aria-hidden="true" />
    </span>
  );
}

const viewListeners = new Set<() => void>();

/** Vista (tabla o tablero) de un listado, recordada en el navegador de cada persona. */
export function useStoredView(key: string, fallback: ViewMode): [ViewMode, (mode: ViewMode) => void] {
  const view = useSyncExternalStore(
    (listener) => {
      viewListeners.add(listener);
      return () => { viewListeners.delete(listener); };
    },
    () => readView(key, fallback),
    () => fallback,
  );
  const change = useCallback((mode: ViewMode) => {
    saveView(key, mode);
    viewListeners.forEach((listener) => listener());
  }, [key]);

  return [view, change];
}

/** Conmutador Tabla / Kanban de la cabecera de un listado. */
export function ViewSwitch({ value, onChange }: { value: ViewMode; onChange: (mode: ViewMode) => void }) {
  const options: [ViewMode, string, ReactNode][] = [
    ["table", "Tabla", <List key="table" size={15} />],
    ["kanban", "Kanban", <SquareKanban key="kanban" size={15} />],
  ];
  return (
    <div role="group" aria-label="Tipo de vista" className="app-surface inline-flex h-[38px] items-center gap-0.5 rounded-[9px] border border-[color:var(--app-border)] p-[3px]">
      {options.map(([mode, label, icon]) => (
        <button
          key={mode}
          type="button"
          aria-pressed={value === mode}
          onClick={() => onChange(mode)}
          className={`inline-flex h-full cursor-pointer items-center gap-1.5 rounded-[7px] px-2.5 text-[12.5px] font-medium transition-colors ${
            value === mode ? "bg-[#1B8FC9] text-[#fff]" : "app-muted hover:bg-[var(--app-surface-muted)] hover:text-[var(--app-text)]"
          }`}
        >
          {icon}
          <span>{label}</span>
        </button>
      ))}
    </div>
  );
}
