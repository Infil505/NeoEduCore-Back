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
    |
    | El tutor se pasó a esta clave en su momento, pero las recomendaciones
    | (`AiRecommendationService`) y el prompt libre del docente (`AiController`)
    | se quedaron atrás hasta el 15/09/2026: `OPENAI_MODEL` cambiaba el modelo de
    | uno de los tres caminos y los otros dos seguían en el literal. **Ya son los
    | tres**, así que esta es la única clave del modelo en todo el sistema.
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

        /*
         | Aviso de que quien responde es una IA ([397], decisión D4).
         |
         | Viaja en la respuesta de `POST /ai/tutor/chat` y de
         | `GET /ai/tutor/diagnosis`, en vez de quedar como un rótulo fijo del
         | frontend: el compromiso es del sistema, y así el día que haya una app
         | móvil o un segundo cliente no hay que acordarse de repetirlo.
         |
         | Está en configuración porque es texto que se ajusta —es para menores
         | de primaria— sin tocar código.
         */
        'notice' => env(
            'OPENAI_TUTOR_NOTICE',
            'Esta respuesta la escribió un asistente de inteligencia artificial. '
            . 'Puede equivocarse: si algo no te cuadra, preguntale a tu docente.'
        ),

        /*
         | Lo que se responde cuando el mensaje intenta reescribir las reglas
         | del tutor (`AiInputSanitizer::pareceInyeccion()`).
         |
         | Ese turno no llega a OpenAI, así que este texto es literalmente lo
         | que lee el alumno. Va en configuración por lo mismo que `notice`: es
         | texto para menores de primaria y lo afina el profesorado, no el
         | desarrollador. La negativa se ofrece con salida —«pero sí puedo…»—
         | porque casi siempre quien lo escribe es un crío probando qué pasa,
         | no un atacante.
         */
        'injection_reply' => env(
            'OPENAI_TUTOR_INJECTION_REPLY',
            'Esa parte no la puedo hacer: mis instrucciones no se cambian. '
            . 'Pero sí puedo ayudarte con tus materias. ¿Qué tema quieres repasar?'
        ),

        /*
        |----------------------------------------------------------------------
        | Registro de lenguaje por grado
        |----------------------------------------------------------------------
        |
        | Entre 1.º y 6.º de primaria hay seis años de diferencia lectora, y el
        | tutor los trataba igual: el prompt mandaba «grado 3» y una instrucción
        | vaga («adapta el nivel de detalle al perfil»), así que el registro lo
        | improvisaba el modelo. Para 1.º eso no es un matiz de estilo — a esa
        | edad muchos apenas leen con fluidez, y un párrafo denso no es poco
        | adaptado: es inservible.
        |
        | Cada franja describe **cómo escribir**, no qué enseñar. Va aquí y no
        | en el código porque es texto pedagógico: quien mejor lo ajusta es el
        | profesorado del centro, y no debería hacer falta desplegar para ello.
        |
        | `hasta` es el grado máximo al que aplica la franja; se evalúan en
        | orden. `null` es el texto para cuando no se conoce el grado, que pasa
        | con el alumnado cargado en masa sin ese dato.
        */
        'registro' => [
            'franjas' => [
                [
                    'hasta' => 2,
                    'texto' => 'Escribe para alguien de 6 a 8 años que está aprendiendo a leer: '
                        . 'frases muy cortas, una idea por frase, vocabulario cotidiano y ningún '
                        . 'tecnicismo. Apóyate en objetos y situaciones que conozca. No uses más '
                        . 'de tres frases seguidas sin cortar.',
                ],
                [
                    'hasta' => 4,
                    'texto' => 'Escribe para alguien de 8 a 10 años que ya lee para aprender: '
                        . 'frases cortas y directas. Puedes usar un término propio de la materia '
                        . 'si lo explicas con palabras suyas la primera vez. Las instrucciones, '
                        . 'en pasos de dos o tres.',
                ],
                [
                    'hasta' => 6,
                    'texto' => 'Escribe para alguien de 10 a 12 años que puede seguir una '
                        . 'explicación de varios pasos: vocabulario académico básico, y puedes '
                        . 'pedirle que justifique su razonamiento o que compare dos caminos.',
                ],
            ],

            'sin_grado' => 'No sabes en qué grado está: usa lenguaje sencillo de primaria, '
                . 'evita tecnicismos y no des por supuesta una lectura rápida.',
        ],

        /*
        |----------------------------------------------------------------------
        | Formato de la respuesta por estilo de aprendizaje (O2)
        |----------------------------------------------------------------------
        |
        | El estilo solo cambiaba el TONO («usa analogías sonoras»), y la
        | respuesta era el mismo bloque de texto para todos. Aquí cambia la
        | FORMA: cómo se organiza lo que se escribe.
        |
        | `auditivo` es el que más se separa, porque su respuesta está pensada
        | para que el frontend la lea en voz alta: la respuesta lleva
        | `presentation: "auditivo"` para que lo sepa. Por eso nada de tablas,
        | símbolos ni emojis, que un lector de voz no sabe pronunciar.
        |
        | No se pide markdown (negritas, almohadillas): no está garantizado que
        | el cliente lo renderice, y en crudo ensucia el texto de un niño.
        |
        | Sin estilo en el perfil no se añade nada: el tutor responde como antes.
        */
        /*
         | Estilos a los que el tutor les entrega el vídeo de apoyo del examen
         | (`exams.video_url`, opcional, lo pone el docente). Viaja aparte del
         | texto, en el campo `video`, en el chat sobre ese examen y en sus
         | recomendaciones.
         */
        'video_para_estilos' => ['visual', 'auditivo'],

        'formato' => [
            'visual' => 'Organiza la respuesta para que se entienda de un vistazo: pasos '
                . 'numerados y cortos, una idea por línea, y si ayuda, un esquema sencillo '
                . 'hecho con texto (por ejemplo con flechas →). Puedes usar uno o dos emojis '
                . 'como marcadores, no más. Usa comparaciones con cosas que se pueden ver.',
            'auditivo' => 'Tu respuesta se va a leer en voz alta, así que escribe como si '
                . 'hablaras: frases cortas y completas, sin tablas, sin viñetas, sin emojis, '
                . 'sin símbolos ni abreviaturas. Si hay pasos, nómbralos con palabras '
                . '(«primero», «después», «por último»). Termina repitiendo la idea clave '
                . 'en una sola frase.',
            'lector' => 'Estructura la respuesta como un texto para leer con calma: empieza '
                . 'con la idea principal en una frase, sigue con los detalles en una lista '
                . 'ordenada y, si aparece una palabra nueva, da su definición. Termina con '
                . 'una frase de resumen.',
        ],
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
