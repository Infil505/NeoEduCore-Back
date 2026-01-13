<?php

namespace App\Services;

use App\Models\AiRecommendation;
use App\Models\ExamAttempt;
use App\Models\StudyResource;

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
     * Generar recomendaciones simples basadas en porcentaje del intento
     * (sin OpenAI: útil como fallback o primera versión)
     */
    public function generateFromAttempt(ExamAttempt $attempt): array
    {
        $attempt->load('exam.subject');

        $studentUserId = $attempt->student_user_id;
        $subjectId = $attempt->exam->subject_id;
        $examId = $attempt->exam_id;

        $pct = (float) $attempt->percentage;

        $created = [];

        if ($pct >= 85) {
            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'strength',
                'Excelente desempeño. Continúa reforzando con ejercicios de mayor dificultad y retos adicionales.'
            );
        } elseif ($pct >= 70) {
            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'action',
                'Buen desempeño. Recomendación: repasa los temas donde fallaste y realiza un ejercicio corto de práctica.'
            );
        } else {
            $created[] = $this->create(
                $studentUserId,
                $subjectId,
                $examId,
                'weakness',
                'Se detectan áreas por reforzar. Recomendación: repasar los conceptos base y practicar con ejemplos guiados.'
            );

            // sugerir 1 recurso del catálogo si existe (RN-AI-008)
            $resource = StudyResource::query()
                ->where('subject_id', $subjectId) // si luego agregas subject_id a recursos
                ->first();

            if ($resource) {
                $created[] = $this->create(
                    $studentUserId,
                    $subjectId,
                    $examId,
                    'resource',
                    'Te recomiendo este recurso para reforzar la materia.',
                    [
                        'title' => $resource->title,
                        'type' => $resource->resource_type->value,
                        'url' => $resource->url,
                        'difficulty' => $resource->difficulty,
                        'estimated_duration' => $resource->estimated_duration,
                    ]
                );
            }
        }

        return $created;
    }
}