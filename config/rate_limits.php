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

];
