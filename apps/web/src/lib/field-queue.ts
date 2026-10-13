import { ApiError, apiFetch } from "@/lib/api";
import { getAuthToken } from "@/lib/auth";
import type { FieldRoute, WorkOrderStatus } from "@/types/operations";

/**
 * App del técnico sin conexión. Lo que el técnico hace sin señal se guarda en el teléfono
 * (IndexedDB, que también admite las fotos) y se envía después, en orden, por las rutas normales
 * de la API: ella valida estado y permisos, y lo que ya no vale se rechaza sin frenar lo demás.
 */

export type FieldOpKind = "start" | "block" | "unblock" | "complete" | "check" | "evidence";

export interface FieldOp {
  /** También es la clave de envío repetido: reintentar no aplica dos veces el cambio. */
  id: string;
  userId: string;
  workOrderId: string;
  kind: FieldOpKind;
  itemId?: string;
  done?: boolean;
  reason?: string;
  file?: Blob;
  fileName?: string;
  /** Texto para la persona: "Visita iniciada · OT-2026-0002". */
  label: string;
  at: string;
}

export interface SyncEntry { ok: boolean; label: string; message: string; at: string; workOrderId: string }

const DB_NAME = "erp-field";
const STORE = "ops";
const LOG_KEY = "erp.field.log.";
const ROUTE_KEY = "erp.field.route";
const OFFLINE_KEY = "erp.field.offline";

