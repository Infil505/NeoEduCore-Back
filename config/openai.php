<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Project
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API project. This is used optionally in
    | situations where you are using a legacy user API key and need association
    | with a project. This is not required for the newer API keys.
    */
    'project' => env('OPENAI_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Base URL
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API base URL used to make requests. This
    | is needed if using a custom API endpoint. Defaults to: api.openai.com/v1
    */
    'base_uri' => env('OPENAI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout
    |--------------------------------------------------------------------------
    |
    | The timeout may be used to specify the maximum number of seconds to wait
    | for a response. By default, the client will time out after 30 seconds.
    */

    'request_timeout' => env('OPENAI_REQUEST_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Tutor conversacional
    |--------------------------------------------------------------------------
    |
    | Estos cuatro valores son **coste directo por petición**, y estaban como
    | constantes de clase en `AiTutorService`: ajustarlos exigía desplegar. Aquí
    | se tocan por entorno, que es lo que hace falta cuando la factura de OpenAI
    | sube o cuando el piloto necesita respuestas más largas.
    |
    | `history_messages` es el que más pesa: cada mensaje del historial viaja
    | como contexto en TODAS las peticiones siguientes, así que su efecto sobre
    | el gasto es multiplicativo, no lineal.
    */

    /*
    |--------------------------------------------------------------------------
    | Modelo
    |--------------------------------------------------------------------------
    |
    | El código lo leía de `services.openai.model`, clave que **no existe** en
    | `config/services.php`: la llamada devolvía null y caía siempre al valor por
    | defecto, así que el modelo estaba fijado de hecho. Cambiar de modelo —lo
    | primero que se toca si sube el precio o sale uno mejor— exigía desplegar.
    */
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    'tutor' => [
        // Tope de tokens de la respuesta.
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 600),

        /*
         | El modo «práctica» pide ejercicios resueltos paso a paso, así que
         | necesita más espacio y menos creatividad que una conversación normal.
         */
        'max_tokens_practice' => (int) env('OPENAI_MAX_TOKENS_PRACTICE', 800),
        'temperature'          => (float) env('OPENAI_TEMPERATURE', 0.7),
        'temperature_practice' => (float) env('OPENAI_TEMPERATURE_PRACTICE', 0.5),

        // Mensajes previos que se envían como contexto en cada turno.
        'history_messages' => (int) env('OPENAI_HISTORY_MESSAGES', 20),

        // Mensajes que se conservan en el JSONB de la sesión. Acota el tamaño
        // de la fila, no el coste de la petición.
        'stored_messages' => (int) env('OPENAI_STORED_MESSAGES', 60),

        // Vigencia (segundos) del prompt de sistema cacheado por estudiante.
        'context_ttl' => (int) env('OPENAI_CONTEXT_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validación de la salida
    |--------------------------------------------------------------------------
    |
    | Longitudes aceptables de una respuesta del tutor. Fuera de rango se
    | descarta y se entrega el mensaje de reserva: una respuesta vacía o
    | desbocada suele ser un fallo del modelo, no contenido útil.
    */

    'output' => [
        'min_length' => (int) env('OPENAI_OUTPUT_MIN_LENGTH', 5),
        'max_length' => (int) env('OPENAI_OUTPUT_MAX_LENGTH', 4000),
    ],
];
