"use client";

import { useMemo, useState } from "react";
import { exportCsv, exportXlsx, type ExportColumn } from "@/lib/export-table";
import { recordPlatformExport } from "@/lib/platform";
import { DataTable, ExportButtons, Pager, SelectionBar, useRowSelection, useToasts, type Column, type SortState } from "@/components/ui/proto";

/** Columna de una tabla de la consola: `sortValue` ordena sobre todas las filas. */
export type ClientColumn<T> = Column<T> & { sortValue?: (row: T) => string | number | null };

type Toast = ReturnType<typeof useToasts>["toast"];

/**
 * Tabla de la consola de plataforma con todas las filas en memoria: ordenar,
 * paginar, seleccionar y exportar abarcan la tabla completa (ya filtrada).
 * Cada exportación queda en la auditoría de plataforma.
 */
export function ClientTable<T>({
  table, title, file, cols, rows, rowKey, exportCols, empty, toast, onRowClick, loading = false, tableId,
}: {
  /** Nombre de la tabla en la auditoría de exportaciones. */
  table: string;
  title: string;
  file: string;
  cols: ClientColumn<T>[];
  rows: T[];
  rowKey: (row: T) => string;
  exportCols: ExportColumn<T>[];
  empty: string;
  toast: Toast;
  onRowClick?: (row: T) => void;
  loading?: boolean;
  tableId?: string;
}) {
  const [sort, setSort] = useState<SortState>(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [busy, setBusy] = useState(false);
  const { selection, selectedRows, clear } = useRowSelection(rowKey);

  const sorted = useMemo(() => {
    const column = sort && cols.find((col) => col.sortKey === sort.key);
    if (!column?.sortValue) return rows;
    const value = column.sortValue;
    return [...rows].sort((a, b) => {
      const x = value(a) ?? "";
      const y = value(b) ?? "";
      const order = typeof x === "number" && typeof y === "number" ? x - y : String(x).localeCompare(String(y), "es", { numeric: true });
      return sort?.dir === "asc" ? order : -order;
    });
  }, [rows, sort, cols]);

  const lastPage = Math.max(1, Math.ceil(sorted.length / perPage));
  const current = Math.min(page, lastPage);
  const visible = sorted.slice((current - 1) * perPage, current * perPage);

  async function run(format: "xlsx" | "csv", list: T[], selected: boolean) {
    setBusy(true);
    try {
      await recordPlatformExport(table, format, list.length, selected);
      if (format === "xlsx") await exportXlsx(file, title, exportCols, list);
      else exportCsv(file, exportCols, list);
    } catch {
      toast("No se pudo exportar.", "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <SelectionBar count={selectedRows.length} busy={busy} onExport={(format) => run(format, selectedRows, true)} onClear={clear} />
      <DataTable
        cols={cols} rows={visible} rowKey={rowKey} selection={selection} empty={empty} onRowClick={onRowClick} loading={loading}
        sort={sort} onSort={(next) => { setSort(next); setPage(1); }} tableId={tableId ?? `platform-${table}`}
      />
      <Pager
        page={current} lastPage={lastPage} total={sorted.length} perPage={perPage}
        onPage={setPage} onPerPage={(size) => { setPerPage(size); setPage(1); }}
        left={<ExportButtons total={sorted.length} busy={busy} onExport={(format) => run(format, sorted, false)} />}
      />
    </>
  );
}
