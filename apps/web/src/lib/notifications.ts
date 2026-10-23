import { apiFetch } from "@/lib/api";

/** Una notificación de la persona, tal como la entrega la API. */
export type AppNotification = {
  id: string;
  event: string | null;
  title: string;
  body: string;
  link: string | null;
  read: boolean;
  read_at: string | null;
  created_at: string;
};

export type NotificationSummary = { unread: number; latest: AppNotification[] };

// La campana y el centro de notificaciones se avisan entre sí cuando algo se marca como leído.
const CHANGED = "erp:notifications-changed";

export function notificationsChanged() {
  window.dispatchEvent(new Event(CHANGED));
}

export function onNotificationsChanged(listener: () => void) {
  window.addEventListener(CHANGED, listener);
  return () => window.removeEventListener(CHANGED, listener);
}

export async function markRead(id: string) {
  const row = await apiFetch<AppNotification>(`/v1/me/notifications/${id}/read`, { method: "POST" });
  notificationsChanged();
  return row;
}

export async function markAllRead() {
  const result = await apiFetch<{ marked: number }>("/v1/me/notifications/read-all", { method: "POST" });
  notificationsChanged();
  return result;
}

/** "hace 5 min", "hace 3 h", "ayer", o la fecha: como el prototipo. */
export function relTime(value: string) {
  const diff = (Date.now() - new Date(value).getTime()) / 1000;
  if (diff < 60) return "hace un momento";
  if (diff < 3600) return `hace ${Math.floor(diff / 60)} min`;
  if (diff < 86400) return `hace ${Math.floor(diff / 3600)} h`;
  if (diff < 172800) return "ayer";
  return new Date(value).toLocaleDateString("es-PE", { day: "numeric", month: "short", year: "numeric" });
}
