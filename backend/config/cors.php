<?php

/*
| CORS : en développement on accepte n'importe quel port localhost (Vite peut changer de port) ;
| en production seules les origines déclarées dans CORS_ORIGINS sont autorisées.
| Les cookies de rafraîchissement exigent supports_credentials = true.
*/

return [
    'paths' => ['api/*', 'uploads/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('CORS_ORIGINS', 'http://localhost:5173'))))),

    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => true,
];
