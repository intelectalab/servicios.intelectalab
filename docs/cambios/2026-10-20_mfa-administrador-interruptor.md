# 2026-10-20 — Interruptor del MFA obligatorio del Administrador y candado en la casilla

## Qué se pidió

Que `admin@erp-saas.test` no tenga verificación en dos pasos por ahora. Al quitarle "Exigir verificación en dos pasos" seguía pidiéndola, porque el rol Administrador la exige siempre. Se acordó:
- un interruptor `ADMIN_MFA_REQUIRED`, encendido por defecto;
- que la casilla se vea bloqueada, con candado y una nota, mientras la regla la exija.

## Qué se cambió

**API**
- `config/auth.php`: `admin_mfa_required` lee `ADMIN_MFA_REQUIRED` (por defecto `true`).
- `AuthController::requiresMfa`: el rol Administrador solo obliga el MFA si la regla está encendida. El superadmin y los usuarios del portal lo siguen exigiendo siempre, y cualquier usuario con su casilla marcada también.
- `UserController` (listado de Usuarios): la columna MFA refleja la regla, y la respuesta trae `admin_mfa_required` para la pantalla.
- `.env.example`: se agregó `ADMIN_MFA_REQUIRED=true` con su explicación.

**Web**
- `UsersList.tsx` (Nuevo / Editar usuario): con el rol Administrador elegido y la regla encendida, la casilla aparece marcada y deshabilitada, con candado y la nota "El rol Administrador siempre exige verificación en dos pasos". Al cambiar a otro rol se libera. Lo que se guarda sigue siendo la casilla propia de la persona, así que vuelve a contar si la regla se apaga.

## Qué NO se tocó

El MFA del superadmin y del portal, ni el flujo del código por correo.

## Base de datos

Sin migraciones.

## Cómo se probó

- `php artisan test`: 235 pruebas, todas pasan. Las nuevas, en `MfaLoginTest` y `ComercialTest`, cubren tres casos:
  - el administrador debe verificar con la regla encendida;
  - con la regla apagada entra directo;
  - con la regla apagada, si tiene su casilla marcada, se le sigue pidiendo el código.

  Además, el listado expone el interruptor.
- Navegador local (`app.localhost:3000/users`, como Administrador con una sesión de prueba creada y cerrada al terminar):
  - regla encendida: la casilla de admin@erp-saas.test aparece marcada, bloqueada, con candado y nota;
  - con `ADMIN_MFA_REQUIRED=false` en el `.env` local: la columna MFA dice "No" y la casilla queda libre.
- `tsc` y `eslint` sin errores.

## Pendiente

- En local quedó `ADMIN_MFA_REQUIRED=false` en `apps/api/.env`, como se pidió para admin@erp-saas.test.
- En el VPS sigue encendida. Apagarla allí es decisión aparte: el sitio es público. Conviene volver a encenderla cuando el correo envíe los códigos de verdad.
