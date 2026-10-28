const TOKEN_COOKIE = "auth_token";
// Tipo de cuenta de la sesión: lo usa el proxy para llevar a cada quien a su consola.
const SCOPE_COOKIE = "auth_scope";
// Con "Recordarme": igual que la sesión larga de la API (SANCTUM_REMEMBER_EXPIRATION, 30 días).
// Sin marcar, la cookie es de sesión: se borra al cerrar el navegador.
const REMEMBER_MAX_AGE = 60 * 60 * 24 * 30;

// "portal": cuenta del portal de clientes; entra solo a /portal.
export type AuthScope = "platform" | "tenant" | "portal";

export function getAuthToken() {
  if (typeof document === "undefined") return null;

  const token = document.cookie
    .split("; ")
    .find((cookie) => cookie.startsWith(`${TOKEN_COOKIE}=`))
    ?.split("=")[1];

  return token ? decodeURIComponent(token) : null;
}

export function setAuthToken(token: string, scope: AuthScope, remember: boolean) {
  if (typeof document === "undefined") return;

  const lifetime = remember ? `; Max-Age=${REMEMBER_MAX_AGE}` : "";
  // En un sitio con https la cookie solo viaja cifrada.
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${TOKEN_COOKIE}=${encodeURIComponent(token)}; Path=/${lifetime}; SameSite=Lax${secure}`;
  document.cookie = `${SCOPE_COOKIE}=${scope}; Path=/${lifetime}; SameSite=Lax${secure}`;
}

export function clearAuthToken() {
  if (typeof document === "undefined") return;

  document.cookie = `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
  document.cookie = `${SCOPE_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
}

/** Evento que Mi perfil dispara al cambiar el nombre (detail: el nombre nuevo), para la cabecera. */
export const PROFILE_NAME_EVENT = "erp-profile-name";

export { TOKEN_COOKIE, SCOPE_COOKIE };
