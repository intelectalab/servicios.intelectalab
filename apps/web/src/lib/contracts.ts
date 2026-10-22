import type { Tone } from "@/components/ui/proto";
import { FREQUENCIES, customerDocument, customerName, shortDate } from "@/lib/commercial";
import { downloadDocumentPdf, pdfAmount, pdfMoney } from "@/lib/document-pdf";
import type { Contract, ContractItem, ContractStatus, SignatureStatus } from "@/types/commercial";

export const CONTRACT_STATES: [ContractStatus, string, Tone][] = [
  ["DRAFT", "Borrador", "neutral"],
  ["ACTIVE", "Vigente", "success"],
  ["SUSPENDED", "Suspendido", "warning"],
  ["TERMINATED", "Terminado", "muted"],
  ["EXPIRED", "Vencido", "muted"],
];

/** Estados de la solicitud de firma electrónica (los del prototipo). */
export const SIGNATURE_STATES: [SignatureStatus, string, Tone][] = [
  ["PENDING", "Pendiente", "warning"],
  ["SIGNED", "Firmada", "success"],
  ["DECLINED", "Rechazada", "danger"],
  ["EXPIRED", "Vencida", "muted"],
  ["CANCELLED", "Anulada", "muted"],
];

/** Estado de la firma de cada parte (cliente o empresa). */
export const SIGNER_STATES: Record<string, [string, Tone]> = {
  PENDING: ["Pendiente", "warning"], SIGNED: ["Firmado", "success"], DECLINED: ["Rechazado", "danger"],
};

export const SIGNATURE_PROVIDERS: Record<string, string> = { SIMPLE: "Firma electrónica simple (en el sistema)" };

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

const signedAt = (value: string) => new Date(value).toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" });

/**
 * Genera y descarga el contrato en PDF, con los datos de su ficha. Firmado electrónicamente por las
 * dos partes, ya no es un borrador: cada línea de firma lleva quién firmó, cuándo y su evidencia, y
 * las notas, la huella del documento firmado.
 */
export async function downloadContractPdf(contract: Contract) {
  const currency = contract.currency;
  const signature = contract.signature?.status === "SIGNED" ? contract.signature : null;
  const evidence = (side: "CLIENTE" | "EMPRESA") => {
    const signer = signature?.signers.find((item) => item.side === side);
    if (!signer?.signed_at) return "";
    return `\nFirmado electrónicamente por ${signer.name ?? "-"}\n${signedAt(signer.signed_at)} · código verificado${signer.ip_address ? ` · IP ${signer.ip_address}` : ""}`
      + `\nEvidencia ${signer.evidence_hash?.slice(0, 24) ?? "-"}...`;
  };

  await downloadDocumentPdf({
    file: `Contrato_${contract.contract_number}${signature ? "_firmado" : ""}`,
    title: "CONTRATO DE SERVICIOS",
    number: contract.contract_number,
    watermark: signature && contract.status === "DRAFT" ? undefined : WATERMARK[contract.status],
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
      ...(signature ? [[
        "Firma electrónica",
        `Firmado por ambas partes el ${signedAt(signature.completed_at ?? signature.created_at)} con firma electrónica simple (nombre, código de verificación enviado al correo y aceptación). Huella SHA-256 del documento firmado: ${signature.document_hash}`,
      ] as [string, string]] : []),
    ],
    signatures: (companyName) => [
      `Por el cliente: ${customerName(contract.customer)}${evidence("CLIENTE")}`,
      `Por ${companyName}${evidence("EMPRESA")}`,
    ],
    stamps: signature
      ? (["CLIENTE", "EMPRESA"] as const).map((side) => signature.signers.find((item) => item.side === side && item.signed_at)?.name ?? null)
      : undefined,
  });
}
