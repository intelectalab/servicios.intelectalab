# 2026-10-22 — Documentos en el disco del servidor (sin MinIO ni S3)

## Qué se pidió

Guardar los archivos que suben las empresas (entregables, fotos de evidencia, extractos bancarios, documentos) en el disco del VPS en vez de MinIO, y pasar allá los 8 archivos que había en la PC. Solo en este proyecto: nada de los demás sitios del servidor.

## Cómo quedó

- El disco `documents` (el que usa `DocumentStore`) se elige con **`DOCUMENTS_DRIVER`** en el `.env` de la API:
  - `local` → carpeta `apps/api/storage/app/private/documents/`, dentro del proyecto. `backup.sh` ya respalda `storage/app`, así que los archivos entran en cada respaldo y en cada despliegue.
  - `s3` (lo que se usa si no se pone nada) → MinIO o S3 con las variables `AWS_*`, como antes. **La PC sigue con MinIO**; no cambia nada allí.
- La ruta dentro del almacén no cambia (`tenants/<empresa>/documents/<uuid>.<ext>`): un archivo se puede pasar de MinIO al disco copiándolo con la misma ruta.
- **Descargas:** con `local`, el enlace temporal lo genera la API como enlace firmado a `GET /api/v1/files/<ruta>` (ruta `documents.file`, `DocumentFileController`). Igual que el de S3: solo lo da a quien tiene permiso sobre el registro, vence a los 5 minutos, y sin firma válida responde 403. Se entrega siempre como descarga con su nombre original, con `Content-Security-Policy: sandbox` y `nosniff` (un archivo no se abre como página del sitio). Tope de 60 descargas por minuto por dirección.
- `file_objects.storage_provider` guarda `local` o `s3` según dónde quedó cada archivo (antes siempre `s3`).
- La web no cambia: sigue pidiendo el enlace y abriéndolo.

Archivos: `config/filesystems.php`, `app/Providers/AppServiceProvider.php` (arma el enlace firmado), `app/Modules/Documents/Http/Controllers/DocumentFileController.php` (nuevo), `routes/api.php`, `DocumentStore::provider()`, `DocumentController`, `.env.example`.

## Pruebas

- `OperationsProjectsTest::test_documents_on_the_server_disk_download_only_with_a_signed_link`: sube un entregable con el disco local, comprueba que el archivo queda en la carpeta de su empresa y con `storage_provider = local`, lo descarga con el enlace (sin sesión), y comprueba que sin firma, con la firma de otro archivo o pasado el plazo responde 403.
