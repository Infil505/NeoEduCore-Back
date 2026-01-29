<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\ExamAttempt;
use App\Models\StudyResource;
use OpenAI\Laravel\Facades\OpenAI;

class AiRecommendationService
{
    public function create(
        string $studentUserId,
        string $subjectId,
        ?string $examId,
        string $type,
        string $text,
        ?array $resource = null
    ): AiRecommendation {
        return AiRecommendation::create([
            'student_user_id' => $studentUserId,
            'subject_id' => $subjectId,
            'exam_id' => $examId,
            'type' => $type,
            'recommendation_text' => $text,
            'resource' => $resource,
        ]);
    }

    /**
     * Generar recomendaciones (fallback SIN OpenAI), basadas en porcentaje del intento.
     * - No asume subject_id en StudyResource (porque tu modelo actual no lo tiene)
     * - Si hay recursos del tenant, sugiere uno de forma genérica
     */
    public function generateFromAttempt(ExamAttempt $attempt): array
    {
        $attempt->load(['exam.subject']);

        $studentUserId = $attempt->student_user_id;
        $subjectId = $attempt->exam?->subject_id;
        $examId = $attempt->exam_id;

        if (!$subjectId) {
            // Si no hay materia, devolvemos una recomendación genérica
            return [
                $this->create(
                    $studentUserId,
                    (string) ($subjectId ?? '00000000-0000-0000-0000-000000000000'), // nunca debería usarse
                    $examId,
                    'action',
                    'Revisa tus respuestas incorrectas, anota los temas que te costaron y practica con ejercicios similares.'
                ),
            ];
        }

        // percentage es accesor del modelo ExamAttempt
        $pct = method_exists($attempt, 'getPercentageAttribute')
            ? (float) $attempt->percentage
            : (($attempt->max_score > 0) ? round(((float)$attempt->score / (float)$attempt->max_score) * 100, 2) : 0.0);

        $created = [];

        if ($pct >= 85) {
            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'strength',
                'Excelente desempeño. Continúa reforzando con ejercicios de mayor dificultad y retos adicionales.'
            );

            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'action',
                "Acciones sugeridas:\n- Resuelve 5 ejercicios extra del mismo tema.\n- Explica con tus palabras los conceptos clave.\n- Practica con preguntas de mayor complejidad."
            );
        } elseif ($pct >= 70) {
            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'action',
                "Buen desempeño.\nAcciones sugeridas:\n- Repasa los temas donde fallaste.\n- Realiza un ejercicio corto por cada tema.\n- Vuelve a intentar preguntas similares."
            );
        } else {
            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'weakness',
                "Se detectan áreas por reforzar.\nAcciones sugeridas:\n- Repasa conceptos base.\n- Practica con ejemplos guiados.\n- Pide apoyo en los temas con más errores."
            );

            // ✅ Sugerir 1 recurso del catálogo del tenant (tu StudyResource no tiene subject_id)
            $resource = StudyResource::query()
                ->orderBy('created_at', 'desc')
                ->first();

            if ($resource) {
                $created[] = $this->create(
                    $studentUserId,
                    $subjectId,
                    $examId,
                    'resource',
                    'Te recomiendo este recurso para reforzar.',
                    [
                        'title' => $resource->title,
                        'type' => $resource->resource_type->value,
                        'url' => $resource->url,
                        'difficulty' => $resource->difficulty ?? null,
                        'estimated_duration' => $resource->estimated_duration ?? null,
                        'language' => $resource->language ?? 'es',
                    ]
                );
            } else {
                $created[] = $this->create(
                    $studentUserId,
                    $subjectId,
                    $examId,
                    'resource',
                    'Sugerencia: busca un video corto o una guía práctica del tema principal donde tuviste errores y realiza ejercicios básicos.',
                    null
                );
            }
        }

        return $created;
    }

    /**
     * Regenerar recomendaciones para un intento (SIN prompt libre, seguro para estudiante).
     * - Genera 4 recomendaciones: strength, weakness, action, resource
     * - Guarda cada una en ai_recommendations
     */
    public function regenerateForAttempt(ExamAttempt $attempt, string $requesterUserId = ''): array
    {
        $attempt->load([
            'exam.subject',
            'answers.question.options',
            'answers.selectedOptions',
        ]);

        $studentUserId = $attempt->student_user_id;
        $subjectId = $attempt->exam?->subject_id;
        $examId = $attempt->exam_id;

        if (!$subjectId) {
            // fallback si no hay subject
            return [
                $this->create(
                    $studentUserId,
                    (string) ($subjectId ?? '00000000-0000-0000-0000-000000000000'),
                    $examId,
                    'action',
                    'Revisa tus respuestas incorrectas, identifica los temas y practica ejercicios similares.',
                    null
                ),
            ];
        }

        $wrong = collect($attempt->answers)->filter(fn ($a) => $a->is_correct === false)->values();
        $right = collect($attempt->answers)->filter(fn ($a) => $a->is_correct === true)->values();

        $wrongItems = $wrong->take(8)->map(function ($a) {
            $q = $a->question;
            return [
                'question' => mb_substr((string)($q->question_text ?? ''), 0, 240),
                'type' => $q?->question_type?->value,
                'given' => $a->answer_text ? mb_substr((string)$a->answer_text, 0, 120) : null,
            ];
        })->all();

        $prompt = "Genera recomendaciones educativas para un estudiante según su intento de examen.\n\n"
            . "Contexto:\n"
            . "- Materia: " . ($attempt->exam?->subject?->name ?? 'N/D') . "\n"
            . "- Examen: " . ($attempt->exam?->title ?? 'N/D') . "\n"
            . "- Correctas: " . $right->count() . "\n"
            . "- Incorrectas: " . $wrong->count() . "\n"
            . "- Errores (muestra): " . json_encode($wrongItems, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Devuelve EXACTAMENTE 4 secciones con este formato:\n"
            . "strength: ...\n"
            . "weakness: ...\n"
            . "action: ...\n"
            . "resource: ...\n"
            . "Si incluyes datos de recurso, agrega un JSON al final de resource.\n"
            . "Reglas: español, breve, accionable, no inventes datos.\n";

        try {
            $response = OpenAI::chat()->create([
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un tutor educativo. Recomienda con claridad y acciones concretas.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 650,
            ]);

            $content = trim((string) ($response->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            // fallback si OpenAI falla
            return $this->generateFromAttempt($attempt);
        }

        if ($content === '') {
            return $this->generateFromAttempt($attempt);
        }

        $strengthText = $this->extractSection($content, 'strength') ?? 'Buen desempeño en varios temas. Sigue practicando para consolidar lo aprendido.';
        $weaknessText = $this->extractSection($content, 'weakness') ?? 'Refuerza los temas donde tuviste más errores con ejemplos guiados.';
        $actionText   = $this->extractSection($content, 'action') ?? "Acciones:\n- Repasa los errores.\n- Practica ejercicios.\n- Pide aclaraciones del tema.";

        [$resourceText, $resourceJson] = $this->extractResource($content);

        $created = [];
        $created[] = $this->create($studentUserId, $subjectId, $examId, 'strength', $strengthText, null);
        $created[] = $this->create($studentUserId, $subjectId, $examId, 'weakness', $weaknessText, null);
        $created[] = $this->create($studentUserId, $subjectId, $examId, 'action', $actionText, null);

        // Si OpenAI no dio JSON útil, intentamos sugerir un recurso del catálogo
        if ($resourceJson === null) {
            $r = StudyResource::query()->orderBy('created_at', 'desc')->first();
            if ($r) {
                $resourceJson = [
                    'title' => $r->title,
                    'type' => $r->resource_type->value,
                    'url' => $r->url,
                    'difficulty' => $r->difficulty ?? null,
                    'estimated_duration' => $r->estimated_duration ?? null,
                    'language' => $r->language ?? 'es',
                ];
            }
        }

        $created[] = $this->create($studentUserId, $subjectId, $examId, 'resource', $resourceText, $resourceJson);

        return $created;
    }

    private function extractSection(string $text, string $key): ?string
    {
        $pattern = '/\b' . preg_quote($key, '/') . '\b\s*[:\-]\s*(.+?)(?=\n\s*(strength|weakness|action|resource)\b\s*[:\-]|\z)/is';
        if (preg_match($pattern, $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function extractResource(string $text): array
    {
        $resourceText = $this->extractSection($text, 'resource') ?? 'Recurso sugerido: repasar el tema con una guía práctica o un video corto.';
        $resourceJson = null;

        // Intentar extraer JSON (primera ocurrencia bien formada)
        if (preg_match('/\{.*\}/sU', $text, $m)) {
            $candidate = $m[0];
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                $resourceJson = $decoded;
            }
        }

        return [trim($resourceText), $resourceJson];
    }
}