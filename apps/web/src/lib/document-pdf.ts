import { apiFetch } from "@/lib/api";
import { DEFAULT_PRIMARY, hexToRgb, loadBranding, readableOnWhite, textOn, tint } from "@/lib/branding";

type Rgb = [number, number, number];

// Tinta y grises del documento; los colores de la marca salen de Configuración → Marca de la empresa.
const INK: Rgb = [29, 29, 27];
const MUTED: Rgb = [110, 120, 135];
const LINE: Rgb = [215, 220, 228];

const PAGE_WIDTH = 210;
const MARGIN = 14;

type Company = {
  tenant: { name: string; code: string; legal_name: string | null } | null;
  company: { legal_name: string | null; tax_identifier: string | null } | null;
};

/** Lo que cambia de un documento a otro (cotización, contrato…); la composición es común. */
export type PdfDocument = {
  /** Nombre del archivo, sin extensión. */
  file: string;
  /** Título del recuadro: "COTIZACIÓN", "CONTRATO DE SERVICIOS"… */
  title: string;
  number: string;
  /** Marca de agua en diagonal (BORRADOR, ANULADO…), si corresponde. */
  watermark?: string;
  left: [string, string][];
  right: [string, string][];
  head: string[];
  rows: string[][];
  /** Columnas numéricas (alineadas a la derecha), por índice. */
  numCols: number[];
  /** Totales; el marcado con `true` va resaltado. */
  totals: [string, string, boolean?][];
  notes: [string, string][];
  /** Textos bajo las líneas de firma; recibe la razón social de la empresa. */
  signatures: (companyName: string) => string[];
  /**
   * Sello sobre cada línea de firma (en el mismo orden): nombre de quien firmó electrónicamente,
   * o null si esa parte no firmó. Sin sellos, las líneas quedan en blanco.
   */
  stamps?: (string | null)[];
};

// Letra manuscrita del sello de firma (Great Vibes, licencia OFL, en public/fonts). Se carga una vez.
const SCRIPT_FONT = "/fonts/GreatVibes-Regular.ttf";
let scriptFont: Promise<string | null> | null = null;

function loadScriptFont(): Promise<string | null> {
  scriptFont ??= fetch(SCRIPT_FONT)
    .then((response) => (response.ok ? response.arrayBuffer() : Promise.reject(new Error("sin fuente"))))
    .then((buffer) => {
      let binary = "";
      const bytes = new Uint8Array(buffer);
      for (let i = 0; i < bytes.length; i += 0x8000) binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
      return btoa(binary);
    })
    // Sin la letra (sin conexión), el sello sale con cursiva normal.
    .catch(() => { scriptFont = null; return null; });
  return scriptFont;
}

/** Las primeras `max` líneas; si sobra texto, la última termina en "...". */
const clip = (lines: string[], max: number) =>
  lines.length <= max ? lines : [...lines.slice(0, max - 1), `${lines[max - 1].replace(/[\s,.;:]+$/, "")}...`];

/** La fuente del PDF no trae estos signos: se cambian por su equivalente simple. */
const plain = (text: unknown) =>
  String(text ?? "").replace(/[−–—]/g, "-").replace(/…/g, "...").replace(/[→]/g, "->").replace(/[“”]/g, '"').replace(/[‘’]/g, "'");

export const pdfAmount = (value: string | number) =>
  Number(value).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const pdfMoney = (value: string | number, currency: string) => `${currency === "USD" ? "US$" : "S/"} ${pdfAmount(value)}`;

/**
 * Genera y descarga un documento en PDF con la marca de la empresa (Configuración → Marca de
 * la empresa): franja de su color arriba, su logo (o sus iniciales) con razón social, RUC y
 * dirección, el tipo de documento con su número, los datos en recuadros, la tabla de líneas con
 * la cabecera de su color, totales, notas, firmas y un pie con sus datos de contacto. Se arma en
 * el navegador; sin marca, sale con el azul de siempre.
 */
