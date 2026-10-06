import { apiFetch } from "@/lib/api";
import { customerDocument, customerName, shortDate } from "@/lib/commercial";
import type { Quotation } from "@/types/commercial";

type Rgb = [number, number, number];

// Azul del logo, tinta y grises del documento.
const BRAND: Rgb = [27, 143, 201];
const INK: Rgb = [29, 29, 27];
const MUTED: Rgb = [110, 120, 135];
const LINE: Rgb = [215, 220, 228];

const PAGE_WIDTH = 210;
const MARGIN = 14;

// Marca de agua de los documentos que no son una oferta vigente.
const WATERMARK: Record<string, string> = {
  DRAFT: "BORRADOR", CANCELLED: "ANULADO", REJECTED: "RECHAZADO", EXPIRED: "VENCIDA",
};

type Company = {
  tenant: { name: string; code: string; legal_name: string | null } | null;
  company: { legal_name: string | null; tax_identifier: string | null } | null;
};

/** La fuente del PDF no trae estos signos: se cambian por su equivalente simple. */
const plain = (text: unknown) =>
  String(text ?? "").replace(/[−–—]/g, "-").replace(/…/g, "...").replace(/[→]/g, "->").replace(/[“”]/g, '"').replace(/[‘’]/g, "'");

const amount = (value: string | number) =>
  Number(value).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const withCurrency = (value: string | number, currency: string) => `${currency === "USD" ? "US$" : "S/"} ${amount(value)}`;

/**
 * Genera y descarga la cotización en PDF, con la misma composición que el prototipo:
 * cabecera de la empresa, recuadro con el número, datos del cliente, líneas, totales,
 * condiciones y firmas. Se arma en el navegador con los datos de la ficha.
 */
