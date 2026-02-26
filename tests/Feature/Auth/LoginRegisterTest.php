<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\User;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;

class LoginRegisterTest extends TestCase
{
    public function test_register_success(): void
    {
        $institution = Institution::factory()->create();

        $res = $this->postJson('/api/register', [
            'institution_id' => $institution->id,
            'full_name' => 'Juan Pérez',
            'email' => 'juan@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'juan@example.com',
            'full_name' => 'Juan Pérez',
        ]);
    }

    public function test_register_validation_fails_weak_password(): void
    {
        $institution = Institution::factory()->create();

        $res = $this->postJson('/api/register', [
            'institution_id' => $institution->id,
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

        $res = $this->postJson('/api/register', [
            'institution_id' => $institution->id,
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
            'email' => 'usuario@mail.com',
            'password_hash' => Hash::make('SecurePass123!'),
        ]);

        $res = $this->postJson('/api/auth/login', [
            'email' => 'usuario@mail.com',
            'password' => 'WrongPassword',
        ]);

        $res->assertStatus(401);
    }
}
