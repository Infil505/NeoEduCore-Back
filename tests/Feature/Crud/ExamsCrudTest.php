<?php

namespace Tests\Feature\Crud;

use App\Models\Exams\Exam;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Enums\ExamStatus;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class ExamsCrudTest extends TestCase
{
    use ApiAuth;

    public function test_list_exams(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $res = $this->getJson('/api/exams');

        $res->assertOk();
    }

    public function test_create_exam(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->postJson('/api/exams', [
            'title' => 'Parcial 1 - Matemáticas',
            'subject_id' => $subject->id,
            'grade' => 10,
            'instructions' => 'Responder todas las preguntas',
            'duration_minutes' => 60,
            'status' => 'draft',
            'max_attempts' => 2,
            'show_results_immediately' => true,
            'allow_review_after_submission' => true,
            'randomize_questions' => false,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('exams', [
            'title' => 'Parcial 1 - Matemáticas',
            'subject_id' => $subject->id,
            'institution_id' => $institution->id,
        ]);
    }

    public function test_show_exam(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $res = $this->getJson("/api/exams/{$exam->id}");

        $res->assertOk();
    }

    public function test_update_exam(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
            'title' => 'Examen Original',
            'status' => 'draft',
        ]);

        $res = $this->putJson("/api/exams/{$exam->id}", [
            'title' => 'Examen Actualizado',
            'status' => 'published',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('exams', [
            'id' => $exam->id,
            'title' => 'Examen Actualizado',
        ]);
    }

    public function test_delete_exam(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $res = $this->deleteJson("/api/exams/{$exam->id}");

        $res->assertNoContent();
    }
}
