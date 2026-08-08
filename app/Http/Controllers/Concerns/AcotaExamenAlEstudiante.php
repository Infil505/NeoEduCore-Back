<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserType;
use Illuminate\Support\Collection;

/**
 * Recorta del examen lo que un estudiante no debe ver.
 *
 * Complementa a `RevelaRespuestas`, que gobierna las respuestas correctas. Aquí
 * se cubren dos cosas distintas que viajaban en el mismo JSON:
 *
 * 1. **Datos del docente.** Las rutas de lectura compartida hacen eager load de
 *    la relación `teacher`, y el modelo `User` serializaba también `email`,
 *    `status` y `user_type`. Es PII del personal llegando al alumnado de
 *    primaria. Al estudiante se le deja lo único que necesita para saber de
 *    quién es el examen: id y nombre.
 *
 * 2. **Configuración del examen.** Cuántos intentos quedan, si las preguntas se
 *    aleatorizan o si se permite revisar tras entregar son parámetros de
 *    evaluación, no información para quien la presenta.
 *
 * Admin y docente no se ven afectados.
 */
trait AcotaExamenAlEstudiante
{
    /** Parámetros de evaluación que el estudiante no necesita conocer. */
    private const CONFIGURACION_DEL_EXAMEN = [
        'max_attempts',
        'randomize_questions',
        'allow_review_after_submission',
        'show_results_immediately',
    ];

    /** Lo único que se expone del docente a un estudiante. */
    private const DOCENTE_VISIBLE_AL_ESTUDIANTE = ['id', 'full_name'];

    /**
     * @param  iterable<int,\App\Models\Exams\Exam>  $exams
     */
    protected function acotarExamenes(?object $user, iterable $exams): void
    {
        if (!$user || $user->user_type !== UserType::Student) {
            return;
        }

        foreach ($exams as $exam) {
            $exam->makeHidden(self::CONFIGURACION_DEL_EXAMEN);

            // `relationLoaded` evita disparar una consulta por examen cuando el
            // docente no venía cargado.
            if ($exam->relationLoaded('teacher') && $exam->teacher !== null) {
                // setVisible y no makeHidden: así una columna nueva en `users`
                // no se filtra sola al añadirla.
                $exam->teacher->setVisible(self::DOCENTE_VISIBLE_AL_ESTUDIANTE);
            }
        }
    }

    /** Azúcar para un solo examen. */
    protected function acotarExamen(?object $user, object $exam): void
    {
        $this->acotarExamenes($user, Collection::wrap([$exam]));
    }
}
