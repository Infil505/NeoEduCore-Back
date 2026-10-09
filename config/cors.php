<?php
return [
'paths' => ['api/*', 'sanctum/csrf-cookie'],
'allowed_methods' => ['*'],
// FRONTEND_URL admite VARIOS orígenes separados por comas
// (p. ej. «http://localhost:3000,http://localhost:5173»): el frontend en
// desarrollo corre con Vite (5173) y en producción en otro dominio.
'allowed_origins' => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))
))),
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => false, // usamos tokens Bearer, así que false
];
