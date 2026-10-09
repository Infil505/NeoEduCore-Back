<?php

namespace Tests\Feature\Security;

use App\Models\Exams\Exam;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * `GET /dashboard/staff-overview?include=` y su `summary`.
 *
 * `include` limita lo que se calcula: cada sección son varias consultas y con la
 * base remota cada una cuesta cientos de ms. `summary` son las cifras reales del
 * panel de inicio (hasta el 09/10/2026 el frontend las leía y el backend nunca
 * las enviaba, así que las tarjetas salían a cero). El docente cuenta SOLO lo suyo.
 */
class StaffOverviewIncludeTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function overview(string $query = '')
    {
        return $this->getJson('/api/dashboard/staff-overview' . $query)->assertOk();
    }

    public function test_include_devuelve_solo_las_secciones_pedidas(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $data = $this->overview('?include=summary')->json('data');

        $this->assertArrayHasKey('summary', $data);
        foreach (['users', 'students', 'subjects', 'groups', 'exams', 'calendar', 'resources', 'analyticsInstitution'] as $clave) {
            $this->assertArrayNotHasKey($clave, $data, "include=summary no debía calcular «{$clave}».");
        }
    }

    public function test_sin_include_devuelve_todo_como_antes_mas_el_summary(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $data = $this->overview()->json('data');

        foreach (['users', 'students', 'subjects', 'groups', 'exams', 'calendar', 'resources', 'analyticsInstitution', 'analyticsSubjects', 'summary', 'institutions'] as $clave) {
            $this->assertArrayHasKey($clave, $data);
        }
    }

    public function test_include_acepta_varias_secciones_e_ignora_las_desconocidas(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $data = $this->overview('?include=groups,subjects,inventada')->json('data');

        $this->assertArrayHasKey('groups', $data);
        $this->assertArrayHasKey('subjects', $data);
        $this->assertArrayNotHasKey('inventada', $data);
        $this->assertArrayNotHasKey('summary', $data);
        $this->assertCount(2, $data['groups']);
    }

    public function test_un_include_raro_no_rompe(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/dashboard/staff-overview?include[]=summary')->assertOk();
        $this->getJson('/api/dashboard/staff-overview?include=')->assertOk()->assertJsonStructure(['data' => ['summary']]);
    }

    public function test_el_summary_del_admin_cuenta_el_centro_entero(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $s = $this->overview('?include=summary')->json('data.summary');

        $this->assertCount(2, $s['groups']);
        $this->assertSame(2, $s['students']['total']);
        $this->assertSame(1, $s['exams']['active']);
        $this->assertSame(1, $s['exams']['total']);
        $this->assertNotNull($s['users']);
        $this->assertNotNull($s['attention']);
        // El aula B no tiene docente asignado en el escenario.
        $this->assertSame(1, $s['attention']['groups_without_teacher']);
    }

    public function test_el_summary_del_docente_cuenta_solo_lo_suyo(): void
    {
        // El colega crea su propio examen: no debe sumarse al docente.
        Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->colega->id,
            'subject_id' => $this->materia->id, 'status' => 'draft',
        ]);

        $this->actingAs($this->docente, 'sanctum');
        $s = $this->overview('?include=summary')->json('data.summary');

        $this->assertCount(1, $s['groups'], 'Solo el aula asignada al docente.');
        $this->assertSame($this->aulaA->id, $s['groups'][0]['id']);
        $this->assertSame(1, $s['students']['total'], 'Solo los alumnos de sus aulas.');
        $this->assertSame(1, $s['exams']['total'], 'Solo los exámenes que él creó.');
        $this->assertSame(0, $s['exams']['draft'], 'El borrador del colega no cuenta.');
        $this->assertNull($s['users'], 'Las cifras de cuentas son del administrador.');
        $this->assertNull($s['attention']);
    }

    public function test_el_docente_sin_asignacion_ve_ceros_no_el_centro(): void
    {
        DB::table('teacher_assignments')->where('teacher_user_id', $this->docente->id)->delete();
        $this->actingAs($this->docente, 'sanctum');

        $s = $this->overview('?include=summary')->json('data.summary');

        $this->assertSame([], $s['groups']);
        $this->assertSame(0, $s['students']['total']);
        $this->assertSame(0, $s['subjects']);
    }

    public function test_el_summary_no_cuenta_otros_centros(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $antes = $this->overview('?include=summary')->json('data.summary.students.total');

        // Un centro ajeno con su propio alumnado.
        $otro = \App\Models\Admin\Institution::factory()->create();
        $aula = \App\Models\Academic\Group::factory()->create(['institution_id' => $otro->id]);
        $ajeno = \App\Models\Admin\User::factory()->student()->create(['institution_id' => $otro->id, 'status' => 'active']);
        \App\Models\Students\Student::factory()->create(['user_id' => $ajeno->id, 'institution_id' => $otro->id]);
        $this->matricularEnGrupo($ajeno->id, $aula->id, $otro->id);

        $this->assertSame($antes, $this->overview('?include=summary')->json('data.summary.students.total'));
    }

    public function test_el_admin_recibe_docentes_y_asignaciones_en_la_misma_respuesta(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $data = $this->overview('?include=teachers,assignments')->json('data');

        $this->assertCount(2, $data['teachers']); // docente y colega
        $this->assertCount(2, $data['assignments']); // ambos en el aula A
        $this->assertSame($this->materia->id, $data['assignments'][0]['subject']['id']);
        $this->assertArrayHasKey('full_name', $data['assignments'][0]['teacher']);
        $this->assertArrayNotHasKey('summary', $data);
    }

    public function test_al_docente_no_se_le_devuelven_docentes_ni_asignaciones(): void
    {
        $this->actingAs($this->docente, 'sanctum');

        $data = $this->overview('?include=teachers,assignments')->json('data');

        $this->assertSame([], $data['teachers']);
        $this->assertSame([], $data['assignments']);
    }
}
