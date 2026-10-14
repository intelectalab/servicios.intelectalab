import { clearAuthToken, getAuthToken } from "./auth";

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;
  /** Cuerpo JSON de la respuesta de error, tal como lo devolvió la API. */
  body?: unknown;

  constructor(message: string, status: number, errors?: Record<string, string[]>, body?: unknown) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
    this.body = body;
  }
}

const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "";

// --- Ediciones simultáneas ------------------------------------------------------------
// Cada registro editable trae `lock_version`. Se recuerda la última versión vista de cada
// uno y se envía en `If-Match` al modificarlo: si otra persona lo cambió entretanto, la API
// responde 409 en vez de pisar su cambio.

const UUID = "[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}";
// La colección puede ir dentro de su módulo: "quotations" o "crm/leads".
const RECORD_PATH = new RegExp(`^/v1/((?:crm/)?[a-z-]+)/(${UUID})(?:[/?]|$)`, "i");
const LIST_PATH = /^\/v1\/((?:crm\/)?[a-z-]+)(?:\?|$)/i;
const seenVersions = new Map<string, number>();

type Versioned = { id?: unknown; lock_version?: unknown };

function remember(collection: string, row: unknown) {
  const { id, lock_version: version } = (row ?? {}) as Versioned;
  if (typeof id === "string" && typeof version === "number") seenVersions.set(`${collection}/${id}`, version);
}

/** Tras una respuesta correcta: anota las versiones que trae, o olvida la del registro modificado. */
function trackVersions(path: string, method: string, data: unknown) {
  const record = RECORD_PATH.exec(path);
  if (record) {
    const [, collection, id] = record;
    const body = data as Versioned | null;
    if (body?.id === id && typeof body.lock_version === "number") remember(collection, body);
    // Se modificó y la respuesta no trae la versión nueva: mejor no enviar una que ya no vale.
    else if (method !== "GET") seenVersions.delete(`${collection}/${id}`);
    return;
  }
  const list = LIST_PATH.exec(path);
  const rows = (data as { data?: unknown } | null)?.data;
  if (list && method === "GET" && Array.isArray(rows)) rows.forEach((row) => remember(list[1], row));
}

// --- Envíos repetidos -----------------------------------------------------------------
// Un alta lleva una clave (`Idempotency-Key`). Si el mismo envío sale dos veces en pocos
// segundos (doble clic, reintento), usa la misma clave y la API lo registra una sola vez.

const CREATE_PATH = new RegExp(`^/v1/(?:crm/)?(?:[a-z-]+|invoices/from-events|[a-z-]+/${UUID}/(?:versions|contract|order|orders|prices|roles|opportunity|convert|members|tasks|deliverables|credit-note|refund|renew|refill|void|withholding-receipt))$`, "i");
const REPEAT_WINDOW_MS = 10_000;
const recentSends = new Map<string, { key: string; at: number }>();

function idempotencyKey(path: string, method: string, body: unknown): string | null {
  if (method !== "POST" || !CREATE_PATH.test(path) || (body != null && typeof body !== "string")) return null;
  if (typeof crypto === "undefined" || !crypto.randomUUID) return null;

  const now = Date.now();
  for (const [signature, sent] of recentSends) {
    if (now - sent.at > REPEAT_WINDOW_MS) recentSends.delete(signature);
  }
  const signature = `${method} ${path} ${body ?? ""}`;
  const sent = recentSends.get(signature) ?? { key: crypto.randomUUID(), at: now };
  recentSends.set(signature, sent);
  return sent.key;
}

// --- Lecturas repetidas ----------------------------------------------------------------
// Si la misma lectura (GET) se pide otra vez mientras la primera sigue en curso, las dos
// reciben la misma respuesta: no se envía dos veces. Pasa al entrar a una pantalla (en
// desarrollo React monta cada pantalla dos veces) y cuando dos piezas piden el mismo dato.
// Solo se comparte lo que está en curso: una lectura ya terminada se vuelve a pedir.

const pendingReads = new Map<string, Promise<unknown>>();

/**
 * Wrapper sobre fetch que agrega el Bearer token, maneja errores de
 * Laravel (422 con `errors`, 401, etc.) y parsea JSON.
 */
export function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  // Una modificación cambia lo que las lecturas devuelven: las que están en curso ya no se comparten.
  if (method !== "GET") {
    pendingReads.clear();
    return send<T>(path, options, method);
  }
  if (options.signal || options.headers) return send<T>(path, options, method);

  const key = `${getAuthToken() ?? ""} ${path}`;
  const pending = pendingReads.get(key);
  if (pending) return pending as Promise<T>;

  const request = send<T>(path, options, method).finally(() => {
    if (pendingReads.get(key) === request) pendingReads.delete(key);
  });
  pendingReads.set(key, request);
  return request;
}

/**
 * Lectura secundaria de una pantalla (indicadores, listas de los filtros): sale después de las
 * que la pantalla pide en ese mismo momento, para que lo principal (el listado, la ficha) no
 * espere detrás de ella cuando el servidor atiende las peticiones de una en una.
 */
export function apiFetchLater<T>(path: string): Promise<T> {
  return new Promise<void>((resolve) => { setTimeout(resolve, 0); }).then(() => apiFetch<T>(path));
}

async function send<T>(path: string, options: RequestInit, method: string): Promise<T> {
  const token = getAuthToken();

  const record = method === "GET" ? null : RECORD_PATH.exec(path);
  const version = record ? seenVersions.get(`${record[1]}/${record[2]}`) : undefined;
  const repeatKey = idempotencyKey(path, method, options.body);

  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      // Un archivo viaja como formulario: el navegador pone su propio tipo de contenido.
      ...(options.body instanceof FormData ? {} : { "Content-Type": "application/json" }),
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(version !== undefined ? { "If-Match": String(version) } : {}),
      ...(repeatKey ? { "Idempotency-Key": repeatKey } : {}),
      ...options.headers,
    },
  });

  if (res.status === 204) {
    return undefined as T;
  }

  const data = await res.json().catch(() => null);

  if (!res.ok) {
    if (res.status === 401 && typeof window !== "undefined") {
      clearAuthToken();
      window.location.href = "/login";
    }
    // La empresa dejó de estar activa (suspendida, en mantenimiento, cerrada…):
    // se saca al usuario de la consola hacia la pantalla de bloqueo.
    if (res.status === 403 && data?.code === "TENANT_BLOCKED" && typeof window !== "undefined") {
      if (window.location.pathname !== "/blocked") {
        window.location.href = `/blocked?status=${encodeURIComponent(data.tenant_status ?? "")}`;
      }
    }
    throw new ApiError(data?.message ?? "Error al comunicarse con el servidor.", res.status, data?.errors, data);
  }

  trackVersions(path, method, data);

  return data as T;
}
