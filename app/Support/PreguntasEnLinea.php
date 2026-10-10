<?php

namespace App\Support;

use App\Models\Exams\QuestionOption;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Trae las preguntas CON sus opciones en UNA consulta (subselect `json_agg`) en
 * lugar de dos (`with('options')`).
 *
 * Con la base remota cada viaje cuesta ~0,4 s, y casi toda pantalla de examen pide
 * preguntas y opciones juntas. Cada pregunta sale con su relación `options` ya
 * cargada, igual que con `with()`, así que el JSON y `RevelaRespuestas` (que
 * decide qué se oculta al alumno por los `$hidden` de los modelos) no cambian.
 *
 * Uso: `PreguntasEnLinea::obtener($exam->questions()->orderBy('order_index'))`.
 */
final class PreguntasEnLinea
{
    /**
     * @param  Builder|Relation  $preguntas  consulta sobre `questions` (ya acotada y ordenada)
     * @return Collection<int,\App\Models\Exams\Question>
     */
    public static function obtener(Builder|Relation $preguntas): Collection
    {
        $preguntas->addSelect('questions.*')->selectRaw(
            "COALESCE((SELECT json_agg(json_build_object(
                    'id', o.id, 'institution_id', o.institution_id, 'question_id', o.question_id,
                    'option_index', o.option_index, 'option_text', o.option_text, 'is_correct', o.is_correct
                ) ORDER BY o.id)
                FROM question_options o
                WHERE o.question_id = questions.id AND o.institution_id = questions.institution_id), '[]'::json) AS opciones_json"
        );

        $filas = $preguntas->get();

        foreach ($filas as $pregunta) {
            $atributos = $pregunta->getAttributes();
            $opciones = json_decode((string) ($atributos['opciones_json'] ?? '[]'), true) ?: [];

            $pregunta->setRawAttributes(Arr::except($atributos, 'opciones_json'), true);
            $pregunta->setRelation('options', new Collection(array_map(
                fn (array $fila) => (new QuestionOption())->newFromBuilder($fila),
                $opciones
            )));
        }

        return $filas;
    }
}
