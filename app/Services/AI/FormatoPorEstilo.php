<?php

namespace App\Services\AI;

use App\Enums\LearningStyle;

/**
 * Cómo debe organizar el tutor su respuesta según el estilo de aprendizaje (O2).
 *
 * Antes el estilo solo cambiaba el tono del prompt y la respuesta era siempre
 * el mismo bloque de texto. Ahora decide la forma —pasos y esquemas para
 * `visual`, texto para leer en voz alta para `auditivo`, estructura con
 * definiciones para `lector`— y viaja además en la respuesta como
 * `presentation`, para que el frontend pueda, por ejemplo, leerla en voz alta.
 *
 * Igual que `RegistroPorGrado`: lo usan el chat y el diagnóstico, y los textos
 * están en `config/openai.php` porque los afina el profesorado.
 */
class FormatoPorEstilo
{
    /** Instrucción de formato lista para concatenar al prompt, o null sin estilo. */
    public function para(?LearningStyle $estilo): ?string
    {
        if ($estilo === null) {
            return null;
        }

        $texto = config("openai.tutor.formato.{$estilo->value}");

        return is_string($texto) && $texto !== '' ? $texto : null;
    }

    /** Lo que se devuelve al frontend en `presentation`. */
    public function presentacion(?LearningStyle $estilo): ?string
    {
        return $estilo?->value;
    }
}
