const TOKEN_COOKIE = "auth_token";
const TOKEN_MAX_AGE = 60 * 60 * 24 * 30;

export function getAuthToken() {
  if (typeof document === "undefined") return null;

  const token = document.cookie
    .split("; ")
    .find((cookie) => cookie.startsWith(`${TOKEN_COOKIE}=`))
    ?.split("=")[1];

  return token ? decodeURIComponent(token) : null;
}

export function setAuthToken(token: string) {
  if (typeof document === "undefined") return;

  document.cookie = `${TOKEN_COOKIE}=${encodeURIComponent(token)}; Path=/; Max-Age=${TOKEN_MAX_AGE}; SameSite=Lax`;
}

export function clearAuthToken() {
  if (typeof document === "undefined") return;

  document.cookie = `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax`;
}

export { TOKEN_COOKIE };
