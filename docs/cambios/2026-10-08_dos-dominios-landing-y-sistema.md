# 2026-10-08 — Dos dominios: la página pública y el sistema

Continúa [2026-10-08_desconectar-clientes-proveedores-empleados.md](2026-10-08_desconectar-clientes-proveedores-empleados.md).

## Qué se pidió

Diferenciar la página de planes y promoción del sistema, como HubSpot (`hubspot.es` y `app.hubspot.com`). Se eligió la opción de **un solo proyecto separado por dominio**. Aún no hay dominio, así que queda configurable.

## Cómo funciona

Dos variables de entorno en la web (`apps/web/.env.local`, o las del servidor al publicar):

```
SITE_URL=http://localhost:3000        # página pública (landing, planes)
APP_URL=http://app.localhost:3000     # el sistema (login y todo lo de adentro)
```

En producción serán, por ejemplo, `https://midominio.com` y `https://app.midominio.com`.

- En el dominio público solo se ve la landing (`/`). Cualquier otra ruta (`/login`, `/actors`, `/superadmin`…) se abre en el dominio del sistema, conservando la ruta.
- En el dominio del sistema no hay landing: la raíz lleva al login, o a la consola si ya hay sesión.
- **Sin las dos variables todo sigue en un solo dominio**, como antes.

## Qué se cambió

Solo web.

| Archivo | Cambio |
| --- | --- |
| `src/proxy.ts` | Lee `SITE_URL` y `APP_URL` y redirige según el dominio por el que se entra. Se añadieron `/` y `/blocked` a las rutas que revisa. |
| `next.config.ts` | `allowedDevOrigins: ["app.localhost"]`, para que el servidor de desarrollo acepte `app.localhost`. |
| `.env.local` (no va al repo) | Las dos variables, con los valores locales de arriba. |

## Qué NO se tocó

- La landing, el login y las pantallas: ningún botón ni enlace cambió ("Iniciar sesión" sigue yendo a `/login`; el proxy lo lleva al otro dominio).
- La API. La sesión viaja como token en la cabecera, no por cookies de la API, y los correos envían códigos, no enlaces: no hizo falta cambiar nada.

## A tener en cuenta

- **En local el sistema ahora se abre en `http://app.localhost:3000`.** `http://localhost:3000/login` redirige ahí. La sesión abierta en `localhost:3000` no sirve en el otro dominio: hay que iniciar sesión una vez más.
- La sesión se guarda solo en el dominio del sistema; la landing no sabe si hay sesión abierta (su botón "Iniciar sesión" lleva al sistema, que entra directo si la hay).

## Al publicar con el dominio real

1. Apuntar el dominio y el subdominio `app.` al mismo despliegue de la web.
2. Definir `SITE_URL` y `APP_URL` con `https://`.
3. `NEXT_PUBLIC_API_URL` con la dirección de la API (p. ej. `https://api.midominio.com/api`).
4. Si la API restringe orígenes (CORS), permitir los dos dominios. Hoy no hay `config/cors.php` propio: usa el de Laravel por defecto.

## Cómo se probó

`tsc` y `eslint` sin errores. En el navegador, con `ventas@erp-saas.test`:

1. `localhost:3000/` muestra la landing.
2. "Iniciar sesión" en la landing abre `app.localhost:3000/login`.
3. Iniciar sesión ahí entra a `app.localhost:3000/actors`, con sus datos y sin errores en la consola.
4. Con sesión: `app.localhost:3000/` lleva a `/actors`, y `localhost:3000/login` lleva a `app.localhost:3000/actors`.
5. Por línea de comandos: `localhost:3000/actors/x?a=1` → `app.localhost:3000/actors/x?a=1`; `app.localhost:3000/` sin sesión → `/login`; por `127.0.0.1:3000` (ni uno ni otro dominio) todo funciona como antes.

No se probó: con `https` ni con un dominio real, el registro de empresa desde la landing, la pantalla `/blocked`, ni la cuenta de superadmin.

## Pendiente

- Elegir el dominio.
- Si la landing crece (blog, SEO), se puede sacar a un proyecto aparte sin cambiar las direcciones.
