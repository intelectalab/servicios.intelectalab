# 2026-10-23 — Marca de la empresa y documentos con diseño corporativo

## Qué se pidió

Pediste tres cosas:

- **Un diseño más corporativo** para los documentos que genera el sistema.
- **Que el administrador suba el logo de su empresa.**
- **Que se tomen los colores principales del logo** y se usen en esos documentos.

Tus respuestas:

1. **Dónde:** en Configuración → Marca de la empresa.
2. **Dónde se usa, además de los PDF:** en el portal de clientes, en los Excel exportados y en los correos al cliente.
3. **Estilo:** franja de color arriba y logo junto a la empresa.

Durante el trabajo pediste: **"ten cuidado con que el texto se superponga"**.

## Configuración → Marca de la empresa (`/settings`)

El ítem "Configuración" del menú (Administración) ya no es "próximamente". Pide el permiso `tenant.manage`, que tiene el Administrador. Quien no lo tiene ve la pantalla de solo lectura, con candado.

**Logo de la empresa**
- "Subir logo" / "Cambiar logo" y "Quitar logo".
- Acepta PNG, JPG o WEBP de hasta 2 MB.
- Sin logo, los documentos llevan las iniciales de la empresa.

**Colores de los documentos**
- Color principal y color secundario, cada uno con su selector, su código #RRGGBB y las muestras sacadas del logo, que se eligen con un clic.
- Al subir el logo, el sistema propone los colores solo. El principal es el primer color con tono; el secundario, otro color distinto de la paleta.
- Si el color principal es claro, avisa que en títulos y líneas se usará una versión más oscura (y cuál), para que se lea.

**Pie de los documentos**
- Dirección, teléfono, correo y sitio web.
- Si están vacíos, se usan la dirección y el teléfono registrados de la empresa.

**Vista previa y prueba**
- Vista previa de la cabecera de un documento, que cambia mientras se editan los colores.
- "PDF de ejemplo" genera una cotización de prueba con lo que está en pantalla, aunque no se haya guardado.

## Cómo se protege

- **El logo se vuelve a dibujar.** El servidor no guarda el archivo recibido: lo vuelve a dibujar como PNG nuevo (con GD), de 800 × 400 como máximo. Así se quitan metadatos y cualquier contenido oculto. SVG no se acepta (puede llevar código), y un archivo que no es imagen se rechaza aunque se llame `.png`.
- **Los colores se sacan en el servidor**, de la imagen ya limpia. Se descartan el blanco, el fondo transparente y los colores de los bordes con pocos píxeles; los grises pesan menos.
- **Tabla propia en la base:** `company_branding`, una fila por empresa.
  - Aislada por empresa, como todas.
  - Revisa el formato de los colores, el tamaño del logo (512 KB como máximo) y que logo y huella vayan juntos.
  - La aplicación no puede borrar filas.
- **Auditoría:** cada cambio queda registrado (`branding.logo`, `branding.logo_removed`, `branding.update`).

## Dónde se usa la marca

**PDF.** Todos salen de la misma pieza, así que el nuevo diseño llega a cotizaciones, contratos, órdenes, comprobantes, recibos, estados de cuenta, asientos y órdenes de compra, también los que se descargan desde el portal. Llevan:
- La franja del color principal arriba (y una línea fina del secundario).
- El logo, o las iniciales, con la razón social, el RUC y la dirección.
- El tipo de documento en grande, del color de la marca, con su número.
- Los datos en dos recuadros con un filo de color.
- La tabla con la cabecera de color y las filas alternas suaves.
- Los totales con el TOTAL resaltado.
- Notas, firmas y sellos de firma del color de la marca.
- Un pie con la empresa, el RUC, el contacto y el número de página.

Si la empresa no definió su marca, salen con el azul de siempre.

**Excel exportados.** El título y la cabecera usan el color de la empresa; el subtítulo dice "{Empresa} · Exportado el …".

**Portal de clientes.** La barra muestra el logo de la empresa (antes, sus iniciales) y su color.

**Correos al cliente.** La invitación al portal, el aviso de contrato por firmar y el código para firmar (solo el que recibe el cliente) llevan:
- el logo de la empresa (o su nombre, si no subió logo);
- su color en la franja, el botón y el código;
- "Enviado por {empresa}" al pie.

Los demás correos del sistema siguen con la marca de GSCreativas.

## Que el texto no se monte

- **Totales:** el recuadro se ensancha si una etiqueta larga no cabe junto a su monto. Lo viste en el contrato: "Valor total del contrato (inc. IGV)" se montaba sobre el monto. Ya está corregido.
- **Razón social:** la letra se achica hasta entrar en dos líneas (tres como mucho). Si aun así no entra, termina en "...". La dirección ocupa hasta tres líneas.
- **Título y número:** se achican si no caben en su columna.
- **Pie:** cada línea se corta antes del número de página.
- **Probado** con una razón social, una dirección y un contacto muy largos. Nada se monta.

## API

