<?php

namespace Tests\Traits;

use App\Models\User;
use App\Models\Institution;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

trait ApiAuth
{
    protected function signInTeacher(array $overrides = []): User
    {
        $institutionId = $overrides['institution_id'] ?? null;

        $institution = $institutionId
            ? Institution::query()->findOrFail($institutionId)
            : Institution::factory()->create();

        // ⚠️ Importante: si te pasan institution_id en overrides, lo respetamos
        $user = User::factory()->teacher()->create(array_merge([
            'institution_id' => $institution->id,
            'password_hash' => Hash::make('Abcdefg1'),
            'status' => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }

    protected function signInAdmin(array $overrides = []): User
    {
        $institutionId = $overrides['institution_id'] ?? null;

        $institution = $institutionId
            ? Institution::query()->findOrFail($institutionId)
            : Institution::factory()->create();

        $user = User::factory()->admin()->create(array_merge([
            'institution_id' => $institution->id,
            'password_hash' => Hash::make('Abcdefg1'),
            'status' => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }

    protected function signInStudent(array $overrides = []): User
    {
        $institutionId = $overrides['institution_id'] ?? null;

        $institution = $institutionId
            ? Institution::query()->findOrFail($institutionId)
            : Institution::factory()->create();

        $user = User::factory()->student()->create(array_merge([
            'institution_id' => $institution->id,
            'password_hash' => Hash::make('Abcdefg1'),
            'status' => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }
}