<?php

return [

    /*
    | Igual que la configuración por defecto de Laravel, salvo `max_age`: el
    | navegador guarda el permiso previo (preflight) y no lo repite antes de
    | cada llamada, que era lo que duplicaba todas las peticiones de la web.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
