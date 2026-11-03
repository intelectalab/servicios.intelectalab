# Revisión previa y dependencias de la web al día

Fecha: 2026-11-03.

## 1. Qué se pidió

Antes de pasar el proyecto a la revisión con Claude (sobre la repo de la empresa), dejarlo limpio: correr las pruebas, el lint y la compilación, y buscar claves expuestas y fallas de seguridad conocidas.

## 2. Qué se revisó y qué salió

| Revisión | Resultado |
| --- | --- |
| Pruebas de la API (`php artisan test`) | 346 en verde |
| Lint de la web (`npm run lint`) | 2 avisos en `lib/api.ts` (corregidos, ver abajo) |
| Tipos (`tsc --noEmit`) y compilación (`next build`) | Sin errores |
| Claves o archivos sensibles en el repo | Ninguno (solo `apps/api/.env.example`, sin valores reales) |
| Dependencias de la API (`composer audit`) | Ninguna falla conocida |
| Dependencias de la web (`npm audit --omit=dev`) | 14 fallas: 2 críticas, 3 altas y 9 moderadas |

## 3. Qué se cambió

- **Next.js 16.3.4 → 16.4.3** (y `eslint-config-next` igual). La 16.3.4 tenía fallas críticas, como la ejecución remota de código en la optimización de imágenes, en `use cache` y en `next/og`. Se eligió la 16.4.3 (publicada el 23/10) y no la 16.4.5 (publicada el mismo día de la revisión): la 16.4.3 no tiene ninguna falla conocida.
- **`npm audit fix`** sin cambios de versión mayor: `dompurify`, `sharp`, `source-map-js`, `brace-expansion`, `zod`, `typescript-eslint`, `semver` e `ignore`, en versiones menores.
- **`lib/api.ts`:** las dos redirecciones con `window.location.href` (a `/login` cuando la sesión vence y a `/blocked` cuando la empresa está bloqueada) son una recarga completa a propósito, para que no quede nada de la sesión anterior en memoria. Se marcaron como excepción del lint, con el motivo escrito.
- **`apps/web/.env.example`:** nuevo, con las tres variables que usa la web (`NEXT_PUBLIC_API_URL`, `SITE_URL` y `APP_URL`) y valores de ejemplo locales. El `.gitignore` de la web ahora lo deja subir (`!.env.example`).

## 4. Lo que queda

- **9 fallas moderadas, todas de `exceljs`** (la librería que arma los Excel de exportar e importar), en `archiver`, `glob`, `minimatch`, `uuid`, etc. La única "solución" de npm es bajar `exceljs` a la 3.4, que es más vieja y rompe la API. Esas partes leen y escriben archivos en el disco con Node, cosa que en el navegador no se usa: el riesgo real es bajo. Se revisará cuando `exceljs` saque una versión nueva.
- **Al desplegar:** el VPS tiene que reinstalar las dependencias y recompilar la web (`deploy.sh` ya lo hace).
