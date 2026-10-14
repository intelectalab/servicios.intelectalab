<?php

namespace App\Modules\Finance\Fiscal;

/**
 * Proveedor que además sabe presentar comunicaciones de baja. No todos los proveedores las
 * ofrecen: el que sí, cumple también este contrato y la ficha del comprobante ofrece la baja.
 */
interface FiscalVoidProvider extends FiscalProvider
{
    /**
     * Presenta la baja de un comprobante ya aceptado. Debe poder repetirse sin duplicarla.
     * Si no logra comunicarse, lanza una excepción: la baja queda rechazada con ese motivo y se
     * puede volver a pedir.
     *
     * `FiscalResult::pending` significa que SUNAT entregó un ticket y aún no resuelve: su `code`
     * es ese ticket.
     *
     * @param  array  $document  el comprobante, ya normalizado (ver `FiscalDocument::from`)
     * @param  array{tax_identifier: ?string, environment: string, configuration: array}  $settings
     */
    public function void(array $document, string $reason, array $settings): FiscalResult;
}
