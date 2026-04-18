<?php

namespace Tests\Feature\Crud;

use App\Models\AI\AiRecommendation;
use App\Models\Academic\Subject;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class AiRecommendationsTest extends TestCase
{
    use ApiAuth;

    public function test_list_ai_recommendations(): void
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

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $res = $this->getJson('/api/ai-recommendations');

        $res->assertOk();
    }

    public function test_my_ai_recommendations(): void
    {
        $institution = Institution::factory()->create();
        
        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/ai-recommendations/me');

        $res->assertOk();
    }

    public function test_show_ai_recommendation(): void
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

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $recommendation = AiRecommendation::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $res = $this->getJson("/api/ai-recommendations/{$recommendation->id}");

        $res->assertOk();
    }

    public function test_regenerate_exam_attempt_recommendations(): void
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

        $attempt = \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id,
            'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson("/api/exam-attempts/{$attempt->id}/recommendations/regenerate");

        $res->assertSuccessful();
    }
}
