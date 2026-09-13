<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Http\Requests\StoreDocumentRequest;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentVersion;
use App\Modules\Documents\Models\FileObject;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = Document::query()
            ->when($request->filled('document_type'), fn ($q) => $q->where('document_type', $request->query('document_type')));

        return response()->json($query->with('latestVersion')->paginate($request->integer('per_page', 20)));
    }

    public function show(string $document)
    {
        $document = Document::with('versions.fileObject')->findOrFail($document);

        return response()->json($document);
    }

    public function store(StoreDocumentRequest $request)
    {
        $tenantId = $request->attributes->get('tenant_id');
        $uploadedFile = $request->file('file');

        $document = DB::transaction(function () use ($request, $tenantId, $uploadedFile) {
            // 1. Guardar el archivo físico en el disco (MinIO/S3), dentro de una
            //    carpeta por tenant, con un nombre único para evitar colisiones.
            $objectKey = "tenants/{$tenantId}/" . (string) Str::uuid() . '_' . $uploadedFile->getClientOriginalName();
            $contents = file_get_contents($uploadedFile->getRealPath());

            Storage::disk('documents')->put($objectKey, $contents);

            $sha256 = hash('sha256', $contents);

            // 2. Registrar el archivo físico.
            $fileObject = FileObject::create([
                'tenant_id' => $tenantId,
                'storage_provider' => 's3',
                'bucket' => config('filesystems.disks.documents.bucket'),
                'object_key' => $objectKey,
                'original_name' => $uploadedFile->getClientOriginalName(),
                'mime_type' => $uploadedFile->getClientMimeType(),
                'size_bytes' => $uploadedFile->getSize(),
                'sha256' => $sha256,
                'encrypted' => true,
                'antivirus_status' => 'PENDING', // el escaneo real queda pendiente (ver nota abajo)
            ]);

            // 3. Crear el documento lógico.
            $document = Document::create([
                'tenant_id' => $tenantId,
                'document_type' => $request->document_type,
                'title' => $request->title,
                'status' => 'ACTIVE',
                'classification' => $request->classification ?? 'PRIVATE',
                'created_by' => $request->user()->id,
            ]);

            // 4. Crear la primera versión, conectando documento con archivo.
            DocumentVersion::create([
                'tenant_id' => $tenantId,
                'document_id' => $document->id,
                'file_object_id' => $fileObject->id,
                'version' => 1,
                'checksum' => $sha256,
                'created_by' => $request->user()->id,
            ]);

            AuditLogger::log(
                tenantId: $tenantId,
                userId: $request->user()->id,
                action: 'document.upload',
                entityType: 'Document',
                entityId: $document->id,
                metadata: ['title' => $request->title, 'size_bytes' => $uploadedFile->getSize()],
            );

            return $document;
        });

        return response()->json($document->load('versions.fileObject'), 201);
    }

    /**
     * Genera una URL temporal firmada para descargar el archivo,
     * en vez de exponer un enlace público permanente.
     */
    public function downloadUrl(string $document, Request $request)
    {
        $document = Document::with('latestVersion.fileObject')->findOrFail($document);

        if (! $document->latestVersion) {
            return response()->json(['message' => 'Este documento no tiene ninguna versión.'], 404);
        }

        $fileObject = $document->latestVersion->fileObject;

        $url = Storage::disk('documents')->temporaryUrl(
            $fileObject->object_key,
            now()->addMinutes(15)
        );

        AuditLogger::log(
            tenantId: $request->attributes->get('tenant_id'),
            userId: $request->user()->id,
            action: 'document.download_url_generated',
            entityType: 'Document',
            entityId: $document->id,
        );

        return response()->json(['url' => $url, 'expires_in_minutes' => 15]);
    }
}