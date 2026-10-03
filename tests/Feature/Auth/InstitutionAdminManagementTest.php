<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordSetupMail;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Gestión de los administradores de centro desde el superadmin: listar con
 * filtros, ver, editar (incluido el traslado de centro), cambiar el estado y
 * reenviar el enlace de contraseña.
 *
 * `SuperAdminScopeTest` fija lo que el superadmin NO puede hacer; aquí se
 * prueba que lo que sí puede hacer funciona y no se sale de los admins.
 */
class InstitutionAdminManagementTest extends TestCase
{
    use ApiAuth;

    public function test_listing_filters_by_institution_status_and_text(): void
    {
        $this->signInSuperAdmin();

        $centroA = Institution::factory()->create();
        $centroB = Institution::factory()->create();

        // La base de tests acumula filas entre tests: la búsqueda por texto es
        // global, así que se busca una marca que no puede existir en otra fila.
        $marca = 'zq' . strtolower(\Illuminate\Support\Str::random(8));

        $ana   = User::factory()->admin()->create(['institution_id' => $centroA->id, 'full_name' => 'Ana Directora', 'email' => "ana.{$marca}@centro-a.com", 'status' => 'active']);
        $bruno = User::factory()->admin()->create(['institution_id' => $centroA->id, 'full_name' => 'Bruno Jefe', 'email' => 'bruno@centro-a.com', 'status' => 'suspended']);
        $carla = User::factory()->admin()->create(['institution_id' => $centroB->id, 'full_name' => "Carla Rectora {$marca}", 'email' => 'carla.' . $marca . '@centro-b.com', 'status' => 'active']);

        $ids = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data.data'))->pluck('id')->all();

        $porCentro = $ids("/api/institution-admins?institution_id={$centroA->id}");
        $this->assertEqualsCanonicalizing([$ana->id, $bruno->id], $porCentro);

        $this->assertSame([$bruno->id], $ids("/api/institution-admins?institution_id={$centroA->id}&status=suspended"));

        // Búsqueda sin distinguir mayúsculas, por nombre o por correo.
        $this->assertSame([$carla->id], $ids('/api/institution-admins?q=RECTORA%20' . strtoupper($marca)));
        $this->assertSame([$ana->id], $ids("/api/institution-admins?q=ana.{$marca}"));

        $this->getJson('/api/institution-admins?status=inventado')->assertStatus(422);
    }

    public function test_show_returns_the_admin_with_its_institution(): void
    {
        $this->signInSuperAdmin();

        $centro = Institution::factory()->create();
        $admin  = User::factory()->admin()->create(['institution_id' => $centro->id]);

        $this->getJson("/api/institution-admins/{$admin->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.institution.id', $centro->id)
            ->assertJsonMissingPath('data.password_hash');
    }

    public function test_update_normalizes_the_data_and_can_move_the_admin_to_another_institution(): void
    {
        $this->signInSuperAdmin();

        $origen  = Institution::factory()->create();
        $destino = Institution::factory()->create();
        $admin   = User::factory()->admin()->create(['institution_id' => $origen->id]);

        $this->putJson("/api/institution-admins/{$admin->id}", [
            'full_name'      => '  Directora Nueva  ',
            'email'          => 'NUEVA@Centro.COM',
            'institution_id' => $destino->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Directora Nueva')
            ->assertJsonPath('data.email', 'nueva@centro.com')
            ->assertJsonPath('data.institution.id', $destino->id);

        // El rol no cambia desde aquí.
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'user_type' => 'admin', 'institution_id' => $destino->id]);
    }

    public function test_update_rejects_an_email_already_in_use_and_an_unknown_institution(): void
    {
        $this->signInSuperAdmin();

        $centro = Institution::factory()->create();
        $admin  = User::factory()->admin()->create(['institution_id' => $centro->id]);
        $otro   = User::factory()->teacher()->create(['institution_id' => $centro->id, 'email' => 'ocupado@centro.com']);

        $this->putJson("/api/institution-admins/{$admin->id}", ['email' => $otro->email])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->putJson("/api/institution-admins/{$admin->id}", ['institution_id' => '00000000-0000-4000-8000-000000000000'])
            ->assertStatus(422)->assertJsonValidationErrors('institution_id');

        // Su propio correo no cuenta como duplicado.
        $this->putJson("/api/institution-admins/{$admin->id}", ['email' => $admin->email])->assertOk();
    }

    public function test_status_can_be_changed_and_only_to_a_valid_value(): void
    {
        $this->signInSuperAdmin();

        $admin = User::factory()->admin()->create([
            'institution_id' => Institution::factory()->create()->id,
            'status'         => 'active',
        ]);

        $this->patchJson("/api/institution-admins/{$admin->id}/status", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->patchJson("/api/institution-admins/{$admin->id}/status", ['status' => 'borrado'])
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'suspended']);
    }

    public function test_reset_password_sends_the_setup_link_and_never_returns_a_credential(): void
    {
        Mail::fake();
        $this->signInSuperAdmin();

        $admin = User::factory()->admin()->create(['institution_id' => Institution::factory()->create()->id]);

        $res = $this->patchJson("/api/institution-admins/{$admin->id}/reset-password")
            ->assertOk()
            ->assertJsonPath('data.correo_enviado', true);

        $this->assertSame(['correo_enviado'], array_keys($res->json('data')));
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $admin->email)->count());

        Mail::assertQueued(PasswordSetupMail::class, fn ($m) => $m->hasTo($admin->email));
    }

    public function test_reset_password_does_not_work_on_a_non_admin(): void
    {
        Mail::fake();
        $this->signInSuperAdmin();

        $docente = User::factory()->teacher()->create(['institution_id' => Institution::factory()->create()->id]);

        $this->patchJson("/api/institution-admins/{$docente->id}/reset-password")->assertNotFound();
        $this->patchJson("/api/institution-admins/{$docente->id}/status", ['status' => 'suspended'])->assertNotFound();

        Mail::assertNothingQueued();
    }
}
