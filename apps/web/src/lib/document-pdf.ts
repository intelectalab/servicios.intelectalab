import { apiFetch } from "@/lib/api";

type Rgb = [number, number, number];

// Azul del logo, tinta y grises del documento.
const BRAND: Rgb = [27, 143, 201];
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

/** La fuente del PDF no trae estos signos: se cambian por su equivalente simple. */
const plain = (text: unknown) =>
  String(text ?? "").replace(/[−–—]/g, "-").replace(/…/g, "...").replace(/[→]/g, "->").replace(/[“”]/g, '"').replace(/[‘’]/g, "'");

export const pdfAmount = (value: string | number) =>
  Number(value).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export const pdfMoney = (value: string | number, currency: string) => `${currency === "USD" ? "US$" : "S/"} ${pdfAmount(value)}`;

/**
 * Genera y descarga un documento en PDF con la composición del prototipo: cabecera de
 * la empresa, recuadro con el número, dos bloques de datos, tabla de líneas, totales,
 * notas, firmas, pie y marca de agua. Se arma en el navegador.
 */
export async function downloadDocumentPdf(spec: PdfDocument) {
  // En el portal de clientes la empresa y la persona salen de /portal/me (las rutas internas no le responden).
  const portal = typeof window !== "undefined" && /^\/portal(\/|$)/.test(window.location.pathname);
  // jsPDF pesa: se carga solo al descargar.
  const [{ jsPDF }, { default: autoTable }, company, me] = await Promise.all([
    import("jspdf"),
    import("jspdf-autotable"),
    apiFetch<Company>(portal ? "/v1/portal/me" : "/v1/me/access"),
    portal ? apiFetch<{ name: string }>("/v1/portal/me").catch(() => null) : apiFetch<{ name: string }>("/v1/me").catch(() => null),
  ]);

  const companyName = company.company?.legal_name || company.tenant?.legal_name || company.tenant?.name || "Empresa";
  const ruc = company.company?.tax_identifier;
  const initials = companyName.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();

  const doc = new jsPDF({ unit: "mm", format: "a4" });
  doc.setProperties({ title: plain(`${spec.title} ${spec.number}`), subject: plain(spec.title), author: plain(companyName), creator: "ERP SaaS" });

  // Cabecera: empresa a la izquierda, recuadro con el número a la derecha.
  doc.setFillColor(...BRAND);
  doc.roundedRect(MARGIN, 12, 16, 16, 2, 2, "F");
  doc.setTextColor(255);
  doc.setFont("helvetica", "bold");
  doc.setFontSize(11);
  doc.text(plain(initials), MARGIN + 8, 22, { align: "center" });
  doc.setTextColor(...INK);
  doc.setFontSize(12.5);
  doc.text(plain(companyName), MARGIN + 20, 17.5);
  doc.setFont("helvetica", "normal");
  doc.setFontSize(8.5);
  doc.setTextColor(...MUTED);
  if (ruc) doc.text(plain(`RUC ${ruc}`), MARGIN + 20, 22.5);
  doc.text("Perú", MARGIN + 20, ruc ? 26.5 : 22.5);

  const boxLeft = 128;
  const boxWidth = PAGE_WIDTH - MARGIN - boxLeft;
  doc.setDrawColor(...BRAND);
  doc.setLineWidth(0.6);
  doc.roundedRect(boxLeft, 10, boxWidth, 22, 2, 2);
  doc.setTextColor(...INK);
  doc.setFont("helvetica", "bold");
  doc.setFontSize(spec.title.length > 20 ? 10 : 12);
  doc.text(plain(spec.title), boxLeft + boxWidth / 2, 19, { align: "center" });
  doc.setFont("helvetica", "normal");
  doc.setFontSize(10.5);
  doc.text(plain(spec.number), boxLeft + boxWidth / 2, 26.5, { align: "center" });
  doc.setLineWidth(0.8);
  doc.line(MARGIN, 36, PAGE_WIDTH - MARGIN, 36);

  // Dos bloques de datos.
  const block = (rows: [string, string][], x: number, width: number) => {
    let y = 42;
    rows.forEach(([label, value]) => {
      doc.setFont("helvetica", "bold");
      doc.setFontSize(7.5);
      doc.setTextColor(...MUTED);
      doc.text(plain(label).toUpperCase(), x, y);
      doc.setFont("helvetica", "normal");
      doc.setFontSize(9);
      doc.setTextColor(...INK);
      const lines = doc.splitTextToSize(plain(value), width) as string[];
      doc.text(lines, x, y + 4);
      y += 4 + lines.length * 4 + 2.2;
    });
    return y;
  };

  let y = Math.max(block(spec.left, MARGIN, 100), block(spec.right, boxLeft, boxWidth)) + 2;

  autoTable(doc, {
    startY: y,
    margin: { left: MARGIN, right: MARGIN },
    head: [spec.head],
    body: spec.rows.length
      ? spec.rows.map((row) => row.map(plain))
      : [[{ content: "Sin registros", colSpan: spec.head.length, styles: { halign: "center", textColor: MUTED } }]],
    styles: { font: "helvetica", fontSize: 8.5, cellPadding: 2.2, textColor: INK, lineColor: LINE, lineWidth: 0.1 },
    headStyles: { fillColor: BRAND, textColor: 255, fontStyle: "bold" },
    alternateRowStyles: { fillColor: [246, 248, 251] },
    columnStyles: {
      ...Object.fromEntries(spec.numCols.map((index) => [index, { halign: "right" as const }])),
      ...(spec.head[0] === "#" ? { 0: { cellWidth: 9 } } : {}),
    },
  });
  y = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 4;

  // Totales, con el marcado resaltado.
  if (y + spec.totals.length * 6 + 6 > 270) {
    doc.addPage();
    y = 20;
  }
  y += 4;
  spec.totals.forEach(([label, value, strong]) => {
    if (strong) {
      doc.setFillColor(...BRAND);
      doc.rect(120, y - 4, PAGE_WIDTH - MARGIN - 120, 6.4, "F");
      doc.setTextColor(255);
      doc.setFont("helvetica", "bold");
    } else {
      doc.setTextColor(...INK);
      doc.setFont("helvetica", "normal");
    }
    doc.setFontSize(9);
    doc.text(plain(label), 122, y);
    doc.text(plain(value), PAGE_WIDTH - MARGIN - 2, y, { align: "right" });
    y += 6.6;
  });
  y += 3;

  spec.notes.forEach(([label, text]) => {
    const lines = doc.splitTextToSize(plain(text), PAGE_WIDTH - 2 * MARGIN) as string[];
    if (y + 6 + lines.length * 3.8 > 272) {
      doc.addPage();
      y = 20;
    }
    doc.setFont("helvetica", "bold");
    doc.setFontSize(8);
    doc.setTextColor(...BRAND);
    doc.text(plain(label).toUpperCase(), MARGIN, y);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(8.5);
    doc.setTextColor(...INK);
    doc.text(lines, MARGIN, y + 4);
    y += 6 + lines.length * 3.8;
  });

  // Firmas.
  const signatures = spec.signatures(companyName);
  const stamped = spec.stamps?.some(Boolean) ?? false;
  if (y + (stamped ? 56 : 28) > 272) {
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
      const top = y - 27;
      doc.setDrawColor(...BRAND);
      doc.setLineWidth(0.35);
      doc.roundedRect(left, top, boxWidth, 24, 2, 2);
      doc.setFillColor(...BRAND);
      doc.circle(left + 5.5, top + 5, 2.4, "F");
      doc.setDrawColor(255);
      doc.setLineWidth(0.55);
      doc.line(left + 4.3, top + 5.1, left + 5.2, top + 6.1);
      doc.line(left + 5.2, top + 6.1, left + 6.9, top + 3.9);
      doc.setFont("helvetica", "bold");
      doc.setFontSize(7.5);
      doc.setTextColor(...BRAND);
      doc.text("FIRMADO ELECTRÓNICAMENTE", left + 9.5, top + 6);
      doc.setTextColor(...INK);
      if (script) doc.setFont("GreatVibes", "normal");
      else doc.setFont("times", "italic");
      let size = script ? 22 : 15;
      doc.setFontSize(size);
      while (size > 11 && doc.getTextWidth(plain(stamp)) > boxWidth - 8) doc.setFontSize(--size);
      doc.text(plain(stamp), left + boxWidth / 2, top + 18.5, { align: "center" });
      doc.setFont("helvetica", "normal");
    }
    doc.setDrawColor(...MUTED);
    doc.setLineWidth(0.3);
    doc.line(x, y, x + width, y);
    doc.setFontSize(8);
    doc.setTextColor(...INK);
    doc.text(doc.splitTextToSize(plain(text), width) as string[], x + width / 2, y + 4, { align: "center" });
  });

  // Pie y marca de agua en cada página.
  const generated = new Date().toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
  const pages = doc.getNumberOfPages();
  for (let page = 1; page <= pages; page++) {
    doc.setPage(page);
    doc.setDrawColor(...LINE);
    doc.setLineWidth(0.2);
    doc.line(MARGIN, 284, PAGE_WIDTH - MARGIN, 284);
    doc.setFont("helvetica", "normal");
    doc.setFontSize(7);
    doc.setTextColor(...MUTED);
    doc.text(plain(`Generado el ${generated}${me?.name ? ` por ${me.name}` : ""}`), MARGIN, 288.5);
    doc.text(`${page} / ${pages}`, PAGE_WIDTH - MARGIN, 288.5, { align: "right" });

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
