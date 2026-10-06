"use client";

import {
  createContext, useContext, useEffect, useRef, useState, type KeyboardEvent, type PointerEvent, type ReactNode,
} from "react";
import { motion } from "framer-motion";

/**
 * Tarjetas que cada persona puede reordenar arrastrándolas desde su asa (o con
 * las flechas del teclado). El orden se guarda en el navegador, por pantalla.
 * Una "zona" es un contenedor (una columna, una grilla): las tarjetas se mueven
 * dentro de su zona y de una zona a otra.
 */

type HandleProps = {
  onPointerDown: (event: PointerEvent<HTMLButtonElement>) => void;
  onKeyDown: (event: KeyboardEvent<HTMLButtonElement>) => void;
};

const CardDragContext = createContext<{ dragging: boolean; handleProps: HandleProps } | null>(null);

/** Lo usa `Card` para mostrar el asa cuando está dentro de un `SortableBoard`. */
export function useCardDrag() {
  return useContext(CardDragContext);
}

export type BoardZone = { className?: string; cards: string[] };
export type BoardCard = { id: string; node: ReactNode; className?: string };

type Layout = string[][];

const sameLayout = (a: Layout, b: Layout) => JSON.stringify(a) === JSON.stringify(b);

/** Orden guardado, descartando tarjetas que ya no existen y añadiendo las nuevas a su zona por defecto. */
function readLayout(storageKey: string, defaults: Layout): Layout {
  try {
    const saved: unknown = JSON.parse(window.localStorage.getItem(storageKey) ?? "null");
    if (!Array.isArray(saved) || saved.length !== defaults.length) return defaults;

    const known = new Set(defaults.flat());
    const seen = new Set<string>();
    const layout: Layout = saved.map((zone) =>
      (Array.isArray(zone) ? zone : []).filter((id): id is string => {
        if (typeof id !== "string" || !known.has(id) || seen.has(id)) return false;
        seen.add(id);
        return true;
      }),
    );
    defaults.forEach((zone, index) => zone.forEach((id) => { if (!seen.has(id)) layout[index].push(id); }));
    return layout;
  } catch {
    return defaults;
  }
}

function saveLayout(storageKey: string, layout: Layout, defaults: Layout) {
  try {
    if (sameLayout(layout, defaults)) window.localStorage.removeItem(storageKey);
    else window.localStorage.setItem(storageKey, JSON.stringify(layout));
  } catch {
    // Sin almacenamiento (modo privado): el orden dura lo que dure la pantalla.
  }
}

const zoneOf = (layout: Layout, id: string) => layout.findIndex((zone) => zone.includes(id));

/** Mueve `id` al lugar que ocupa `targetId`. */
function moveOnto(layout: Layout, id: string, targetId: string): Layout {
  const from = zoneOf(layout, id);
  const to = zoneOf(layout, targetId);
  if (from < 0 || to < 0) return layout;

  // Dentro de la misma zona, al bajar la tarjeta queda después de la que pisa.
  const after = from === to && layout[from].indexOf(id) < layout[to].indexOf(targetId);
  const next = layout.map((zone) => zone.filter((item) => item !== id));
  next[to].splice(next[to].indexOf(targetId) + (after ? 1 : 0), 0, id);
  return next;
}

function moveToZoneEnd(layout: Layout, id: string, zone: number): Layout {
  const next = layout.map((items) => items.filter((item) => item !== id));
  next[zone].push(id);
  return next;
}

/** Mueve `id` un lugar entre las tarjetas visibles; en el borde de una zona pasa a la vecina. */
function moveStep(layout: Layout, id: string, step: -1 | 1, visible: ReadonlySet<string>): Layout {
  const zone = zoneOf(layout, id);
  if (zone < 0) return layout;

  const shown = layout[zone].filter((item) => visible.has(item));
  const neighbour = shown[shown.indexOf(id) + step];
  if (neighbour) return moveOnto(layout, id, neighbour);

  const target = zone + step;
  if (target < 0 || target >= layout.length) return layout;
  const next = layout.map((items) => items.filter((item) => item !== id));
  if (step === 1) next[target].unshift(id);
  else next[target].push(id);
  return next;
}

function scrollParent(element: HTMLElement | null): HTMLElement | null {
  for (let node = element?.parentElement ?? null; node; node = node.parentElement) {
    const overflow = getComputedStyle(node).overflowY;
    if ((overflow === "auto" || overflow === "scroll") && node.scrollHeight > node.clientHeight) return node;
  }
  return null;
}

const EDGE = 70;

