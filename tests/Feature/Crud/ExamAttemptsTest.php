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

        $res = $this->postJson("/api/exams/{$exam->id}/attempts/start");

        $res->assertCreated();
        $this->assertDatabaseHas('exam_attempts', [
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);
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
