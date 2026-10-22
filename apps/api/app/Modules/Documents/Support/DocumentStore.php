<?php

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Models\FileObject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Guarda un archivo como documento con versiones, para adjuntarlo a un registro de otro módulo
 * (hoy, el entregable de un proyecto). El archivo va al almacén en la carpeta de su empresa; en
 * la base quedan el archivo, el documento, su versión y el vínculo con el registro.
 */
class DocumentStore
{
    public const DISK = 'documents';

    /** Tamaño máximo, en kilobytes (20 MB). */
    public const MAX_KB = 20480;

    /** Solo se aceptan archivos de trabajo: nada que el equipo de quien lo descargue pueda ejecutar. */
    public const EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv',
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'zip', 'rar', '7z', 'dwg', 'dxf', 'mp4',
    ];

    /** Lo que el contenido revela, diga lo que diga el nombre del archivo. */
    private const BLOCKED_MIMES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-executable', 'application/x-sharedlib',
        'application/x-mach-binary', 'application/x-sh', 'application/x-shellscript', 'text/x-shellscript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/html', 'application/javascript', 'text/javascript',
        'application/x-msi', 'application/java-archive',
    ];

    public const CATEGORIES = ['Contrato', 'Entregable', 'Evidencia', 'Proyecto', 'Cliente', 'Proveedor', 'Servidor', 'Dominio', 'Hosting', 'Cloud', 'Otros'];

    public const VISIBILITIES = ['INTERNAL', 'PORTAL'];

    /** Dónde queda el archivo: 'local' (disco del servidor) o 's3' (MinIO o S3), según DOCUMENTS_DRIVER. */
    public static function provider(): string
    {
        return config('filesystems.disks.'.self::DISK.'.driver') === 'local' ? 'local' : 's3';
    }

    /** Reglas del archivo para un formulario de subida. */
    public static function fileRules(): array
    {
        return ['required', 'file', 'max:'.self::MAX_KB];
    }

    public static function fileMessages(): array
    {
        return [
            'file.required' => 'Elige el archivo.',
            'file.file' => 'El archivo no se pudo leer.',
            'file.uploaded' => 'El archivo no se pudo subir: supera el tamaño que admite el servidor.',
            'file.max' => 'El archivo supera los 20 MB.',
        ];
    }

    /**
     * Sube el archivo y lo registra como versión nueva de `$document`, o como documento nuevo.
     * `$expectedSha` es la huella que calculó el navegador: si no coincide con lo recibido, se rechaza.
     *
     * @param  array{title: string, category: string, visibility: string}  $attributes
     */
    public static function store(
        string $tenantId, string $userId, UploadedFile $file, array $attributes, ?Document $document = null, ?string $expectedSha = null,
    ): DocumentVersion {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, self::EXTENSIONS, true) || in_array(strtolower((string) $file->getMimeType()), self::BLOCKED_MIMES, true)) {
            throw ValidationException::withMessages(['file' => ['Este tipo de archivo no se admite. Sube un PDF, un documento de oficina, una imagen o un comprimido.']]);
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        if ($expectedSha !== null && ! hash_equals(strtolower($expectedSha), $sha256)) {
            throw ValidationException::withMessages(['file' => ['El archivo llegó distinto de como salió de tu equipo. Vuelve a subirlo.']]);
        }

        // El nombre original solo se guarda como dato; la ruta en el almacén no depende de él.
        $objectKey = "tenants/{$tenantId}/documents/".Str::uuid().'.'.$extension;
        $disk = Storage::disk(self::DISK);

        // El almacén es un servicio externo: se escribe antes de abrir la transacción.
        $stream = fopen($file->getRealPath(), 'r');
        try {
            $disk->put($objectKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return DB::transaction(function () use ($tenantId, $userId, $file, $attributes, $document, $sha256, $objectKey) {
                $fileObject = FileObject::create([
                    'tenant_id' => $tenantId,
                    'storage_provider' => self::provider(),
                    'bucket' => (string) (config('filesystems.disks.'.self::DISK.'.bucket') ?? self::DISK),
                    'object_key' => $objectKey,
                    'original_name' => Str::limit($file->getClientOriginalName(), 480, ''),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'encrypted' => false,
                    // Aún no hay motor antivirus conectado: queda sin analizar.
                    'antivirus_status' => 'PENDING',
                ]);

                $fields = ['title' => $attributes['title'], 'document_type' => $attributes['category'], 'visibility' => $attributes['visibility']];
                if ($document) {
                    // Bloquea el documento: dos subidas a la vez no toman el mismo número de versión.
                    $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
                    $document->update($fields);
                } else {
                    $document = Document::create([...$fields, 'tenant_id' => $tenantId, 'status' => 'ACTIVE', 'classification' => 'INTERNAL', 'created_by' => $userId]);
                }

                $version = DocumentVersion::create([
                    'tenant_id' => $tenantId,
                    'document_id' => $document->id,
                    'file_object_id' => $fileObject->id,
                    'version' => ((int) $document->versions()->max('version')) + 1,
                    'checksum' => $sha256,
                    'created_by' => $userId,
                ]);

                return $version->setRelation('fileObject', $fileObject)->setRelation('document', $document);
            });
        } catch (\Throwable $error) {
            // Sin registro en la base, el archivo no debe quedar suelto en el almacén.
            rescue(fn () => $disk->delete($objectKey), report: false);
            throw $error;
        }
    }

    /** Vincula el documento con un registro; repetirlo no duplica el vínculo. */
    public static function link(Document $document, string $entityType, string $entityId): void
    {
        DB::table('document_links')->insertOrIgnore([
            'tenant_id' => $document->tenant_id, 'document_id' => $document->id,
            'entity_type' => $entityType, 'entity_id' => $entityId, 'link_type' => 'ATTACHMENT',
        ]);
    }

    /** Enlace temporal para descargar la última versión, con su nombre original. */
    public static function downloadUrl(Document $document, int $minutes = 5): ?string
    {
        $file = $document->latestVersion?->fileObject;
        if (! $file) {
            return null;
        }
        $name = str_replace(['"', '\\', "\r", "\n"], '', Str::ascii($file->original_name)) ?: 'documento';

        return Storage::disk(self::DISK)->temporaryUrl($file->object_key, now()->addMinutes($minutes), [
            'ResponseContentDisposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
