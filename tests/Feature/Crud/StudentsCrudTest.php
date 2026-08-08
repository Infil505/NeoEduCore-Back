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
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

        $res = $this->getJson("/api/students/{$student->user_id}");

        $res->assertOk();
        $this->assertNotNull($res->json('data.user_id') ?? $res->json('user_id'));
    }

    public function test_update_student(): void
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

        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

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

    /**
     * `student_code` es único por institución: `PUT /students/{id}` debe dar 422
     * y no un 500 al chocar contra la constraint. Antes no validaba nada.
     */
    public function test_update_rejects_a_student_code_already_used_in_the_institution(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInAdmin(['institution_id' => $institution->id]);

        $ocupadoUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id'        => $ocupadoUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-OCUPADO',
        ]);

        $otroUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $otro = Student::factory()->create([
            'user_id'        => $otroUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-LIBRE',
        ]);

        $this->putJson("/api/students/{$otro->user_id}", ['student_code' => 'EST-OCUPADO'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_code');

        // Conservar el propio código no es un duplicado de sí mismo.
        $this->putJson("/api/students/{$otro->user_id}", ['student_code' => 'EST-LIBRE'])
            ->assertOk();
    }

    /** El mismo código en otra institución no estorba. */
    public function test_update_allows_a_student_code_used_by_another_institution(): void
    {
        $otra = Institution::factory()->create();
        $ajenoUser = User::factory()->student()->create(['institution_id' => $otra->id]);
        Student::factory()->create([
            'user_id'        => $ajenoUser->id,
            'institution_id' => $otra->id,
            'student_code'   => 'EST-AJENO',
        ]);

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $mioUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $mio = Student::factory()->create([
            'user_id'        => $mioUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->putJson("/api/students/{$mio->user_id}", ['student_code' => 'EST-AJENO'])
            ->assertOk();
    }

    public function test_set_student_status(): void
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

        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

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
