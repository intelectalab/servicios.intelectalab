# 2026-10-08 — Sedes adicionales por persona

## Qué se pidió

Preguntaste si un usuario puede estar vinculado a varias sedes y si convenía dar esa opción, siendo un ERP SaaS donde algún tenant lo va a necesitar (un supervisor de zona, un vendedor regional, técnicos que rotan). Se propuso un esquema y respondiste "sí, hazlo".

## Cómo funciona

- Cada persona tiene una **sede principal** (igual que antes): ahí **nace** lo que crea.
- Además puede tener **sedes adicionales**, que solo **amplían lo que ve** con el alcance "Sede": lo propio, lo de su sede principal y lo de sus adicionales.
- Quien tiene una sola sede no nota ninguna diferencia.
- Las adicionales **requieren una sede principal**: sin ella, lo que la persona crea no tendría dónde nacer.
- Con alcance "Propios" o "Equipo" las sedes adicionales no cambian nada; con "Toda la empresa" tampoco hacen falta.
- Se asignan en **Usuarios → Editar usuario → Sedes adicionales**. El cambio queda en auditoría (antes y después), junto con rol, sede y equipo.
- Vale para todo lo que lleva alcance: Cotizaciones, Contratos, Órdenes, Solicitudes del portal, Leads y Oportunidades, porque la regla vive en un solo lugar.

## Qué se cambió

| Archivo | Cambio |
| --- | --- |
| `api/app/Services/AccessScope.php` | El alcance "Sede" filtra por la sede principal y las adicionales. La sede en la que nace un documento sigue siendo la principal. |
| `api/.../Core/Http/Controllers/UserController.php` | El listado devuelve las sedes adicionales; alta y edición las reciben, las validan (de la empresa, sin repetir la principal, solo con principal) y las guardan. |
| `api/.../Core/Http/Controllers/OrgUnitController.php` | En la pestaña Sedes, el número de usuarios de cada sede cuenta también a quienes la tienen como adicional. |
| `api/app/Http/Controllers/Auth/AuthController.php` | Mi perfil devuelve las sedes adicionales. |
| `web/.../admin/UsersList.tsx` | Campo **Sedes adicionales** (casillas) en el formulario; en la tabla, junto a la sede, un "+N" con los nombres al pasar el cursor; columna nueva en la exportación. |
| `web/.../account/ProfileView.tsx` | Con adicionales, la tarjeta Cuenta muestra "Sede principal" y "Sedes adicionales". |
| `web/.../ScopeNote.tsx` | El texto del alcance dice "tus sedes". |
| `api/tests/Feature/AccessScopeTest.php` | Una prueba nueva. |

## Qué NO se tocó

- La sede principal, el equipo y los demás alcances.
- La sede de los documentos que ya existen.
- El campo `user_roles.branch_id` (un rol por sede) sigue sin uso: no se adoptó ese modelo porque obligaría a elegir sede en cada documento.

## Diferencias con el prototipo

El prototipo trae varias sedes por usuario en sus datos, pero su formulario pide una sola y no distingue principal de adicionales. Aquí se distinguen, para que cada documento tenga siempre una sede clara de origen.

## Base de datos

Migración `2026_10_08_001000_additional_branches_for_users`. **Ya está corrida en la base local**, con el usuario dueño. Solo añade la tabla `tenant_user_branches` (empresa, persona, sede), con su regla de aislamiento; la aplicación puede asignar y quitar, no modificar.

## Cómo se probó

`php artisan test`: **110 pruebas pasan** (109 anteriores + 1 nueva):

- Con solo su sede, la persona no alcanza las otras. Al asignarle una adicional: la ve y actúa sobre ella, no ve una tercera, y lo que crea nace en su sede principal.
- La sede principal repetida como adicional se ignora; sin sede principal o con una sede de otra empresa responde 422 y no cambia nada.
- Cambiar la lista la reemplaza; vaciarla devuelve a la persona a su sola sede.
- Queda en auditoría, sale en el listado de usuarios, en el conteo de la sede y en Mi perfil.
- Con alcance "Propios" las adicionales no amplían nada.

`tsc` y `eslint` sin errores.

En el navegador (`app.localhost:3000`), como **Administrador**:

- Se creó la sede "Sede norte (prueba)" y, al editar a Lucía Paredes, aparece el campo Sedes adicionales con esa sede; se marcó y se guardó. En la tabla sale "Sede principal +1" con el nombre al pasar el cursor; en la pestaña Sedes, cada una cuenta 1 usuario; al reabrir el formulario la casilla sigue marcada.
- Al poner "Sin sede" como principal, las casillas se deshabilitan con la nota "Se guardan con una sede principal: elígela primero" (no se guardó así).
- A 375 px el formulario cabe sin desborde (medido y con captura).
- Por la API, Mi perfil de Ventas devuelve "Sede principal" y la adicional.

### Segunda ronda: lo que faltaba probar

Pediste probar lo que faltaba. Para verlo con datos reales se cambió **temporalmente** la configuración de la empresa de prueba y al final se dejó como estaba:

1. Como Administrador: Gerencia pasó a la sede "Sede norte (prueba)" y el alcance de Ventas en Cotizaciones pasó de Propios a **Sede**.
2. Gerencia creó la cotización **COT-2026-0011**, que nació en la sede norte.
3. **Como Ventas, en el navegador:** el listado de Cotizaciones muestra COT-2026-0011 (ajena, de la sede adicional) junto a las dos suyas, con la etiqueta "Alcance: Sede" y el texto "lo propio y lo de tus sedes"; su ficha abre. En **Mi perfil**, la tarjeta Cuenta muestra "Sede principal: Sede principal" y "Sedes adicionales: Sede norte (prueba)", vista en claro y en **oscuro** (con captura).
4. **Contraprueba:** al quitarle la sede adicional a Lucía, Ventas deja de ver COT-2026-0011 (ve solo las dos suyas); al devolvérsela, vuelve a verla.
5. **En oscuro**, el formulario Editar usuario con el campo Sedes adicionales (con captura): borde, texto y nota legibles.
6. Restaurado: Ventas otra vez con alcance **Propios** (ve solo sus dos cotizaciones), Gerencia otra vez **sin sede**, Lucía con su sede adicional como quedó en la primera ronda.

Queda de esta ronda la cotización **COT-2026-0011** (borrador, de Gerencia, en la sede norte).

### Lo que no se probó

- Nada pendiente de esta tanda. (El alcance "Sede" con Contratos, Órdenes, Leads y Oportunidades en el navegador no se repitió: usan la misma regla y están en las pruebas automáticas.)

### Datos de prueba que quedaron en "Tenant A - Prueba"

La sede **"Sede norte (prueba)"**, asignada como adicional a Lucía Paredes (Ventas). No cambia lo que ella ve, porque su alcance es "Propios". Se puede renombrar, desactivar o quitar desde Usuarios.

## Pendiente

- Mostrar y cambiar la sede de un documento desde su ficha (sigue pendiente de antes).
- Si un tenant lo pide: elegir la sede al crear un documento cuando la persona tiene varias (hoy siempre es la principal).
