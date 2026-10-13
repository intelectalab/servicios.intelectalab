<?php

namespace App\Modules\Finance\Fiscal;

/**
 * Proveedor de facturación electrónica (OSE / PSE): quien firma el comprobante y lo presenta a
 * SUNAT. El sistema no habla con SUNAT directamente; habla con una clase que cumple este contrato.
 *
 * Para conectar un proveedor: escribir su clase, registrarla en `config/fiscal.php` con un código
 * y poner ese código en `fiscal_configurations.provider_code` de la empresa. Nada más cambia: la
 * emisión, el reintento, la ficha y la auditoría ya pasan por `FiscalGateway`.
 */
interface FiscalProvider
{
    /** Nombre visible del proveedor ("Nubefact", "Efact"…). */
    public function name(): string;

    /**
     * Presenta un comprobante. Debe poder repetirse sin duplicarlo: el mismo comprobante (serie y
     * número) puede llegar otra vez en un reintento.
     *
     * Se llama fuera de toda transacción de la base. Si no logra comunicarse, lanza una excepción:
     * el comprobante queda "Rechazado" con ese motivo y se puede reintentar.
     *
     * @param  array  $document  el comprobante, ya normalizado (ver `FiscalDocument::from`)
     * @param  array{tax_identifier: ?string, environment: string, configuration: array}  $settings  datos fiscales de la empresa
     */
    public function send(array $document, array $settings): FiscalResult;
}
