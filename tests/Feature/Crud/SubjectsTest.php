<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class SubjectsTest extends TestCase
{
    use ApiAuth;

    public function test_list_subjects(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson('/api/subjects');

        $res->assertOk();
    }

    public function test_list_subjects_can_be_filtered_by_search(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Matemáticas',
        ]);
        Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Historia',
        ]);

        $res = $this->getJson('/api/subjects?search=mate');

        $res->assertOk();
        $names = array_column($res->json('data.data'), 'name');

        $this->assertContains('Matemáticas', $names);
        $this->assertNotContains('Historia', $names);
    }

    public function test_create_subject_as_admin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/subjects', [
            'name' => 'Matemáticas',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('subjects', [
            'name' => 'Matemáticas',
            'institution_id' => $institution->id,
        ]);
    }

    public function test_teacher_cannot_create_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/subjects', [
            'name' => 'Química',
        ]);

        $res->assertForbidden();
        $this->assertDatabaseMissing('subjects', [
            'name' => 'Química',
        ]);
    }

    public function test_student_cannot_create_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInStudent(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/subjects', [
            'name' => 'Física',
        ]);

        $res->assertForbidden();
        $this->assertDatabaseMissing('subjects', [
            'name' => 'Física',
        ]);
    }

    public function test_show_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson("/api/subjects/{$subject->id}");

        $res->assertOk();
    }

    public function test_update_subject_as_admin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Inglés',
        ]);

        $res = $this->putJson("/api/subjects/{$subject->id}", [
            'name' => 'Inglés Avanzado',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'name' => 'Inglés Avanzado',
        ]);
    }

    public function test_teacher_cannot_rename_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Biología',
        ]);

        $res = $this->putJson("/api/subjects/{$subject->id}", [
            'name' => 'Biología Avanzada',
        ]);

        $res->assertForbidden();
        $this->assertDatabaseHas('subjects', [
            'id' => $subject->id,
            'name' => 'Biología',
        ]);
    }

    public function test_delete_subject_as_admin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->deleteJson("/api/subjects/{$subject->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('subjects', [
            'id' => $subject->id,
        ]);
    }

    public function test_teacher_cannot_delete_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->deleteJson("/api/subjects/{$subject->id}");

        $res->assertForbidden();
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    /* =========================
     |  Unicidad del nombre
     ========================= */

    public function test_cannot_create_two_subjects_with_the_same_name(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/subjects', ['name' => 'Matemática'])->assertCreated();

        $res = $this->postJson('/api/subjects', ['name' => 'Matemática']);

        $res->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(1, Subject::query()
            ->where('institution_id', $institution->id)
            ->where('name', 'Matemática')
            ->count());
    }

    public function test_duplicate_check_ignores_case_and_surrounding_spaces(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/subjects', ['name' => 'Química'])->assertCreated();

        $this->postJson('/api/subjects', ['name' => 'QUÍMICA'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson('/api/subjects', ['name' => '  química  '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
    }

    public function test_same_subject_for_different_grades_is_allowed(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/subjects', ['name' => 'Matemática 1er grado'])->assertCreated();
        $this->postJson('/api/subjects', ['name' => 'Matemática 2do grado'])->assertCreated();

        $this->assertSame(2, Subject::query()
            ->where('institution_id', $institution->id)
            ->where('name', 'ilike', 'Matemática%grado')
            ->count());
    }

    public function test_same_name_is_allowed_in_a_different_institution(): void
    {
        $otra = Institution::factory()->create();
        Subject::factory()->create([
            'institution_id' => $otra->id,
            'name' => 'Filosofía',
        ]);

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/subjects', ['name' => 'Filosofía'])->assertCreated();
    }

    public function test_cannot_rename_subject_onto_an_existing_name(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Arte',
        ]);
        $musica = Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Música',
        ]);

        $res = $this->putJson("/api/subjects/{$musica->id}", ['name' => 'arte']);

        $res->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('subjects', [
            'id' => $musica->id,
            'name' => 'Música',
        ]);
    }

    public function test_renaming_a_subject_to_its_own_name_is_allowed(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
            'name' => 'Geografía',
        ]);

        // Mismo nombre: no debe chocar consigo mismo
        $this->putJson("/api/subjects/{$subject->id}", ['name' => 'Geografía'])->assertOk();
    }
}
