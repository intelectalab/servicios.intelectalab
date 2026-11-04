import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";

/**
 * Deja las empresas de prueba como las crea E2eSeeder (leads, vigencia, contraseña) antes de cada
 * corrida. El seeder corre como el dueño de las tablas (erp_user), con la contraseña local de
 * docker-compose.yml.
 */
export default async function globalSetup() {
  if (!process.env.E2E_PASSWORD) throw new Error("Falta E2E_PASSWORD en apps/web/.env.e2e (la misma del .env de la API).");

  const root = path.resolve(__dirname, "..", "..", "..");
  // En GitHub (CI) la contraseña del dueño viene en E2E_DB_OWNER_PASSWORD; en local, la de docker-compose.yml.
  const dbPassword = process.env.E2E_DB_OWNER_PASSWORD
    ?? readFileSync(path.join(root, "docker-compose.yml"), "utf8").match(/POSTGRES_PASSWORD:\s*(\S+)/)?.[1];
  if (!dbPassword) throw new Error("No se encontró la contraseña local de la base en docker-compose.yml.");

  execFileSync("php", ["artisan", "db:seed", "--class=E2eSeeder", "--force"], {
    cwd: path.join(root, "apps", "api"),
    env: { ...process.env, DB_USERNAME: "erp_user", DB_PASSWORD: dbPassword },
    stdio: "pipe",
  });
}
