import { defineConfig } from "@playwright/test";
import { existsSync, readFileSync } from "node:fs";

// Pruebas de punta a punta contra el sistema local ya levantado: API en 127.0.0.1:8000 y web en
// app.localhost:3000 (npm run dev). Antes de cada corrida, e2e/global-setup.ts deja las empresas
// de prueba como las crea E2eSeeder. La contraseña de esas cuentas está en .env.e2e (no se sube).
for (const line of existsSync(".env.e2e") ? readFileSync(".env.e2e", "utf8").split(/\r?\n/) : []) {
  const match = line.match(/^([A-Z0-9_]+)=(.*)$/);
  if (match && process.env[match[1]] === undefined) process.env[match[1]] = match[2];
}

export default defineConfig({
  testDir: "./e2e",
  globalSetup: "./e2e/global-setup.ts",
  // Comparten las mismas empresas de prueba: una a la vez.
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [["list"]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://app.localhost:3000",
    // El Chrome instalado en la máquina: no hace falta descargar navegadores.
    channel: "chrome",
    headless: true,
    locale: "es-PE",
    timezoneId: "America/Lima",
    trace: "retain-on-failure",
  },
});