export async function downloadDocumentPdf(spec: PdfDocument) {
  // En el portal de clientes la persona sale de /portal/me (las rutas internas no le responden).
  const portal = typeof window !== "undefined" && /^\/portal(\/|$)/.test(window.location.pathname);
  // jsPDF pesa: se carga solo al descargar.
  const [{ jsPDF }, { default: autoTable }, branding, me] = await Promise.all([
    import("jspdf"),
    import("jspdf-autotable"),
    loadBranding(),
    portal ? apiFetch<{ name: string }>("/v1/portal/me").catch(() => null) : apiFetch<{ name: string }>("/v1/me").catch(() => null),
  ]);
  // Sin la marca (no se pudo leer), los datos de la empresa salen de la sesión, como antes.
  const company = branding ? null : await apiFetch<Company>(portal ? "/v1/portal/me" : "/v1/me/access").catch(() => null);

  const companyName = branding?.name || company?.company?.legal_name || company?.tenant?.legal_name || company?.tenant?.name || "Empresa";
  const ruc = branding?.tax_identifier ?? company?.company?.tax_identifier ?? null;
  const initials = companyName.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();

  // Colores de la marca: el principal para franjas y cabeceras; para textos y líneas, su versión legible sobre blanco.
  const primaryHex = branding?.primary_color ?? DEFAULT_PRIMARY;
  const PRIMARY = hexToRgb(primaryHex);
  const ACCENT = readableOnWhite(primaryHex);
  const ON_PRIMARY = textOn(primaryHex);
  const SECONDARY = branding?.secondary_color ? hexToRgb(branding.secondary_color) : null;
  const BOX = tint(primaryHex, 0.07);
  const ROW = tint(primaryHex, 0.035);

  const doc = new jsPDF({ unit: "mm", format: "a4" });
  doc.setProperties({ title: plain(`${spec.title} ${spec.number}`), subject: plain(spec.title), author: plain(companyName), creator: plain(companyName) });

  // ---- Cabecera: logo y empresa a la izquierda; tipo de documento y número a la derecha.
  const top = 11;
  let textX = MARGIN;
  let headerBottom = top + 16;
  if (branding?.logo && branding.logo_width && branding.logo_height) {
    const ratio = branding.logo_width / branding.logo_height;
    const width = Math.min(46, 17 * ratio);
    const height = width / ratio;
    doc.addImage(branding.logo, "PNG", MARGIN, top, width, height, undefined, "FAST");
    textX = MARGIN + width + 5;
    headerBottom = top + height;
  } else {
    doc.setFillColor(...PRIMARY);
    doc.roundedRect(MARGIN, top, 16, 16, 2, 2, "F");
    doc.setTextColor(...ON_PRIMARY);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(11);
    doc.text(plain(initials), MARGIN + 8, top + 10, { align: "center" });
    textX = MARGIN + 21;
  }

  const titleLeft = 124;
  const textWidth = titleLeft - textX - 4;
  doc.setTextColor(...INK);
  doc.setFont("helvetica", "bold");
  // La razón social completa: la letra se achica hasta que entre en dos líneas (tres como mucho).
  let nameSize = 11.5;
  doc.setFontSize(nameSize);
  while (nameSize > 8.5 && (doc.splitTextToSize(plain(companyName), textWidth) as string[]).length > 2) doc.setFontSize(--nameSize);
  const nameLines = clip(doc.splitTextToSize(plain(companyName), textWidth) as string[], 3);
  doc.text(nameLines, textX, top + 4.5);
  let textY = top + 4.5 + nameLines.length * nameSize * 0.42;
  doc.setFont("helvetica", "normal");
  doc.setFontSize(8);
  doc.setTextColor(...MUTED);
  const details = [
    ruc ? `RUC ${ruc}` : null,
    ...clip(doc.splitTextToSize(plain(branding?.address ?? "Perú"), textWidth) as string[], 3),
  ].filter(Boolean) as string[];
  details.forEach((line) => {
    doc.text(plain(line), textX, textY);
    textY += 3.7;
  });
  headerBottom = Math.max(headerBottom, textY - 1);

  doc.setTextColor(...ACCENT);
  doc.setFont("helvetica", "bold");
  let titleSize = 16;
  doc.setFontSize(titleSize);
  while (titleSize > 10.5 && doc.getTextWidth(plain(spec.title)) > PAGE_WIDTH - MARGIN - titleLeft) doc.setFontSize(--titleSize);
  doc.text(plain(spec.title), PAGE_WIDTH - MARGIN, top + 6, { align: "right" });
  doc.setTextColor(...INK);
  let numberSize = 10.5;
  doc.setFontSize(numberSize);
  while (numberSize > 7.5 && doc.getTextWidth(plain(`N.º ${spec.number}`)) > PAGE_WIDTH - MARGIN - titleLeft) doc.setFontSize(--numberSize);
  doc.text(plain(`N.º ${spec.number}`), PAGE_WIDTH - MARGIN, top + 12.5, { align: "right" });
  headerBottom = Math.max(headerBottom, top + 14) + 4;

  doc.setDrawColor(...LINE);
  doc.setLineWidth(0.3);
  doc.line(MARGIN, headerBottom, PAGE_WIDTH - MARGIN, headerBottom);

  // ---- Datos en dos recuadros del mismo alto, con un filo del color de la marca.
  const leftX = MARGIN;
  const leftWidth = 106;
  const rightX = MARGIN + leftWidth + 4;
  const rightWidth = PAGE_WIDTH - MARGIN - rightX;
  const pad = 4;
  const measure = (rows: [string, string][], width: number) => {
    doc.setFontSize(9);
    return rows.reduce((height, [, value]) => height + 3.4 + (doc.splitTextToSize(plain(value), width - 2 * pad - 1.5) as string[]).length * 4 + 2, 0);
  };
  const boxTop = headerBottom + 5;
  const boxHeight = Math.max(measure(spec.left, leftWidth), measure(spec.right, rightWidth)) + pad + 1;
  const drawBox = (rows: [string, string][], x: number, width: number) => {
    doc.setFillColor(...BOX);
    doc.roundedRect(x, boxTop, width, boxHeight, 1.8, 1.8, "F");
    doc.setFillColor(...PRIMARY);
    doc.rect(x, boxTop + 1.2, 1.1, boxHeight - 2.4, "F");
    let y = boxTop + pad + 2;
    rows.forEach(([label, value]) => {
      doc.setFont("helvetica", "bold");
      doc.setFontSize(7);
      doc.setTextColor(...ACCENT);
      doc.text(plain(label).toUpperCase(), x + pad + 1.5, y);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(9);
      doc.setTextColor(...INK);
      const lines = doc.splitTextToSize(plain(value), width - 2 * pad - 1.5) as string[];
      doc.text(lines, x + pad + 1.5, y + 3.9);
      y += 3.4 + lines.length * 4 + 2;
    });
  };
  drawBox(spec.left, leftX, leftWidth);
  drawBox(spec.right, rightX, rightWidth);

  // ---- Líneas del documento.
  autoTable(doc, {
    startY: boxTop + boxHeight + 6,
    margin: { left: MARGIN, right: MARGIN, top: 14, bottom: 20 },
    head: [spec.head],
    body: spec.rows.length
      ? spec.rows.map((row) => row.map(plain))
      : [[{ content: "Sin registros", colSpan: spec.head.length, styles: { halign: "center", textColor: MUTED } }]],
    styles: { font: "helvetica", fontSize: 8.5, cellPadding: { top: 2.4, bottom: 2.4, left: 2.4, right: 2.4 }, textColor: INK, lineColor: LINE, lineWidth: { bottom: 0.15 } },
    headStyles: { fillColor: PRIMARY, textColor: ON_PRIMARY, fontStyle: "bold", lineWidth: 0 },
    alternateRowStyles: { fillColor: ROW },
    columnStyles: {
      ...Object.fromEntries(spec.numCols.map((index) => [index, { halign: "right" as const }])),
      ...(spec.head[0] === "#" ? { 0: { cellWidth: 9, halign: "center" as const } } : {}),
    },
  });
  let y = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 4;

  // ---- Totales a la derecha; el marcado, con el color de la marca.
  if (y + spec.totals.length * 7 + 6 > 268) {
    doc.addPage();
    y = 20;
  }
  // El recuadro se ensancha hacia la izquierda si una etiqueta larga no cabe junto a su monto.
  const needed = Math.max(...spec.totals.map(([label, value, strong]) => {
    doc.setFont("helvetica", strong ? "bold" : "normal");
    doc.setFontSize(strong ? 10 : 9);
    return doc.getTextWidth(plain(label)) + doc.getTextWidth(plain(value)) + 14;
  }), rightWidth);
  const totalsWidth = Math.min(needed, PAGE_WIDTH - 2 * MARGIN);
  const totalsX = PAGE_WIDTH - MARGIN - totalsWidth;
  y += 2;
  spec.totals.forEach(([label, value, strong]) => {
    if (strong) {
      doc.setFillColor(...PRIMARY);
      doc.roundedRect(totalsX, y - 1, totalsWidth, 8, 1.4, 1.4, "F");
      doc.setTextColor(...ON_PRIMARY);
      doc.setFont("helvetica", "bold");
      doc.setFontSize(10);
      doc.text(plain(label), totalsX + 3, y + 4.4);
      doc.text(plain(value), totalsX + totalsWidth - 3, y + 4.4, { align: "right" });
      y += 9.5;
    } else {
      doc.setFont("helvetica", "normal");
      doc.setFontSize(9);
      doc.setTextColor(...MUTED);
      doc.text(plain(label), totalsX + 3, y + 3.8);
      doc.setTextColor(...INK);
      doc.text(plain(value), totalsX + totalsWidth - 3, y + 3.8, { align: "right" });
      doc.setDrawColor(...LINE);
      doc.setLineWidth(0.15);
      doc.line(totalsX, y + 6, totalsX + totalsWidth, y + 6);
      y += 6.8;
    }
  });
  y += 4;

  // ---- Notas.
  spec.notes.forEach(([label, text]) => {
    const lines = doc.splitTextToSize(plain(text), PAGE_WIDTH - 2 * MARGIN) as string[];
    if (y + 6 + lines.length * 3.8 > 268) {
      doc.addPage();
      y = 20;
    }
    doc.setFont("helvetica", "bold");
    doc.setFontSize(7.5);
    doc.setTextColor(...ACCENT);
    doc.text(plain(label).toUpperCase(), MARGIN, y);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.setTextColor(...INK);
    doc.text(lines, MARGIN, y + 4);
    y += 7 + lines.length * 3.8;
  });

  // ---- Firmas.
  const signatures = spec.signatures(companyName);
  const stamped = spec.stamps?.some(Boolean) ?? false;
  if (y + (stamped ? 56 : 28) > 268) {
    doc.addPage();
    y = 20;
  }
  y += stamped ? 34 : 16;
  const gap = 10;
  const width = (PAGE_WIDTH - 2 * MARGIN - gap * (signatures.length - 1)) / signatures.length;
  const script = stamped ? await loadScriptFont() : null;
  if (script) {
    doc.addFileToVFS("GreatVibes-Regular.ttf", script);
    doc.addFont("GreatVibes-Regular.ttf", "GreatVibes", "normal");
  }
  signatures.forEach((text, index) => {
    const x = MARGIN + index * (width + gap);
    const stamp = spec.stamps?.[index];
    if (stamp) {
      // Sello: recuadro con el check y "Firmado electrónicamente", y el nombre en letra manuscrita.
      const boxWidth = Math.min(width - 6, 74);
      const left = x + (width - boxWidth) / 2;
      const stampTop = y - 27;
      doc.setDrawColor(...ACCENT);
      doc.setLineWidth(0.35);
      doc.roundedRect(left, stampTop, boxWidth, 24, 2, 2);
      doc.setFillColor(...ACCENT);
      doc.circle(left + 5.5, stampTop + 5, 2.4, "F");
      doc.setDrawColor(255);
      doc.setLineWidth(0.55);
      doc.line(left + 4.3, stampTop + 5.1, left + 5.2, stampTop + 6.1);
      doc.line(left + 5.2, stampTop + 6.1, left + 6.9, stampTop + 3.9);
      doc.setFont("helvetica", "bold");
      doc.setFontSize(7.5);
      doc.setTextColor(...ACCENT);
      doc.text("FIRMADO ELECTRÓNICAMENTE", left + 9.5, stampTop + 6);
      doc.setTextColor(...INK);
      if (script) doc.setFont("GreatVibes", "normal");
      else doc.setFont("times", "italic");
      let size = script ? 22 : 15;
      doc.setFontSize(size);
      while (size > 11 && doc.getTextWidth(plain(stamp)) > boxWidth - 8) doc.setFontSize(--size);
      doc.text(plain(stamp), left + boxWidth / 2, stampTop + 18.5, { align: "center" });
      doc.setFont("helvetica", "normal");
    }
    doc.setDrawColor(...MUTED);
    doc.setLineWidth(0.3);
    doc.line(x, y, x + width, y);
    doc.setFontSize(8);
    doc.setTextColor(...INK);
    doc.text(doc.splitTextToSize(plain(text), width) as string[], x + width / 2, y + 4, { align: "center" });
  });

  // ---- En cada página: franja de la marca arriba, pie con contacto y número de página, marca de agua.
  const contact = [branding?.phone, branding?.email, branding?.website].filter(Boolean).join("  ·  ");
  const generated = new Date().toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
  const pages = doc.getNumberOfPages();
  for (let page = 1; page <= pages; page++) {
    doc.setPage(page);
    doc.setFillColor(...PRIMARY);
    doc.rect(0, 0, PAGE_WIDTH, 3.2, "F");
    if (SECONDARY) {
      doc.setFillColor(...SECONDARY);
      doc.rect(0, 3.2, PAGE_WIDTH, 0.9, "F");
    }

    doc.setDrawColor(...PRIMARY);
    doc.setLineWidth(0.5);
    doc.line(MARGIN, 281, PAGE_WIDTH - MARGIN, 281);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(7.5);
    doc.setTextColor(...INK);
    const footerWidth = PAGE_WIDTH - 2 * MARGIN - 18;
    doc.text((doc.splitTextToSize(plain([companyName, ruc ? `RUC ${ruc}` : null].filter(Boolean).join("  ·  ")), footerWidth) as string[])[0], MARGIN, 285.5);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(7);
    doc.setTextColor(...MUTED);
    if (contact) doc.text((doc.splitTextToSize(plain(contact), footerWidth) as string[])[0], MARGIN, 289.3);
    doc.setFontSize(6.5);
    doc.text(plain(`Generado el ${generated}${me?.name ? ` por ${me.name}` : ""}`), MARGIN, contact ? 292.8 : 289.3);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(7.5);
    doc.setTextColor(...ACCENT);
    doc.text(`${page} / ${pages}`, PAGE_WIDTH - MARGIN, 285.5, { align: "right" });

    if (spec.watermark) {
      doc.saveGraphicsState();
      doc.setGState(doc.GState({ opacity: 0.08 }));
      doc.setFont("helvetica", "bold");
      doc.setFontSize(80);
      doc.setTextColor(200, 30, 40);
      doc.text(spec.watermark, 105, 170, { align: "center", angle: 35 });
      doc.restoreGraphicsState();
    }
  }

  doc.save(`${spec.file}.pdf`);
}
