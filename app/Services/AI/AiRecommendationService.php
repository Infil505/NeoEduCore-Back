<?php

namespace App\Services\AI;

use App\Models\AI\AiRecommendation;
use App\Models\Exams\ExamAttempt;
use App\Models\Academic\StudyResource;
use App\Services\AI\AiOutputValidator;
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
            'student_user_id'     => $studentUserId,
            'subject_id'          => $subjectId,
            'exam_id'             => $examId,
            'recommendation_type' => $type,
            'recommendation_text' => $text,
            'resource'            => $resource,
            'generated_at'        => now(),
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

            $resource = $this->recursoSugerido($attempt);

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

        $strengthText = $this->depurar($this->extractSection($content, 'strength'), 'Buen desempeño en varios temas. Sigue practicando para consolidar lo aprendido.');
        $weaknessText = $this->depurar($this->extractSection($content, 'weakness'), 'Refuerza los temas donde tuviste más errores con ejemplos guiados.');
        $actionText   = $this->depurar($this->extractSection($content, 'action'), "Acciones:\n- Repasa los errores.\n- Practica ejercicios.\n- Pide aclaraciones del tema.");

        [$resourceText, $resourceJson] = $this->extractResource($content);
        $resourceText = $this->depurar($resourceText, 'Recurso sugerido: repasar el tema con una guía práctica o un video corto.');

        $created = [];
        $created[] = $this->create($studentUserId, $subjectId, $examId, 'strength', $strengthText, null);
        $created[] = $this->create($studentUserId, $subjectId, $examId, 'weakness', $weaknessText, null);
        $created[] = $this->create($studentUserId, $subjectId, $examId, 'action', $actionText, null);

        // Si OpenAI no dio JSON útil, intentamos sugerir un recurso del catálogo
        if ($resourceJson === null) {
            $r = $this->recursoSugerido($attempt);
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

    /**
     * Recurso del catálogo del centro adecuado al estudiante.
     *
     * Antes era `orderBy('created_at','desc')->first()`: **el último recurso
     * subido a la institución, para todo el mundo**. Un alumno de 1.º que
     * reprobaba Ciencias recibía el vídeo de Estudios Sociales de 6.º si era el
     * más reciente. El informe promete «recursos personalizados» en la Figura 10
     * y [263] pone el ejemplo contrario, así que la elección no puede ser
     * indiferente al alumno.
     *
     * Se filtra por el **rango de grado** del recurso (`grade_min`/`grade_max`,
     * columnas que ya existían y nadie usaba) y se prefiere la dificultad
     * `basic`: esta rama solo se recorre por debajo del 65-70 %, donde lo útil es
     * material de refuerzo, no de ampliación.
     *
     * **Limitación conocida, y es de modelo, no de código:** `study_resources`
     * no tiene `subject_id`, así que no se puede acotar por materia. Es la misma
     * carencia de metadatos que impide diagnosticar por tema (`questions` tampoco
     * tiene tema ni indicador). Anotado en `ESTADO_Y_PENDIENTES.md` §3.5.
     */
    private function recursoSugerido(ExamAttempt $attempt): ?StudyResource
    {
        $attempt->loadMissing('student');
        $grade = $attempt->student?->grade;

        $porDificultad = fn ($q) => $q
            ->orderByRaw("CASE WHEN difficulty = 'basic' THEN 0 WHEN difficulty IS NULL THEN 1 ELSE 2 END")
            ->orderByDesc('created_at');

        if ($grade !== null) {
            $delGrado = $porDificultad(
                StudyResource::query()
                    ->where(fn ($w) => $w->whereNull('grade_min')->orWhere('grade_min', '<=', $grade))
                    ->where(fn ($w) => $w->whereNull('grade_max')->orWhere('grade_max', '>=', $grade))
            )->first();

            if ($delGrado) {
                return $delGrado;
            }
        }

        // Sin grado en el perfil, o sin nada que le encaje: mejor un recurso
        // genérico que ninguno — el texto de la recomendación ya es útil sin él,
        // pero el alumno agradece un punto de partida.
        return $porDificultad(StudyResource::query())->first();
    }

    /**
     * Pasa por `AiOutputValidator` un texto salido del modelo, o devuelve el de
     * reserva si no supera la validación.
     *
     * Estas cuatro secciones **no pasaban por ningún filtro**: `chat()` y
     * `getDiagnosis()` del tutor sí validaban su salida, pero las recomendaciones
     * regeneradas se guardaban crudas en `ai_recommendations`, y de ahí van al
     * alumno, al reporte de estrategias del docente y al PDF. Es la misma
     * superficie y merecía la misma comprobación: PII, longitud y enlaces fuera
     * de la lista blanca.
     */
    private function depurar(?string $texto, string $reserva): string
    {
        $texto = $texto !== null ? trim($texto) : '';

        if ($texto === '') {
            return $reserva;
        }

        $validator = new AiOutputValidator();

        if ($validator->validate($texto) !== null) {
            return $reserva;
        }

        return $validator->sanitize($texto);
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
                // Validar URL contra whitelist antes de persistir
                $url = $decoded['url'] ?? null;
                if ($url && !(new AiOutputValidator())->isUrlAllowed($url)) {
                    unset($decoded['url']);
                }
                $resourceJson = $decoded;
            }
        }

        return [trim($resourceText), $resourceJson];
    }
}