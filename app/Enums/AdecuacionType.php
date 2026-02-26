<?php

namespace App\Enums;

enum AdecuacionType: string
{
    // Tipos comunes de adecuaciones en Costa Rica:
    // - acceso: adecuaciones para permitir el acceso al currículo
    // - contenido: adecuaciones al contenido o carga curricular
    // - evaluacion: adecuaciones en la forma de evaluación
    case Acceso     = 'acceso';
    case Contenido  = 'contenido';
    case Evaluacion = 'evaluacion';
}
