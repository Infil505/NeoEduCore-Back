<?php

namespace App\Enums;

/**
 * De dónde salió el texto de una recomendación.
 *
 * `heuristic` son las plantillas que el sistema escribe al instante al entregar
 * el examen, elegidas por tramo de porcentaje; `ai` son las que redactó el
 * modelo analizando las respuestas falladas. El alumno ve las dos cosas en el
 * mismo sitio, así que sin este campo ni la API ni el frontend pueden decir cuál
 * está mirando — que es lo que [397] pide avisar.
 */
enum AiGenerationSource: string
{
    case Heuristic = 'heuristic';
    case Ai        = 'ai';
}
