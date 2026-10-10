<?php

namespace App\Support;

use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\StudentAnswer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;

/**
 * Las respuestas de un intento con TODO lo que pide su pantalla de revisión —la pregunta, las
 * opciones de la pregunta y las opciones que marcó el alumno— en UNA consulta.
 *
 * Con `load(['answers.question.options', 'answers.selectedOptions'])` son cuatro viajes a la
 * base (~0,4 s cada uno con la base remota). Aquí la pregunta va unida (`RelacionesEnLinea`)
 * y las dos listas salen como `json_agg` de subselects; después se reconstruyen las relaciones
 * `question`, `question.options` y `selectedOptions` (con su `pivot`) con la misma forma que
 * Eloquent, así que el JSON no cambia.
 */
final class RespuestasEnLinea
{
    /** Campos de una opción dentro del `json_build_object`; `%1$s` es el alias de la tabla. */
    private const OPCION = "'id', %1\$s.id, 'institution_id', %1\$s.institution_id, 'question_id', %1\$s.question_id, "
        . "'option_index', %1\$s.option_index, 'option_text', %1\$s.option_text, 'is_correct', %1\$s.is_correct";

    /** @return Collection<int,StudentAnswer> */
    public static function delIntento(string $intentoId): Collection
    {
        $opcion = sprintf(self::OPCION, 'o');
        $marcada = sprintf(self::OPCION, 'm');

        $respuestas = RelacionesEnLinea::unir(
            StudentAnswer::query()->where('student_answers.attempt_id', $intentoId),
            ['question' => ['questions', 'question_id', Question::COLUMNAS]]
        )->selectRaw(
            "COALESCE((SELECT json_agg(json_build_object({$opcion}) ORDER BY o.id)
                FROM question_options o
                WHERE o.question_id = student_answers.question_id AND o.institution_id = student_answers.institution_id), '[]'::json) AS opciones_json,
            COALESCE((SELECT json_agg(json_build_object({$marcada},
                    'pivot_student_answer_id', sao.student_answer_id, 'pivot_option_id', sao.option_id,
                    'pivot_institution_id', sao.institution_id) ORDER BY m.id)
                FROM student_answer_options sao
                JOIN question_options m ON m.id = sao.option_id
                WHERE sao.student_answer_id = student_answers.id AND sao.institution_id = student_answers.institution_id), '[]'::json) AS marcadas_json"
        )->get();

        // Las listas JSON se leen y se quitan de los atributos ANTES de hidratar la pregunta.
        $listas = [];
        foreach ($respuestas as $respuesta) {
            $atributos = $respuesta->getAttributes();
            $listas[$respuesta->getKey()] = [
                json_decode((string) ($atributos['opciones_json'] ?? '[]'), true) ?: [],
                json_decode((string) ($atributos['marcadas_json'] ?? '[]'), true) ?: [],
            ];
            $respuesta->setRawAttributes(Arr::except($atributos, ['opciones_json', 'marcadas_json']), true);
        }

        RelacionesEnLinea::hidratar($respuestas, ['question' => Question::class]);

        foreach ($respuestas as $respuesta) {
            [$opciones, $marcadas] = $listas[$respuesta->getKey()];

            $respuesta->question?->setRelation('options', new Collection(array_map(
                fn (array $fila) => (new QuestionOption())->newFromBuilder($fila),
                $opciones
            )));

            $respuesta->setRelation('selectedOptions', new Collection(array_map(function (array $fila) use ($respuesta) {
                $pivot = [
                    'student_answer_id' => $fila['pivot_student_answer_id'],
                    'option_id'         => $fila['pivot_option_id'],
                    'institution_id'    => $fila['pivot_institution_id'],
                ];
                $opcion = (new QuestionOption())->newFromBuilder(Arr::except($fila, array_keys(Arr::prependKeysWith($pivot, 'pivot_'))));
                $opcion->setRelation('pivot', Pivot::fromRawAttributes($respuesta, $pivot, 'student_answer_options', true));

                return $opcion;
            }, $marcadas)));
        }

        return $respuestas;
    }
}
