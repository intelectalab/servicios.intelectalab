# Nombre del producto: IntelectaERP

Fecha: 2026-11-19.

## 1. Qué se pidió

El kit v1.5 nombra el producto **IntelectaERP**. Se cambiaron solo los textos. Quedan como estaban hasta que el equipo confirme la marca:

- el logo y los colores (Creativas);
- el «Powered by GsCreativas»;
- el dominio (`servicios.intelectalab.com`; el kit propone `app.intelectaerp.com`).

## 2. Qué se cambió

| Lugar | Antes | Ahora |
|---|---|---|
| Pestaña del navegador sin empresa, descripción de la web | «ERP SaaS» / «Sistema ERP» | «IntelectaERP» |
| App instalable (PWA): nombre y nombre corto | «ERP SaaS» / «ERP Campo» | «IntelectaERP» |
| Cabecera y título de página sin empresa | «ERP SaaS» | «IntelectaERP» |
| Fichas 360 antiguas (clientes, proveedores, empleados) | «ERP SaaS - Ficha 360…» | «IntelectaERP - Ficha 360…» |
| Correo de invitación a superadmin | «…plataforma ERP SaaS…» | «…plataforma IntelectaERP…» |
| Remitente de los correos (`MAIL_FROM_NAME`): `.env.example`, `.env` local y servidor | «ERP SaaS GsCreativas» (servidor) | «IntelectaERP» |

**`APP_NAME` no se cambió.** Laravel lo usa para los prefijos de la caché, de Redis y de la cookie de sesión. Cambiarlo reiniciaría la caché y los contadores de intentos de ingreso, sin que se vea en ningún lado.

**En el servidor:** antes de cambiar el remitente se respaldó el `.env` en `/root/backups/api_env_antes_nombre_202611190709`. El LEEME de esa carpeta explica cómo volver atrás. El cambio solo afecta a este ERP.

## 3. Pruebas

- En el navegador: la pestaña, la descripción y el manifiesto de la app instalable dicen «IntelectaERP».
- Pruebas de superadmin en verde; tipos de la web sin errores.
