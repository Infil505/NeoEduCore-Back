<?php

namespace App\Enums;

/**
 * Estado del análisis de IA de un intento.
 *
 * `null` (ausencia de estado) significa que nunca se encoló nada para ese
 * intento: es lo que tienen los intentos entregados que el alumno todavía no ha
 * consultado, y es justo el valor que dispara el encolado cuando los abre.
 */
enum AiRecommendationsStatus: string
{
    case Preparing = 'preparing';
    case Ready     = 'ready';
    case Failed    = 'failed';
}
