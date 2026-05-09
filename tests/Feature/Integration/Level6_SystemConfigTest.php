<?php

namespace Tests\Feature\Integration;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 6 — Configuración del sistema.
 *
 * Verifica GET/PUT /api/system/config con defaults, persistencia de valores
 * y restricciones de rol.
 */
class Level6_SystemConfigTest extends TestCase
{
    use ApiAuth;

    public function test_admin_reads_default_config(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->getJson('/api/system/config');

        $res->assertOk();
        $res->assertJsonStructure([
            'data' => [
                'institution_id',
                'institution_name',
                'config' => [
                    'timezone',
                    'language',
                    'max_exam_duration',
                    'allow_registration',
                ],
            ],
        ]);

        // Valores por defecto
        $this->assertEquals('America/Costa_Rica', $res->json('data.config.timezone'));
        $this->assertEquals('es', $res->json('data.config.language'));
    }

    public function test_admin_updates_config_and_change_persists(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->putJson('/api/system/config', [
            'language'          => 'en',
            'max_exam_duration' => 120,
            'allow_registration' => false,
        ]);

        $res->assertOk();
        $this->assertEquals('en', $res->json('data.config.language'));
        $this->assertEquals(120, $res->json('data.config.max_exam_duration'));
        $this->assertFalse($res->json('data.config.allow_registration'));

        // Verificar que se guardó en BD
        $this->assertDatabaseHas('institutions', ['id' => $institution->id]);
        $fresh = Institution::find($institution->id);
        $this->assertEquals('en', $fresh->settings['language']);
    }

    public function test_partial_update_does_not_overwrite_other_keys(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        // Primer update: cambia language
        $this->putJson('/api/system/config', ['language' => 'en']);

        // Segundo update: cambia solo max_exam_duration
        $res = $this->putJson('/api/system/config', ['max_exam_duration' => 90]);
        $res->assertOk();

        // Language debe seguir siendo 'en'
        $this->assertEquals('en', $res->json('data.config.language'));
        $this->assertEquals(90, $res->json('data.config.max_exam_duration'));
    }

    public function test_invalid_timezone_rejected(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->putJson('/api/system/config', ['timezone' => 'Mordor/Shire']);
        $res->assertStatus(422);
    }

    public function test_invalid_language_rejected(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $res = $this->putJson('/api/system/config', ['language' => 'klingon']);
        $res->assertStatus(422);
    }

    public function test_teacher_can_read_but_not_update_config(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $this->getJson('/api/system/config')->assertOk();
        $this->putJson('/api/system/config', ['language' => 'en'])->assertStatus(403);
    }

    public function test_student_cannot_read_or_update_config(): void
    {
        $institution = Institution::factory()->create();
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $this->getJson('/api/system/config')->assertStatus(403);
        $this->putJson('/api/system/config', ['language' => 'en'])->assertStatus(403);
    }

    public function test_unauthenticated_cannot_access_config(): void
    {
        $this->getJson('/api/system/config')->assertStatus(401);
        $this->putJson('/api/system/config', [])->assertStatus(401);
    }
}
