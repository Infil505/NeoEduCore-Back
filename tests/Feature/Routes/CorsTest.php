<?php

namespace Tests\Feature\Routes;

use Tests\TestCase;

/**
 * CORS: el login "fallaba" desde el frontend de desarrollo (Vite, :5173) porque
 * solo se permitía http://localhost:3000. El navegador bloqueaba la respuesta y
 * la pantalla decía «revisa tus credenciales» sin que el servidor rechazara nada.
 */
class CorsTest extends TestCase
{
    private function preflight(string $origen)
    {
        return $this->call('OPTIONS', '/api/auth/login', [], [], [], [
            'HTTP_ORIGIN'                         => $origen,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
    }

    public function test_frontend_origins_can_be_a_comma_separated_list(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000', 'https://app.ejemplo.edu']]);

        $this->assertSame('https://app.ejemplo.edu', $this->preflight('https://app.ejemplo.edu')->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('http://localhost:3000', $this->preflight('http://localhost:3000')->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_an_origin_outside_the_list_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:3000']]);

        $this->assertNotSame('http://evil.example', $this->preflight('http://evil.example')->headers->get('Access-Control-Allow-Origin'));
    }
}
