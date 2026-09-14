<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\User;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class UsersTest extends TestCase
{
    use ApiAuth;

    public function test_list_users(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->signInAdmin(['institution_id' => $institution->id]);

        User::factory()->teacher()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson('/api/users');

        $res->assertOk();
    }

    public function test_show_user(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create([
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson("/api/users/{$teacher->id}");

        $res->assertOk();
    }

    public function test_update_user(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create([
            'institution_id' => $institution->id,
            'full_name' => 'Juan Original',
        ]);

        $res = $this->putJson("/api/users/{$teacher->id}", [
            'full_name' => 'Juan Actualizado',
            'email' => 'juan.nuevo@mail.com',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'full_name' => 'Juan Actualizado',
        ]);
    }

    public function test_set_user_status(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create([
            'institution_id' => $institution->id,
            'status' => 'active',
        ]);

        $res = $this->patchJson("/api/users/{$teacher->id}/status", [
            'status' => 'inactive',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $teacher->id,
            'status' => 'inactive',
        ]);
    }

    public function test_reset_user_password(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create([
            'institution_id' => $institution->id,
            'status'         => 'active',
        ]);

        $res = $this->patchJson("/api/users/{$teacher->id}/reset-password", [
            'password'              => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.can_sign_in', true);
        $res->assertJsonPath('data.activation_needed', false);
    }

    /**
     * Decisión D6. El reset del administrador **no activa la cuenta**: activar
     * exige que el titular defina contraseña desde el enlace que recibe por
     * correo, porque eso prueba que controla ese buzón.
     *
     * Lo que se comprueba aquí es que la respuesta lo **diga**. Antes devolvía
     * un escueto «contraseña actualizada» y el administrador entregaba una
     * contraseña con la que no se podía entrar, sin que nada lo advirtiera.
     */
    public function test_el_reset_no_activa_la_cuenta_y_la_respuesta_lo_advierte(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create([
            'institution_id' => $institution->id,
            'status'         => 'inactive',
        ]);

        $res = $this->patchJson("/api/users/{$teacher->id}/reset-password", [
            'password'              => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertOk();
        $res->assertJsonPath('data.status', 'inactive');
        $res->assertJsonPath('data.can_sign_in', false);
        $res->assertJsonPath('data.activation_needed', true);
        $this->assertStringContainsString('sigue sin activarse', $res->json('message'));

        // Y sigue sin poder entrar, que es lo que el aviso anuncia.
        $this->assertDatabaseHas('users', ['id' => $teacher->id, 'status' => 'inactive']);
    }
}
