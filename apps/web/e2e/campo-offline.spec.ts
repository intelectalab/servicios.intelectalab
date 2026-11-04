import { expect, test } from "@playwright/test";
import { login } from "./helpers";

// App del técnico sin señal desde cero: tras abrirla una vez con conexión, el service worker
// (public/sw.js) la guarda y vuelve a abrir sin red con la ruta guardada en el teléfono.
// El service worker solo se registra en producción: con `npm run dev` esta prueba se salta.
// Para correrla: `next build`, `next start -p 3200` y E2E_BASE_URL=http://app.localhost:3200.

test("la app del técnico abre sin señal después de abrirla una vez", async ({ page, context }) => {
  await login(page, "e2e-admin@erp-saas.test");
  await page.goto("/campo");
  await expect(page.getByText("Cómo funciona el modo sin conexión")).toBeVisible();

  const registered = await page.evaluate(async () => {
    if (!("serviceWorker" in navigator)) return false;
    const ready = navigator.serviceWorker.ready.then(() => true);
    return Promise.race([ready, new Promise<boolean>((resolve) => setTimeout(() => resolve(false), 5000))]);
  });
  test.skip(!registered, "Sin service worker (modo desarrollo): solo se prueba contra la compilación de producción.");

  // Una recarga con red para que la pantalla quede guardada, y luego sin red.
  await page.reload();
  await expect(page.getByText("Cómo funciona el modo sin conexión")).toBeVisible();
  await context.setOffline(true);
  await page.reload();
  await expect(page.getByText("Cómo funciona el modo sin conexión")).toBeVisible();
  await expect(page.getByText("No se pudo cargar tu sesión")).toHaveCount(0);
  await context.setOffline(false);
});
