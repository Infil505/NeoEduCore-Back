<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La consulta previa de CORS (OPTIONS) se pide ANTES de cada petición con
 * `Authorization` si el navegador no puede recordarla. Con una conexión mala son
 * dos viajes por llamada: la respuesta debe decir cuánto se puede guardar.
 */
class CorsPreflightTest extends TestCase
{
    private function preflight(string $origen)
    {
        return $this->call('OPTIONS', '/api/subjects', [], [], [], [
            'HTTP_ORIGIN'                         => $origen,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD'  => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ]);
    }

    public function test_el_navegador_puede_recordar_la_consulta_previa(): void
    {
        $origen = config('cors.allowed_origins')[0];

        $respuesta = $this->preflight($origen);

        $this->assertSame($origen, $respuesta->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('7200', $respuesta->headers->get('Access-Control-Max-Age'));
    }

    public function test_un_origen_ajeno_sigue_sin_permiso(): void
    {
        $ajeno = 'https://sitio-malicioso.example';

        $permitido = $this->preflight($ajeno)->headers->get('Access-Control-Allow-Origin');

        // Nunca se refleja el origen ajeno ni se abre a todos: el navegador lo rechaza.
        $this->assertNotSame($ajeno, $permitido);
        $this->assertNotSame('*', $permitido);
    }

    public function test_la_consulta_previa_no_toca_la_base_de_datos(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->preflight(config('cors.allowed_origins')[0]);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $consultas);
    }
}
