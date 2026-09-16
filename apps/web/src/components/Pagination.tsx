import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from "lucide-react";

interface PaginationProps {
  currentPage: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPageChange: (page: number) => void;
  onPerPageChange: (perPage: number) => void;
}

export function Pagination({
  currentPage,
  lastPage,
  total,
  perPage,
  onPageChange,
  onPerPageChange,
}: PaginationProps) {
  const start = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
  const end = Math.min(currentPage * perPage, total);

  // Generador de páginas con puntos suspensivos ("...") sin errores de TypeScript
  const getPageNumbers = () => {
    const delta = 2;
    const range: number[] = [];
    const rangeWithDots: (number | string)[] = [];

    for (let i = 1; i <= lastPage; i++) {
      if (
        i === 1 ||
        i === lastPage ||
        (i >= currentPage - delta && i <= currentPage + delta)
      ) {
        range.push(i);
      }
    }

    let lastNum: number | null = null;
    
    for (const i of range) {
      if (lastNum !== null) {
        if (i - lastNum === 2) {
          rangeWithDots.push(lastNum + 1);
        } else if (i - lastNum !== 1) {
          rangeWithDots.push("...");
        }
      }
      rangeWithDots.push(i);
      lastNum = i;
    }

    return rangeWithDots;
  };

  return (
    <div className="flex flex-col items-center justify-between gap-4 border-t border-slate-200 bg-white px-8 py-4 sm:flex-row">
      
      {/* Información de registros y selector (Izquierda) */}
      <div className="flex items-center gap-4">
        <p className="text-sm text-slate-500">
          Mostrando <span className="font-medium text-slate-900">{start}</span> a{" "}
          <span className="font-medium text-slate-900">{end}</span> de{" "}
          <span className="font-medium text-slate-900">{total}</span>
        </p>
        <div className="flex items-center gap-2 border-l border-slate-200 pl-4">
          <label htmlFor="perPage" className="text-sm text-slate-500">
            Filas por página:
          </label>
          <select
            id="perPage"
            value={perPage}
            onChange={(e) => onPerPageChange(Number(e.target.value))}
            className="rounded-md border border-slate-200 bg-white px-2 py-1 text-sm text-slate-600 transition-colors focus:border-[var(--app-brand)] focus:outline-none focus:ring-1 focus:ring-[var(--app-brand)]"
          >
            <option value={10}>10</option>
            <option value={25}>25</option>
            <option value={50}>50</option>
            <option value={100}>100</option>
          </select>
        </div>
      </div>

      {/* Botones de navegación estilo paginador avanzado (Derecha) */}
      <div className="flex items-center gap-1.5">
        {/* Ir al principio (<<) */}
        <button
          onClick={() => onPageChange(1)}
          disabled={currentPage <= 1}
          title="Primera página"
          className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white"
        >
          <ChevronsLeft size={16} strokeWidth={2} />
        </button>

        {/* Anterior (<) */}
        <button
          onClick={() => onPageChange(Math.max(1, currentPage - 1))}
          disabled={currentPage <= 1}
          title="Página anterior"
          className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white"
        >
          <ChevronLeft size={16} strokeWidth={2} />
        </button>

        {/* Números de página y puntos suspensivos */}
        {getPageNumbers().map((page, index) => {
          if (page === "...") {
            return (
              <span
                key={`dot-${index}`}
                className="flex h-9 w-9 items-center justify-center text-sm text-slate-400 font-medium"
              >
                ...
              </span>
            );
          }

          const pageNum = page as number;
          const isActive = currentPage === pageNum;

          return (
            <button
              key={pageNum}
              onClick={() => onPageChange(pageNum)}
              className={`flex h-9 w-9 items-center justify-center rounded-xl border text-sm font-semibold transition-colors ${
                isActive
                  ? "border-sky-600 bg-sky-600 text-white shadow-xs" // Forzamos azul sólido y texto blanco
                  : "border-slate-200 bg-white text-slate-700 hover:bg-slate-50"
              }`}
            >
              {pageNum}
            </button>
          );
        })}

        {/* Siguiente (>) */}
        <button
          onClick={() => onPageChange(Math.min(lastPage, currentPage + 1))}
          disabled={currentPage >= lastPage}
          title="Página siguiente"
          className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white"
        >
          <ChevronRight size={16} strokeWidth={2} />
        </button>

        {/* Ir al final (>>) */}
        <button
          onClick={() => onPageChange(lastPage)}
          disabled={currentPage >= lastPage}
          title="Última página"
          className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-white"
        >
          <ChevronsRight size={16} strokeWidth={2} />
        </button>
      </div>
    </div>
  );
}