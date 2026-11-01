import { apiFetch } from "@/lib/api";
import { DEFAULT_PRIMARY, hexToRgb, loadBranding, readableOnWhite, textOn, tint } from "@/lib/branding";
import { plain } from "@/lib/document-pdf";

type Rgb = [number, number, number];

const INK: Rgb = [29, 29, 27];
const MUTED: Rgb = [110, 120, 135];
const LINE: Rgb = [215, 220, 228];
const GOOD: Rgb = [24, 128, 72];
const BAD: Rgb = [196, 48, 54];

// A4 apaisado: los reportes tienen muchas columnas.
const W = 297;
const H = 210;
const MARGIN = 12;

export type ReportPdfKpi = { label: string; value: string; delta?: { text: string; good: boolean } | null; hint?: string | null };

export type ReportPdf = {
  /** Nombre del archivo, sin extensión. */
  file: string;
  title: string;
  /** Líneas bajo el título: periodo, filtros aplicados, filas dentro del alcance. */
  meta: string[];
  kpis: ReportPdfKpi[];
  /** En lugar del gráfico: un resumen en dos columnas (tramo o estado → cifra). */
  summary?: { title: string; rows: [string, string][] } | null;
  head: string[];
  rows: string[][];
  /** Columnas numéricas (a la derecha), por índice. */
  numCols: number[];
};

/**
 * Reporte en PDF con la marca de la empresa (Configuración → Marca de la empresa): franja de su
 * color, logo (o iniciales) con razón social y RUC, título y periodo, los indicadores, un resumen y
 * la tabla, con el pie de contacto, quién lo generó y el número de página. Se arma en el navegador.
 */
