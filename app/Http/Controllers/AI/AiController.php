<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Models\Academic\Subject;
use App\Services\AI\AiRecommendationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenAI\Laravel\Facades\OpenAI;

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
     *   "prompt": "texto",
     *   "resource": { ... } // opcional (si type=resource)
     * }
     */
    public function generate(Request $request, AiRecommendationService $aiService)
    {
        $user = $request->user();

        // Solo teacher/admin (evitar que un estudiante genere recomendaciones arbitrarias)
        if ($user->user_type->value === 'student') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'student_user_id' => ['required', 'uuid'],
            'subject_id'      => ['required', 'uuid'],
            'exam_id'         => ['nullable', 'uuid'],
            'type'            => ['required', Rule::in(['strength', 'weakness', 'resource', 'action'])],
            'prompt'          => ['required', 'string', 'min:3', 'max:6000'],
            'resource'        => ['nullable', 'array'], // se guarda como json en ai_recommendations.resource
        ]);

        // ✅ Validar subject (scoped por tenant si usas TenantScoped + app('tenant_id'))
        Subject::where('id', $data['subject_id'])->firstOrFail();

        // ✅ Validar que exista el estudiante (scoped)
        Student::where('user_id', $data['student_user_id'])->firstOrFail();

        // ✅ (Opcional) Validar examen: existe y coincide materia si se envía
        $exam = null;
        if (!empty($data['exam_id'])) {
            $exam = Exam::where('id', $data['exam_id'])->firstOrFail();

            if (!empty($exam->subject_id) && $exam->subject_id !== $data['subject_id']) {
                return response()->json([
                    'message' => 'El examen no corresponde a la materia indicada',
                ], 422);
            }
        }

        // 🔥 Llamada a OpenAI (con manejo de error)
        try {
            $response = OpenAI::chat()->create([
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Eres un tutor educativo. Responde en español, de forma clara, breve y accionable. Usa viñetas si ayuda. No inventes datos.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $data['prompt'],
                    ],
                ],
                'temperature' => 0.5,
                'max_tokens' => 500,
            ]);

            $text = $response->choices[0]->message->content ?? null;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Error al generar recomendación con IA',
                'error' => $e->getMessage(),
            ], 502);
        }

        if (!$text || trim($text) === '') {
            return response()->json([
                'message' => 'Sin respuesta del modelo',
            ], 502);
        }

        // Guardar recomendación (institution_id puede llenarse por backend o triggers)
        $rec = $aiService->create(
            $data['student_user_id'],
            $data['subject_id'],
            $data['exam_id'] ?? null,
            $data['type'],
            trim($text),
            $data['resource'] ?? null
        );

        return response()->json([
            'data' => $rec,
        ], 201);
    }
}
