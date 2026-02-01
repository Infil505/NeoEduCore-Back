<?php

namespace Tests\Feature\Routes;

use Tests\TestCase;

class PublicRoutesTest extends TestCase
{
    public function test_ping_works(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_public_endpoints_exist_not_404(): void
    {
        // Nota: algunos pueden devolver 422 por validación si faltan datos, y eso está bien.
        $endpoints = [
            ['POST', '/api/ai/generate'],
            ['POST', '/api/register'],
            ['POST', '/api/auth/login'],
            ['POST', '/api/password/forgot'],
            ['POST', '/api/password/verify'],
            ['POST', '/api/password/reset'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $res = $this->json($method, $uri, []);
            $this->assertNotEquals(404, $res->status(), "Endpoint missing (404): {$method} {$uri}");
        }
    }
}