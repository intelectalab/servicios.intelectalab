import type { Tone } from "@/components/ui/proto";
import type { AntivirusStatus, DocumentStatus, DocumentVisibility } from "@/types/documents";

export const DOCUMENT_STATES: [DocumentStatus, string, Tone][] = [
  ["ACTIVE", "Disponible", "success"],
  ["QUARANTINED", "En cuarentena", "danger"],
  ["ARCHIVED", "Archivado", "muted"],
];

export const VISIBILITIES: [DocumentVisibility, string][] = [
  ["INTERNAL", "Interno"],
  ["PORTAL", "Visible en portal"],
];

/** Opciones de visibilidad del formulario (texto del prototipo). */
export const VISIBILITY_OPTIONS: [DocumentVisibility, string][] = [
  ["INTERNAL", "Interno"],
  ["PORTAL", "Visible para el cliente en el portal"],
];

export const MAX_BYTES = 20 * 1024 * 1024;
export const ACCEPT = ".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,.png,.jpg,.jpeg,.webp,.gif,.zip,.rar,.7z,.dwg,.dxf,.mp4";

/** Resultado del antivirus de un archivo, en texto y color. `engine` "SIMULATED" se dice. */
export function antivirusText(status: AntivirusStatus, engine?: string | null): [string, Tone] {
  const simulated = engine === "SIMULATED" ? " (simulado)" : "";
  switch (status) {
    case "CLEAN": return [`Limpio${simulated}`, "success"];
    case "INFECTED": return [`Amenaza detectada${simulated}`, "danger"];
    case "FAILED": return ["No se pudo analizar", "warning"];
    default: return ["Sin analizar", "warning"];
  }
}

/** Huella SHA-256 abreviada, como en la columna Integridad del prototipo. */
export const shortSha = (sha: string, length = 10) => (sha ? `${sha.slice(0, length)}…` : "—");

/** Huella SHA-256 del archivo, calculada en el navegador; null si el navegador no puede. */
export async function sha256Of(file: File): Promise<string | null> {
  if (typeof crypto === "undefined" || !crypto.subtle) return null;
  const digest = await crypto.subtle.digest("SHA-256", await file.arrayBuffer());
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}
