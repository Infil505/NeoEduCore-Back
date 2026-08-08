<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * CRUD de instituciones — reservado al superadmin desde el 08/08/2026.
 *
 * Antes vivía bajo `role:admin` y `index()` no filtraba por institución, así que
 * el administrador de un centro listaba **todos** los del SaaS con su código,
 * dirección y contacto. Ese es el caso que fija
 * `test_institution_admin_cannot_reach_any_institution_route`.
 */
class InstitutionsTest extends TestCase
{
    use ApiAuth;

    public function test_list_institutions(): void
    {
        $this->signInSuperAdmin();

        Institution::factory()->create();

        $this->getJson('/api/institutions')->assertOk();
    }

    public function test_create_institution(): void
    {
        $this->signInSuperAdmin();

        $res = $this->postJson('/api/institutions', [
            'code' => 'NUEVO01',
            'name' => 'Colegio Nuevo',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('institutions', ['name' => 'Colegio Nuevo']);
    }

    public function test_institution_code_must_be_unique(): void
    {
        $this->signInSuperAdmin();

        Institution::factory()->create(['code' => 'REPE01']);

        $this->postJson('/api/institutions', ['code' => 'REPE01', 'name' => 'Otro'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_show_institution(): void
    {
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();

        $this->getJson("/api/institutions/{$institution->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['institution', 'usuarios' => ['admin', 'teacher', 'student', 'total']]]);
    }

    public function test_update_institution(): void
    {
        $this->signInSuperAdmin();

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
        $this->signInSuperAdmin();

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

    /**
     * El hallazgo que motivó el rol: un admin de centro no debe ver el catálogo
     * de centros del SaaS, ni siquiera el suyo por esta vía.
     */
    public function test_institution_admin_cannot_reach_any_institution_route(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->getJson('/api/institutions')->assertForbidden();
        $this->getJson("/api/institutions/{$institution->id}")->assertForbidden();
        $this->putJson("/api/institutions/{$institution->id}", ['name' => 'Intento'])->assertForbidden();
        $this->patchJson("/api/institutions/{$institution->id}/toggle")->assertForbidden();
        $this->deleteJson("/api/institutions/{$institution->id}")->assertForbidden();
        $this->postJson('/api/institutions', ['code' => 'X', 'name' => 'X'])->assertForbidden();
    }

    public function test_teacher_and_student_cannot_reach_institution_routes(): void
    {
        $institution = Institution::factory()->create();

        $this->signInTeacher(['institution_id' => $institution->id]);
        $this->getJson('/api/institutions')->assertForbidden();

        $this->signInStudent(['institution_id' => $institution->id]);
        $this->getJson('/api/institutions')->assertForbidden();
    }

    /**
     * Borrado en cascada: decisión explícita del proyecto (08/08/2026).
     *
     * Las cuentas se borran **a mano** en el controlador porque
     * `users.institution_id` es `ON DELETE SET NULL`, no CASCADE: la cascada
     * sola las dejaría vivas y sin institución, es decir capaces de
     * autenticarse y con el mismo `institution_id = NULL` que marca al
     * superadmin.
     */
    public function test_deleting_an_institution_removes_its_users(): void
    {
        $this->signInSuperAdmin();

        $institution = Institution::factory()->create();
        $admin   = User::factory()->admin()->create(['institution_id' => $institution->id]);
        $docente = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $this->deleteJson("/api/institutions/{$institution->id}")->assertOk();

        $this->assertDatabaseMissing('institutions', ['id' => $institution->id]);
        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
        $this->assertDatabaseMissing('users', ['id' => $docente->id]);
    }

    /** Borrar un centro no puede tocar los datos de otro. */
    public function test_deleting_an_institution_leaves_other_institutions_intact(): void
    {
        $this->signInSuperAdmin();

        $victima = Institution::factory()->create();
        $vecina  = Institution::factory()->create();

        $usuarioVecino = User::factory()->admin()->create(['institution_id' => $vecina->id]);

        $this->deleteJson("/api/institutions/{$victima->id}")->assertOk();

        $this->assertDatabaseHas('institutions', ['id' => $vecina->id]);
        $this->assertDatabaseHas('users', ['id' => $usuarioVecino->id]);
    }
}
