<?php

namespace Tests\Feature\Crud;

use App\Models\Students\StudentProgress;
use App\Models\Academic\Subject;
use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class StudentProgressTest extends TestCase
{
    use ApiAuth;

    public function test_list_student_progress(): void
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

        StudentProgress::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $res = $this->getJson('/api/student-progress');

        $res->assertOk();
    }

    public function test_student_progress_me(): void
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

        StudentProgress::factory()->create([
            'institution_id' => $institution->id,
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/student-progress/me');

        $res->assertOk();
    }

    public function test_upsert_student_progress(): void
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

        $res = $this->postJson('/api/student-progress', [
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'mastery_percentage' => 85.50,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('student_progress', [
            'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id,
            'mastery_percentage' => 85.50,
        ]);
    }

    public function test_recalculate_progress_from_attempts(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/student-progress/recalc');

        $res->assertOk();
    }
}
