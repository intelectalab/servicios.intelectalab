"use client";

import { useEffect, useRef, useState } from "react";
import { Filter, X } from "lucide-react";
import { useColumnFilters, type ActiveFilter } from "@/hooks/useColumnFilters";

// Filtros por columna de un listado ("Filtrar por esta columna" en los tres puntos de la cabecera).
// Los aplica la API sobre todo el listado: aquí solo se arman, se muestran y se envían.

export type ColumnFilterType = ActiveFilter["type"];

const NO_ITEMS: never[] = [];
const NO_LOCAL_VALUE = () => "";
const FILTER_SELECT = "app-surface app-text rounded border border-[color:var(--app-border)] px-2 py-0.5 text-xs outline-none";

const DATE_OPERATORS: Record<string, string> = {
  all: "todos", today: "hoy", yesterday: "ayer", this_week: "esta semana", last_week: "la semana pasada",
  last_month: "el mes pasado", year_start: "este año",
};
const TEXT_OPERATORS: Record<string, string> = { contains: "contiene", equals: "es igual a", greater: "mayor que", less: "menor que" };

/** Un filtro por columna en una frase: «Documento contiene "2099"», «Año: esta semana». */
function filterText(filter: ActiveFilter) {
  if (filter.type === "date") {
    if (filter.operator !== "range") return `${filter.label}: ${DATE_OPERATORS[filter.operator] ?? filter.operator}`;
    const [from, to] = filter.value.split("|");
    return `${filter.label}: ${from ? `desde ${from}` : ""}${from && to ? " " : ""}${to ? `hasta ${to}` : ""}${!from && !to ? "periodo sin fechas" : ""}`;
  }
  if (filter.type === "select") return `${filter.label}: ${filter.value === "true" ? "Sí" : "No"}`;
  const operator = TEXT_OPERATORS[filter.operator] ?? filter.operator;
  return filter.value.trim() ? `${filter.label} ${operator} "${filter.value.trim()}"` : `${filter.label}: sin valor`;
}

/**
 * Estado de los filtros por columna de un listado. `apply` los añade a la petición
 * (parámetro `filters`); `onChange` avisa cuando cambia lo que se envía, para volver a la página 1.
 * Solo viajan los filtros que ya tienen algo que filtrar, con una pequeña espera para no pedir en cada tecla.
 */
export function useServerColumnFilters(onChange: () => void) {
  const state = useColumnFilters<never>(NO_ITEMS, NO_LOCAL_VALUE);

  const wanted = JSON.stringify(
    state.activeFilters
      .filter((filter) => (filter.type === "date" ? filter.operator !== "all" : filter.type === "select" || filter.value.trim() !== ""))
      .map(({ id, type, operator, value }) => ({ id, type, operator, value })),
  );
  const [param, setParam] = useState("[]");
  const sent = useRef("[]");
  const changed = useRef(onChange);
  useEffect(() => {
    changed.current = onChange;
  });
  useEffect(() => {
    const timer = window.setTimeout(() => {
      if (sent.current === wanted) return;
      sent.current = wanted;
      setParam(wanted);
      changed.current();
    }, 350);
    return () => window.clearTimeout(timer);
  }, [wanted]);

  return {
    ...state,
    /** Lo que viaja a la API; cambia con retraso respecto de lo que se escribe. */
    param,
    apply: (params: URLSearchParams) => applyFilters(params, param),
    clear: () => state.setActiveFilters([]),
  };
}

/** Añade a la petición los filtros por columna ya listos para enviar (`filters.param`). */
export function applyFilters(params: URLSearchParams, param: string) {
  if (param !== "[]") params.set("filters", param);
  return params;
}

export type ServerColumnFilters = ReturnType<typeof useServerColumnFilters>;

/**
 * Lleva a un filtro de la barra de arriba (un desplegable de "Rol", "Estado"…) y lo abre.
 * Se usa cuando la columna ya tiene su filtro arriba, para no crear un segundo filtro del mismo dato.
 */
export function openTopFilter(label: string) {
  const select = document.querySelector<HTMLSelectElement>(`[role="search"] select[aria-label="${label}"]`);
  if (!select) return;
  select.scrollIntoView({ block: "center" });
  select.focus();
  try {
    select.showPicker();
  } catch { /* si el navegador no deja abrirlo solo, queda enfocado */ }
}

/** Lo que hace "Filtrar por esta columna": agrega el filtro, o abre el de arriba si la columna ya tiene uno. */
export function addColumnFilter(
  filters: ServerColumnFilters,
  column: { label: string; sortKey?: string; filter?: ColumnFilterType | { top: string } },
) {
  if (!column.filter) return;
  if (typeof column.filter === "object") openTopFilter(column.filter.top);
  else filters.handleAddFilter(column.sortKey ?? column.label, column.label, column.filter);
}

/**
 * Va dentro de `<Filters>`, después de los filtros de arriba: el botón "Filtros por columna",
 * "Limpiar filtros" (quita todos, también los de arriba), las etiquetas de los filtros activos
 * cuando el panel está cerrado, y el panel para editarlos.
 */
