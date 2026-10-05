const TOKEN_COOKIE = "auth_token";
// Tipo de cuenta de la sesión: lo usa el proxy para llevar a cada quien a su consola.
const SCOPE_COOKIE = "auth_scope";
const TOKEN_MAX_AGE = 60 * 60 * 24 * 30;

export type AuthScope = "platform" | "tenant";

export function getAuthToken() {
  if (typeof document === "undefined") return null;

  const token = document.cookie
    .split("; ")
    .find((cookie) => cookie.startsWith(`${TOKEN_COOKIE}=`))
    ?.split("=")[1];

  return token ? decodeURIComponent(token) : null;
}

export function setAuthToken(token: string, scope: AuthScope) {
  if (typeof document === "undefined") return;

  document.cookie = `${TOKEN_COOKIE}=${encodeURIComponent(token)}; Path=/; Max-Age=${TOKEN_MAX_AGE}; SameSite=Lax`;
  document.cookie = `${SCOPE_COOKIE}=${scope}; Path=/; Max-Age=${TOKEN_MAX_AGE}; SameSite=Lax`;
}

export function clearAuthToken() {
  if (typeof document === "undefined") return;

  document.cookie = `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
  document.cookie = `${SCOPE_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
}

export { TOKEN_COOKIE, SCOPE_COOKIE };
