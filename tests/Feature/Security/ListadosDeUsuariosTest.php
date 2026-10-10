<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * El alumnado tiene su propio listado: `GET /api/users?staff=1` devuelve solo
 * administradores y docentes, y `GET /api/students?q=` busca por nombre, correo
 * o código sin traer el padrón entero.
 */
class ListadosDeUsuariosTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function tipos(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data.data'))->pluck('user_type')->unique()->values()->all();
    }

    public function test_staff_excluye_al_alumnado(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->assertContains('student', $this->tipos('/api/users?per_page=100'));

        $sinAlumnos = $this->tipos('/api/users?staff=1&per_page=100');
        $this->assertNotContains('student', $sinAlumnos);
        $this->assertContains('teacher', $sinAlumnos);
    }

    public function test_el_listado_de_alumnos_busca_por_nombre_correo_y_codigo(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->alumnoA->update(['full_name' => 'Zacarías Quintanilla']);
        $codigo = DB::table('students')->where('user_id', $this->alumnoA->id)->value('student_code');

        foreach (['Quintanilla', strtoupper(substr($this->alumnoA->email, 0, 8)), $codigo] as $busqueda) {
            $ids = collect($this->getJson('/api/students?q=' . urlencode((string) $busqueda))->assertOk()->json('data.data'))->pluck('user_id');
            $this->assertTrue($ids->contains($this->alumnoA->id), "No encontró al alumno buscando «{$busqueda}».");
        }

        $this->assertSame([], $this->getJson('/api/students?q=' . urlencode('zzz-no-existe'))->json('data.data'));
    }

    public function test_la_busqueda_trata_los_comodines_como_texto(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        // «%» no debe comportarse como patrón y devolver a todo el mundo.
        $this->assertSame([], $this->getJson('/api/students?q=' . urlencode('%'))->json('data.data'));
    }

    public function test_el_listado_de_alumnos_no_expone_datos_de_cuenta_de_mas(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $fila = $this->getJson('/api/students?per_page=100')->assertOk()->json('data.data.0');

        $this->assertEqualsCanonicalizing(['id', 'full_name', 'email', 'status'], array_keys($fila['user']));
    }

    private function idsDe(string $url): \Illuminate\Support\Collection
    {
        return collect($this->getJson($url)->assertOk()->json('data.data'))->pluck('user_id');
    }

    public function test_filtra_por_aula_para_listar_y_para_elegir_a_quien_matricular(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $enA = $this->idsDe("/api/students?group_id={$this->aulaA->id}&per_page=100");
        $this->assertTrue($enA->contains($this->alumnoA->id));
        $this->assertFalse($enA->contains($this->alumnoB->id));

        // Para matricular en A: los que NO están en A.
        $candidatos = $this->idsDe("/api/students?exclude_group_id={$this->aulaA->id}&per_page=100");
        $this->assertFalse($candidatos->contains($this->alumnoA->id));
        $this->assertTrue($candidatos->contains($this->alumnoB->id));

        // Ambos tienen aula: ninguno es «sin aula».
        $sinAula = $this->idsDe('/api/students?unassigned=1&per_page=100');
        $this->assertFalse($sinAula->contains($this->alumnoA->id));
        $this->assertFalse($sinAula->contains($this->alumnoB->id));
    }

    public function test_un_alumno_retirado_del_aula_deja_de_salir_en_ella_y_pasa_a_sin_aula(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        DB::table('group_students')->where('student_user_id', $this->alumnoA->id)->update(['left_at' => now()]);

        $this->assertFalse($this->idsDe("/api/students?group_id={$this->aulaA->id}&per_page=100")->contains($this->alumnoA->id));
        $this->assertTrue($this->idsDe('/api/students?unassigned=1&per_page=100')->contains($this->alumnoA->id));
    }

    public function test_los_filtros_de_aula_rechazan_valores_que_no_son_uuid(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/students?group_id=no-es-uuid')->assertStatus(422);
        $this->getJson('/api/students?exclude_group_id=1%20OR%201=1')->assertStatus(422);
    }

    public function test_los_listados_no_mandan_los_enlaces_de_paginacion(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        foreach (['/api/students', '/api/users', '/api/groups', '/api/exams'] as $url) {
            $claves = array_keys($this->getJson($url)->assertOk()->json('data'));
            sort($claves);

            $this->assertSame(['current_page', 'data', 'from', 'last_page', 'per_page', 'to', 'total'], $claves, $url);
        }
    }

    public function test_el_docente_solo_busca_entre_sus_alumnos(): void
    {
        $this->actingAs($this->docente, 'sanctum');

        $ids = collect($this->getJson('/api/students?per_page=100')->assertOk()->json('data.data'))->pluck('user_id');
        $this->assertTrue($ids->contains($this->alumnoA->id));
        $this->assertFalse($ids->contains($this->alumnoB->id));

        $this->assertSame([], $this->getJson('/api/students?q=' . urlencode($this->alumnoB->email))->json('data.data'));
    }
}
