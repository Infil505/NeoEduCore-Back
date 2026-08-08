<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\RevelaRespuestas;
use App\Enums\QuestionType;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    use RevelaRespuestas;

    /**
     * Listar preguntas por examen
     */
    public function index(Request $request, Exam $exam)
    {
        // Misma comprobación que `ExamController::show()`: si no, esta ruta era
        // la vía directa a los enunciados de un examen ajeno o en borrador.
        if (!Exam::query()->whereKey($exam->getKey())->visibleTo($request->user())->exists()) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $query = $exam->questions()->with('options');

        if ($exam->randomize_questions) {
            $query->inRandomOrder();
        } else {
            $query->orderBy('order_index');
        }

        $questions = $query->limit(200)->get();

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
            if (empty($data['options']) || count($data['options']) !== 4) {
                return response()->json([
                    'message' => 'multiple_choice debe tener exactamente 4 opciones',
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

        return DB::transaction(function () use ($exam, $data, $type, $request) {
            $orderIndex = $data['order_index'] ?? ($exam->questions()->max('order_index') + 1);

            $question = Question::create([
                'exam_id' => $exam->id,
                'question_text' => $data['question_text'],
                'question_type' => $type,
                'points' => (int) $data['points'],
                'correct_answer_text' => $type === QuestionType::ShortAnswer->value ? $data['correct_answer_text'] : null,
                'order_index' => (int) $orderIndex,
            ]);

            // Crear opciones si aplica
            if (!empty($data['options'])) {
                foreach ($data['options'] as $idx => $opt) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_index' => isset($opt['option_index']) ? (int) $opt['option_index'] : $idx,
                        'option_text'  => $opt['option_text'],
                        'is_correct'   => (bool) $opt['is_correct'],
                    ]);
                }
            }

            // Ruta admin/teacher: aquí sí procede devolver las respuestas.
            $question->load('options');
            $this->revelarRespuestasDe($request->user(), $question);

            return response()->json([
                'data' => $question,
            ], 201);
        });
    }

    /**
     * Actualizar pregunta + opciones
     */
    public function update(Request $request, Question $question)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher') {
            $question->loadMissing('exam');
            if ($question->exam->created_by_teacher_id !== $user->id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        $data = $request->validate([
            'question_text' => ['sometimes', 'string', 'min:3', 'max:2000'],
            'points' => ['sometimes', 'integer', 'between:1,10'],
            'order_index' => ['sometimes', 'integer', 'min:1'],

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

            $expected = $type === QuestionType::MultipleChoice->value ? 4 : 2;

            if (empty($data['options']) || count($data['options']) !== $expected) {
                return response()->json([
                    'message' => "{$type} debe tener exactamente {$expected} opciones",
                ], 422);
            }

            $correctCount = collect($data['options'])->where('is_correct', true)->count();
            if ($correctCount !== 1) {
                return response()->json([
                    'message' => 'Debe existir exactamente 1 opción correcta',
                ], 422);
            }
        }

        return DB::transaction(function () use ($question, $data, $request) {
            $question->fill($data);
            $question->save();

            // Reemplazar opciones si vienen
            if (array_key_exists('options', $data)) {
                $question->options()->delete();

                foreach ($data['options'] as $opt) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_index' => (int) $opt['option_index'],
                        'option_text' => $opt['option_text'],
                        'is_correct' => (bool) $opt['is_correct'],
                    ]);
                }
            }

            $question->load('options');
            $this->revelarRespuestasDe($request->user(), $question);

            return response()->json([
                'data' => $question,
            ]);
        });
    }

    /**
     * Eliminar pregunta (no permitir eliminar la última)
     */
    public function destroy(Request $request, Question $question)
    {
        $user = $request->user();
        $exam = $question->exam;

        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($exam->questions()->limit(2)->count() <= 1) {
            return response()->json([
                'message' => 'No se puede eliminar la última pregunta del examen',
            ], 409);
        }

        $question->delete(); // opciones eliminadas en cascada por DB

        return response()->noContent();
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
