<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\User;
use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginRegisterTest extends TestCase
{
    use ApiAuth;

    public function test_register_success(): void
    {
        $institution = Institution::factory()->create();
        $admin = $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/register', [
            'full_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'user_type' => 'teacher',
        ]);

        $res->assertStatus(201);
        // El usuario se crea SIEMPRE en la institución del admin
        $this->assertDatabaseHas('users', [
            'email' => 'juan@example.com',
            'full_name' => 'Juan Pérez',
            'institution_id' => $institution->id,
            'user_type' => 'teacher',
        ]);
    }

    public function test_register_requires_admin(): void
    {
        // Un teacher autenticado NO puede dar de alta usuarios
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/register', [
            'full_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $res->assertStatus(403);
    }

    public function test_register_requires_auth(): void
    {
        // Sin autenticación → 401 (ya no es público)
        $res = $this->postJson('/api/register', [
            'full_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $res->assertStatus(401);
    }

    public function test_register_validation_fails_weak_password(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/register', [
            'full_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'password' => '123',
            'password_confirmation' => '123',
        ]);

        $res->assertStatus(422);
    }

    public function test_register_validation_fails_password_mismatch(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/register', [
            'full_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass123!',
        ]);

        $res->assertStatus(422);
    }

    public function test_login_success(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'usuario@mail.com',
            'password_hash' => Hash::make('SecurePass123!'),
            'user_type' => 'student',
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email' => 'usuario@mail.com',
            'password' => 'SecurePass123!',
        ]);

        $res->assertOk();
        $this->assertNotNull($res->json('token') ?? $res->json('access_token'));
    }

    public function test_login_invalid_credentials(): void
    {
        Institution::factory()->create();
        User::factory()->create([
            'email' => 'invalid_test@mail.com',
            'password_hash' => Hash::make('SecurePass123!'),
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email' => 'invalid_test@mail.com',
            'password' => 'WrongPassword',
        ]);

        $res->assertStatus(401);
    }

    public function test_registering_a_student_requires_an_aula(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $this->postJson('/api/register', [
            'full_name' => 'Ana Solís',
            'email' => 'ana.sinaula@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'user_type' => 'student',
        ])->assertStatus(422)->assertJsonValidationErrors('group_id');

        $this->assertDatabaseMissing('users', ['email' => 'ana.sinaula@example.com']);
    }

    public function test_registering_a_student_enrolls_them_in_the_aula(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $aula = Group::factory()->create([
            'institution_id' => $institution->id,
            'group_code'     => '7A2026',
            'grade'          => 7,
            'section'        => 'A',
        ]);

        $res = $this->postJson('/api/register', [
            'full_name' => 'Ana Solís',
            'email' => 'ana.solis@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'user_type' => 'student',
            'group_id' => $aula->id,
            'student_code' => 'EST-0100',
            'parent_name' => 'Lucía Solís',
            'adecuacion_type' => 'acceso',
        ]);

        $res->assertStatus(201);
        $userId = $res->json('user.id');

        $this->assertDatabaseHas('students', [
            'user_id'         => $userId,
            'student_code'    => 'EST-0100',
            'grade'           => 7,
            'section'         => 'A',
            'parent_name'     => 'Lucía Solís',
            'adecuacion_type' => 'acceso',
        ]);
        $this->assertSame(1, DB::table('group_students')->where('group_id', $aula->id)->where('student_user_id', $userId)->whereNull('left_at')->count());
        $this->assertSame(1, (int) $aula->fresh()->student_count);
    }

    public function test_registering_a_student_in_another_institutions_aula_fails(): void
    {
        $institution = Institution::factory()->create();
        $otra = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        $ajena = Group::factory()->create(['institution_id' => $otra->id, 'group_code' => 'AJENA2026']);

        $this->postJson('/api/register', [
            'full_name' => 'Ana Solís',
            'email' => 'ana.ajena@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'user_type' => 'student',
            'group_id' => $ajena->id,
        ])->assertStatus(422)->assertJsonValidationErrors('group_id');
    }
}