export async function downloadReportPdf(spec: ReportPdf, options: { asBlob?: boolean } = {}): Promise<Blob | void> {
  const [{ jsPDF }, { default: autoTable }, branding, me] = await Promise.all([
    import("jspdf"),
    import("jspdf-autotable"),
    loadBranding(),
    apiFetch<{ name: string }>("/v1/me").catch(() => null),
  ]);
  const company = branding ? null : await apiFetch<{ tenant: { name: string; legal_name: string | null } | null; company: { legal_name: string | null; tax_identifier: string | null } | null }>("/v1/me/access").catch(() => null);
  const companyName = branding?.name || company?.company?.legal_name || company?.tenant?.legal_name || company?.tenant?.name || "Empresa";
  const ruc = branding?.tax_identifier ?? company?.company?.tax_identifier ?? null;

  const primaryHex = branding?.primary_color ?? DEFAULT_PRIMARY;
  const PRIMARY = hexToRgb(primaryHex);
  const ACCENT = readableOnWhite(primaryHex);
  const ON_PRIMARY = textOn(primaryHex);
  const SECONDARY = branding?.secondary_color ? hexToRgb(branding.secondary_color) : null;
  const BOX = tint(primaryHex, 0.07);
  const ROW = tint(primaryHex, 0.035);

  const doc = new jsPDF({ unit: "mm", format: "a4", orientation: "landscape" });
  doc.setProperties({ title: plain(spec.title), subject: "Reporte", author: plain(companyName), creator: plain(companyName) });

  // ---- Cabecera: empresa a la izquierda; "REPORTE", título y periodo a la derecha.
  const top = 10;
  let textX = MARGIN;
  let headerBottom = top + 14;
  if (branding?.logo && branding.logo_width && branding.logo_height) {
    const ratio = branding.logo_width / branding.logo_height;
    const width = Math.min(40, 14 * ratio);
    const height = width / ratio;
    doc.addImage(branding.logo, "PNG", MARGIN, top, width, height, undefined, "FAST");
    textX = MARGIN + width + 4;
    headerBottom = top + height;
  } else {
    const initials = companyName.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
    doc.setFillColor(...PRIMARY);
    doc.roundedRect(MARGIN, top, 14, 14, 2, 2, "F");
    doc.setTextColor(...ON_PRIMARY);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(10);
    doc.text(plain(initials), MARGIN + 7, top + 9, { align: "center" });
    textX = MARGIN + 18;
  }
  doc.setTextColor(...INK);
  doc.setFont("helvetica", "bold");
  doc.setFontSize(11);
  const nameLines = (doc.splitTextToSize(plain(companyName), 120) as string[]).slice(0, 2);
  doc.text(nameLines, textX, top + 4.5);
  doc.setFont("helvetica", "normal");
  doc.setFontSize(8);
  doc.setTextColor(...MUTED);
  if (ruc) doc.text(plain(`RUC ${ruc}`), textX, top + 4.5 + nameLines.length * 4.6);
  headerBottom = Math.max(headerBottom, top + 4.5 + nameLines.length * 4.6 + 1);

  doc.setFont("helvetica", "bold");
  doc.setFontSize(7.5);
  doc.setTextColor(...ACCENT);
  doc.text("REPORTE", W - MARGIN, top + 2.5, { align: "right" });
  doc.setFontSize(15);
  doc.setTextColor(...INK);
  doc.text(plain(spec.title), W - MARGIN, top + 9, { align: "right" });
  doc.setFont("helvetica", "normal");
  doc.setFontSize(8);
  doc.setTextColor(...MUTED);
  let metaY = top + 14;
  spec.meta.forEach((line) => {
    const lines = (doc.splitTextToSize(plain(line), 150) as string[]).slice(0, 2);
    lines.forEach((text) => {
      doc.text(text, W - MARGIN, metaY, { align: "right" });
      metaY += 3.7;
    });
  });
  headerBottom = Math.max(headerBottom, metaY - 1) + 3;
  doc.setDrawColor(...LINE);
  doc.setLineWidth(0.3);
  doc.line(MARGIN, headerBottom, W - MARGIN, headerBottom);

  // ---- Indicadores: tarjetas con filo de la marca, cifra, cambio frente al periodo anterior y nota.
  let y = headerBottom + 5;
  if (spec.kpis.length) {
    const gap = 4;
    const boxWidth = (W - 2 * MARGIN - gap * (spec.kpis.length - 1)) / spec.kpis.length;
    const boxHeight = 22;
    spec.kpis.forEach((kpi, index) => {
      const x = MARGIN + index * (boxWidth + gap);
      doc.setFillColor(...BOX);
      doc.roundedRect(x, y, boxWidth, boxHeight, 1.8, 1.8, "F");
      doc.setFillColor(...PRIMARY);
      doc.rect(x, y + 1.2, 1.1, boxHeight - 2.4, "F");
      doc.setFont("helvetica", "bold");
      doc.setFontSize(7);
      doc.setTextColor(...ACCENT);
      doc.text(plain(kpi.label).toUpperCase(), x + 5, y + 5);
      doc.setFontSize(14);
      doc.setTextColor(...INK);
      doc.text(plain(kpi.value), x + 5, y + 12);
      doc.setFontSize(7.5);
      let lineY = y + 16.5;
      if (kpi.delta) {
        doc.setTextColor(...(kpi.delta.good ? GOOD : BAD));
        doc.text(plain(kpi.delta.text), x + 5, lineY);
        lineY += 3.5;
      }
      if (kpi.hint) {
        doc.setFont("helvetica", "normal");
        doc.setTextColor(...MUTED);
        doc.text((doc.splitTextToSize(plain(kpi.hint), boxWidth - 8) as string[])[0], x + 5, lineY);
      }
    });
    y += boxHeight + 5;
  }

  // ---- Resumen (en lugar del gráfico).
  if (spec.summary?.rows.length) {
    doc.setFont("helvetica", "bold");
    doc.setFontSize(8);
    doc.setTextColor(...ACCENT);
    doc.text(plain(spec.summary.title).toUpperCase(), MARGIN, y + 2);
    autoTable(doc, {
      startY: y + 4,
      margin: { left: MARGIN, right: W - MARGIN - 110 },
      body: spec.summary.rows.map((row) => row.map(plain)),
      styles: { font: "helvetica", fontSize: 8, cellPadding: 1.6, textColor: INK, lineColor: LINE, lineWidth: { bottom: 0.15 } },
      columnStyles: { 1: { halign: "right", fontStyle: "bold" } },
    });
    y = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 6;
  }

  // ---- Tabla del reporte (las filas filtradas, todas).
  autoTable(doc, {
    startY: y,
    margin: { left: MARGIN, right: MARGIN, top: 14, bottom: 18 },
    head: [spec.head.map(plain)],
    body: spec.rows.length
      ? spec.rows.map((row) => row.map(plain))
      : [[{ content: "No hay datos con estos filtros.", colSpan: spec.head.length, styles: { halign: "center", textColor: MUTED } }]],
    styles: { font: "helvetica", fontSize: 8, cellPadding: 2, textColor: INK, lineColor: LINE, lineWidth: { bottom: 0.15 }, overflow: "linebreak" },
    headStyles: { fillColor: PRIMARY, textColor: ON_PRIMARY, fontStyle: "bold", lineWidth: 0 },
    alternateRowStyles: { fillColor: ROW },
    columnStyles: Object.fromEntries(spec.numCols.map((index) => [index, { halign: "right" as const }])),
    // La cabecera de una columna numérica, alineada como sus cifras.
    didParseCell: (cell) => {
      if (cell.section === "head" && spec.numCols.includes(cell.column.index)) cell.cell.styles.halign = "right";
    },
  });

  // ---- En cada página: franja de la marca arriba y pie con la empresa, contacto, quién lo generó y la página.
  const contact = [branding?.phone, branding?.email, branding?.website].filter(Boolean).join("  ·  ");
  const generated = new Date().toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
  const pages = doc.getNumberOfPages();
  for (let page = 1; page <= pages; page++) {
    doc.setPage(page);
    doc.setFillColor(...PRIMARY);
    doc.rect(0, 0, W, 3, "F");
    if (SECONDARY) {
      doc.setFillColor(...SECONDARY);
      doc.rect(0, 3, W, 0.8, "F");
    }
    doc.setDrawColor(...PRIMARY);
    doc.setLineWidth(0.4);
    doc.line(MARGIN, H - 12, W - MARGIN, H - 12);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(7.5);
    doc.setTextColor(...INK);
    doc.text(plain([companyName, ruc ? `RUC ${ruc}` : null].filter(Boolean).join("  ·  ")), MARGIN, H - 8);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(6.8);
    doc.setTextColor(...MUTED);
    doc.text(plain([contact, `Generado el ${generated}${me?.name ? ` por ${me.name}` : ""}`].filter(Boolean).join("   ·   ")), MARGIN, H - 4.5);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(7.5);
    doc.setTextColor(...ACCENT);
    doc.text(`${page} / ${pages}`, W - MARGIN, H - 8, { align: "right" });
  }

  if (options.asBlob) return doc.output("blob");
  doc.save(`${spec.file}.pdf`);
}
