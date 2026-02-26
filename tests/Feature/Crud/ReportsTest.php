<?php

namespace Tests\Feature\Crud;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class ReportsTest extends TestCase
{
    use ApiAuth;

    public function test_exam_results_report(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $student1 = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $student1->id,
            'institution_id' => $institution->id,
        ]);

        $student2 = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $student2->id,
            'institution_id' => $institution->id,
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $student1->id,
            'score' => 80,
            'max_score' => 100,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $student2->id,
            'score' => 70,
            'max_score' => 100,
        ]);

        $res = $this->getJson("/api/reports/exams/{$exam->id}/results");

        $res->assertOk();
    }

    public function test_exam_results_csv_export(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $student = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $student->id,
            'institution_id' => $institution->id,
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $student->id,
        ]);

        $res = $this->getJson("/api/reports/exams/{$exam->id}/results.csv");

        $res->assertOk();
        // Verificar que es CSV
        $this->assertTrue(
            str_contains($res->headers->get('content-type'), 'text/csv') ||
            str_contains($res->headers->get('content-type'), 'application/csv')
        );
    }

    public function test_student_history_report(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        ExamAttempt::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $res = $this->getJson("/api/reports/students/{$studentUser->id}/history");

        $res->assertOk();
    }
}
