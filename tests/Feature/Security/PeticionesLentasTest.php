<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * El registro de peticiones lentas solo actúa si se activa (`SLOW_REQUEST_MS`), y
 * entonces dice cuánto fue base de datos y cuánto PHP.
 */
class PeticionesLentasTest extends TestCase
{
    public function test_desactivado_no_registra_nada(): void
    {
        config(['app.slow_request_ms' => 0]);
        Log::spy();

        $this->getJson('/api/ping')->assertOk();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_activado_registra_la_peticion_lenta_con_el_reparto_de_tiempo(): void
    {
        config(['app.slow_request_ms' => 1]); // cualquier petición supera 1 ms
        Log::spy();

        $this->getJson('/api/ping')->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return $mensaje === 'Petición lenta'
                && $contexto['ruta'] === 'GET /api/ping'
                && $contexto['estado'] === 200
                && isset($contexto['total_ms'], $contexto['base_ms'], $contexto['php_ms'], $contexto['consultas']);
        })->once();
    }

    /**
     * Regresión del 12/10/2026 (prueba de carga): un oyente de `DB::listen` por
     * petición sobrevivía en el worker de Octane y cada uno acumulaba las consultas de
     * todas las peticiones siguientes, hasta agotar los 128 MB y devolver 500.
     */
    public function test_no_acumula_oyentes_ni_consultas_entre_peticiones(): void
    {
        config(['app.slow_request_ms' => 600000]); // activo, pero nada se registra en el log
        Log::spy();

        $antes = count(app('events')->getListeners(\Illuminate\Database\Events\QueryExecuted::class));

        foreach (range(1, 6) as $_) {
            $this->getJson('/api/ping')->assertOk();
        }

        $despues = count(app('events')->getListeners(\Illuminate\Database\Events\QueryExecuted::class));

        $this->assertLessThanOrEqual($antes + 1, $despues, 'cada petición está registrando un oyente nuevo de DB::listen');

        // Y lo que se guardó de una petición no se arrastra a la siguiente.
        $buffer = (new \ReflectionClass(\App\Http\Middleware\RegistraPeticionesLentas::class))->getProperty('consultas');
        $this->assertSame([], $buffer->getValue());
    }

    public function test_una_peticion_rapida_bajo_el_umbral_no_se_registra(): void
    {
        config(['app.slow_request_ms' => 600000]);
        Log::spy();

        $this->getJson('/api/ping')->assertOk();

        Log::shouldNotHaveReceived('warning');
    }
}
