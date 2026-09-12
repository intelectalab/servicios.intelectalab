import { clearAuthToken, getAuthToken } from "./auth";

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.errors = errors;
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
    throw new ApiError(data?.message ?? "Error al comunicarse con el servidor.", res.status, data?.errors);
  }

  return data as T;
}
