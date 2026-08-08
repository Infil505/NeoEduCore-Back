<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class GroupsCrudTest extends TestCase
{
    use ApiAuth;

    public function test_list_groups(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        Group::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson('/api/groups');

        $res->assertOk();
    }

    public function test_create_group(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/groups', [
            'name' => '10-A',
            'grade' => 10,
            'section' => 'A',
            'year' => 2026,
            'group_code' => '10A2026',
            'student_count' => 0,
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('groups', [
            'name' => '10-A',
            'grade' => 10,
            'institution_id' => $institution->id,
        ]);
    }

    public function test_show_group(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create([
            'institution_id' => $institution->id,
        ]);

        // `show` devuelve la lista nominal del grupo, así que exige asignación.
        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $group->id, $subject->id);

        $res = $this->getJson("/api/groups/{$group->id}");

        $res->assertOk();
    }

    public function test_teacher_cannot_show_a_group_they_are_not_assigned_to(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $ajeno = Group::factory()->create(['institution_id' => $institution->id]);

        $this->getJson("/api/groups/{$ajeno->id}")->assertForbidden();
    }

    public function test_teacher_index_only_lists_assigned_groups(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $mio   = Group::factory()->create(['institution_id' => $institution->id]);
        $ajeno = Group::factory()->create(['institution_id' => $institution->id]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $mio->id, $subject->id);

        $res = $this->getJson('/api/groups')->assertOk();

        $ids = collect($res->json('data.data'))->pluck('id')->all();

        $this->assertContains($mio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    public function test_update_group(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->putJson("/api/groups/{$group->id}", [
            'name' => 'Grupo Actualizado',
            'grade' => 11,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('groups', [
            'id' => $group->id,
            'name' => 'Grupo Actualizado',
            'grade' => 11,
        ]);
    }

    public function test_delete_group(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->deleteJson("/api/groups/{$group->id}");

        $res->assertNoContent();
        $this->assertDatabaseMissing('groups', [
            'id' => $group->id,
        ]);
    }
}
