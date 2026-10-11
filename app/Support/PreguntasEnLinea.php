<?php

namespace App\Support;

use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preguntas CON sus opciones en UNA consulta (subselect `json_agg`) en lugar de dos
 * (`with('options')`), y alta de pregunta + opciones en UNA sentencia.
 *
 * Con la base remota cada viaje cuesta ~0,4 s, y casi toda pantalla de examen pide preguntas y
 * opciones juntas. Cada pregunta sale con su relación `options` ya cargada, igual que con `with()`,
 * así que el JSON y `RevelaRespuestas` (que decide qué se oculta al alumno por los `$hidden` de los
 * modelos) no cambian.
 *
 * Uso: `PreguntasEnLinea::obtener($exam->questions()->orderBy('order_index'))`.
 */
final class PreguntasEnLinea
{
    private const COLUMNA = 'opciones_json';

    /**
     * Añade a la consulta de `questions` el subselect con las opciones (columna `opciones_json`).
     * Después, `hidratar()` las convierte en la relación `options`.
     */
    public static function seleccionarOpciones(Builder|Relation $preguntas): void
    {
        $preguntas->addSelect('questions.*')->selectRaw(
            "COALESCE((SELECT json_agg(json_build_object(
                    'id', o.id, 'institution_id', o.institution_id, 'question_id', o.question_id,
                    'option_index', o.option_index, 'option_text', o.option_text, 'is_correct', o.is_correct
                ) ORDER BY o.id)
                FROM question_options o
                WHERE o.question_id = questions.id AND o.institution_id = questions.institution_id), '[]'::json) AS " . self::COLUMNA
        );
    }

    /** Convierte `opciones_json` de cada pregunta en la relación `options` (y lo quita de los atributos). */
    public static function hidratar(iterable $preguntas): void
    {
        foreach ($preguntas as $pregunta) {
            $atributos = $pregunta->getAttributes();
            $opciones = json_decode((string) ($atributos[self::COLUMNA] ?? '[]'), true) ?: [];

            $pregunta->setRawAttributes(Arr::except($atributos, self::COLUMNA), true);
            $pregunta->setRelation('options', self::opciones($opciones));
        }
    }

    /**
     * @param  Builder|Relation  $preguntas  consulta sobre `questions` (ya acotada y ordenada)
     * @return Collection<int,Question>
     */
    public static function obtener(Builder|Relation $preguntas): Collection
    {
        self::seleccionarOpciones($preguntas);

        $filas = $preguntas->get();
        self::hidratar($filas);

        return $filas;
    }

    /**
     * Crea la pregunta y todas sus opciones en UNA sentencia (CTE con INSERT): antes eran el cálculo
     * del orden, el INSERT de la pregunta, uno por opción y la lectura de las opciones, dentro de
     * una transacción (con su BEGIN y COMMIT). Una sentencia ya es atómica.
     *
     * Al no pasar por Eloquent no saltan sus observadores: se invalida la caché de reportes a mano.
     *
     * @param  array<string,mixed>  $campos    question_text, question_type, points, correct_answer_text,
     *                                          order_index (null = al final), topic, indicator, difficulty
     * @param  array<int,array{option_index:int,option_text:string,is_correct:bool}>  $opciones
     */
    public static function crear(string $institucion, string $examenId, array $campos, array $opciones): Question
    {
        $id = (string) Str::orderedUuid();

        $sql = 'WITH q AS (
                    INSERT INTO questions (id, institution_id, exam_id, question_text, question_type, points, correct_answer_text,
                                           order_index, topic, indicator, difficulty, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?,
                            COALESCE(?::int, (SELECT COALESCE(MAX(order_index), 0) + 1 FROM questions WHERE exam_id = ? AND institution_id = ?)),
                            ?, ?, ?, now(), now())
                    RETURNING *
                )';
        $bindings = [
            $id, $institucion, $examenId, $campos['question_text'], $campos['question_type'], $campos['points'],
            $campos['correct_answer_text'] ?? null, $campos['order_index'] ?? null, $examenId, $institucion,
            $campos['topic'] ?? null, $campos['indicator'] ?? null, $campos['difficulty'] ?? null,
        ];

        if ($opciones !== []) {
            $sql .= ', o AS (
                    INSERT INTO question_options (institution_id, question_id, option_index, option_text, is_correct)
                    SELECT ?, q.id, v.option_index, v.option_text, v.is_correct
                      FROM q, (VALUES ' . implode(', ', array_fill(0, count($opciones), '(?::int, ?::text, ?::boolean)')) . ') AS v(option_index, option_text, is_correct)
                    RETURNING *
                )';
            $bindings[] = $institucion;
            foreach ($opciones as $opcion) {
                array_push($bindings, $opcion['option_index'], $opcion['option_text'], $opcion['is_correct'] ? 'true' : 'false');
            }
        }

        $sql .= ' SELECT (SELECT row_to_json(q) FROM q) AS pregunta, '
            . ($opciones !== [] ? "COALESCE((SELECT json_agg(o ORDER BY o.id) FROM o), '[]'::json)" : "'[]'::json") . ' AS opciones';

        $fila = DB::selectOne($sql, $bindings);

        $pregunta = (new Question())->newFromBuilder(json_decode($fila->pregunta, true));
        $pregunta->setRelation('options', self::opciones(json_decode($fila->opciones, true) ?: []));

        TenantCache::invalidar($institucion, TenantCache::REPORTES);

        return $pregunta;
    }

    /**
     * Sustituye todas las opciones de una pregunta (borra y vuelve a insertar, en UNA transacción
     * del llamador) y devuelve las nuevas con su id.
     *
     * @param  array<int,array{option_index:int,option_text:string,is_correct:bool}>  $opciones
     * @return Collection<int,QuestionOption>
     */
    public static function reemplazarOpciones(string $institucion, string $preguntaId, array $opciones): Collection
    {
        DB::delete('DELETE FROM question_options WHERE question_id = ? AND institution_id = ?', [$preguntaId, $institucion]);

        if ($opciones === []) {
            return new Collection();
        }

        $filas = DB::select(
            'INSERT INTO question_options (institution_id, question_id, option_index, option_text, is_correct)
             VALUES ' . implode(', ', array_fill(0, count($opciones), '(?, ?, ?::int, ?::text, ?::boolean)')) . ' RETURNING *',
            collect($opciones)->flatMap(fn ($o) => [$institucion, $preguntaId, $o['option_index'], $o['option_text'], $o['is_correct'] ? 'true' : 'false'])->all()
        );

        return self::opciones(array_map(fn ($f) => (array) $f, $filas));
    }

    /** @param  array<int,array<string,mixed>>  $filas */
    private static function opciones(array $filas): Collection
    {
        return new Collection(array_map(fn (array $fila) => (new QuestionOption())->newFromBuilder($fila), $filas));
    }
}
