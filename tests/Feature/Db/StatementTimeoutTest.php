<?php

namespace Tests\Feature\Db;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `statement_timeout` corta las consultas que se pasan de tiempo.
 *
 * Es la red de seguridad por debajo de los rate limiters: estos acotan el NÚMERO
 * de peticiones, no lo que cuesta cada una. Sin timeout, una consulta pesada
 * retiene un worker de Octane indefinidamente, y con el techo de ~40 workers que
 * impone el presupuesto de conexiones a Supabase, es la vía más barata de tumbar
 * el servicio.
 */
class StatementTimeoutTest extends TestCase
{
    /**
     * En la suite el provider NO instala el listener (`runningInConsole()` es
     * true, para no cortar migraciones ni trabajos en cola), así que aquí se
     * reproduce lo que hace en una petición HTTP y se comprueba el efecto real
     * contra PostgreSQL.
     */
    public function test_a_query_that_exceeds_the_timeout_is_aborted(): void
    {
        DB::statement('SET statement_timeout = 300');

        try {
            $this->expectException(\Illuminate\Database\QueryException::class);

            // pg_sleep(3) supera con creces los 300 ms fijados
            DB::select('SELECT pg_sleep(3)');
        } finally {
            DB::statement('SET statement_timeout = 0');
        }
    }

    public function test_a_normal_query_is_not_affected(): void
    {
        DB::statement('SET statement_timeout = ' . config('database.statement_timeout_ms'));

        try {
            $this->assertSame(1, DB::select('SELECT 1 as n')[0]->n);
        } finally {
            DB::statement('SET statement_timeout = 0');
        }
    }

    /** El listener solo debe montarse en HTTP, nunca en consola. */
    public function test_the_listener_is_not_installed_in_console(): void
    {
        $this->assertTrue(
            $this->app->runningInConsole(),
            'La suite corre en consola, que es donde el timeout NO debe aplicarse'
        );

        // Sin listener, una consulta larga no se corta: es lo que protege a las
        // migraciones de morir a medias.
        $this->assertEmpty(
            $this->app['events']->getListeners(ConnectionEstablished::class),
            'No debería haber listener de ConnectionEstablished en consola'
        );
    }
}
