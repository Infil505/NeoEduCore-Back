<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `GET /up` es el healthcheck del despliegue (Coolify compara el cuerpo con «OK»).
 * Si cambia la respuesta, el contenedor pasa a «Degraded» y el proxy deja de enrutarlo.
 */
class SaludTest extends TestCase
{
    public function test_up_responde_ok_en_texto_plano_sin_autenticacion(): void
    {
        $respuesta = $this->get('/up');

        $respuesta->assertOk();
        $this->assertSame('OK', $respuesta->getContent());
        $this->assertStringStartsWith('text/plain', $respuesta->headers->get('Content-Type'));
    }

    public function test_up_no_hace_consultas_a_la_base_de_datos(): void
    {
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->get('/up')->assertOk();

        $this->assertCount(0, \Illuminate\Support\Facades\DB::getQueryLog());
    }
}
