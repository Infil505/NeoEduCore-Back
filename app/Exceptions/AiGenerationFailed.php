<?php

namespace App\Exceptions;

/**
 * El modelo no pudo redactar las recomendaciones de un intento.
 *
 * Existe para que el job de cola distinga «OpenAI falló» de «ya está» sin tener
 * que inspeccionar lo que devuelve el servicio. En la ruta manual —el alumno
 * pulsando «regenerar»— no se lanza: allí interesa devolver las plantillas antes
 * que un error, porque el alumno está esperando delante de la pantalla. En la
 * cola sí, porque escribir plantillas otra vez duplicaría el lote que ya existe.
 */
class AiGenerationFailed extends \RuntimeException
{
}
