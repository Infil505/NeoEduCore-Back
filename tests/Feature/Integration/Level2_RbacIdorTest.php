<?php

namespace Tests\Feature\Integration;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 2 — RBAC e IDOR.
 *
 * Verifica que:
 * - Cada rol solo accede a lo que le corresponde.
 * - No hay fugas de datos entre tenants ni entre usuarios del mismo tenant.
 */
class Level2_RbacIdorTest extends TestCase
{
    use ApiAuth;

    // -------------------------------------------------------------------------
    // IDOR — aislamiento entre usuarios del mismo tenant
    // -------------------------------------------------------------------------

    public function test_student_cannot_see_another_students_attempt(): void
    {
        $institution = Institution::factory()->create();

        $studentUserA = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUserA->id, 'institution_id' => $institution->id]);

        $studentUserB = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUserB->id, 'institution_id' => $institution->id]);

        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam    = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        // Intento de A
        $attempt = ExamAttempt::factory()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUserA->id,
        ]);

        // B intenta ver el intento de A
        $this->actingAs($studentUserB, 'sanctum');
        $res = $this->getJson("/api/exams/{$exam->id}/attempts/{$attempt->id}");

        $res->assertStatus(404);
    }

    public function test_student_cannot_submit_another_students_attempt(): void
    {
        $institution = Institution::factory()->create();

        $studentUserA = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUserA->id, 'institution_id' => $institution->id]);

        $studentUserB = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUserB->id, 'institution_id' => $institution->id]);

        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam    = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUserA->id,
        ]);

        // B intenta enviar el intento de A
        $this->actingAs($studentUserB, 'sanctum');
        $res = $this->postJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/submit", [
            'answers' => [],
        ]);

        $res->assertStatus(404);
    }

    public function test_teacher_cannot_edit_another_teachers_exam(): void
    {
        $institution = Institution::factory()->create();

        $teacherA = $this->signInTeacher(['institution_id' => $institution->id]);
        $teacherB = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $examOfA = Exam::factory()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacherA->id,
        ]);

        // Teacher B intenta editar el examen de A
        $this->actingAs($teacherB, 'sanctum');
        $res = $this->putJson("/api/exams/{$examOfA->id}", ['title' => 'Hack']);

        $res->assertStatus(403);
    }

    public function test_teacher_can_only_review_answers_of_their_own_exams(): void
    {
        $institution = Institution::factory()->create();

        $teacherA = $this->signInTeacher(['institution_id' => $institution->id]);
        $teacherB = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $examOfA = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacherA->id,
        ]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $examOfA->id,
            'student_user_id' => $studentUser->id,
        ]);

        $question = \App\Models\Exams\Question::factory()->shortAnswer()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $examOfA->id,
        ]);

        $answer = \App\Models\Students\StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id'     => $attempt->id,
            'question_id'    => $question->id,
        ]);

        // Teacher B (no dueño del examen) intenta revisar respuesta
        $this->actingAs($teacherB, 'sanctum');
        $res = $this->patchJson("/api/student-answers/{$answer->id}/review", [
            'is_correct'     => true,
            'points_awarded' => 5,
        ]);

        $res->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // RBAC — restricciones por rol
    // -------------------------------------------------------------------------

    public function test_student_cannot_create_exam(): void
    {
        $institution = Institution::factory()->create();
        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/exams', [
            'title'            => 'Examen trampa',
            'subject_id'       => $subject->id,
            'grade'            => 7,
            'duration_minutes' => 30,
        ]);

        $res->assertStatus(403);
    }

    public function test_student_cannot_access_admin_user_list(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $this->getJson('/api/users')->assertStatus(403);
    }

    public function test_student_cannot_access_system_config_write(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $this->putJson('/api/system/config', ['timezone' => 'America/New_York'])->assertStatus(403);
    }

    public function test_teacher_cannot_write_system_config(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $this->putJson('/api/system/config', ['timezone' => 'America/New_York'])->assertStatus(403);
    }

    public function test_teacher_can_read_system_config(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $this->getJson('/api/system/config')->assertOk();
    }

    public function test_admin_can_read_and_write_system_config(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->getJson('/api/system/config')->assertOk();
        $this->putJson('/api/system/config', ['language' => 'en'])->assertOk();
    }

    // -------------------------------------------------------------------------
    // Aislamiento de tenant — datos de institución A no visibles desde B
    // -------------------------------------------------------------------------

    public function test_cross_tenant_exam_not_accessible(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();

        $teacherA = User::factory()->teacher()->create(['institution_id' => $institutionA->id]);
        $examA    = Exam::factory()->create([
            'institution_id'        => $institutionA->id,
            'created_by_teacher_id' => $teacherA->id,
        ]);

        // Teacher de B intenta ver examen de A
        $this->signInTeacher(['institution_id' => $institutionB->id]);

        $res = $this->getJson("/api/exams/{$examA->id}");
        // TenantScoped filtra por institution_id → 404
        $res->assertStatus(404);
    }

    public function test_cross_tenant_student_not_accessible(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();

        $studentUserA = User::factory()->student()->create(['institution_id' => $institutionA->id]);
        $studentA     = Student::factory()->create([
            'user_id'        => $studentUserA->id,
            'institution_id' => $institutionA->id,
        ]);

        // Teacher de B intenta ver estudiante de A
        $this->signInTeacher(['institution_id' => $institutionB->id]);

        $res = $this->getJson("/api/students/{$studentA->user_id}");
        $res->assertStatus(404);
    }
}
