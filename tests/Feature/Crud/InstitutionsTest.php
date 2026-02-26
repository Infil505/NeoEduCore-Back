<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class InstitutionsTest extends TestCase
{
    use ApiAuth;

    public function test_list_institutions(): void
    {
        $this->signInAdmin();

        Institution::factory()->create();

        $res = $this->getJson('/api/institutions');

        $res->assertOk();
    }

    public function test_show_institution(): void
    {
        $this->signInAdmin();

        $institution = Institution::factory()->create();

        $res = $this->getJson("/api/institutions/{$institution->id}");

        $res->assertOk();
    }

    public function test_update_institution(): void
    {
        $this->signInAdmin();

        $institution = Institution::factory()->create([
            'name' => 'Institución Original',
        ]);

        $res = $this->putJson("/api/institutions/{$institution->id}", [
            'name' => 'Institución Actualizada',
            'code' => 'INST001',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('institutions', [
            'id' => $institution->id,
            'name' => 'Institución Actualizada',
        ]);
    }

    public function test_toggle_institution_status(): void
    {
        $this->signInAdmin();

        $institution = Institution::factory()->create([
            'is_active' => true,
        ]);

        $res = $this->patchJson("/api/institutions/{$institution->id}/toggle");

        $res->assertOk();
        $this->assertDatabaseHas('institutions', [
            'id' => $institution->id,
            'is_active' => false,
        ]);
    }
}
