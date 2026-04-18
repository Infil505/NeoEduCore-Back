<?php

namespace Tests\Feature\Crud;

use App\Models\Academic\Group;
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
        $this->signInTeacher(['institution_id' => $institution->id]);

        $group = Group::factory()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson("/api/groups/{$group->id}");

        $res->assertOk();
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
