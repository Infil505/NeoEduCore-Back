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
// Cuánto tiempo (segundos) recuerda el navegador la respuesta a la consulta
// previa (preflight OPTIONS). Con 0 la repetía ANTES DE CADA petición con
// `Authorization`: dos viajes por llamada, y en un colegio con mala conexión
// (y la API en otro dominio que el front) se nota en todo. 7200 es el tope que
// respeta Chrome; Firefox admite hasta 86400.
'max_age' => (int) env('CORS_MAX_AGE', 7200),
'supports_credentials' => false, // usamos tokens Bearer, así que false
];