export async function downloadQuotationPdf(quotation: Quotation) {
  // jsPDF pesa: se carga solo al descargar.
  const [{ jsPDF }, { default: autoTable }, company, me] = await Promise.all([
    import("jspdf"),
    import("jspdf-autotable"),
    apiFetch<Company>("/v1/me/access"),
    apiFetch<{ name: string }>("/v1/me").catch(() => null),
  ]);

  const companyName = company.company?.legal_name || company.tenant?.legal_name || company.tenant?.name || "Empresa";
  const ruc = company.company?.tax_identifier;
  const initials = companyName.split(" ").filter(Boolean).slice(0, 2).map((word) => word[0]).join("").toUpperCase();
  const currency = quotation.currency;
  const number = quotation.quotation_number + (quotation.version > 1 ? ` (v${quotation.version})` : "");

  const doc = new jsPDF({ unit: "mm", format: "a4" });
  doc.setProperties({ title: plain(`COTIZACIÓN ${number}`), subject: "Cotización", author: plain(companyName), creator: "ERP SaaS" });

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
  doc.setFontSize(12);
  doc.text("COTIZACIÓN", boxLeft + boxWidth / 2, 19, { align: "center" });
  doc.setFont("helvetica", "normal");
  doc.setFontSize(10.5);
  doc.text(plain(number), boxLeft + boxWidth / 2, 26.5, { align: "center" });
  doc.setLineWidth(0.8);
  doc.line(MARGIN, 36, PAGE_WIDTH - MARGIN, 36);

  // Dos bloques de datos: cliente y condiciones de emisión.
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

  const leftEnd = block(
    [
      ["Cliente", customerName(quotation.customer)],
      ["Documento", customerDocument(quotation.customer) || "-"],
      ["Dirección", quotation.customer_contact?.address || "-"],
      ["Correo", quotation.customer_contact?.email || "-"],
    ],
    MARGIN,
    100,
  );
  const rightEnd = block(
    [
      ["Fecha de emisión", shortDate(quotation.created_at)],
      ["Válida hasta", shortDate(quotation.valid_until)],
      ["Moneda", currency === "USD" ? `Dólares (T.C. ${quotation.exchange_rate ? Number(quotation.exchange_rate).toFixed(3) : "-"})` : "Soles"],
      ["Ejecutivo", quotation.creator?.name ?? "-"],
    ],
    boxLeft,
    boxWidth,
  );
  let y = Math.max(leftEnd, rightEnd) + 2;

  autoTable(doc, {
    startY: y,
    margin: { left: MARGIN, right: MARGIN },
    head: [["#", "Descripción", "Unidad", "Cant.", "P. unitario", "Desc.", "Total c/IGV"]],
    body: quotation.items.map((item, index) => {
      const gross = Number(item.quantity) * Number(item.unit_price);
      const pct = gross > 0 ? Math.round((Number(item.discount) / gross) * 1000) / 10 : 0;
      return [
        String(index + 1),
        plain(item.description),
        plain(item.service_snapshot?.unit ?? item.service?.unit ?? "-"),
        String(Number(item.quantity)),
        amount(item.unit_price),
        `${pct}%`,
        amount(item.line_total),
      ];
    }),
    styles: { font: "helvetica", fontSize: 8.5, cellPadding: 2.2, textColor: INK, lineColor: LINE, lineWidth: 0.1 },
    headStyles: { fillColor: BRAND, textColor: 255, fontStyle: "bold" },
    alternateRowStyles: { fillColor: [246, 248, 251] },
    columnStyles: { 0: { cellWidth: 9 }, 3: { halign: "right" }, 4: { halign: "right" }, 5: { halign: "right" }, 6: { halign: "right" } },
  });
  y = (doc as unknown as { lastAutoTable: { finalY: number } }).lastAutoTable.finalY + 4;

  // Totales, con el total resaltado.
  const gross = Number(quotation.subtotal) + Number(quotation.discount_total);
  const totals: [string, string, boolean?][] = [
    ["Subtotal", withCurrency(gross, currency)],
    ["Descuentos", `- ${withCurrency(quotation.discount_total, currency)}`],
    ["IGV 18%", withCurrency(quotation.tax_total, currency)],
    ["TOTAL", withCurrency(quotation.total, currency), true],
  ];
  if (y + totals.length * 6 + 6 > 270) {
    doc.addPage();
    y = 20;
  }
  y += 4;
  totals.forEach(([label, value, strong]) => {
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

  const notes: [string, string][] = [
    ["Condiciones", quotation.notes || "-"],
    [
      "Validez",
      `Esta cotización es válida hasta el ${shortDate(quotation.valid_until)}. Los precios corresponden a la versión del catálogo vigente al emitirla.`,
    ],
  ];
  notes.forEach(([label, text]) => {
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
  const signatures = ["Aceptación del cliente", `Por ${companyName}`];
  if (y + 28 > 272) {
    doc.addPage();
    y = 20;
  }
  y += 16;
  const gap = 10;
  const width = (PAGE_WIDTH - 2 * MARGIN - gap * (signatures.length - 1)) / signatures.length;
  signatures.forEach((text, index) => {
    const x = MARGIN + index * (width + gap);
    doc.setDrawColor(...MUTED);
    doc.setLineWidth(0.3);
    doc.line(x, y, x + width, y);
    doc.setFontSize(8);
    doc.setTextColor(...INK);
    doc.text(doc.splitTextToSize(plain(text), width) as string[], x + width / 2, y + 4, { align: "center" });
  });

  // Pie y marca de agua en cada página.
  const generated = new Date().toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });
  const watermark = WATERMARK[quotation.status];
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

    if (watermark) {
      doc.saveGraphicsState();
      doc.setGState(doc.GState({ opacity: 0.08 }));
      doc.setFont("helvetica", "bold");
      doc.setFontSize(80);
      doc.setTextColor(200, 30, 40);
      doc.text(watermark, 105, 170, { align: "center", angle: 35 });
      doc.restoreGraphicsState();
    }
  }

  doc.save(`Cotizacion_${quotation.quotation_number}.pdf`);
}
