import { apiFetch } from "@/lib/api";
import type { PaginatedResponse } from "@/types/customer";

type CellTone = "success" | "danger" | "warning" | "info" | "neutral";

export type ExportColumn<T> = {
  label: string;
  value: (row: T) => string | number;
  width?: number;
  /** Cómo se alinea y formatea la celda en Excel; por defecto, texto a la izquierda. */
  kind?: "text" | "center" | "number" | "money" | "status";
  /** Solo para "status": color de la celda según el registro. */
  tone?: (row: T) => CellTone;
  /** Solo para "money": moneda del registro (por defecto, soles). */
  currency?: (row: T) => string;
};

const PAGE_SIZE = 200;

// Colores del logo y de los estados, en formato ARGB de Excel.
const BRAND = "FF1B8FC9";
const BRAND_DARK = "FF0F5A82";
const INK = "FF1D1D1B";
const SUBTITLE = "FF526778";
const SUBTITLE_FILL = "FFE3F2FA";
const BORDER = "FFCFE0E8";
const STRIPE = "FFF4FAFD";
const WHITE = "FFFFFFFF";
const TONES: Record<CellTone, { text: string; fill: string }> = {
  success: { text: "FF1B8E4B", fill: "FFE4F6EB" },
  danger: { text: "FFD93A40", fill: "FFFDECEC" },
  warning: { text: "FFB26E00", fill: "FFFFF4DB" },
  info: { text: "FF1679AB", fill: "FFE3F2FA" },
  neutral: { text: "FF4B5566", fill: "FFE6EAF0" },
};

/** Trae todas las páginas de un listado con los filtros que tenga puestos. */
// Filtros, búsqueda y orden de la última exportación completa, para dejarlos en auditoría.
let lastExportQuery = "";

export async function fetchAllPages<T>(path: string, params: URLSearchParams): Promise<T[]> {
  const rows: T[] = [];
  lastExportQuery = params.toString();
  for (let page = 1; ; page++) {
    const query = new URLSearchParams(params);
    query.set("per_page", String(PAGE_SIZE));
    query.set("page", String(page));
    // Marca la petición como exportación: la API exige el permiso de exportar del listado.
    query.set("export", "1");
    const response = await apiFetch<PaginatedResponse<T>>(`${path}?${query}`);
    rows.push(...response.data);
    if (page >= response.last_page) return rows;
  }
}

/**
 * Registra la exportación antes de generar el archivo: la API comprueba el permiso
 * `<recurso>.export` y la deja en auditoría (quién, qué listado, cuántas filas). Sin el
 * permiso responde 403 y el archivo no se genera.
 */
export async function recordExport(resource: string, format: "xlsx" | "csv", rows: number, selection = false) {
  // De una selección no hay filtros que anotar: son las filas marcadas en pantalla.
  const filters = selection ? "" : decodeURIComponent(lastExportQuery).slice(0, 2000);
  await apiFetch("/v1/exports", {
    method: "POST",
    body: JSON.stringify({ resource, format, rows, selection, filters: filters || null }),
  });
}

