<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\Student;
use App\Services\AiRecommendationService;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;
use Illuminate\Validation\Rule;

class AiController extends Controller
{
    /**
     * Generar recomendación IA y guardarla en ai_recommendations
     * body:
     * {
     *   "student_user_id": "uuid",
     *   "subject_id": "uuid",
     *   "exam_id": "uuid|null",
     *   "type": "strength|weakness|resource|action",
     *   "prompt": "texto"
     * }
     */
    public function generate(Request $request, AiRecommendationService $aiService)
    {
        $data = $request->validate([
            'student_user_id' => ['required', 'uuid'],
            'subject_id'      => ['required', 'uuid'],
            'exam_id'         => ['nullable', 'uuid'],
            'type'            => ['required', Rule::in(['strength', 'weakness', 'resource', 'action'])],
            'prompt'          => ['required', 'string', 'min:3', 'max:6000'],
        ]);

        // Validar que exista el estudiante (scoped)
        Student::where('user_id', $data['student_user_id'])->firstOrFail();

        // (Opcional) Validar que el examen corresponda a la materia
        if (!empty($data['exam_id'])) {
            $exam = Exam::where('id', $data['exam_id'])->first();
            if ($exam && $exam->subject_id && $exam->subject_id !== $data['subject_id']) {
                return response()->json([
                    'message' => 'El examen no corresponde a la materia indicada',
                ], 422);
            }
        }

        // Llamada a OpenAI
        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Eres un tutor educativo. Da recomendaciones claras, breves y accionables.',
                ],
                [
                    'role' => 'user',
                    'content' => $data['prompt'],
                ],
            ],
        ]);

        $text = $response->choices[0]->message->content ?? null;

        if (!$text) {
            return response()->json([
                'message' => 'Sin respuesta del modelo',
            ], 502);
        }

        $rec = $aiService->create(
            $data['student_user_id'],
            $data['subject_id'],
            $data['exam_id'] ?? null,
            $data['type'],
            $text,
            null
        );

        return response()->json([
            'data' => $rec,
        ], 201);
    }
}