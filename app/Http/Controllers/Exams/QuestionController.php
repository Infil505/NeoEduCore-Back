<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RevelaRespuestas;
use App\Enums\Difficulty;
use App\Enums\QuestionType;
use App\Models\Exams\Exam;
use App\Support\PreguntasEnLinea;
use App\Support\RelacionesEnLinea;
use Illuminate\Support\Arr;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    /**
     * Opciones de una pregunta de selección múltiple. Antes eran exactamente 4;
     * el docente elige cuántas (06/10/2026). Siempre con una sola correcta.
     */
    private const MC_MIN_OPTIONS = 2;
    private const MC_MAX_OPTIONS = 8;

    use RevelaRespuestas;

    /**
     * Listar preguntas por examen
     */
    public function index(Request $request, string $exam)
    {
        // Misma comprobación que `ExamController::show()`: si no, esta ruta era
        // la vía directa a los enunciados de un examen ajeno o en borrador. Una sola
        // consulta hace de binding y de visibilidad.
        $examen = Exam::query()->visibleTo($request->user())
            ->where('exams.id', $exam)
            ->first(['exams.id', 'exams.institution_id', 'exams.randomize_questions']);

        if ($examen === null) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $query = $examen->questions();

        if ($examen->randomize_questions) {
            $query->inRandomOrder();
        } else {
            $query->orderBy('order_index');
        }

        // Preguntas y opciones en la misma consulta (ver `PreguntasEnLinea`).
        $questions = PreguntasEnLinea::obtener($query->limit(200));

        // Al estudiante se le sirven las preguntas SIN `is_correct` ni
        // `correct_answer_text` (ocultos por defecto en los modelos).
        $this->revelarRespuestas($request->user(), $questions);

        return response()->json([
            'data' => $questions,
        ]);
    }

    /**
     * Crear pregunta + opciones
     */
    public function store(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'question_text' => ['required', 'string', 'min:3', 'max:2000'],
            'question_type' => ['required', Rule::in([
                QuestionType::MultipleChoice->value,
                QuestionType::TrueFalse->value,
                QuestionType::ShortAnswer->value,
                QuestionType::Essay->value,
            ])],
            'points' => ['required', 'integer', 'between:1,10'],
            'order_index' => ['nullable', 'integer', 'min:1'],

            // Metadatos curriculares (D2). Opcionales: obligarlos rompería todo
            // examen ya creado y toda carga existente. El banco de ítems (E2) sí
            // los exige, pero eso es criterio editorial, no del endpoint.
            'topic' => ['nullable', 'string', 'max:120'],
            'indicator' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['nullable', Rule::in(Difficulty::values())],

            // Para short_answer
            'correct_answer_text' => ['nullable', 'string', 'max:2000'],

            // Para opciones (MC/TF)
            'options' => ['nullable', 'array'],
            'options.*.option_index' => ['nullable', 'integer'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:500'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
        ]);

        $type = $data['question_type'];

        // Validaciones RN según tipo
        if ($type === QuestionType::ShortAnswer->value) {
            if (empty($data['correct_answer_text'])) {
                return response()->json([
                    'message' => 'correct_answer_text es obligatorio para short_answer',
                ], 422);
            }
            // No debe traer options
            if (!empty($data['options'])) {
                return response()->json([
                    'message' => 'short_answer no debe incluir opciones',
                ], 422);
            }
        }

        if ($type === QuestionType::MultipleChoice->value) {
            $n = count($data['options'] ?? []);
            if ($n < self::MC_MIN_OPTIONS || $n > self::MC_MAX_OPTIONS) {
                return response()->json([
                    'message' => 'La pregunta de selección múltiple debe tener entre ' . self::MC_MIN_OPTIONS . ' y ' . self::MC_MAX_OPTIONS . ' opciones.',
                ], 422);
            }
        }

        if ($type === QuestionType::TrueFalse->value) {
            if (empty($data['options']) || count($data['options']) !== 2) {
                return response()->json([
                    'message' => 'true_false debe tener exactamente 2 opciones',
                ], 422);
            }
        }

        if ($type === QuestionType::Essay->value) {
            if (!empty($data['options'])) {
                return response()->json([
                    'message' => 'essay no debe incluir opciones',
                ], 422);
            }
        }

        // RN: solo una correcta (MC/TF)
        if (!empty($data['options'])) {
            $correctCount = collect($data['options'])->where('is_correct', true)->count();
            if ($correctCount !== 1) {
                return response()->json([
                    'message' => 'Debe existir exactamente 1 opción correcta',
                ], 422);
            }
        }

        // Pregunta y opciones en UNA sentencia atómica (ver `PreguntasEnLinea::crear`): antes eran
        // siete viajes a la base y una transacción. Las opciones, con `option_index` por posición
        // si no lo traen.
        $opciones = [];
        foreach (array_values($data['options'] ?? []) as $idx => $opt) {
            $opciones[] = [
                'option_index' => isset($opt['option_index']) ? (int) $opt['option_index'] : $idx,
                'option_text'  => $opt['option_text'],
                'is_correct'   => (bool) $opt['is_correct'],
            ];
        }

        $question = PreguntasEnLinea::crear($exam->institution_id, $exam->id, [
            'question_text' => $data['question_text'],
            'question_type' => $type,
            'points' => (int) $data['points'],
            'correct_answer_text' => $type === QuestionType::ShortAnswer->value ? $data['correct_answer_text'] : null,
            'order_index' => isset($data['order_index']) ? (int) $data['order_index'] : null,

            // D2: `topic_normalized` sale sola, la genera PostgreSQL.
            'topic' => $data['topic'] ?? null,
            'indicator' => $data['indicator'] ?? null,
            'difficulty' => $data['difficulty'] ?? null,
        ], $opciones);

        // Ruta admin/teacher: aquí sí procede devolver las respuestas.
        $this->revelarRespuestasDe($request->user(), $question);

        return response()->json([
            'data' => $question,
        ], 201);
    }

    /**
     * Actualizar pregunta + opciones
     */
    public function update(Request $request, string $question)
    {
        $user = $request->user();

        // La pregunta con su examen, sus opciones y si el examen ya tiene intentos, en UNA consulta
        // (antes: binding, examen, comprobación de intentos y opciones por separado).
        $question = $this->preguntaConExamen($question) ?? abort(404);

        if ($user->user_type->value === 'teacher') {
            if ($question->exam->created_by_teacher_id !== $user->id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        $data = $request->validate([
            'question_text' => ['sometimes', 'string', 'min:3', 'max:2000'],
            'points' => ['sometimes', 'integer', 'between:1,10'],
            'order_index' => ['sometimes', 'integer', 'min:1'],

            // Metadatos curriculares (D2)
            'topic' => ['nullable', 'string', 'max:120'],
            'indicator' => ['nullable', 'string', 'max:255'],
            'difficulty' => ['nullable', Rule::in(Difficulty::values())],

            'correct_answer_text' => ['nullable', 'string', 'max:2000'],

            'options' => ['nullable', 'array'],
            'options.*.option_index' => ['required_with:options', 'integer'],
            'options.*.option_text' => ['required_with:options', 'string', 'max:500'],
            'options.*.is_correct' => ['required_with:options', 'boolean'],
        ]);

        $type = $question->question_type->value;

        // Si es short_answer, correct_answer_text debe existir
        if ($type === QuestionType::ShortAnswer->value && array_key_exists('correct_answer_text', $data)) {
            if (empty($data['correct_answer_text'])) {
                return response()->json([
                    'message' => 'correct_answer_text es obligatorio para short_answer',
                ], 422);
            }
        }

        // Si actualizan opciones, validar reglas por tipo
        if (array_key_exists('options', $data)) {
            if ($type === QuestionType::ShortAnswer->value) {
                return response()->json([
                    'message' => 'short_answer no permite opciones',
                ], 422);
            }

            $n = count($data['options'] ?? []);
            $valido = $type === QuestionType::MultipleChoice->value
                ? $n >= self::MC_MIN_OPTIONS && $n <= self::MC_MAX_OPTIONS
                : $n === 2;

            if (!$valido) {
                return response()->json([
                    'message' => $type === QuestionType::MultipleChoice->value
                        ? 'La pregunta de selección múltiple debe tener entre ' . self::MC_MIN_OPTIONS . ' y ' . self::MC_MAX_OPTIONS . ' opciones.'
                        : 'La pregunta de verdadero o falso debe tener exactamente 2 opciones.',
                ], 422);
            }

            $correctCount = collect($data['options'])->where('is_correct', true)->count();
            if ($correctCount !== 1) {
                return response()->json([
                    'message' => 'Debe existir exactamente 1 opción correcta',
                ], 422);
            }
        }

        if ($question->tiene_intentos && $this->cambiaLoQuePuntua($question, $data)) {
            return response()->json([
                'message' => 'Este examen ya tiene intentos: no se pueden cambiar las opciones, la respuesta correcta ni los puntos de una pregunta. El enunciado sí se puede corregir.',
            ], 409);
        }

        $question->fill(Arr::except($data, 'options'));

        if (array_key_exists('options', $data)) {
            // Cambiar las opciones son varias sentencias: ahí sí hace falta la transacción.
            DB::transaction(function () use ($question, $data) {
                $question->save();
                $question->setRelation('options', PreguntasEnLinea::reemplazarOpciones(
                    $question->institution_id,
                    $question->id,
                    collect($data['options'])->map(fn ($o) => [
                        'option_index' => (int) $o['option_index'],
                        'option_text'  => $o['option_text'],
                        'is_correct'   => (bool) $o['is_correct'],
                    ])->all()
                ));
            });
        } else {
            // Una sola sentencia; las opciones ya vienen cargadas de la primera consulta.
            $question->save();
        }

        $this->revelarRespuestasDe($request->user(), $question);

        return response()->json([
            'data' => $question,
        ]);
    }

    /**
     * Eliminar pregunta (no permitir eliminar la última)
     */
    public function destroy(Request $request, string $question)
    {
        $user = $request->user();

        // La pregunta con su examen, si hay intentos y cuántas preguntas tiene el examen, en UNA
        // consulta (antes: binding, examen, intentos y recuento).
        $question = $this->preguntaConExamen($question) ?? abort(404);
        $exam = $question->exam;

        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($question->tiene_intentos) {
            // `student_answers.question_id` es ON DELETE CASCADE: borrarla se
            // llevaría las respuestas y las notas ya calculadas de los alumnos.
            return response()->json([
                'message' => 'Este examen ya tiene intentos: no se pueden eliminar sus preguntas.',
            ], 409);
        }

        if ($question->total_preguntas <= 1) {
            return response()->json([
                'message' => 'No se puede eliminar la última pregunta del examen',
            ], 409);
        }

        $question->delete(); // opciones eliminadas en cascada por DB

        return response()->noContent();
    }

    /**
     * La pregunta con su examen (`exam`), sus opciones (`options`) y dos datos del examen que piden
     * `update` y `destroy`: si ya tiene intentos (`tiene_intentos`: desde ahí sus notas dependen de
     * las preguntas) y cuántas preguntas tiene (`total_preguntas`). Todo en UNA consulta.
     */
    private function preguntaConExamen(string $id): ?Question
    {
        $consulta = RelacionesEnLinea::unir(Question::query(), [
            'exam' => ['exams', 'exam_id', Exam::COLUMNAS],
        ]);
        PreguntasEnLinea::seleccionarOpciones($consulta);
        $pregunta = $consulta
            ->selectRaw('EXISTS (SELECT 1 FROM exam_attempts ea WHERE ea.exam_id = questions.exam_id AND ea.institution_id = questions.institution_id) AS tiene_intentos')
            ->selectRaw('(SELECT COUNT(*) FROM questions q2 WHERE q2.exam_id = questions.exam_id AND q2.institution_id = questions.institution_id) AS total_preguntas')
            ->where('questions.id', $id)
            ->first();

        if ($pregunta === null) {
            return null;
        }

        $tieneIntentos = (bool) $pregunta->getAttribute('tiene_intentos');
        $total = (int) $pregunta->getAttribute('total_preguntas');
        $pregunta->setRawAttributes(Arr::except($pregunta->getAttributes(), ['tiene_intentos', 'total_preguntas']), true);
        RelacionesEnLinea::hidratar([$pregunta], ['exam' => Exam::class]);
        PreguntasEnLinea::hidratar([$pregunta]);

        // No son columnas: propiedades de la petición (no salen en el JSON ni se guardan).
        $pregunta->tiene_intentos = $tieneIntentos;
        $pregunta->total_preguntas = $total;

        return $pregunta;
    }

    /**
     * ¿La petición cambia algo de lo que puntúa (puntos, respuesta correcta,
     * opciones)? Se compara con lo guardado: un formulario que reenvía todos los
     * campos al corregir una errata no debe recibir un 409.
     */
    private function cambiaLoQuePuntua(Question $question, array $data): bool
    {
        if (array_key_exists('points', $data) && (int) $data['points'] !== (int) $question->points) {
            return true;
        }

        if (array_key_exists('correct_answer_text', $data)
            && trim((string) $data['correct_answer_text']) !== trim((string) $question->correct_answer_text)) {
            return true;
        }

        if (array_key_exists('options', $data)) {
            $forma = fn ($opciones) => collect($opciones)
                ->map(fn ($o) => [(int) $o['option_index'], (string) $o['option_text'], (bool) $o['is_correct']])
                ->sortBy(0)->values()->all();

            return $forma($data['options'] ?? []) !== $forma($question->options->map(fn ($o) => [
                'option_index' => $o->option_index, 'option_text' => $o->option_text, 'is_correct' => $o->is_correct,
            ])->all());
        }

        return false;
    }

    private function sanitizeForStudent(Question $question): array
    {
        return [
            'id' => $question->id,
            'exam_id' => $question->exam_id,
            'question_text' => $question->question_text,
            'question_type' => $question->question_type->value,
            'points' => $question->points,
            'order_index' => $question->order_index,
            'options' => $question->options
                ->sortBy('option_index')
                ->values()
                ->map(fn (QuestionOption $option) => [
                    'id' => $option->id,
                    'question_id' => $option->question_id,
                    'option_index' => $option->option_index,
                    'option_text' => $option->option_text,
                ]),
        ];
    }
}