function open(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: "id" });
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function run<T>(mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const db = await open();
  try {
    return await new Promise<T>((resolve, reject) => {
      const request = work(db.transaction(STORE, mode).objectStore(STORE));
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  } finally {
    db.close();
  }
}

/** Cambios pendientes de esa persona, del más antiguo al más reciente. */
export async function queuedOps(userId: string): Promise<FieldOp[]> {
  const all = await run<FieldOp[]>("readonly", (store) => store.getAll());
  return all.filter((op) => op.userId === userId).sort((a, b) => a.at.localeCompare(b.at));
}

export const enqueueOp = (op: FieldOp) => run("readwrite", (store) => store.put(op));
export const dropOp = (id: string) => run("readwrite", (store) => store.delete(id));

export function newOp(op: Omit<FieldOp, "id" | "at">): FieldOp {
  return { ...op, id: crypto.randomUUID(), at: new Date().toISOString() };
}

// --- Registro de sincronizaciones y última ruta vista (en el navegador de cada persona) ------

export function syncLog(userId: string): SyncEntry[] {
  try { return JSON.parse(window.localStorage.getItem(LOG_KEY + userId) ?? "[]") as SyncEntry[]; } catch { return []; }
}

function addToLog(userId: string, entries: SyncEntry[]) {
  try { window.localStorage.setItem(LOG_KEY + userId, JSON.stringify([...entries.reverse(), ...syncLog(userId)].slice(0, 20))); } catch { /* sin almacenamiento */ }
}

/** Marca corta de la sesión: la ruta guardada solo se muestra a la sesión que la guardó. */
function sessionTag(): string {
  const token = getAuthToken() ?? "";
  let hash = 0;
  for (let index = 0; index < token.length; index += 1) hash = (hash * 31 + token.charCodeAt(index)) | 0;
  return String(hash);
}

export function saveRoute(route: FieldRoute) {
  try { window.localStorage.setItem(ROUTE_KEY, JSON.stringify({ tag: sessionTag(), route })); } catch { /* sin almacenamiento */ }
}

/** Última ruta que esta sesión cargó con señal, para seguir trabajando sin ella. */
export function savedRoute(technicianId?: string): FieldRoute | null {
  try {
    const saved = JSON.parse(window.localStorage.getItem(ROUTE_KEY) ?? "null") as { tag: string; route: FieldRoute } | null;
    if (!saved || saved.tag !== sessionTag()) return null;
    return !technicianId || saved.route.technician.id === technicianId ? saved.route : null;
  } catch {
    return null;
  }
}

export function offlineMode(): boolean {
  try { return window.localStorage.getItem(OFFLINE_KEY) === "1"; } catch { return false; }
}

export function setOfflineMode(on: boolean) {
  try { if (on) window.localStorage.setItem(OFFLINE_KEY, "1"); else window.localStorage.removeItem(OFFLINE_KEY); } catch { /* sin almacenamiento */ }
}

// --- Envío a la API ---------------------------------------------------------------------------

/** No hubo respuesta de la API (sin señal, servidor inalcanzable): el cambio se queda en la cola. */
export const isNetworkError = (error: unknown) => !(error instanceof ApiError);

async function send(op: FieldOp): Promise<void> {
  const base = `/v1/work-orders/${op.workOrderId}`;
  const headers = { "Idempotency-Key": op.id };

  if (op.kind === "check") {
    await apiFetch(`${base}/checklist-items/${op.itemId}`, { method: "PATCH", body: JSON.stringify({ done: op.done }) });
  } else if (op.kind === "evidence") {
    const body = new FormData();
    body.set("file", op.file as Blob, op.fileName ?? "evidencia.jpg");
    await apiFetch(`${base}/checklist-items/${op.itemId}/evidence`, { method: "POST", body, headers });
  } else {
    await apiFetch(`${base}/${op.kind}`, { method: "POST", body: op.reason ? JSON.stringify({ reason: op.reason }) : undefined, headers });
  }
}

/**
 * Aplica un cambio en la API. Si otra persona tocó la orden entretanto (409), se lee la versión
 * vigente y se reintenta una vez: lo que decide si el cambio vale es el estado de la orden.
 */
export async function applyOp(op: FieldOp): Promise<void> {
  try {
    await send(op);
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 409) throw error;
    await apiFetch(`/v1/work-orders/${op.workOrderId}`);
    await send(op);
  }
}

/**
 * Envía la cola en orden. Un cambio rechazado se anota y no frena los demás; si se cae la señal,
 * lo que falta se queda en la cola. Devuelve lo que pasó con cada cambio enviado.
 */
export async function syncQueue(userId: string): Promise<SyncEntry[]> {
  const results: SyncEntry[] = [];
  const refreshed = new Set<string>();
  const gone = new Set<string>();

  for (const op of await queuedOps(userId)) {
    const entry = (ok: boolean, message: string): SyncEntry => ({ ok, label: op.label, message, at: new Date().toISOString(), workOrderId: op.workOrderId });
    try {
      // Antes del primer cambio de cada orden se lee su versión vigente.
      if (!refreshed.has(op.workOrderId) && !gone.has(op.workOrderId)) {
        await apiFetch(`/v1/work-orders/${op.workOrderId}`);
        refreshed.add(op.workOrderId);
      }
      if (gone.has(op.workOrderId)) throw new ApiError("La orden ya no está disponible.", 404);
      await applyOp(op);
      results.push(entry(true, "Aplicado"));
    } catch (error) {
      if (isNetworkError(error)) break;
      if (error instanceof ApiError && error.status === 404) gone.add(op.workOrderId);
      const detail = error instanceof ApiError ? (Object.values(error.errors ?? {})[0]?.[0] ?? error.message) : "No se pudo aplicar.";
      results.push(entry(false, error instanceof ApiError && error.status === 404 ? "La orden ya no está disponible." : detail));
    }
    await dropOp(op.id);
  }

  addToLog(userId, [...results]);

  // Lo rechazado queda también en el historial de su orden, para quien supervisa.
  const rejected = results.filter((entry) => !entry.ok && !gone.has(entry.workOrderId));
  if (rejected.length) {
    await apiFetch("/v1/field/sync-log", {
      method: "POST",
      body: JSON.stringify({ rejected: rejected.map((entry) => ({ work_order_id: entry.workOrderId, label: entry.label, message: entry.message })) }),
    }).catch(() => undefined);
  }

  return results;
}

/** Estado de la visita contando los cambios aún sin enviar. */
export function effectiveStatus(status: WorkOrderStatus, ops: FieldOp[]): WorkOrderStatus {
  const next: Partial<Record<FieldOpKind, WorkOrderStatus>> = { start: "IN_PROGRESS", unblock: "IN_PROGRESS", block: "BLOCKED", complete: "COMPLETED" };
  return ops.reduce((current, op) => next[op.kind] ?? current, status);
}
