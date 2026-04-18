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
}
