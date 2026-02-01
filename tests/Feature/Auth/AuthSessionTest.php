<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use Tests\Traits\ApiAuth;

class AuthSessionTest extends TestCase
{
    use ApiAuth;

    public function test_me_returns_current_user(): void
    {
        $user = $this->signInTeacher();

        $res = $this->getJson('/api/auth/me');
        $res->assertOk();

        $json = $res->json();

        // Busca el id del usuario en distintas estructuras comunes
        $id =
            $json['id'] ??
            ($json['user']['id'] ?? null) ??
            ($json['data']['id'] ?? null) ??
            ($json['data']['user']['id'] ?? null);

        $this->assertNotNull($id, 'Response does not include a user id field.');
        $this->assertEquals((string) $user->id, (string) $id);
    }

    public function test_logout_works(): void
    {
        $this->signInTeacher();

        $res = $this->postJson('/api/auth/logout');

        // Normalmente 200 o 204 (según tu implementación)
        $this->assertTrue(
            in_array($res->status(), [200, 204]),
            'Logout must return 200 or 204.'
        );
    }
}