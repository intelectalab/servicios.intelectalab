# Buscador en los filtros desplegables con muchas opciones

Fecha: 2026-11-01.

## 1. Qué se pidió

Agregar un buscador a los filtros desplegables que tienen muchas opciones (por ejemplo, Entidad en Auditoría), sin cambiar nada más de Documentos ni de Auditoría.

## 2. Qué se cambió

- `FilterSelect` (`apps/web/src/components/ui/proto.tsx`), el filtro de las barras de los listados: con **9 opciones o más** usa `PickSelect` con buscador (el mismo del Libro diario). Busca sin tildes ni mayúsculas, se maneja con flechas, Enter y Escape, y la primera opción sigue siendo "…: todos".
- Con menos opciones sigue el desplegable de siempre (Estado, Visibilidad…).
- Se aplica en todo el ERP; en Control: Entidad y Usuario de Auditoría, y Categoría de Documentos.

## 3. Qué NO se tocó

- Las pantallas de Documentos y Auditoría, sus columnas ni sus filtros cortos.
- La API.

## 4. Diferencias con el prototipo

El prototipo no tiene buscador en estos filtros; es una mejora pedida.

## 5. Base de datos

Sin cambios.

## 6. Cómo se probó

- `tsc --noEmit` y ESLint sin errores.
- Navegador (1366 × 768), Documentos con la cuenta Ventas: el filtro Categoría queda alineado con los demás; al escribir "ser" deja solo "Servidor" y "contr" solo "Contrato"; Enter filtra el listado y "Categoría: todos" lo restablece.
- Auditoría no se pudo abrir en el navegador: las cuentas locales que la ven piden código por correo (SMTP) o tienen contraseña propia. Usa el mismo componente.

## 7. Pendiente

- Mirar Entidad en Auditoría con la cuenta de Administrador.
