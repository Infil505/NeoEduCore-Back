<?php

namespace Tests\Feature\Integration;

use App\Models\Academic\Group;
use App\Models\Academic\StudentSubject;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 3 — Ciclo de vida del estudiante.
 *
 * Cubre: inscripción a grupos/materias, acceso a exámenes disponibles,
 * límite de intentos, StudentSubject.
 */
class Level3_StudentLifecycleTest extends TestCase
{
    use ApiAuth;

    // -------------------------------------------------------------------------
    // Grupos y exámenes disponibles
    // -------------------------------------------------------------------------

    public function test_student_in_group_sees_active_exam_in_available(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);
        $group->students()->attach($studentUser->id, ['joined_at' => now(), 'institution_id' => $institution->id]);

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);
        $exam->syncGroups([$group->id]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me/available-exams');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->toArray();
        $this->assertContains($exam->id, $ids);
    }

    public function test_student_not_in_group_does_not_see_exam(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create(['institution_id' => $institution->id]);

        // Estudiante SIN grupo
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);
        $exam->syncGroups([$group->id]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me/available-exams');
        $res->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($exam->id, $ids);
    }

    public function test_inactive_exam_not_in_available(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);
        $group->students()->attach($studentUser->id, ['joined_at' => now(), 'institution_id' => $institution->id]);

        // Examen en draft (no activo)
        $exam = Exam::factory()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'status'                => 'draft',
        ]);
        $exam->syncGroups([$group->id]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me/available-exams');
        $ids = collect($res->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($exam->id, $ids);
    }

    public function test_exam_not_shown_when_max_attempts_reached(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);
        $group->students()->attach($studentUser->id, ['joined_at' => now(), 'institution_id' => $institution->id]);

        $exam = Exam::factory()->active()->create([
            'institution_id'        => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'max_attempts'          => 1,
        ]);
        $exam->syncGroups([$group->id]);

        // Consumir el único intento
        ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me/available-exams');
        $ids = collect($res->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($exam->id, $ids);
    }

    // -------------------------------------------------------------------------
    // StudentSubject — inscripción explícita a materias
    // -------------------------------------------------------------------------

    public function test_teacher_can_enroll_student_in_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $res = $this->postJson("/api/students/{$studentUser->id}/subjects", [
            'subject_id' => $subject->id,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('student_subjects', [
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
        ]);
    }

    public function test_duplicate_enrollment_returns_409(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student     = Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        StudentSubject::create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
            'enrolled_at'     => now(),
        ]);

        $res = $this->postJson("/api/students/{$studentUser->id}/subjects", [
            'subject_id' => $subject->id,
        ]);

        $res->assertStatus(409);
    }

    public function test_teacher_can_unenroll_student(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student     = Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        StudentSubject::create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
            'enrolled_at'     => now(),
        ]);

        $res = $this->deleteJson("/api/students/{$studentUser->id}/subjects/{$subject->id}");

        $res->assertOk();
        $this->assertDatabaseMissing('student_subjects', [
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
        ]);
    }

    public function test_student_sees_their_own_subjects(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student     = Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        StudentSubject::create([
            'institution_id'  => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
            'enrolled_at'     => now(),
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me/subjects');
        $res->assertOk();

        $subjectIds = collect($res->json('data'))->pluck('subject_id')->toArray();
        $this->assertContains($subject->id, $subjectIds);
    }
}
