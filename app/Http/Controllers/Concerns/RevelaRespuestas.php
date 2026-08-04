<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserType;
use Illuminate\Support\Collection;

/**
 * Revela los campos que delatan la respuesta correcta.
 *
 * `Question::correct_answer_text` y `QuestionOption::is_correct` están ocultos
 * por defecto en los modelos (ver sus `$hidden`). Este trait los vuelve a
 * exponer **solo** a quien puede verlos: administradores y docentes, que son
 * quienes redactan y corrigen los exámenes.
 *
 * Un estudiante no debe conocer la respuesta antes de entregar bajo ninguna
 * circunstancia — tampoco entre intentos de un examen con `max_attempts > 1`.
 */
trait RevelaRespuestas
{
    protected function puedeVerRespuestas(?object $user): bool
    {
        return $user
            && in_array($user->user_type, [UserType::Admin, UserType::Teacher], true);
    }

    /**
     * Expone los campos sensibles de un conjunto de preguntas si el usuario
     * tiene derecho a verlos. Si no, las deja tal cual (ocultos).
     *
     * @param  iterable<int,\App\Models\Exams\Question>  $questions
     */
    protected function revelarRespuestas(?object $user, iterable $questions): void
    {
        if (!$this->puedeVerRespuestas($user)) {
            return;
        }

        foreach ($questions as $question) {
            $question->makeVisible('correct_answer_text');

            // `relationLoaded` evita disparar una consulta por pregunta cuando
            // las opciones no venían cargadas.
            if ($question->relationLoaded('options')) {
                foreach ($question->options as $option) {
                    $option->makeVisible('is_correct');
                }
            }
        }
    }

    /** Azúcar para una sola pregunta. */
    protected function revelarRespuestasDe(?object $user, object $question): void
    {
        $this->revelarRespuestas($user, Collection::wrap([$question]));
    }
}
