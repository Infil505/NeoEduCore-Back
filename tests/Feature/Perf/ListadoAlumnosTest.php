<?php

namespace Tests\Feature\Perf;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `GET /api/students` trae la página Y el total en UNA consulta (`COUNT(*) OVER ()`).
 * Con la base remota cada viaje cuesta ~0,4 s: el `count` aparte duplicaba la espera
 * del listado más grande del sistema (≈2 s con el resto de la petición).
 */
class ListadoAlumnosTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $this->centro->id]);

        foreach (range(1, 7) as $_) {
            $u = User::factory()->create(['institution_id' => $this->centro->id, 'user_type' => 'student']);
            Student::factory()->create(['user_id' => $u->id, 'institution_id' => $this->centro->id]);
        }
    }

    private function consultas(callable $peticion): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $peticion();

        return $n;
    }

    public function test_pagina_llena_y_total_salen_de_una_sola_consulta(): void
    {
        // Página llena (3 de 7): antes obligaba a un `count` aparte.
        // La primera petición paga el arranque en frío (token, caché); se mide la segunda.
        $this->getJson('/api/students?per_page=3')->assertOk();

        $n = $this->consultas(fn () => $this->getJson('/api/students?per_page=3')->assertOk()
            ->assertJsonPath('data.total', 7)
            ->assertJsonCount(3, 'data.data'));

        $this->assertSame(1, $n, 'La página y el total deben salir de una sola consulta.');
    }

    public function test_la_columna_auxiliar_no_llega_al_cliente(): void
    {
        $fila = $this->getJson('/api/students?per_page=3')->assertOk()->json('data.data.0');

        $this->assertArrayNotHasKey('total_en_linea', $fila);
        $this->assertArrayHasKey('user', $fila);
        $this->assertArrayNotHasKey('user__id', $fila);
    }

    public function test_pedir_una_pagina_fuera_de_rango_sigue_dando_el_total(): void
    {
        $this->getJson('/api/students?per_page=3&page=99')->assertOk()
            ->assertJsonPath('data.total', 7)
            ->assertJsonCount(0, 'data.data');
    }

    public function test_las_paginas_siguientes_conservan_el_total(): void
    {
        $this->getJson('/api/students?per_page=3&page=3')->assertOk()
            ->assertJsonPath('data.total', 7)
            ->assertJsonCount(1, 'data.data');
    }
}
