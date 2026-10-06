<?php

namespace Tests\Feature\Crud;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class ExamAttemptsTest extends TestCase
{
    use ApiAuth;

    public function test_start_exam_attempt(): void
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

        // Cambiar a estudiante
        $this->actingAs($studentUser, 'sanctum');

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'status' => 'active',
        ]);

        // El examen llega a un aula donde el alumno está matriculado.
        $aula = \App\Models\Academic\Group::factory()->create(['institution_id' => $institution->id]);
        $exam->syncGroups([$aula->id]);
        $this->matricularEnGrupo($studentUser->id, $aula->id, $institution->id);

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/start");

        $res->assertCreated();
        $this->assertDatabaseHas('exam_attempts', [
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);
    }

    /**
     * Sin estar matriculado en un aula a la que se envió el examen no se puede
     * empezar, aunque esté activo y se conozca su id: ni de otra aula, ni sin
     * aula, ni habiendo dejado la que era. 404 y no 403: no se confirma que exista.
     */
    public function test_student_cannot_start_an_exam_of_another_aula_or_after_leaving_it(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'status' => 'active',
        ]);
        $suya  = \App\Models\Academic\Group::factory()->create(['institution_id' => $institution->id]);
        $otra  = \App\Models\Academic\Group::factory()->create(['institution_id' => $institution->id]);
        $this->matricularEnGrupo($studentUser->id, $suya->id, $institution->id);

        $this->actingAs($studentUser, 'sanctum');

        // El examen es de otra aula.
        $exam->syncGroups([$otra->id]);
        $this->postJson("/api/exams/{$exam->id}/attempts/start")->assertNotFound();

        // Ahora sí es de su aula...
        $exam->syncGroups([$suya->id]);
        $this->postJson("/api/exams/{$exam->id}/attempts/start")->assertCreated();

        // ...pero si deja el aula, el siguiente intento (otro examen igual) ya no.
        $otroExamen = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'status' => 'active',
        ]);
        $otroExamen->syncGroups([$suya->id]);
        \Illuminate\Support\Facades\DB::table('group_students')
            ->where('student_user_id', $studentUser->id)->update(['left_at' => now()]);

        $this->postJson("/api/exams/{$otroExamen->id}/attempts/start")->assertNotFound();
        $this->getJson("/api/exams/{$otroExamen->id}")->assertNotFound();
    }

    public function test_submit_exam_attempt(): void
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

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/submit", [
            'answers' => [],
        ]);

        $res->assertOk();
    }

    public function test_show_exam_attempt(): void
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

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson("/api/exams/{$exam->id}/attempts/{$attempt->id}");

        $res->assertOk();
    }
}
