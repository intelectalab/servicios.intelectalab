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

];
