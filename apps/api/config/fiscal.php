<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedores de facturación electrónica (OSE / PSE)
    |--------------------------------------------------------------------------
    |
    | Código => clase que cumple App\Modules\Finance\Fiscal\FiscalProvider.
    | Cada empresa elige el suyo en `fiscal_configurations.provider_code`.
    | Mientras la lista esté vacía, ningún comprobante se envía a SUNAT.
    |
    | Ejemplo: 'nubefact' => \App\Modules\Finance\Fiscal\Providers\NubefactProvider::class,
    |
    */

    'providers' => [],

    /*
    |--------------------------------------------------------------------------
    | Tipo de cambio de SUNAT
    |--------------------------------------------------------------------------
    |
    | Archivo público de SUNAT con el tipo de cambio del día, en una línea:
    | "día/mes/año|compra|venta|". Lo lee "Actualizar desde SUNAT" en Monedas
    | y tipo de cambio.
    |
    */

    'exchange_rate_url' => env('SUNAT_EXCHANGE_RATE_URL', 'https://www.sunat.gob.pe/a/txt/tipoCambio.txt'),

];
