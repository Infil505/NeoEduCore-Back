<?php

namespace Tests\Feature\Crud;

use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Enums\QuestionType;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class QuestionsCrudTest extends TestCase
{
    use ApiAuth;

    public function test_list_questions(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
        ]);

        $res = $this->getJson("/api/exams/{$exam->id}/questions");

        $res->assertOk();
    }

    public function test_create_question(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $res = $this->postJson("/api/exams/{$exam->id}/questions", [
            'question_text' => '¿Cuál es la capital de Costa Rica?',
            'question_type' => 'multiple_choice',
            'points' => 2,
            'order_index' => 1,
            'options' => [
                ['option_text' => 'San José', 'is_correct' => true],
                ['option_text' => 'Limón', 'is_correct' => false],
                ['option_text' => 'Heredia', 'is_correct' => false],
            ]
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('questions', [
            'exam_id' => $exam->id,
            'question_text' => '¿Cuál es la capital de Costa Rica?',
        ]);
    }

    public function test_update_question(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $question = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
        ]);

        $res = $this->putJson("/api/questions/{$question->id}", [
            'question_text' => 'Pregunta Actualizada',
            'points' => 5,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('questions', [
            'id' => $question->id,
            'question_text' => 'Pregunta Actualizada',
        ]);
    }

    public function test_delete_question(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $question = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
        ]);

        $res = $this->deleteJson("/api/questions/{$question->id}");

        $res->assertNoContent();
    }
}
