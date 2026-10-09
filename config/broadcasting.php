<?php

/*
| Difusión en tiempo real. Hoy solo la usa el aviso «examen activado»
| (`App\Events\ExamenActivado`): el alumnado lo ve sin recargar la página.
|
| El servidor WebSocket es Laravel Reverb, un proceso aparte que hay que
| arrancar con `php artisan reverb:start`. Si no está levantado NO se rompe
| nada: el evento va por la cola y, si falla, el alumno sigue viendo el examen
| al recargar o por la notificación. `BROADCAST_CONNECTION=log` lo apaga.
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Si Reverb no responde, que la cola no se quede colgada.
                'timeout' => 5,
            ],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],

    ],

];
