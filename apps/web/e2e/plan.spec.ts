import { expect, test } from "@playwright/test";
import { api, createLead, login } from "./helpers";

// Límites del plan y vencimiento de la suscripción, de punta a punta: lo que ve la persona en la
// cabecera y en Módulos y plan, y lo que la API deja o no hacer.

test("al 80 % del límite avisa y al llegar ya no deja crear más", async ({ page }) => {
  await login(page, "e2e-admin@erp-saas.test");

  // 4 de 5 leads: aviso en la cabecera y en Módulos y plan.
  const banner = page.locator('[data-banner="plan-limits"]');
  await expect(banner).toContainText("Estás cerca del límite en leads (4/5)");
  await page.goto("/plan");
  await expect(page.getByText("Te queda 1")).toBeVisible();

  // El quinto entra; el sexto se rechaza con el mensaje del plan.
  expect((await createLead(page)).status()).toBe(201);
  const blocked = await createLead(page);
  expect(blocked.status()).toBe(422);
  expect((await blocked.json()).message).toContain("Llegaste al límite de tu plan: 5 leads");

  await page.reload();
  await expect(page.locator('[data-banner="plan-limits"]')).toContainText("Llegaste al límite en leads (5/5)");
  await expect(page.getByText("Llegaste al límite de tu plan: no se pueden crear más")).toBeVisible();
});

test("vencida y pasada la gracia queda en solo lectura", async ({ page }) => {
  await login(page, "e2e-vencida@erp-saas.test");

  const banner = page.locator('[data-banner="read-only"]');
  await expect(banner).toContainText("Solo lectura");
  await expect(banner.getByRole("link", { name: "Renovar" })).toBeVisible();

  // Ver sí; crear no.
  expect((await api(page, "GET", "/v1/crm/leads")).status()).toBe(200);
  const blocked = await createLead(page);
  expect(blocked.status()).toBe(403);
  expect((await blocked.json()).message).toContain("solo lectura");

  // Pedir la renovación sí se puede.
  expect((await api(page, "POST", "/v1/tenant/plan/request", { detail: "Renovar el plan (E2E)" })).status()).toBe(200);
});

test("una empresa nueva trae los roles base del kit", async ({ page }) => {
  await login(page, "e2e-admin@erp-saas.test");
  await page.goto("/roles");
  for (const role of ["Administrador", "Gerencia", "Ventas", "Operaciones", "Técnico", "Finanzas", "Soporte"]) {
    await expect(page.getByText(role, { exact: true }).first()).toBeVisible();
  }
});
