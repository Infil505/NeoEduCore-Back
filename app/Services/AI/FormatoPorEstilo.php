<?php

namespace App\Services\AI;

use App\Enums\LearningStyle;
use App\Models\Exams\Exam;

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

    /**
     * El campo `video` de la respuesta: el vídeo que el docente puso en el
     * examen, si el estilo del alumno es de los que lo reciben
     * (`openai.tutor.video_para_estilos`). Null si no toca o no hay vídeo.
     *
     * El enlace lo eligió el docente, nunca el modelo: con menores de 6 a 12
     * años no se entrega contenido que nadie haya revisado.
     */
    public function videoPara(?LearningStyle $estilo, ?Exam $exam): ?array
    {
        if (!$this->recibeVideo($estilo) || $exam === null || !$exam->video_url) {
            return null;
        }

        return [
            'url'        => $exam->video_url,
            'exam_id'    => $exam->id,
            'exam_title' => $exam->title,
        ];
    }

    /** Para no buscar el examen cuando el estilo no recibe vídeo. */
    public function recibeVideo(?LearningStyle $estilo): bool
    {
        return $estilo !== null
            && in_array($estilo->value, (array) config('openai.tutor.video_para_estilos', []), true);
    }
}
