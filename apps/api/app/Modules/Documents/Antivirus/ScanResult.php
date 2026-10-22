<?php

namespace App\Modules\Documents\Antivirus;

/** Resultado del análisis: CLEAN, INFECTED o FAILED (el motor no respondió). */
final class ScanResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $engine,
        public readonly ?string $threat = null,
    ) {}

    public function infected(): bool
    {
        return $this->status === 'INFECTED';
    }
}
