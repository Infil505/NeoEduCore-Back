<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dominios permitidos para recursos educativos (whitelist)
    |--------------------------------------------------------------------------
    | Cualquier URL sugerida por la IA o ingresada como recurso de estudio
    | debe provenir de uno de estos dominios. La validación es por sufijo de
    | host (e.g. 'youtube.com' también permite 'www.youtube.com').
    */
    'allowed_domains' => [
        'youtube.com',
        'youtu.be',
        'khanacademy.org',
        'coursera.org',
        'edx.org',
        'wikipedia.org',
        'wikimedia.org',
        'mep.go.cr',
        'desmos.com',
        'geogebra.org',
        'wolframalpha.com',
        'codecademy.com',
        'w3schools.com',
        'developer.mozilla.org',
        'docs.php.net',
        'php.net',
        'laravel.com',
        'duolingo.com',
        'brilliant.org',
        'ted.com',
        'nationalgeographic.com',
        'bbc.co.uk',
        'bbc.com',
        'britannica.com',
        'rae.es',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dominios del vídeo de apoyo del examen
    |--------------------------------------------------------------------------
    | `exams.video_url` lo pone el docente y el tutor se lo entrega al alumnado
    | visual o auditivo. Más estricto que `allowed_domains`: solo plataformas de
    | vídeo, porque el frontend lo va a incrustar como reproductor. Misma
    | validación por sufijo de host ('youtube.com' admite 'www.' y 'm.').
    */
    'video_domains' => [
        'youtube.com',
        'youtu.be',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enlaces de apoyo del examen
    |--------------------------------------------------------------------------
    | Cuántos enlaces (vídeos, textos) puede dejar el docente en un examen
    | (`exams.support_resources`). Los usa el tutor al recomendar recursos.
    */
    'max_support_resources' => (int) env('EXAM_MAX_SUPPORT_RESOURCES', 5),

    /*
    |--------------------------------------------------------------------------
    | Comprobación de enlaces antes de entregarlos
    |--------------------------------------------------------------------------
    | El tutor comprueba que un enlace siga vivo antes de dárselo al alumno
    | (`App\Services\AI\EnlaceDisponible`). El veredicto se cachea: `ttl_ok`
    | para los vivos, `ttl_broken` para los rotos (se reintenta pronto, por si
    | el docente lo arregla) y `ttl_unknown` cuando no hubo veredicto (tiempo
    | agotado, sin red). `timeout` en segundos: el sondeo no puede alargar la
    | petición del alumno.
    */
    'link_check' => [
        'enabled'     => (bool) env('AI_LINK_CHECK', true),
        'timeout'     => (int) env('AI_LINK_CHECK_TIMEOUT', 3),
        'ttl_ok'      => (int) env('AI_LINK_CHECK_TTL_OK', 21600),
        'ttl_broken'  => (int) env('AI_LINK_CHECK_TTL_BROKEN', 900),
        'ttl_unknown' => (int) env('AI_LINK_CHECK_TTL_UNKNOWN', 60),
    ],
];
