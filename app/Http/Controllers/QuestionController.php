<?php

namespace App\Http\Controllers;

use App\Enums\QuestionType;
use App\Models\Exam;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class QuestionController extends Controller
{
    /**
     * Listar preguntas por examen
     */
    public function index(Request $request, Exam $exam)
    {
        return response()->json([
            'data' => $exam->questions()
                ->with('options')
                ->orderBy('order_index')
                ->get(),
        ]);
    }

    /**
     * Crear pregunta + opciones
     */
    public function store(Request $request, Exam $exam)
    {
        $data = $request->validate([
            'question_text' => ['required', 'string', 'min:3', 'max:2000'],
            'question_type' => ['required', Rule::in([
                QuestionType::MultipleChoice->value,
                QuestionType::TrueFalse->value,
                QuestionType::ShortAnswer->value,
            ])],
            'points' => ['required', 'integer', 'between:1,10'],
            'order_index' => ['nullable', 'integer', 'min:1'],

            // Para short_answer
            'correct_answer_text' => ['nullable', 'string', 'max:2000'],

            // Para opciones (MC/TF)
            'options' => ['nullable', 'array'],
            'options.*.option_index' => ['required_with:options', 'integer'],
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

        // RN: solo una correcta (MC/TF)
        if (!empty($data['options'])) {
            $correctCount = collect($data['options'])->where('is_correct', true)->count();
            if ($correctCount !== 1) {
                return response()->json([
                    'message' => 'Debe existir exactamente 1 opción correcta',
                ], 422);
            }
        }

        return DB::transaction(function () use ($exam, $data, $type) {
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
                foreach ($data['options'] as $opt) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'option_index' => (int) $opt['option_index'],
                        'option_text' => $opt['option_text'],
                        'is_correct' => (bool) $opt['is_correct'],
                    ]);
                }
            }

            return response()->json([
                'data' => $question->load('options'),
            ], 201);
        });
    }

    /**
     * Actualizar pregunta + opciones
     */
    public function update(Request $request, Exam $exam, Question $question)
    {
        // Asegurar que la pregunta pertenece al examen (extra seguro)
        if ($question->exam_id !== $exam->id) {
            return response()->json(['message' => 'Pregunta no pertenece a este examen'], 404);
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

        return DB::transaction(function () use ($question, $data) {
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

            return response()->json([
                'data' => $question->load('options'),
            ]);
        });
    }

    /**
     * Eliminar pregunta (no permitir eliminar la última)
     */
    public function destroy(Exam $exam, Question $question)
    {
        if ($question->exam_id !== $exam->id) {
            return response()->json(['message' => 'Pregunta no pertenece a este examen'], 404);
        }

        if ($exam->questions()->count() <= 1) {
            return response()->json([
                'message' => 'No se puede eliminar la última pregunta del examen',
            ], 409);
        }

        $question->options()->delete();
        $question->delete();

        return response()->json(['message' => 'Pregunta eliminada']);
    }
}