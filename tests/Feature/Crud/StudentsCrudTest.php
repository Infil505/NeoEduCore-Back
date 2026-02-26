<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Illuminate\Support\Facades\Hash;

class StudentsCrudTest extends TestCase
{
    use ApiAuth;

    public function test_list_students(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        // Crear estudiantes
        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson('/api/students');

        $res->assertOk();
        // Verificar que devuelve una lista
        $this->assertTrue(is_array($res->json('data') ?? $res->json()));
    }

    public function test_show_student(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson("/api/students/{$student->user_id}");

        $res->assertOk();
        $this->assertNotNull($res->json('id') ?? $res->json('data.id'));
    }

    public function test_update_student(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $res = $this->putJson("/api/students/{$student->user_id}", [
            'full_name' => 'Juan Actualizado',
            'grade' => 11,
            'parent_name' => 'Nuevo Acudiente',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('students', [
            'user_id' => $studentUser->id,
            'grade' => 11,
        ]);
    }

    public function test_student_me_endpoint(): void
    {
        $institution = Institution::factory()->create();
        
        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        // Actuar como el estudiante
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me');

        $res->assertOk();
    }

    public function test_set_student_status(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $res = $this->patchJson("/api/students/{$student->user_id}/status", [
            'status' => 'inactive',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('students', [
            'user_id' => $studentUser->id,
            'status' => 'inactive',
        ]);
    }
}
