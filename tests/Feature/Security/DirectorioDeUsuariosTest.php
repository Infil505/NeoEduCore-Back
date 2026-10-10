<?php

namespace Tests\Feature\Security;

use App\Models\Admin\Institution;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * `GET /api/users/directory`: lo de «Usuarios y roles» en una sola petición.
 */
class DirectorioDeUsuariosTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    public function test_trae_personal_aulas_y_una_pagina_de_estudiantes_con_su_total(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $d = $this->getJson('/api/users/directory?per_page=20')->assertOk()->json('data');

        $tipos = collect($d['staff'])->pluck('user_type')->unique()->all();
        $this->assertNotContains('student', $tipos);
        $this->assertContains('teacher', $tipos);

        $this->assertNotEmpty($d['groups']);

        $ids = collect($d['students']['data'])->pluck('user_id');
        $this->assertTrue($ids->contains($this->alumnoA->id));
        $this->assertTrue($ids->contains($this->alumnoB->id));
        $this->assertSame(count($d['students']['data']), $d['students']['total']);
        $this->assertSame($d['students']['total'], $d['students']['center_total']);
        $this->assertSame(1, $d['students']['last_page']);
    }

    public function test_solo_el_administrador_lo_ve(): void
    {
        $this->actingAs($this->docente, 'sanctum');
        $this->getJson('/api/users/directory')->assertForbidden();

        $this->actingAs($this->alumnoA, 'sanctum');
        $this->getJson('/api/users/directory')->assertForbidden();
    }

    public function test_no_mezcla_otros_centros(): void
    {
        $otro = Institution::factory()->create();
        $ajeno = \App\Models\Admin\User::factory()->student()->create(['institution_id' => $otro->id, 'full_name' => 'Ajeno Zzyzx']);
        DB::table('students')->insert([
            'user_id' => $ajeno->id, 'institution_id' => $otro->id, 'student_code' => 'AJENO-1',
            'grade' => 1, 'section' => 'A', 'status' => 'active', 'enrolled_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin, 'sanctum');
        $d = $this->getJson('/api/users/directory?q=Zzyzx')->assertOk()->json('data');

        $this->assertSame([], $d['students']['data']);
        $this->assertNotContains($ajeno->id, collect($d['staff'])->pluck('id')->all());
        $this->assertNotContains($otro->id, collect($d['groups'])->pluck('institution_id')->all());
    }

    public function test_busca_por_nombre_correo_y_codigo_y_los_comodines_son_texto(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->alumnoA->update(['full_name' => 'Zacarías Quintanilla']);
        $codigo = DB::table('students')->where('user_id', $this->alumnoA->id)->value('student_code');

        foreach (['Quintanilla', $this->alumnoA->email, $codigo] as $busqueda) {
            $ids = collect($this->getJson('/api/users/directory?students_only=1&q=' . urlencode((string) $busqueda))->assertOk()->json('data.students.data'))->pluck('user_id');
            $this->assertTrue($ids->contains($this->alumnoA->id), "No encontró buscando «{$busqueda}».");
        }

        $this->assertSame([], $this->getJson('/api/users/directory?students_only=1&q=' . urlencode('%'))->json('data.students.data'));
    }

    public function test_solo_alumnos_no_manda_el_personal_ni_las_aulas(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $d = $this->getJson('/api/users/directory?students_only=1')->assertOk()->json('data');

        $this->assertSame([], $d['staff']);
        $this->assertSame([], $d['groups']);
        $this->assertNotEmpty($d['students']['data']);
    }

    public function test_el_total_filtrado_y_el_del_centro_son_distintos_al_buscar(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->alumnoA->update(['full_name' => 'Unico Buscable Qwerty']);

        $s = $this->getJson('/api/users/directory?students_only=1&q=Qwerty')->assertOk()->json('data.students');

        $this->assertSame(1, $s['total']);
        $this->assertGreaterThan(1, $s['center_total']);
    }

    public function test_una_pagina_mas_alla_del_final_sigue_diciendo_cuantos_hay(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $s = $this->getJson('/api/users/directory?students_only=1&per_page=1&page=99')->assertOk()->json('data.students');

        $this->assertSame([], $s['data']);
        $this->assertGreaterThan(1, $s['total']);
    }

    public function test_cuesta_pocas_consultas(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/users/directory')->assertOk(); // calienta la caché de aulas

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/users/directory')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Personal y estudiantes (con su total) en una sola sentencia. Las aulas salen de la caché.
        $this->assertLessThanOrEqual(1, $n);
    }
}
