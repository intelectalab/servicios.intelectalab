<?php

namespace App\Providers;

use App\Modules\Documents\Antivirus\AntivirusScanner;
use App\Modules\Documents\Antivirus\SimulatedScanner;
use App\Modules\Documents\Support\DocumentStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Antivirus de los documentos: el motor que diga config/documents.php.
        $this->app->bind(AntivirusScanner::class, fn () => match (config('documents.antivirus')) {
            'simulated' => new SimulatedScanner,
            default => throw new \RuntimeException('Antivirus de documentos desconocido: '.config('documents.antivirus')),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Adjuntos en el disco del servidor: el enlace temporal apunta a la ruta firmada de la API,
        // con el nombre de descarga que pide quien lo genera (ResponseContentDisposition, como en S3).
        if (config('filesystems.disks.'.DocumentStore::DISK.'.driver') === 'local') {
            Storage::disk(DocumentStore::DISK)->buildTemporaryUrlsUsing(function (string $path, $expiration, array $options = []) {
                preg_match('/filename="([^"]*)"/', (string) ($options['ResponseContentDisposition'] ?? ''), $match);

                return URL::temporarySignedRoute('documents.file', $expiration, array_filter([
                    'path' => $path,
                    'name' => $match[1] ?? null,
                ]));
            });
        }
    }
}
