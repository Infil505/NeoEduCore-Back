<?php

namespace App\Enums;

/**
 * En qué punto del tutor ocurrió la incidencia.
 *
 * Separa el chat del diagnóstico porque son dos superficies distintas: el chat
 * es texto libre del alumno y el diagnóstico lo arma el sistema. Si las
 * incidencias se concentran en uno de los dos, el arreglo es distinto.
 */
enum AiIncidentStage: string
{
    case Chat      = 'chat';
    case Diagnosis = 'diagnosis';
}
