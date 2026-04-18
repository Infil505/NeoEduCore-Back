<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\User;
use App\Models\Admin\Institution;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class PasswordResetTest extends TestCase
{
    use ApiAuth;

    public function test_request_password_reset(): void
    {
        Mail::fake();

        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
            'email' => 'user@mail.com',
        ]);

        $res = $this->postJson('/api/password/forgot', [
            'email' => 'user@mail.com',
        ]);

        $res->assertOk();
    }

    public function test_verify_reset_token(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
        ]);

        // Crear token en la BD
        $token = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $res = $this->postJson('/api/password/verify', [
            'email' => $user->email,
            'token' => $token,
        ]);

        $res->assertOk();
    }

    public function test_reset_password(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->create([
            'institution_id' => $institution->id,
        ]);

        // Crear token en la BD
        $token = \Illuminate\Support\Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now(),
        ]);

        $res = $this->postJson('/api/password/reset', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertOk();
    }

    public function test_change_password_authenticated(): void
    {
        $institution = Institution::factory()->create();
        $user = $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/password/change', [
            'current_password' => 'Abcdefg1',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $res->assertOk();
    }
}
