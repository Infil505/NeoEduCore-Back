<?php

namespace App\Enums;

/**
 * Nivel de dificultad, compartido por los ítems de examen y los recursos.
 *
 * Son los mismos tres valores a propósito: el recurso que se le sugiere a un
 * alumno se elige comparando su nivel con el del ítem que falló, y dos escalas
 * distintas obligarían a traducir entre ellas en cada consulta.
 *
 * En la base son `varchar` con CHECK —no enum nativo de PostgreSQL— porque así
 * nació `study_resources.difficulty` y cambiarlo ahora no aporta nada.
 */
enum Difficulty: string
{
    case Basic        = 'basic';
    case Intermediate = 'intermediate';
    case Advanced     = 'advanced';

    /** @return array<int,string> */
    public static function values(): array
    {
        return array_map(fn (self $d) => $d->value, self::cases());
    }
}
