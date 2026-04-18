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

    public function test_create_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/subjects', [
            'name' => 'Matemáticas',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('subjects', [
            'name' => 'Matemáticas',
            'institution_id' => $institution->id,
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

    public function test_update_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

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

    public function test_delete_subject(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->deleteJson("/api/subjects/{$subject->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('subjects', [
            'id' => $subject->id,
        ]);
    }
}
