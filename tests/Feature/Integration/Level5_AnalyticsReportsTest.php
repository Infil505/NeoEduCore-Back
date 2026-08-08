<?php

namespace Tests\Feature\Integration;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 5 — Analíticas y Reportes.
 *
 * Verifica estructura y datos de todos los endpoints de reporting:
 * institution/subjects/student analytics, reportes CSV, métricas de tutor.
 */
class Level5_AnalyticsReportsTest extends TestCase
{
    use ApiAuth;

    private function buildFullScenario(): array
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student     = Student::factory()->create([
            'user_id'        => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $exam = Exam::factory()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);

        ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUser->id,
            'score'           => 8,
            'max_score'       => 10,
        ]);

        StudentProgress::factory()->create([
            'institution_id'    => $institution->id,
            'student_user_id'   => $studentUser->id,
            'subject_id'        => $subject->id,
            'mastery_percentage' => 80,
        ]);

        return compact('institution', 'teacher', 'subject', 'studentUser', 'student', 'exam');
    }

    // -------------------------------------------------------------------------
    // Analytics
    // -------------------------------------------------------------------------

    public function test_institution_analytics_returns_expected_structure(): void
    {
        $this->buildFullScenario();

        $res = $this->getJson('/api/analytics/institution');

        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'total_students',
                'active_students',
                'exams_completed',
                'average_score_pct',
            ],
        ]);
    }

    public function test_subjects_analytics_returns_per_subject_data(): void
    {
        ['institution' => $institution] = $this->buildFullScenario();

        $res = $this->getJson('/api/analytics/subjects');

        $res->assertOk();
        $res->assertJsonStructure(['data']);
        $this->assertIsArray($res->json('data'));
    }

    public function test_student_analytics_returns_full_detail(): void
    {
        ['studentUser' => $studentUser] = $this->buildFullScenario();

        $res = $this->getJson("/api/analytics/students/{$studentUser->id}");

        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'student',
                'attempts_count',
                'average_score_pct',
                'progress',
                'recent_attempts',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Reportes
    // -------------------------------------------------------------------------

    public function test_exam_results_report_returns_paginated_data(): void
    {
        ['exam' => $exam] = $this->buildFullScenario();

        $res = $this->getJson("/api/reports/exams/{$exam->id}/results");

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['results']]);
    }

    public function test_csv_export_returns_text_csv(): void
    {
        ['exam' => $exam] = $this->buildFullScenario();

        $res = $this->get("/api/reports/exams/{$exam->id}/results.csv");

        $res->assertOk();
        $contentType = $res->headers->get('Content-Type');
        $this->assertStringContainsString('text/csv', $contentType ?? '');
    }

    public function test_student_history_report(): void
    {
        ['studentUser' => $studentUser] = $this->buildFullScenario();

        $res = $this->getJson("/api/reports/students/{$studentUser->id}/history");

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['student', 'attempts']]);
    }

    // -------------------------------------------------------------------------
    // Métricas de uso del tutor
    // -------------------------------------------------------------------------

    public function test_tutor_usage_report_returns_expected_structure(): void
    {
        ['institution' => $institution, 'studentUser' => $studentUser] = $this->buildFullScenario();

        // Crear sesiones de tutor para tener datos
        AiChatSession::create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'messages'        => [
                ['role' => 'user',      'content' => 'Hola'],
                ['role' => 'assistant', 'content' => 'Hola, ¿en qué te ayudo?'],
            ],
        ]);

        AiRecommendation::factory()->create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id'      => Subject::where('institution_id', $institution->id)->first()->id,
        ]);

        $res = $this->getJson('/api/reports/ai/tutor-usage');

        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'sessions' => [
                    'total_sessions',
                    'active_sessions',
                    'closed_sessions',
                    'unique_students',
                    'total_messages',
                ],
                'top_recommendation_types',
            ],
        ]);

        // El escenario entra como docente, y `top_students_by_usage` (top 10 con
        // nombre y propio) es solo para admin desde el 08/08/2026: [173] limita
        // al personal docente a métricas agregadas y anónimas. El caso del admin
        // está en `TutorStrategiesTest`.
        $res->assertJsonMissingPath('data.top_students_by_usage');
    }

    public function test_tutor_usage_total_messages_counts_correctly(): void
    {
        ['institution' => $institution, 'studentUser' => $studentUser] = $this->buildFullScenario();

        AiChatSession::create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'messages'        => [
                ['role' => 'user',      'content' => 'Msg 1'],
                ['role' => 'assistant', 'content' => 'Reply 1'],
                ['role' => 'user',      'content' => 'Msg 2'],
                ['role' => 'assistant', 'content' => 'Reply 2'],
            ],
        ]);

        $res = $this->getJson('/api/reports/ai/tutor-usage');
        $res->assertOk();

        $total = (int) $res->json('data.sessions.total_messages');
        $this->assertGreaterThanOrEqual(4, $total);
    }

    public function test_student_cannot_access_reports(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $this->getJson('/api/analytics/institution')->assertStatus(403);
        $this->getJson('/api/reports/ai/tutor-usage')->assertStatus(403);
    }
}
