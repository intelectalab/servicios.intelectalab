import type { Tone } from "@/components/ui/proto";
import { FREQUENCIES, customerDocument, customerName, shortDate } from "@/lib/commercial";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import type { Contract, ContractItem, ContractStatus } from "@/types/commercial";

export const CONTRACT_STATES: [ContractStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["ACTIVE", "Vigente", "success"],
  ["SUSPENDED", "Suspendido", "warning"],
  ["TERMINATED", "Terminado", "muted"],
  ["EXPIRED", "Vencido", "muted"],
];

export const SLA_LEVELS: Record<string, string> = { ESTANDAR: "Estándar", PREMIUM: "Premium" };

export const slaLabel = (level: string) => SLA_LEVELS[level] ?? level;

/** "01 oct. 2026 → 30 sept. 2027" */
export const validity = (contract: Pick<Contract, "start_date" | "end_date">) =>
  `${shortDate(contract.start_date)} → ${shortDate(contract.end_date)}`;

/** Cómo se factura la línea, según el servicio copiado de la cotización. */
export function billingOf(item: ContractItem): [string, "accent" | "neutral"] {
  const snapshot = item.service_snapshot;
  if (snapshot?.frequency) {
    const label = FREQUENCIES[snapshot.frequency] ?? snapshot.frequency;
    return [label.charAt(0).toUpperCase() + label.slice(1), "accent"];
  }
  return [snapshot?.billing_mode === "PER_EVENT" ? "Por evento" : "Única", "neutral"];
}

// Marca de agua de los contratos que no están vigentes.
const WATERMARK: Record<string, string> = { DRAFT: "BORRADOR", EXPIRED: "VENCIDO" };

/** Genera y descarga el contrato en PDF, con los datos de su ficha. */
export async function downloadContractPdf(contract: Contract) {
  const currency = contract.currency;

  await downloadDocumentPdf({
    file: `Contrato_${contract.contract_number}`,
    title: "CONTRATO DE SERVICIOS",
    number: contract.contract_number,
    watermark: WATERMARK[contract.status],
    left: [
      ["Cliente", customerName(contract.customer)],
      ["Documento", customerDocument(contract.customer) || "-"],
      ["Dirección", contract.customer_contact?.address || "-"],
      ["Nivel de servicio (SLA)", slaLabel(contract.sla_level)],
    ],
    right: [
      ["Inicio", shortDate(contract.start_date)],
      ["Fin", shortDate(contract.end_date)],
      ["Cotización de origen", contract.quotation?.quotation_number ?? "-"],
      ["Responsable", contract.creator?.name ?? "-"],
    ],
    head: ["#", "Servicio", "Facturación", "Cant.", "P. unitario", "Total c/IGV"],
    rows: (contract.items ?? []).map((item, index) => [
      String(index + 1),
      item.description,
      billingOf(item)[0],
      String(Number(item.quantity)),
      pdfAmount(item.unit_price),
      pdfAmount(item.line_total),
    ]),
    numCols: [3, 4, 5],
    totals: [["Valor total del contrato (inc. IGV)", pdfMoney(contract.total_value, currency), true]],
    notes: [
      ["Condiciones", contract.terms || "-"],
      [
        "Cláusulas",
        "1. Objeto: prestación de los servicios detallados. 2. Vigencia: según fechas indicadas, renovable por acuerdo. 3. Niveles de servicio: según el SLA pactado. 4. Facturación y pago: según la frecuencia indicada en cada línea. 5. Confidencialidad y protección de datos personales (Ley 29733). (Texto de referencia: el modelo final lo define el área legal.)",
      ],
    ],
    signatures: (companyName) => [`Por el cliente: ${customerName(contract.customer)}`, `Por ${companyName}`],
  });
}
