import { apiFetch } from "@/lib/api";
import type { PaginatedResponse } from "@/types/customer";

export type ExportColumn<T> = { label: string; value: (row: T) => string | number; width?: number };

const PAGE_SIZE = 200;
const BRAND = "FF1B8FC9";

/** Trae todas las páginas de un listado con los filtros que tenga puestos. */
export async function fetchAllPages<T>(path: string, params: URLSearchParams): Promise<T[]> {
  const rows: T[] = [];
  for (let page = 1; ; page++) {
    const query = new URLSearchParams(params);
    query.set("per_page", String(PAGE_SIZE));
    query.set("page", String(page));
    const response = await apiFetch<PaginatedResponse<T>>(`${path}?${query}`);
    rows.push(...response.data);
    if (page >= response.last_page) return rows;
  }
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

export async function exportXlsx<T>(name: string, sheet: string, columns: ExportColumn<T>[], rows: T[]) {
  // exceljs pesa: se carga solo al exportar.
  const ExcelJS = (await import("exceljs")).default;
  const workbook = new ExcelJS.Workbook();
  workbook.created = new Date();
  const ws = workbook.addWorksheet(sheet);

  ws.columns = columns.map((column) => ({ header: column.label, width: column.width ?? 22 }));
  const header = ws.getRow(1);
  header.font = { bold: true, color: { argb: "FFFFFFFF" } };
  header.fill = { type: "pattern", pattern: "solid", fgColor: { argb: BRAND } };
  rows.forEach((row) => ws.addRow(columns.map((column) => column.value(row))));
  ws.views = [{ state: "frozen", ySplit: 1 }];

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
