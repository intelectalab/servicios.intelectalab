# Prueba automática de GitHub (CI) en verde, librerías sin vulnerabilidades y repo pública sin secretos

Fecha: 2026-11-01.

## 1. Qué se pidió

1. Subir el código a la repo **pública** de la empresa (`intelectalab/servicios.intelectalab`) con el historial, sin información sensible y sin que aparezca Claude como coautor.
2. Revisar por qué la prueba automática de GitHub (CI) salía con la ✗ roja.

## 2. Qué se cambió

**Información sensible (antes de publicar)**
- Seeders locales (`Local*Seeder`): ya no traen contraseñas escritas; usan `SEED_TEST_PASSWORD` del `.env` local (nueva en `.env.example`) o generan una al azar y la muestran una sola vez (`database/seeders/TestPassword.php`).
- Se quitaron correos y nombres personales de `docs/` y de una prueba.
- VPS: las 8 cuentas de prueba `@erp-saas.test` (no superadmin) recibieron contraseñas nuevas al azar y se cerraron sus sesiones; quedan en un archivo del servidor solo para root, con respaldo y LEEME. Ninguna otra cuenta se tocó.
- La repo de la empresa recibe una copia del historial sin la línea "Co-Authored-By: Claude" y sin esos textos en ninguna versión; se publica con una herramienta local excluida de Git.

**Por qué fallaba la CI** (no era por lo de este día):
1. **Web — Lint:** 2 errores en `WorkOrderModals.tsx` (estado cambiado dentro de un efecto). Se ajusta el índice de la foto durante el render y el cierre queda en su propio efecto; la carga de la vista previa sigue igual.
2. **API — `composer audit`:** 8 vulnerabilidades conocidas en 4 librerías, entre ellas **PhpSpreadsheet** (lee los Excel que suben los usuarios: XXE y DoS) y Symfony. Actualizadas dentro de sus versiones: `phpoffice/phpspreadsheet` 5.9.0 → 5.10.0, `league/commonmark` 2.10.1 → 2.10.7, `symfony/http-foundation` y `symfony/mime` 7.4.18 → 7.4.22 (y sus dependencias menores). `composer audit`: sin vulnerabilidades.
3. **API — pruebas:** la CI armaba la base con `database/schema_reference.sql` del **10 de septiembre** (sin lo construido después) y con una ruta equivocada; además daba a `erp_app` permiso de borrar en todas las tablas, contrario a las protecciones de la base.
   - `schema_reference.sql` regenerado desde la base actual: estructura, RLS, funciones y permisos por rol, más los catálogos que llenan las migraciones (`migrations`, `permissions`, `module_catalog`, `saas_plans`). Sin datos de empresas ni contraseñas.
   - `ci.yml`: crea los mismos roles que el servidor (`erp_user`, `erp_app`, `erp_platform`, `erp_console`), restaura el esquema deteniéndose ante cualquier error, carga una empresa mínima (`.github/ci-fixture.sql`: algunas pruebas de la consola leen "la primera empresa") y pasa al usuario de la consola sus credenciales de prueba. Se quitaron los permisos amplios a `erp_app`.

**Regenerar `schema_reference.sql` después de cada migración nueva** (con la base local al día):

```bash
cd apps/api/database
{ head -4 schema_reference.sql; docker exec erp_postgres pg_dump -U erp_user -d erp_saas --schema-only --no-comments; docker exec erp_postgres pg_dump -U erp_user -d erp_saas --data-only --no-comments -t public.migrations -t public.permissions -t public.module_catalog -t public.saas_plans; } | grep -v -E '^.(restrict|unrestrict) ' > /tmp/s.sql && mv /tmp/s.sql schema_reference.sql
```

## 3. Qué NO se tocó

- El código de las pantallas y la API (salvo `WorkOrderModals.tsx`).
- Los avisos de GitHub por Node 20 (son avisos, no fallas).

## 4. Diferencias con el prototipo

Ninguna.

## 5. Base de datos

Sin migraciones. `schema_reference.sql` es solo para la CI.

## 6. Cómo se probó

- Se reprodujo la CI en local: base vacía `erp_saas_ci` armada solo con el nuevo `schema_reference.sql` y la empresa mínima, sin errores con `ON_ERROR_STOP`; **302 pruebas pasan** contra ella (antes de la empresa mínima fallaban 2) y también contra la base normal.
- `npm run lint` en toda la web: 0 errores. `tsc --noEmit` sin errores. `composer audit`: sin vulnerabilidades.
- Navegador: galería de evidencias de OT-2026-0006 abre "Foto 1 de 1" con la imagen cargada.
- Repo de la empresa: publicada (143 commits, `256246f`) tras revisar que no queden textos privados ni la firma de Claude y que el código final sea idéntico.

## 7. Pendiente

- Confirmar en GitHub que la CI queda en verde tras subir estos cambios.
- Regenerar `schema_reference.sql` con cada migración nueva (comando arriba).