export function ColumnFilterBar({
  filters, hasOtherFilters, onClearAll,
}: {
  filters: ServerColumnFilters;
  /** Hay algo puesto en el buscador o en los filtros de arriba. */
  hasOtherFilters: boolean;
  /** Limpia el buscador y los filtros de arriba; los de columna los limpia esta barra. */
  onClearAll: () => void;
}) {
  const { activeFilters, showAdvancedFilters, setShowAdvancedFilters, handleUpdateFilter, handleRemoveFilter } = filters;

  return (
    <>
      <button
        type="button"
        onClick={() => setShowAdvancedFilters(!showAdvancedFilters)}
        className={`flex h-10 cursor-pointer items-center gap-2 rounded-[9px] border px-3 text-[13px] font-medium ${
          activeFilters.length ? "border-[#1B8FC9] bg-[#E3F2FA] text-[#1679AB]" : "border-[color:var(--app-border)] hover:bg-[var(--app-surface-muted)]"
        }`}
      >
        <Filter size={14} />
        Filtros por columna
        {activeFilters.length > 0 && (
          <span className="grid h-5 min-w-5 place-items-center rounded-full bg-[#1B8FC9] px-1 text-[10px] font-bold text-[#fff]">{activeFilters.length}</span>
        )}
      </button>
      {(hasOtherFilters || activeFilters.length > 0) && (
        <button
          type="button"
          onClick={() => {
            filters.clear();
            onClearAll();
          }}
          className="h-10 cursor-pointer px-1.5 text-[13px] font-medium text-[#D93A40] hover:underline"
        >
          Limpiar filtros
        </button>
      )}

      {/* Con el panel cerrado, cada filtro por columna queda a la vista como etiqueta. */}
      {!showAdvancedFilters && activeFilters.length > 0 && (
        <div className="flex w-full flex-wrap items-center gap-2">
          {activeFilters.map((filter) => (
            <span key={filter.id} className="inline-flex h-7 items-center rounded-lg border border-[#BFE6F8] bg-[#E3F2FA] text-xs font-medium text-[#0F5A82]">
              <button type="button" onClick={() => setShowAdvancedFilters(true)} title="Cambiar este filtro" className="h-full cursor-pointer pl-2.5 pr-1 hover:underline">
                {filterText(filter)}
              </button>
              <button
                type="button"
                onClick={() => handleRemoveFilter(filter.id)}
                aria-label={`Quitar filtro ${filter.label}`}
                className="grid h-full w-6 cursor-pointer place-items-center rounded-r-lg hover:bg-[#BFE6F8]"
              >
                <X size={12} />
              </button>
            </span>
          ))}
        </div>
      )}

      {showAdvancedFilters && (
        <div className="flex w-full flex-wrap items-center gap-2 rounded-xl border border-[color:var(--app-border)] bg-[var(--app-surface-muted)] p-3 text-xs">
          <span className="app-muted mr-1 font-semibold">Filtros activos:</span>
          {activeFilters.length === 0 ? (
            <span className="app-muted italic">No hay filtros activos. Haz clic en los tres puntos de cualquier columna de la tabla para filtrar.</span>
          ) : activeFilters.map((filter) => (
            <div key={filter.id} className="app-surface flex flex-wrap items-center gap-2 rounded-lg border border-[color:var(--app-border)] px-3 py-1.5">
              <span className="font-semibold">{filter.label}:</span>
              <select
                value={filter.operator}
                onChange={(event) => {
                  const operator = event.target.value;
                  handleUpdateFilter(filter.id, { operator, value: operator === "range" ? "|" : ["today", "all"].includes(operator) ? "" : filter.value });
                }}
                className={FILTER_SELECT}
                aria-label={`Operador de ${filter.label}`}
              >
                {filter.type === "select" ? (
                  <option value="equals">es igual a</option>
                ) : filter.type === "date" ? (
                  <>
                    <option value="all">Todos</option>
                    <option value="today">Hoy</option>
                    <option value="yesterday">Ayer</option>
                    <option value="this_week">Al principio de esta semana</option>
                    <option value="last_week">La semana pasada</option>
                    <option value="last_month">El mes pasado</option>
                    <option value="year_start">Al principio de este año</option>
                    <option value="range">Periodo personalizado</option>
                  </>
                ) : (
                  <>
                    <option value="contains">contiene</option>
                    <option value="equals">es igual a</option>
                    {filter.type === "number" && (
                      <>
                        <option value="greater">mayor que</option>
                        <option value="less">menor que</option>
                      </>
                    )}
                  </>
                )}
              </select>

              {filter.type === "select" ? (
                <select value={filter.value} onChange={(event) => handleUpdateFilter(filter.id, { value: event.target.value })} className={`${FILTER_SELECT} w-28`} aria-label="Valor">
                  <option value="true">Sí</option>
                  <option value="false">No</option>
                </select>
              ) : filter.type === "date" && filter.operator === "range" ? (
                <span className="flex flex-wrap items-center gap-1">
                  <span className="app-muted">Desde:</span>
                  <input
                    type="date"
                    value={filter.value.split("|")[0] || ""}
                    onChange={(event) => handleUpdateFilter(filter.id, { value: `${event.target.value}|${filter.value.split("|")[1] ?? ""}` })}
                    className={FILTER_SELECT}
                  />
                  <span className="app-muted">Hasta:</span>
                  <input
                    type="date"
                    value={filter.value.split("|")[1] || ""}
                    onChange={(event) => handleUpdateFilter(filter.id, { value: `${filter.value.split("|")[0] ?? ""}|${event.target.value}` })}
                    className={FILTER_SELECT}
                  />
                </span>
              ) : filter.type === "date" ? (
                <span className="app-muted px-1 text-[11px] italic">Se aplica al período</span>
              ) : (
                <input
                  type={filter.type === "number" ? "number" : "text"}
                  value={filter.value}
                  onChange={(event) => handleUpdateFilter(filter.id, { value: event.target.value })}
                  placeholder="Valor..."
                  className={`${FILTER_SELECT} w-28`}
                />
              )}

              <button type="button" onClick={() => handleRemoveFilter(filter.id)} className="app-muted cursor-pointer hover:text-[#D93A40]" aria-label={`Quitar filtro ${filter.label}`}>
                <X size={13} />
              </button>
            </div>
          ))}
        </div>
      )}
    </>
  );
}
