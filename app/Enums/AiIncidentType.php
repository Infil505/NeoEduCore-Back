<?php

namespace App\Enums;

/**
 * Por qué se bloqueó o se degradó una intervención del tutor IA.
 *
 * [173] compromete al sistema a «registrar incidencias» y fija un criterio de
 * calidad: más del 75 % de los mensajes deben superar la validación. Mientras
 * esto solo dejaba un `Log::warning`, el criterio no era medible — que es tanto
 * como no tenerlo.
 */
enum AiIncidentType: string
{
    /** La respuesta traía algo que parecía un contacto: correo, teléfono, documento. */
    case Pii = 'pii';

    /** Respuesta vacía o demasiado corta: casi siempre un fallo del modelo. */
    case TooShort = 'too_short';

    /** Respuesta desbocada, por encima del máximo configurado. */
    case TooLong = 'too_long';

    /** Llevaba un enlace fuera de la lista blanca; se sustituyó por el aviso. */
    case BlockedUrl = 'blocked_url';

    /** OpenAI no respondió. No es un fallo de validación, pero sí una incidencia. */
    case ModelError = 'model_error';

    /**
     * El mensaje del alumno intentaba reescribir las instrucciones del tutor.
     *
     * Es la única que se decide **antes** de llamar al modelo: el turno se
     * bloquea en la entrada, así que no hay respuesta que validar ni petición
     * que pagar. Ver `AiInputSanitizer`.
     */
    case PromptInjection = 'prompt_injection';

    /**
     * Las que cuentan para el criterio del 75 %: son fallos de **validación**.
     * Un error de red de OpenAI no dice nada sobre la calidad del contenido, y
     * meterlo en el porcentaje mezclaría disponibilidad con seguridad.
     *
     * `BlockedUrl` tampoco entra: ahí la respuesta **sí se entrega**, con el
     * enlace sustituido. Se registra porque interesa saber cuántas veces pasa.
     *
     * `PromptInjection` tampoco: mide lo que intenta **el alumno**, no lo que
     * acierta el modelo. Sumarla hundiría el porcentaje de calidad del tutor
     * cada vez que alguien probara a saltárselo, que es justo cuando el tutor
     * está funcionando bien.
     *
     * @return array<int,string>
     */
    public static function deValidacion(): array
    {
        return [self::Pii->value, self::TooShort->value, self::TooLong->value];
    }
}
