<?php

namespace Tests\Feature\Integration;

use App\Enums\AdecuacionType;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 1 — Flujo completo del examen.
 *
 * Cubre la ruta crítica de punta a punta:
 * crear examen → agregar preguntas → asignar grupo → activar →
 * estudiante inicia → responde → calificación automática → progreso → recomendaciones.
 */
class Level1_ExamFullFlowTest extends TestCase
{
    use ApiAuth;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildScenario(): array
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $group   = Group::factory()->create(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student     = Student::factory()->create([
            'user_id'        => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        // Añadir estudiante al grupo
        $group->students()->attach($studentUser->id, ['joined_at' => now(), 'institution_id' => $institution->id]);

        return compact('institution', 'teacher', 'subject', 'group', 'studentUser', 'student');
    }

    /**
     * Crea una pregunta MC con 4 opciones directamente en BD.
     * Devuelve [$question, $correctOptionId, $wrongOptionId].
     */
    private function createMcQuestion(Exam $exam, Institution $institution, int $points = 2): array
    {
        $question = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $exam->id,
            'question_type'  => 'multiple_choice',
            'points'         => $points,
        ]);

        $correct = QuestionOption::create([
            'institution_id' => $institution->id,
            'question_id'    => $question->id,
            'option_index'   => 1,
            'option_text'    => 'Respuesta correcta',
            'is_correct'     => true,
        ]);

        foreach ([2, 3, 4] as $idx) {
            QuestionOption::create([
                'institution_id' => $institution->id,
                'question_id'    => $question->id,
                'option_index'   => $idx,
                'option_text'    => "Distractor {$idx}",
                'is_correct'     => false,
            ]);
        }

        $wrong = QuestionOption::where('question_id', $question->id)
            ->where('is_correct', false)->first();

        return [$question, $correct->id, $wrong->id];
    }

    private function createSaQuestion(Exam $exam, Institution $institution, int $points = 5): Question
    {
        return Question::factory()->shortAnswer()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $exam->id,
            'points'         => $points,
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_mc_question_auto_graded_correct_answer(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'group'       => $group,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
            'duration_minutes'      => 60,
        ]);

        [$question, $correctId] = $this->createMcQuestion($exam, $institution);

        // Asignar grupo al examen
        $exam->syncGroups([$group->id]);

        $this->actingAs($studentUser, 'sanctum');

        // Iniciar intento
        $startRes = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $startRes->assertCreated();
        $attemptId = $startRes->json('data.id');

