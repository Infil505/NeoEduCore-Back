<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\Institution;
use App\Models\Academic\Group;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `?per_page=` en los listados que el frontend recorre completos.
 *
 * El frontend pide páginas de 100 para bajar un listado en pocas peticiones; con
 * la base remota cada petición cuesta segundos. Hasta el 09/10/2026 el backend
 * ignoraba el parámetro y devolvía siempre 20.
 */
class PaginacionTest extends TestCase
{
    use ApiAuth;

    private function centroConAulas(int $n): Institution
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);
        Group::factory()->count($n)->create(['institution_id' => $institution->id]);

        return $institution;
    }

    public function test_per_page_agranda_la_pagina(): void
    {
        $this->centroConAulas(30);

        $this->assertCount(20, $this->getJson('/api/groups')->assertOk()->json('data.data'));
        $this->assertCount(30, $this->getJson('/api/groups?per_page=100')->assertOk()->json('data.data'));
        $this->assertSame(1, $this->getJson('/api/groups?per_page=100')->json('data.last_page'));
    }

    public function test_per_page_se_acota_al_maximo(): void
    {
        $this->centroConAulas(3);

        $this->assertSame(100, $this->getJson('/api/groups?per_page=100000')->assertOk()->json('data.per_page'));
    }

    public function test_un_per_page_invalido_usa_el_valor_por_defecto_sin_error(): void
    {
        $this->centroConAulas(3);

        foreach (['abc', '0', '-5', '1.5', '', '[]'] as $valor) {
            $this->getJson('/api/groups?per_page=' . urlencode($valor))
                ->assertOk()
                ->assertJsonPath('data.per_page', 20);
        }
    }

    public function test_los_totales_son_los_de_paginate_aunque_no_se_cuente(): void
    {
        $this->centroConAulas(25);

        // Llena (hay que contar), parcial (el total se deduce) y más allá del final.
        $casos = [
            1 => ['filas' => 10, 'from' => 1,  'to' => 10],
            2 => ['filas' => 10, 'from' => 11, 'to' => 20],
            3 => ['filas' => 5,  'from' => 21, 'to' => 25],
            4 => ['filas' => 0,  'from' => null, 'to' => null],
        ];

        foreach ($casos as $pagina => $esperado) {
            $d = $this->getJson("/api/groups?per_page=10&page={$pagina}")->assertOk()->json('data');

            $this->assertCount($esperado['filas'], $d['data'], "página {$pagina}");
            $this->assertSame(25, $d['total'], "total en la página {$pagina}");
            $this->assertSame(3, $d['last_page'], "last_page en la página {$pagina}");
            $this->assertSame($pagina, $d['current_page']);
            $this->assertSame($esperado['from'], $d['from'], "from en la página {$pagina}");
            $this->assertSame($esperado['to'], $d['to'], "to en la página {$pagina}");
        }
    }

    public function test_un_listado_pequeno_no_ejecuta_la_consulta_de_conteo(): void
    {
        $this->centroConAulas(3);

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->getJson('/api/groups')->assertOk();
        $conteos = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'count(*)') && str_contains($q['query'], '"groups"'));
        \DB::disableQueryLog();

        $this->assertCount(0, $conteos, 'Un listado que cabe en una página no necesita COUNT.');
    }
}