- `GET /v1/branding`: cualquiera de la empresa la lee, porque sus documentos la usan.
- `PUT /v1/branding`, `POST /v1/branding/logo` y `DELETE /v1/branding/logo`: piden `tenant.manage`.
- `GET /v1/portal/branding`: la misma marca, para el portal.
- Piezas: `App\Services\Branding` (limpiar el logo, paleta, colores legibles) y `BrandingController`.
- Correos: `App\Mail\Concerns\UsesTenantBrand` (`->withBrand($tenantId)`); las plantillas `layout`, `code` y `details` reciben `$accent`, `$accentSoft` y `$brand`.
- **Migración `2026_10_23_000400_company_branding`**: corrida en la PC, falta el VPS.

## Web

- `lib/branding.ts`: la marca se lee una vez por pestaña, y tiene las funciones de color (contraste, versión legible, tonos).
- `lib/document-pdf.ts`: el nuevo diseño.
- `lib/export-table.ts`: colores del Excel.
- `components/dashboard/settings/BrandingSettings.tsx`, ruta `/settings` (también en `proxy.ts`).
- `PortalShell.tsx`: el logo y el color en la barra del portal.

## Cómo se probó

- **`BrandingTest`: 4 pruebas.**
  - Subir un logo: se limpia, da sus colores (el azul de principal, el negro de secundario) y queda en la auditoría.
  - Validaciones de color y web; quitar el logo.
  - Un archivo que no es imagen y un SVG con código se rechazan.
  - Sin `tenant.manage`: lee pero no cambia (403).
  - Cada empresa solo ve su marca, y la base no deja borrarla.
  - El correo al cliente lleva logo, color legible y "Enviado por".
- **Total: 284 pruebas pasan** (285 con la de plantillas, agregada después).
- **Navegador**, con un logo de prueba cargado en Tenant A ("AndesTec", verde, gris y naranja):
  - **PDF, revisados abiertos:** la cotización COT-2026-0014 y el contrato CTR-2026-0007 (con los dos sellos de firma); el PDF de ejemplo con el naranja (claro), que usa texto oscuro sobre el naranja y títulos en naranja oscuro; y el PDF con textos muy largos.
  - **Excel de Comprobantes:** colores verdes y el nombre de la empresa en el subtítulo.
  - **Pantalla de Configuración** a 1366 px, sin desborde.
  - **Barra del portal** de Rosa con el logo y el verde.
  - **Correo de invitación**, visto como página.
- **Al terminar**, la marca de prueba se quitó de Tenant A: tu empresa queda como estaba, hasta que el Administrador suba su logo en Configuración.
- **No probado:** subir el logo desde la pantalla con la cuenta de Administrador, porque su contraseña no estaba en la sesión. La subida sí está probada por la API en `BrandingTest`.

## Lo que faltaba (pedido después: "haz lo que falta")

- **Todo el portal con el color de la empresa**, no solo la barra: títulos, íconos, bordes de los indicadores, enlaces, pestañas, botones principales, casillas y la cabecera de las ventanas.
  - Las piezas comunes (`proto.tsx`) toman sus colores de variables (`--p-accent`, `--p-accent-hover`, `--p-on-accent`). Si nadie las define, valen el azul de siempre, así que el sistema interno no cambia: se comprobó que el botón "Nueva cotización" sigue en #1B8FC9.
  - El portal pone en `<body>` la clase `tenant-brand` y las variables de la marca. También cubre las ventanas, que se dibujan fuera del portal, y se quitan al salir.
  - Hay dos juegos de variables, claro (`--bl-…`) y oscuro (`--bd-…`); `globals.css` elige el del tema. En oscuro, el color se aclara hasta leerse sobre el fondo (contraste de 4.5:1 o más).
  - Los botones usan la versión legible del color, con texto blanco u oscuro según cuál se lea mejor.
- **Plantillas Excel de importación** (`SpreadsheetImport::templateBook`): el título, las notas y la cabecera usan el color de la empresa de la sesión; sin marca, los de siempre. Prueba nueva en `BrandingTest`.
- **Probado en el navegador:** el portal de Rosa con la marca de prueba, en claro y oscuro, a 1366 y 375 px, sin desborde: inicio, cotización y Soporte. Al salir del portal, el sistema queda en azul. La marca de prueba se quitó al terminar.

## En el VPS (2026-10-23)

- Pediste commitear todo y desplegar. Tu commit `1729dd4` ("Arreglando modulos") ya traía la consola, el panel, las notificaciones y los botones; esto fue el commit `17038c2`. Los dos se subieron a GitHub.
- `deploy.sh --si` (sin el `--si` se cancela solo cuando no hay terminal).
  - Respaldo en `/root/backups/erp/20261023_072653_antes_deploy_8e1f1ce`, que es la vuelta atrás.
  - Se corrieron las migraciones `2026_10_23_000100`, `000200`, `000300` y `000400`.
  - Web compilada; solo se reinició `servicios-web`.
  - Registro en `/root/backups/erp/deploy_20261023_marca.log`.
- Comprobado después:
  - Web y API responden, y las páginas y rutas nuevas existen (sin sesión llevan al inicio de sesión o piden sesión).
  - La tabla `company_branding` existe, el catálogo tiene 15 módulos y `notifications.read_at` está.
  - GD está activo en PHP 8.4.
  - Los procesos de pm2 de los otros proyectos siguen en línea.
- No se probó con una cuenta real del VPS, porque las contraseñas no estaban en la sesión.

## Pendiente

- Que el Administrador de cada empresa suba su logo en Configuración → Marca de la empresa.