export function SortableBoard({
  storageKey, zones, cards, className = "",
}: { storageKey: string; zones: BoardZone[]; cards: BoardCard[]; className?: string }) {
  const defaults = zones.map((zone) => zone.cards);
  // El tablero solo se monta en el navegador, con los datos ya cargados.
  const [layout, setLayout] = useState<Layout>(() => readLayout(storageKey, defaults));
  const [dragging, setDragging] = useState<string | null>(null);
  const board = useRef<HTMLDivElement>(null);
  const lastOver = useRef<string | null>(null);
  const stopDrag = useRef<(() => void) | null>(null);

  useEffect(() => () => stopDrag.current?.(), []);

  const byId = new Map(cards.map((card) => [card.id, card]));
  const visible = new Set(byId.keys());

  function apply(change: (current: Layout) => Layout, persist = false) {
    setLayout((current) => {
      const next = change(current);
      if (persist) saveLayout(storageKey, next, defaults);
      return next;
    });
  }

  function handlePropsFor(id: string): HandleProps {
    return {
      onPointerDown: (event) => {
        if (event.button !== 0 || stopDrag.current) return;
        event.preventDefault();
        lastOver.current = null;
        const scroller = scrollParent(board.current);

        const move = (pointer: globalThis.PointerEvent) => {
          // Cerca del borde de la pantalla, la página acompaña al arrastre.
          const delta = pointer.clientY < EDGE ? -14 : pointer.clientY > window.innerHeight - EDGE ? 14 : 0;
          if (delta) (scroller ?? window).scrollBy(0, delta);

          const under = document.elementFromPoint(pointer.clientX, pointer.clientY);
          if (!under || !board.current?.contains(under)) return;

          const overId = under.closest<HTMLElement>("[data-card-id]")?.dataset.cardId;
          if (overId) {
            if (overId === id) lastOver.current = null;
            // Tras un intercambio no se repite hasta salir de esa tarjeta: evita el vaivén.
            else if (overId !== lastOver.current) {
              lastOver.current = overId;
              apply((current) => moveOnto(current, id, overId));
            }
            return;
          }

          // Hueco libre de una zona (p. ej. una columna vacía): la tarjeta va al final.
          const zone = Number(under.closest<HTMLElement>("[data-zone]")?.dataset.zone ?? -1);
          if (zone >= 0) {
            lastOver.current = null;
            apply((current) => (zoneOf(current, id) === zone ? current : moveToZoneEnd(current, id, zone)));
          }
        };

        // Se escucha en la ventana: al reordenar, el asa cambia de lugar en la página.
        const stop = () => {
          window.removeEventListener("pointermove", move);
          window.removeEventListener("pointerup", end);
          window.removeEventListener("pointercancel", end);
          stopDrag.current = null;
        };
        const end = () => {
          stop();
          setDragging(null);
          apply((current) => current, true);
        };

        window.addEventListener("pointermove", move);
        window.addEventListener("pointerup", end);
        window.addEventListener("pointercancel", end);
        stopDrag.current = stop;
        setDragging(id);
      },
      onKeyDown: (event) => {
        const step = event.key === "ArrowUp" || event.key === "ArrowLeft" ? -1 : event.key === "ArrowDown" || event.key === "ArrowRight" ? 1 : 0;
        if (!step) return;
        event.preventDefault();
        apply((current) => moveStep(current, id, step, visible), true);
      },
    };
  }

  return (
    <div ref={board} className={dragging ? "select-none" : ""}>
      <div className={className}>
        {zones.map((zone, index) => (
          <div key={index} data-zone={index} className={zone.className}>
            {layout[index].map((id) => {
              const card = byId.get(id);
              if (!card) return null;
              return (
                // layout + layoutId: la tarjeta se desliza a su nuevo lugar, también al cambiar de zona.
                <motion.div
                  key={id}
                  layout="position"
                  layoutId={`${storageKey}:${id}`}
                  transition={{ type: "spring", stiffness: 420, damping: 38, mass: 0.9 }}
                  data-card-id={id}
                  className={`min-w-0 ${dragging === id ? "relative z-10" : ""} ${card.className ?? ""}`}
                >
                  <CardDragContext.Provider value={{ dragging: dragging === id, handleProps: handlePropsFor(id) }}>
                    {card.node}
                  </CardDragContext.Provider>
                </motion.div>
              );
            })}
          </div>
        ))}
      </div>
      {!sameLayout(layout, defaults) && (
        <div className="-mt-1.5 mb-3 text-right">
          <button
            type="button"
            onClick={() => apply(() => defaults, true)}
            className="app-muted cursor-pointer text-xs font-medium underline-offset-2 hover:text-[var(--app-text)] hover:underline"
          >
            Restablecer orden de las tarjetas
          </button>
        </div>
      )}
    </div>
  );
}
