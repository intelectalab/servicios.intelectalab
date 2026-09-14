import { ChevronLeft, ChevronRight } from "lucide-react";

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

      {/* Botones de navegación estilo bloque 1 (Derecha) */}
      <div className="flex gap-2">
        <button
          onClick={() => onPageChange(Math.max(1, currentPage - 1))}
          disabled={currentPage <= 1}
          className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-700 disabled:opacity-40 disabled:hover:bg-white disabled:hover:text-slate-500"
        >
          <ChevronLeft size={16} strokeWidth={2} />
          Anterior
        </button>
        <button
          onClick={() => onPageChange(Math.min(lastPage, currentPage + 1))}
          disabled={currentPage >= lastPage}
          className="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-500 transition-colors hover:bg-slate-50 hover:text-slate-700 disabled:opacity-40 disabled:hover:bg-white disabled:hover:text-slate-500"
        >
          Siguiente
          <ChevronRight size={16} strokeWidth={2} />
        </button>
      </div>
    </div>
  );
}