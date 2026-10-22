"use client";

import { useState } from "react";
import { exportCsv, exportXlsx, type ExportColumn } from "@/lib/export-table";
import { ExportButtons } from "@/components/ui/proto";
import { usePortal } from "./PortalShell";

/** Pie de una tabla del portal con "Exportar a Excel" y CSV: exporta todas sus filas (el portal no pagina). */
export function PortalExport<T>({ file, title, columns, rows }: { file: string; title: string; columns: ExportColumn<T>[]; rows: T[] }) {
  const { toast } = usePortal();
  const [busy, setBusy] = useState(false);

  if (!rows.length) return null;

  async function run(format: "xlsx" | "csv") {
    setBusy(true);
    try {
      if (format === "xlsx") await exportXlsx(file, title, columns, rows);
      else exportCsv(file, columns, rows);
      toast(`${rows.length} ${rows.length === 1 ? "fila exportada" : "filas exportadas"}`);
    } catch {
      toast("No se pudo exportar la tabla.", "danger");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-wrap items-center gap-2 border-t border-[color:var(--app-border)] px-3.5 py-2.5">
      <ExportButtons total={rows.length} busy={busy} onExport={run} />
      <span className="app-muted text-xs">{rows.length} {rows.length === 1 ? "registro" : "registros"}</span>
    </div>
  );
}
