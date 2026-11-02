import type { Tone } from "@/components/ui/proto";

export type MessageTemplate = {
  event: string;
  label: string;
  audience: "INTERNAL" | "CUSTOMER";
  channel: "EMAIL";
  variables: string[];
  subject: string;
  body: string;
  default_subject: string;
  default_body: string;
  customized: boolean;
  active: boolean;
  lock_version: number;
  updated_at: string | null;
};

export type MessagingChannels = { email: boolean; sender_name: string; reply_to: string; from_address: string; whatsapp: null };

export type MessagingData = {
  kpis: { sent: number; opened: number; failed: number; queued: number; total: number; active_templates: number };
  templates: MessageTemplate[];
  channels: MessagingChannels;
  states: Record<string, string>;
  events: Record<string, string>;
};

export type OutgoingMessage = {
  id: string;
  channel: "EMAIL" | "WHATSAPP";
  event: string;
  event_label: string;
  audience: "INTERNAL" | "CUSTOMER";
  to: string | null;
  to_name: string | null;
  subject: string;
  status: "QUEUED" | "SENT" | "FAILED" | "SKIPPED";
  error: string | null;
  attempts: number;
  opened_at: string | null;
  sent_at: string | null;
  at: string;
  body?: string;
  link?: string | null;
};

export const MESSAGE_STATES: [OutgoingMessage["status"], string, Tone][] = [
  ["QUEUED", "En cola", "info"],
  ["SENT", "Enviado", "success"],
  ["FAILED", "Fallido", "danger"],
  ["SKIPPED", "Omitido", "muted"],
];

export const AUDIENCES: [OutgoingMessage["audience"], string][] = [
  ["CUSTOMER", "Cliente"],
  ["INTERNAL", "Equipo interno"],
];

/** Texto de ejemplo de cada variable, para la vista previa de la plantilla. */
export const SAMPLE: Record<string, string> = {
  titulo: "Cotización por aprobar",
  detalle: "COT-2026-0012 · S/ 4,500.00",
  empresa: "Tu empresa",
  enlace: "https://…",
  cliente: "Minera Los Andes S.A.C.",
  numero: "COT-2026-0012",
  total: "S/ 4,500.00",
  vigencia: "30/11/2026",
  tipo: "factura",
  vencimiento: "15/12/2026",
  asunto: "Impresora sin red",
  mensaje: "Ya reiniciamos el equipo y quedó conectado.",
  resolucion: "Se cambió el cable de red.",
};

export const render = (text: string, values: Record<string, string>) =>
  text.replace(/\{([a-z_]+)\}/g, (match, key: string) => (key in values ? values[key] : match));
