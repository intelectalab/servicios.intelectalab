// La sesión la guarda la API en esta cookie HttpOnly: la página no la puede leer (SessionCookie).
const TOKEN_COOKIE = "auth_token";
// Marca de la sesión, sin secreto: sirve para que las cachés de la página no se mezclen entre sesiones.
const SESSION_COOKIE = "auth_session";
// Tipo de cuenta de la sesión: lo usa el proxy para llevar a cada quien a su consola.
const SCOPE_COOKIE = "auth_scope";
// Con "Recordarme": igual que la sesión larga de la API (SANCTUM_REMEMBER_EXPIRATION, 30 días).
// Sin marcar, la cookie es de sesión: se borra al cerrar el navegador.
const REMEMBER_MAX_AGE = 60 * 60 * 24 * 30;

// "portal": cuenta del portal de clientes; entra solo a /portal.
export type AuthScope = "platform" | "tenant" | "portal";

/** Encabezado que la API exige para escribir con la cookie de sesión (protección contra CSRF). */
export const APP_HEADERS: Record<string, string> = { "X-Requested-With": "XMLHttpRequest" };

function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const value = document.cookie.split("; ").find((cookie) => cookie.startsWith(`${name}=`))?.split("=")[1];
  return value ? decodeURIComponent(value) : null;
}

/** Marca de la sesión actual (no es el token): para separar lo que se guarda en la página por sesión. */
export function getSessionKey(): string {
  return readCookie(SESSION_COOKIE) ?? "";
}

/** Tras iniciar sesión: el token ya lo guardó la API en su cookie; aquí, el tipo de cuenta y la marca. */
export function startSession(scope: AuthScope, remember: boolean) {
  if (typeof document === "undefined") return;

  const lifetime = remember ? `; Max-Age=${REMEMBER_MAX_AGE}` : "";
  // En un sitio con https la cookie solo viaja cifrada.
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  const key = typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : String(Date.now() + Math.random());
  document.cookie = `${SCOPE_COOKIE}=${scope}; Path=/${lifetime}; SameSite=Lax${secure}`;
  document.cookie = `${SESSION_COOKIE}=${key}; Path=/${lifetime}; SameSite=Lax${secure}`;
}

/** Limpia lo que la página guardó de la sesión. La cookie del token la borra la API (al salir o con un 401). */
export function clearSession() {
  if (typeof document === "undefined") return;

  document.cookie = `${SCOPE_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
  document.cookie = `${SESSION_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
  // Sesiones de antes de la cookie HttpOnly: esa sí la puede borrar la página.
  document.cookie = `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
}

/** Evento que Mi perfil dispara al cambiar el nombre (detail: el nombre nuevo), para la cabecera. */
export const PROFILE_NAME_EVENT = "erp-profile-name";

export { TOKEN_COOKIE, SCOPE_COOKIE };
