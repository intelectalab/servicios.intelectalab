# Propuesta: antivirus para los archivos del ERP

*Para revisar con el equipo que administra el VPS. Fecha: 2026-10-22.*

## Situación actual

El ERP deja subir archivos:

- documentos de clientes, contratos, proyectos y órdenes de trabajo;
- entregables de proyectos;
- fotos de evidencia;
- extractos bancarios.

Algunos los descarga después el cliente desde su portal.

Hoy cada archivo ya:

- pasa una **lista cerrada de tipos** (PDF, Office, imágenes, comprimidos…) y se rechaza un programa aunque venga con nombre de PDF;
- guarda su **huella SHA-256**, comprobada contra la que calculó el navegador;
- se guarda en una **carpeta por empresa** y solo se descarga con un **enlace temporal de 5 minutos** que queda registrado;
- pasa por un **antivirus simulado**: no analiza de verdad, solo reconoce archivos de prueba. Sirve para tener lista la cuarentena (el documento queda "En cuarentena" y nunca se entrega). Cada archivo queda marcado como analizado por "SIMULATED", para volver a revisarlo cuando haya un motor real.

Falta **un antivirus real**. El sistema ya está preparado: el motor es una pieza intercambiable (`AntivirusScanner`) y se elige con `DOCUMENTS_ANTIVIRUS` en el `.env`.

## Opciones

| | Opción | Qué implica en el VPS | Ventajas | Riesgos / costo |
|---|---|---|---|---|
| **A** | **ClamAV como servicio (`clamd`)** | Instalar `clamav-daemon` y `clamav-freshclam` con apt. El ERP le pasa cada archivo por su socket local. | Análisis en segundos; firmas actualizadas solas (freshclam); gratuito y de código abierto; los archivos no salen del servidor. | El servicio mantiene cargada la base de firmas en memoria: alrededor de **1 GB o algo más de RAM** de forma permanente. Hay que **medir la RAM libre** del VPS antes, porque lo comparten otros proyectos. |
| **B** | **ClamAV sin servicio (`clamscan`)** | Instalar `clamav` y `clamav-freshclam`. El ERP lanza `clamscan` por cada archivo. | No ocupa memoria mientras no se sube nada. | Cada análisis carga las firmas desde cero: **10–30 s por archivo** y un pico de CPU y RAM en cada subida. Molesto para el usuario y para los demás sitios. |
| **C** | **Servicio externo por API** (p. ej. VirusTotal) | Nada que instalar. | Sin carga en el VPS. | Los archivos de los clientes **saldrían a un tercero** (contratos, datos personales): no recomendable para un ERP. Las versiones gratuitas no permiten uso comercial. |
| **D** | **Seguir con el simulado** | Nada. | Sin costo ni riesgo para el VPS. | No detecta nada real; solo quedan las otras protecciones (tipos, huella, enlace temporal). |

## Memoria del VPS (medida)

El 2026-10-23, al desplegar el módulo Documentos, el VPS tenía **7,8 GB de RAM en total y unos 6,1 GB disponibles** (`free -h`). Con eso, `clamd` (alrededor de 1 GB) parece caber holgado. Es una sola medición: conviene repetirla en un momento de más uso de los otros proyectos antes de decidir.

## Recomendación

**Opción A (ClamAV con `clamd`)**, si el VPS tiene memoria libre suficiente.

Si no la tiene, **seguir con D** hasta mover el ERP a un servidor propio o ampliar el VPS. No recomiendo B (castiga a todos los sitios en cada subida) ni C (privacidad de los clientes).

### Cómo se haría (A), sin afectar a los demás proyectos

1. **Medir antes** (`free -h`, uso actual de los 7 sitios) y decidir con el equipo.
2. **Respaldo y vuelta atrás.** Antes de instalar, dejar un `LEEME` en `/root/backups/` con cómo desinstalar: `apt remove clamav-daemon clamav-freshclam`. Mirar que no haya otro `apt` corriendo.
3. **Instalar** `clamav-daemon` y `clamav-freshclam`. Solo escucha por un socket local; no abre puertos.
4. **En el ERP:** escribir la clase `ClamAvScanner` y poner `DOCUMENTS_ANTIVIRUS=clamav` en su `.env`. Si el antivirus no responde, el archivo **no se acepta** (mejor que guardarlo sin analizar).
5. **Volver a analizar** los archivos marcados "SIMULATED" con una orden del ERP.
6. **Medir después** y comparar con el paso 1.

A quién afecta: solo al ERP (`servicios.intelectalab.com`). Los demás proyectos solo notarían la memoria que use `clamd`, y por eso se mide antes y después.
