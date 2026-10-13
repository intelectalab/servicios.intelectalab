<?php

namespace App\Modules\Finance\Fiscal;

/** Lo que responde el proveedor al presentar un comprobante. */
final class FiscalResult
{
    /**
     * @param  string  $status  ACCEPTED, REJECTED o PENDING (el proveedor lo recibió y SUNAT aún no responde)
     * @param  string|null  $message  respuesta legible, tal como se mostrará en la ficha
     * @param  string|null  $code  código de respuesta de SUNAT o del proveedor
     * @param  string|null  $hash  resumen (hash) del XML firmado, 64 caracteres
     * @param  string|null  $cdr  identificador de la constancia de recepción (CDR)
     * @param  array  $raw  respuesta completa, para guardarla. Sin secretos.
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?string $code = null,
        public readonly ?string $hash = null,
        public readonly ?string $cdr = null,
        public readonly array $raw = [],
    ) {}

    public static function accepted(?string $message = null, ?string $code = null, ?string $hash = null, ?string $cdr = null, array $raw = []): self
    {
        return new self('ACCEPTED', $message ?? 'Aceptado por SUNAT.', $code, $hash, $cdr, $raw);
    }

    public static function rejected(string $message, ?string $code = null, array $raw = []): self
    {
        return new self('REJECTED', $message, $code, null, null, $raw);
    }

    public static function pending(?string $message = null, array $raw = []): self
    {
        return new self('PENDING', $message ?? 'Recibido por el proveedor; SUNAT aún no responde.', null, null, null, $raw);
    }
}