        // Enviar con respuesta correcta
        $submitRes = $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                [
                    'question_id'         => $question->id,
                    'selected_option_ids' => [$correctId],
                    'answer_text'         => null,
                ],
            ],
        ]);

        $submitRes->assertOk();

        // La pregunta fue calificada como correcta
        $this->assertDatabaseHas('student_answers', [
            'attempt_id'  => $attemptId,
            'question_id' => $question->id,
            'is_correct'  => true,
        ]);

        // El intento tiene score = max_score (2 puntos)
        $this->assertDatabaseHas('exam_attempts', [
            'id'        => $attemptId,
            'score'     => 2,
            'max_score' => 2,
        ]);
    }

    public function test_mc_question_auto_graded_wrong_answer(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);

        [, , $wrongId] = $this->createMcQuestion($exam, $institution, points: 3);

        $this->actingAs($studentUser, 'sanctum');
        $start = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $start->assertCreated();
        $attemptId = $start->json('data.id');

        $submit = $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                [
                    'question_id'         => Question::where('exam_id', $exam->id)->first()->id,
                    'selected_option_ids' => [$wrongId],
                ],
            ],
        ]);

        $submit->assertOk();
        $this->assertDatabaseHas('exam_attempts', ['id' => $attemptId, 'score' => 0]);
    }

    public function test_short_answer_marked_needs_review(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam     = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);
        $question = $this->createSaQuestion($exam, $institution);

        $this->actingAs($studentUser, 'sanctum');
        $start = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $start->assertCreated();
        $attemptId = $start->json('data.id');

        $submit = $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'answer_text' => 'Mi respuesta'],
            ],
        ]);

        $submit->assertOk();
        $this->assertDatabaseHas('student_answers', [
            'attempt_id'    => $attemptId,
            'question_id'   => $question->id,
            'review_status' => 'needs_review',
        ]);
    }

    public function test_teacher_reviews_short_answer_and_score_is_recorded(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam     = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);
        $question = $this->createSaQuestion($exam, $institution, points: 5);

        $this->actingAs($studentUser, 'sanctum');
        $start = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $attemptId = $start->json('data.id');

        $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [['question_id' => $question->id, 'answer_text' => 'Respuesta del estudiante']],
        ]);

        // Teacher revisa
        $this->actingAs($teacher, 'sanctum');
        $answer = \App\Models\Students\StudentAnswer::where('attempt_id', $attemptId)->first();

        $reviewRes = $this->patchJson("/api/student-answers/{$answer->id}/review", [
            'is_correct'      => true,
            'explanation'     => 'Correcto',
            'points_awarded'  => 5,
        ]);

        $reviewRes->assertOk();
        $this->assertDatabaseHas('student_answers', [
            'id'            => $answer->id,
            'is_correct'    => true,
            'review_status' => 'reviewed',
        ]);
    }

    public function test_student_progress_updated_after_submit(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);

        [$question, $correctId] = $this->createMcQuestion($exam, $institution);

        $this->actingAs($studentUser, 'sanctum');
        $start = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $attemptId = $start->json('data.id');

        $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'selected_option_ids' => [$correctId]],
            ],
        ]);

        // Progreso creado para la materia
        $this->assertDatabaseHas('student_progress', [
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
        ]);

        // overall_average y exams_completed_count actualizados en students
        $this->assertDatabaseHas('students', [
            'user_id'                => $studentUser->id,
            'exams_completed_count'  => 1,
        ]);
    }

    public function test_ai_recommendations_created_after_submit(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);
        $this->createMcQuestion($exam, $institution);

        $this->actingAs($studentUser, 'sanctum');
        $start = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $attemptId = $start->json('data.id');

        $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [],
        ]);

        $this->assertDatabaseHas('ai_recommendations', [
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
        ]);
    }

    public function test_expired_exam_submit_rejected(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
            'duration_minutes'      => 30,
        ]);

        // Iniciar el intento cuando el tiempo era "hace 61 minutos"
        $this->actingAs($studentUser, 'sanctum');
        $this->travel(-61)->minutes();
        $startRes = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $startRes->assertCreated();
        $attemptId = $startRes->json('data.id');
        $this->travelBack();

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [],
        ]);

        $res->assertStatus(409);
        $this->assertStringContainsString('expirado', strtolower($res->json('message')));
    }

    public function test_adecuacion_evaluacion_grants_extra_time(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        // Estudiante con adecuación de evaluación (×1.5)
        Student::where('user_id', $studentUser->id)
            ->update(['adecuacion_type' => AdecuacionType::Evaluacion->value]);

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
            'duration_minutes'      => 60,
        ]);

        $question = $this->createSaQuestion($exam, $institution);

        // Iniciar el intento cuando el tiempo era "hace 80 minutos"
        // Para estudiante normal (60 min): vencido. Con adecuación evaluación (×1.5 = 90 min): todavía tiene tiempo.
        $this->actingAs($studentUser, 'sanctum');
        $this->travel(-80)->minutes();
        $startRes = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $startRes->assertCreated();
        $attemptId = $startRes->json('data.id');
        $this->travelBack();

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['question_id' => $question->id, 'answer_text' => 'respuesta'],
            ],
        ]);

        $res->assertOk();
    }

    public function test_pause_and_resume_extends_deadline(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
            'duration_minutes'      => 30,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $start = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $start->assertCreated();
        $attemptId = $start->json('data.id');

        // Pausar
        $pause = $this->patchJson("/api/exams/{$exam->id}/attempts/{$attemptId}/pause");
        $pause->assertOk();

        $this->assertNotNull($pause->json('data.paused_at'));

        // Simular que pasaron 5 segundos en pausa
        $this->travel(5)->seconds();

        // Reanudar
        $resume = $this->patchJson("/api/exams/{$exam->id}/attempts/{$attemptId}/resume");
        $resume->assertOk();

        $this->travelBack();

        $attempt = ExamAttempt::find($attemptId);
        $this->assertNull($attempt->paused_at);
        $this->assertGreaterThan(0, (int) $attempt->total_paused_seconds);
    }

    public function test_cannot_submit_already_submitted_attempt(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam    = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
        ]);
        $attempt = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/submit", [
            'answers' => [],
        ]);

        $res->assertStatus(409);
    }

    public function test_max_attempts_enforced(): void
    {
        [
            'institution' => $institution,
            'teacher'     => $teacher,
            'subject'     => $subject,
            'studentUser' => $studentUser,
        ] = $this->buildScenario();

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'subject_id'            => $subject->id,
            'max_attempts'          => 1,
        ]);

        // Intento ya consumido
        ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/start");
        $res->assertStatus(409);
    }
}
