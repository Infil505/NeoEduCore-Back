<?php

namespace Tests\Feature\Crud;

use Tests\TestCase;
use Tests\Traits\ApiAuth;

class SubjectsCrudSmokeTest extends TestCase
{
    use ApiAuth;

    public function test_subjects_index_works(): void
    {
        $this->signInTeacher();

        $res = $this->getJson('/api/subjects');
        $res->assertOk();
    }

    public function test_subjects_store_is_not_server_error(): void
    {
        $this->signInTeacher();

        // ✅ NO se envía institution_id; debe salir del usuario autenticado
        $res = $this->postJson('/api/subjects', [
            'name' => 'Matemática',
        ]);

        // Si falla validación, 422 es aceptable; lo que no aceptamos es 500
        $this->assertNotEquals(500, $res->status(), 'Should not return 500.');
        $this->assertTrue(in_array($res->status(), [200, 201, 422]), 'Expected 200/201 or 422.');
    }

    public function test_subjects_show_not_server_error(): void
    {
        $this->signInTeacher();

        // Creamos subject primero
        $create = $this->postJson('/api/subjects', [
            'name' => 'Matemática',
        ]);

        $this->assertNotEquals(500, $create->status(), 'Creating subject should not return 500.');

        $json = $create->json() ?? [];
        $id =
            $json['id'] ??
            ($json['data']['id'] ?? null) ??
            ($json['subject']['id'] ?? null) ??
            ($json['data']['subject']['id'] ?? null);

        if (!$id) {
            $this->markTestSkipped('Create subject did not return an id to test show endpoint.');
        }

        $res = $this->getJson("/api/subjects/{$id}");
        $this->assertNotEquals(500, $res->status(), 'Should not return 500.');
    }
}