function download(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.setAttribute("download", filename);
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

const stamp = () => new Date().toISOString().slice(0, 10);

/**
 * Excel con el mismo formato que el de Clientes (título, fecha de exportación,
 * encabezado de color, filas alternadas, estados coloreados, filtros y
 * encabezado fijo), con los colores del logo.
 */
export async function exportXlsx<T>(name: string, title: string, columns: ExportColumn<T>[], rows: T[]) {
  // exceljs pesa: se carga solo al exportar.
  const ExcelJS = (await import("exceljs")).default;
  const workbook = new ExcelJS.Workbook();
  workbook.creator = "Creativas";
  workbook.created = new Date();
  const ws = workbook.addWorksheet(title.slice(0, 31));
  const nCols = columns.length;

  const thinBorder = {
    top: { style: "thin" as const, color: { argb: BORDER } },
    left: { style: "thin" as const, color: { argb: BORDER } },
    bottom: { style: "thin" as const, color: { argb: BORDER } },
    right: { style: "thin" as const, color: { argb: BORDER } },
  };
  const solid = (argb: string) => ({ type: "pattern" as const, pattern: "solid" as const, fgColor: { argb } });

  // ---- Título ----
  ws.mergeCells(1, 1, 1, nCols);
  const titleCell = ws.getCell(1, 1);
  titleCell.value = title;
  titleCell.font = { name: "Arial", size: 15, bold: true, color: { argb: WHITE } };
  titleCell.fill = solid(BRAND_DARK);
  titleCell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
  ws.getRow(1).height = 30;

  // ---- Subtítulo ----
  ws.mergeCells(2, 1, 2, nCols);
  const subCell = ws.getCell(2, 1);
  const today = new Date().toLocaleDateString("es-PE", { day: "numeric", month: "long", year: "numeric" });
  subCell.value = `Exportado el ${today} · ${rows.length} ${rows.length === 1 ? "registro" : "registros"}`;
  subCell.font = { name: "Arial", size: 9, italic: true, color: { argb: SUBTITLE } };
  subCell.fill = solid(SUBTITLE_FILL);
  subCell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
  ws.getRow(2).height = 18;

  ws.getRow(3).height = 6;

  const HEADER_ROW = 4;
  const FIRST_DATA_ROW = 5;

  // ---- Encabezado ----
  columns.forEach((column, index) => {
    const cell = ws.getCell(HEADER_ROW, index + 1);
    cell.value = column.label;
    cell.font = { name: "Arial", size: 10.5, bold: true, color: { argb: WHITE } };
    cell.fill = solid(BRAND);
    cell.alignment = { vertical: "middle", horizontal: "center", wrapText: true };
    cell.border = thinBorder;
    ws.getColumn(index + 1).width = column.width ?? 20;
  });
  ws.getRow(HEADER_ROW).height = 32;

  // ---- Datos ----
  rows.forEach((row, rowIndex) => {
    const r = FIRST_DATA_ROW + rowIndex;
    const stripe = rowIndex % 2 === 0 ? WHITE : STRIPE;

    columns.forEach((column, colIndex) => {
      const cell = ws.getCell(r, colIndex + 1);
      const kind = column.kind ?? "text";
      cell.value = column.value(row);
      cell.font = { name: "Arial", size: 10.5, color: { argb: INK } };
      cell.border = thinBorder;
      cell.fill = solid(stripe);

      if (kind === "status") {
        const tone = TONES[column.tone?.(row) ?? "neutral"];
        cell.font = { name: "Arial", size: 10.5, bold: true, color: { argb: tone.text } };
        cell.fill = solid(tone.fill);
        cell.alignment = { vertical: "middle", horizontal: "center" };
      } else if (kind === "money" || kind === "number") {
        cell.alignment = { vertical: "middle", horizontal: "right" };
        if (kind === "money") cell.numFmt = column.currency?.(row) === "USD" ? '"US$ "#,##0.00' : '"S/ "#,##0.00';
      } else if (kind === "center") {
        cell.alignment = { vertical: "middle", horizontal: "center" };
      } else {
        cell.alignment = { vertical: "middle", horizontal: "left", indent: 1 };
      }
    });
    ws.getRow(r).height = 22;
  });

  // ---- Encabezado fijo, filtros y sin líneas de cuadrícula ----
  ws.views = [{ state: "frozen", ySplit: HEADER_ROW, showGridLines: false }];
  if (rows.length) {
    ws.autoFilter = {
      from: { row: HEADER_ROW, column: 1 },
      to: { row: FIRST_DATA_ROW + rows.length - 1, column: nCols },
    };
  }

  const buffer = await workbook.xlsx.writeBuffer();
  download(
    new Blob([buffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" }),
    `${name}_${stamp()}.xlsx`,
  );
}

export function exportCsv<T>(name: string, columns: ExportColumn<T>[], rows: T[]) {
  const cell = (value: string | number) => {
    const text = String(value);
    return /[",;\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
  };
  const lines = [columns.map((column) => cell(column.label)), ...rows.map((row) => columns.map((column) => cell(column.value(row))))];
  // BOM para que Excel abra los acentos bien.
  download(new Blob(["﻿" + lines.map((line) => line.join(",")).join("\r\n")], { type: "text/csv;charset=utf-8" }), `${name}_${stamp()}.csv`);
}
