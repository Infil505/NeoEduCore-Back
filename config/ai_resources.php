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
];
