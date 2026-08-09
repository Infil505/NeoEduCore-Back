<?php

/*
|--------------------------------------------------------------------------
| Límites de peticiones
|--------------------------------------------------------------------------
|
| Todos en peticiones por minuto. Los consume `AppServiceProvider::registrarLimitadores()`.
|
| Viven aquí y no incrustados en el código para poder ajustarlos por entorno sin
| desplegar: en el piloto puede hacer falta aflojarlos, y la suite de tests los
| sube para no tropezar consigo misma.
|
| ⚠️ Nada de esto funciona si `CACHE_STORE=array`: los contadores viven en la
| caché y, con Octane, cada worker llevaría el suyo — con ~40 workers un límite
| de 5/min se convierte de hecho en 200/min. En producción debe ser `database`,
| `file` o `redis`. `AppServiceProvider` avisa en el log si detecta `array`
| fuera de tests.
|
*/

return [

    /*
     | Red de seguridad para TODA la API. Generoso a propósito: no está para
     | frenar un ataque —de eso se encargan los límites específicos— sino para
     | que un cliente descontrolado o un bucle en el frontend no agote los
     | workers de Octane, que son ~40 por el presupuesto de conexiones.
     |
     | Se cuenta por usuario autenticado; si no lo hay, por IP.
     */
    'api' => (int) env('RATE_LIMIT_API_PER_MINUTE', 120),

    /*
     | Intentos de acceso, contados por **correo + IP**.
     |
     | La clave compuesta es deliberada. Contar solo por correo permitiría a un
     | atacante DEJAR FUERA a un alumno agotándole el cupo justo antes de un
     | examen; contar solo por IP castigaría a un aula entera saliendo por el
     | mismo NAT, que es exactamente el pico de este sistema (todos entran a la
     | vez al empezar la prueba).
     */
    'login' => (int) env('RATE_LIMIT_LOGIN_PER_MINUTE', 5),

    /*
     | Tope por IP para el acceso, por encima del anterior: acota a quien pruebe
     | muchas cuentas distintas desde un mismo sitio. Holgado para no romper el
     | aula tras un NAT.
     */
    'login_ip' => (int) env('RATE_LIMIT_LOGIN_IP_PER_MINUTE', 60),

    /*
     | Presupuesto GLOBAL de IA por institución.
     |
     | Los throttle de IA por usuario no acotan el total: cada llamada a OpenAI
     | bloquea un worker de Octane 1-15 s, así que basta una decena de chats
     | simultáneos sostenidos para que no quede ninguno libre para entregar
     | exámenes. Este límite es lo que protege el flujo de examen.
     */
    'ai_global' => (int) env('RATE_LIMIT_AI_GLOBAL_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Límites que estaban literales en las rutas
    |--------------------------------------------------------------------------
    |
    | Hasta el 08/08/2026 estos doce `throttle:N,1` vivían escritos a mano en
    | `routes/api.php`, mientras los cuatro de arriba ya eran configurables. La
    | inconsistencia importaba: el fichero decía «viven aquí para poder
    | ajustarlos por entorno sin desplegar» y la mayoría no cumplía esa promesa.
    |
    */

    /*
     | Correo de recuperación y cambio de contraseña. Bajo a propósito: cada
     | petición encola un correo y consulta la base, y no hay ningún uso
     | legítimo que necesite repetirlo.
     */
    'password' => (int) env('RATE_LIMIT_PASSWORD_PER_MINUTE', 5),

    /*
     | Verificación de token. Más holgado que el anterior porque no envía nada
     | ni escribe: el frontend lo llama al abrir el formulario.
     */
    'password_verify' => (int) env('RATE_LIMIT_PASSWORD_VERIFY_PER_MINUTE', 10),

    /*
     | Carga masiva de estudiantes. El más restrictivo del sistema: cada
     | petición puede procesar 5.000 filas dentro de una transacción.
     */
    'bulk_upload' => (int) env('RATE_LIMIT_BULK_UPLOAD_PER_MINUTE', 3),

    /*
     | Reasignaciones masivas y reseteo de progreso. Tocan cientos de filas y
     | contadores denormalizados de una vez.
     */
    'bulk_ops' => (int) env('RATE_LIMIT_BULK_OPS_PER_MINUTE', 10),

    /*
     | Chat con el tutor, POR USUARIO. Se suma al presupuesto de institución
     | (`ai_global`), que es el que de verdad protege el flujo de examen.
     */
    'ai_chat' => (int) env('RATE_LIMIT_AI_CHAT_PER_MINUTE', 30),

    /*
     | Diagnóstico del tutor: una sola llamada devuelve un análisis completo,
     | así que cuesta bastante más que un turno de chat.
     */
    'ai_diagnosis' => (int) env('RATE_LIMIT_AI_DIAGNOSIS_PER_MINUTE', 10),

    /*
     | Regeneración de recomendaciones de un intento. Es la única vía por la que
     | el alumnado dispara OpenAI a voluntad; el cupo por intento lo limita
     | aparte `AiRecommendationService`.
     */
    'ai_regenerate' => (int) env('RATE_LIMIT_AI_REGENERATE_PER_MINUTE', 5),

    /*
     | Generación manual de recomendaciones por parte del docente
     | (`POST /ai/generate`). También llama a OpenAI, así que entra además en el
     | presupuesto global de institución.
     */
    'ai_generate' => (int) env('RATE_LIMIT_AI_GENERATE_PER_MINUTE', 20),

];
