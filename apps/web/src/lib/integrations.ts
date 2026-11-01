import type { Tone } from "@/components/ui/proto";

export type DeliveryStatus = "PENDING" | "RETRY" | "SENT" | "DEAD";

export type WebhookEndpoint = {
  id: string;
  name: string;
  url: string;
  description: string | null;
  active: boolean;
  events: string[];
  event_labels: string[];
  credential_id: string | null;
  sent: number;
  dead: number;
  queued: number;
  last_delivered_at: string | null;
  creator: string | null;
  created_at: string | null;
};

/** Tarjeta de Conexiones (formato del prototipo), con datos reales. */
export type Connection = {
  key: string;
  name: string;
  status: "ACTIVE" | "PENDING_SETUP" | "PENDING_PROVIDER" | "DISABLED";
  provider: string;
  events: string[];
  credential: string | null;
  /** Llamadas y errores de las últimas 24 h; null si no aplica. */
  calls: number | null;
  errors: number | null;
  note: string | null;
  link: { href: string; label: string } | null;
  endpoint_id?: string;
};

export type IntegrationsData = {
  connections: Connection[];
  endpoints: WebhookEndpoint[];
  events: { key: string; label: string }[];
  counts: Record<DeliveryStatus, number>;
  max_attempts: number;
};

export type Delivery = {
  id: string;
  endpoint_id: string;
  endpoint: string;
  event: string;
  event_label: string;
  record: string | null;
  status: DeliveryStatus;
  attempts: number;
  response_code: number | null;
  error: string | null;
  next_attempt_at: string | null;
  delivered_at: string | null;
  created_at: string | null;
};

/** En el detalle, `attempts` es la lista de intentos (en el listado es cuántos van). */
export type DeliveryDetail = Omit<Delivery, "attempts"> & {
  url: string;
  payload: unknown;
  attempts: { number: number; ok: boolean; code: number | null; body: string | null; error: string | null; ms: number | null; at: string }[];
};

export const DELIVERY_STATES: [DeliveryStatus, string, Tone][] = [
  ["PENDING", "Pendiente", "info"],
  ["RETRY", "Reintentando", "warning"],
  ["SENT", "Entregado", "success"],
  ["DEAD", "Fallido", "danger"],
];

export const CONNECTION_STATES: Record<Connection["status"], [string, Tone]> = {
  ACTIVE: ["Activa", "success"],
  PENDING_SETUP: ["Sin configurar", "warning"],
  PENDING_PROVIDER: ["Por definir", "muted"],
  DISABLED: ["Desactivada", "muted"],
};
