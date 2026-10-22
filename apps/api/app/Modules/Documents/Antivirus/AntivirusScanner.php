<?php

namespace App\Modules\Documents\Antivirus;

/**
 * Analiza un archivo antes de guardarlo. Cada motor (simulado, ClamAV…) es una clase que cumple
 * este contrato; `DocumentStore` no sabe cuál está conectado (ver config/documents.php).
 */
interface AntivirusScanner
{
    public function scan(string $path): ScanResult;
}
