<?php

namespace App\Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Documents\Support\DocumentStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;

/**
 * Entrega un archivo guardado en el disco del servidor (DOCUMENTS_DRIVER=local). No pide sesión:
 * el enlace lo genera la API solo a quien tiene permiso sobre el registro, va firmado y vence a
 * los pocos minutos, igual que el enlace temporal de S3.
 */
class DocumentFileController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        $disk = Storage::disk(DocumentStore::DISK);
        try {
            abort_unless($disk->exists($path), 404);
        } catch (PathTraversalDetected) {
            abort(404);
        }

        $name = str_replace(['"', '\\', '/', "\r", "\n"], '', (string) $request->query('name')) ?: basename($path);

        // Siempre como descarga y sin que el navegador interprete el contenido como página.
        return $disk->download($path, $name, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
