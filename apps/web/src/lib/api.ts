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

/**
 * Wrapper sobre fetch que agrega el Bearer token, maneja errores de
 * Laravel (422 con `errors`, 401, etc.) y parsea JSON.
 */
export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = getAuthToken();

  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
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

  return data as T;
}
