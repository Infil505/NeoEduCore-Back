<?php

namespace Tests\Feature\Crud;

use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\StudentAnswer;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class StudentAnswersTest extends TestCase
{
    use ApiAuth;

    public function test_list_exam_attempt_answers(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $question = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
        ]);

        StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
        ]);

        $res = $this->getJson("/api/exam-attempts/{$attempt->id}/answers");

        $res->assertOk();
    }

    public function test_review_student_answer(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $question = Question::factory()->shortAnswer()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'points' => 5,
        ]);

        $answer = StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
        ]);

        $res = $this->patchJson("/api/student-answers/{$answer->id}/review", [
            'is_correct' => true,
            'explanation' => 'Respuesta correcta',
            'points_awarded' => 5,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('student_answers', [
            'id' => $answer->id,
            'is_correct' => true,
        ]);
    }

    public function test_pending_summary_counts_by_exam(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $question = Question::factory()->shortAnswer()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
        ]);

        StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'review_status' => 'needs_review',
        ]);
        StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'review_status' => 'auto_graded',
        ]);

        $res = $this->getJson('/api/reports/exams/pending-reviews');

        $res->assertOk();
        $res->assertJson(['data' => [['exam_id' => $exam->id, 'total' => 1]]]);
    }

    public function test_pending_for_exam_lists_pending_first_then_already_graded(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'max_attempts' => 2,
        ]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id, 'full_name' => 'Ana Pérez']);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $question = Question::factory()->shortAnswer()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'points' => 5,
        ]);

        $yaCalificada = StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'review_status' => 'reviewed',
        ]);
        $pendiente = StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'review_status' => 'needs_review',
        ]);

        // Pregunta de opción múltiple: se autocalifica sola y nunca debe aparecer aquí, aunque
        // comparta intento con las respuestas abiertas de arriba.
        $opcionMultiple = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'question_type' => 'multiple_choice',
        ]);
        StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id' => $attempt->id,
            'question_id' => $opcionMultiple->id,
            'review_status' => 'auto_graded',
        ]);

        $res = $this->getJson("/api/reports/exams/{$exam->id}/pending-reviews");

        $res->assertOk();
        $res->assertJsonPath('data.max_attempts', 2);
        // Ya calificada SÍ sale (para poder corregirla), pero la pendiente va primero.
        $res->assertJsonCount(2, 'data.answers');
        $res->assertJsonPath('data.answers.0.answer_id', $pendiente->id);
        $res->assertJsonPath('data.answers.0.student_name', 'Ana Pérez');
        $res->assertJsonPath('data.answers.0.max_points', 5);
        $res->assertJsonPath('data.answers.0.attempt_number', 1);
        $res->assertJsonPath('data.answers.1.answer_id', $yaCalificada->id);
        $res->assertJsonPath('data.answers.1.review_status', 'reviewed');
    }

    public function test_un_docente_no_ve_pendientes_del_examen_de_otro(): void
    {
        $institution = Institution::factory()->create();
        \App\Models\Admin\User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $otroDocente = \App\Models\Admin\User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $otroDocente->id,
        ]);

        $this->getJson("/api/reports/exams/{$exam->id}/pending-reviews")->assertForbidden();
    }
}
