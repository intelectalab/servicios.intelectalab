import { expect, type APIResponse, type Page } from "@playwright/test";

export const API = process.env.E2E_API_URL ?? "http://127.0.0.1:8000/api";

/** Ingresa con una cuenta de E2eSeeder y espera a que cargue el panel. */
export async function login(page: Page, email: string) {
  await page.goto("/login");
  await page.locator("#email").fill(email);
  await page.locator("#password").fill(process.env.E2E_PASSWORD ?? "");
  await page.getByRole("button", { name: /ingresar/i }).click();
  await expect(page).not.toHaveURL(/\/login/);
}

/** Llama a la API con la sesión de la página (el token está en la cookie auth_token). */
export async function api(page: Page, method: "GET" | "POST", url: string, data?: unknown): Promise<APIResponse> {
  const cookie = (await page.context().cookies()).find((item) => item.name === "auth_token");
  const headers = { Authorization: `Bearer ${decodeURIComponent(cookie?.value ?? "")}`, Accept: "application/json" };
  return method === "GET"
    ? page.request.get(`${API}${url}`, { headers })
    : page.request.post(`${API}${url}`, { headers, data });
}

/** Crea un lead por la API, como lo haría el formulario. */
export async function createLead(page: Page) {
  const options = await (await api(page, "GET", "/v1/crm/leads/options")).json();
  return api(page, "POST", "/v1/crm/leads", {
    organization_name: `Lead E2E ${Date.now()}`, person_name: "Prueba E2E", email: `e2e-${Date.now()}@ejemplo.test`,
    source_id: options.sources[0].id,
  });
}
