<?php

return [

    /*
    | Igual que la configuración por defecto de Laravel, salvo `max_age`: el
    | navegador guarda el permiso previo (preflight) y no lo repite antes de
    | cada llamada, que era lo que duplicaba todas las peticiones de la web.
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // En producción, definir CORS_ALLOWED_ORIGINS con los dominios de la web separados por comas
    // (p. ej. "https://midominio.com,https://app.midominio.com"). Sin definir, acepta cualquiera.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
