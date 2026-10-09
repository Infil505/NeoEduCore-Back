<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Services\Students\EnrollmentService;
use App\Support\TenantCache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Caché por centro de los datos que casi no cambian (`TenantCache`).
 *
 * Dos cosas que NO pueden fallar:
 *  1. **Frescura**: tras cualquier escritura que cambie lo cacheado —también las
 *     que no pasan por Eloquent— la siguiente lectura ve el dato nuevo.
 *  2. **Aislamiento**: un resultado cacheado jamás llega a quien no le toca (otro
 *     centro, o un docente con otras aulas).
 */
class CacheDelCatalogoTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    /** Número de consultas que hace `$peticion`, y su respuesta. */
    private function consultas(callable $peticion): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $peticion();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    private function consultasSobre(string $tabla, callable $peticion): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $peticion();
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], "\"{$tabla}\""))->count();
        DB::disableQueryLog();

        return $n;
    }

    /* ---------- que cachea ---------- */

    public function test_la_segunda_lectura_del_listado_de_aulas_no_toca_la_tabla(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $primera = $this->consultasSobre('groups', fn () => $this->getJson('/api/groups')->assertOk());
        $segunda = $this->consultasSobre('groups', fn () => $this->getJson('/api/groups')->assertOk());

        $this->assertGreaterThan(0, $primera);
        $this->assertSame(0, $segunda, 'La segunda lectura debía salir de la caché.');
    }

    public function test_el_resumen_cachea_materias_aulas_docentes_y_asignaciones(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $url = '/api/dashboard/staff-overview?include=subjects,groups,teachers,assignments';

        $this->getJson($url)->assertOk();

        foreach (['subjects', 'groups', 'teacher_assignments'] as $tabla) {
            $n = $this->consultasSobre($tabla, fn () => $this->getJson($url)->assertOk());
            $this->assertSame(0, $n, "«{$tabla}» se volvió a consultar con la caché caliente.");
        }
    }

    public function test_lo_cacheado_es_identico_a_lo_que_devuelve_la_base(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $fria = $this->getJson('/api/groups?per_page=1')->assertOk()->json();
        $caliente = $this->getJson('/api/groups?per_page=1')->assertOk()->json();

        $this->assertSame($fria, $caliente);
        $this->assertSame(1, $caliente['data']['per_page']);
    }

    public function test_la_configuracion_del_centro_se_cachea_y_se_refresca_al_guardar(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/system/config')->assertOk();
        $this->assertSame(0, $this->consultasSobre('institutions', fn () => $this->getJson('/api/system/config')->assertOk()));

        $this->putJson('/api/system/config', ['name' => 'Centro Renombrado'])->assertOk();

        $this->getJson('/api/system/config')->assertOk()->assertJsonPath('data.institution_name', 'Centro Renombrado');
    }

    /* ---------- frescura: cada tipo de escritura invalida ---------- */

    public function test_crear_un_aula_actualiza_el_listado(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $antes = count($this->getJson('/api/groups')->json('data.data'));

        $this->postJson('/api/groups', ['name' => 'Nueva 2026', 'grade' => 3, 'section' => 'Z'])->assertCreated();

        $this->assertCount($antes + 1, $this->getJson('/api/groups')->json('data.data'));
    }

    public function test_un_recuento_de_alumnado_con_db_table_tambien_invalida(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $contar = fn () => collect($this->getJson('/api/groups')->json('data.data'))->firstWhere('id', $this->aulaA->id)['student_count'];
        // El escenario matricula a un alumno sin tocar `student_count`: parte de 0.
        $this->assertSame(0, $contar());

        // Matrícula nueva + recuento por la vía que NO dispara eventos de Eloquent.
        $this->nuevoAlumno($this->aulaA);
        app(EnrollmentService::class)->recontarAula($this->aulaA->id, $this->centro->id);

        // Ahora hay dos matrículas abiertas (alumnoA y el nuevo).
        $this->assertSame(2, $contar(), 'El recuento no se vio: la caché de aulas quedó vieja.');
    }

    public function test_asignar_un_docente_con_insert_directo_invalida_su_listado(): void
    {
        $otraAula = $this->aulaB;
        $this->actingAs($this->docente, 'sanctum');
        $antes = count($this->getJson('/api/groups')->json('data.data'));
        $this->assertSame(1, $antes);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/teacher-assignments', [
            'teacher_user_id' => $this->docente->id,
            'group_ids'       => [$otraAula->id],
            'subject_ids'     => [$this->materia->id],
        ])->assertSuccessful();

        $this->actingAs($this->docente, 'sanctum');
        $this->assertCount(2, $this->getJson('/api/groups')->json('data.data'), 'El docente no vio su aula nueva: caché vieja.');
    }

    public function test_retirar_asignaciones_en_bloque_invalida(): void
    {
        $this->actingAs($this->docente, 'sanctum');
        $this->assertCount(1, $this->getJson('/api/groups')->json('data.data'));

        $this->actingAs($this->admin, 'sanctum');
        $this->deleteJson('/api/teacher-assignments/bulk', ['teacher_user_id' => $this->docente->id])->assertOk();

        $this->actingAs($this->docente, 'sanctum');
        $this->assertCount(0, $this->getJson('/api/groups')->json('data.data'), 'El docente sigue viendo aulas que ya no tiene.');
    }

    public function test_crear_un_examen_actualiza_el_contador_de_las_materias(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $contar = fn () => collect($this->getJson('/api/subjects')->json('data.data'))->firstWhere('id', $this->materia->id)['exams_count'];
        $antes = $contar();

        Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id,
            'subject_id' => $this->materia->id, 'status' => 'draft',
        ]);

        $this->assertSame($antes + 1, $contar());
    }

    public function test_dar_de_alta_un_docente_actualiza_la_lista_de_docentes(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $contar = fn () => count($this->getJson('/api/dashboard/staff-overview?include=teachers')->json('data.teachers'));
        $antes = $contar();

        User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);

        $this->assertSame($antes + 1, $contar());
    }

    public function test_borrar_una_materia_actualiza_el_catalogo(): void
    {
        $extra = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->actingAs($this->admin, 'sanctum');
        $antes = count($this->getJson('/api/subjects')->json('data.data'));

        $this->deleteJson("/api/subjects/{$extra->id}")->assertSuccessful();

        $this->assertCount($antes - 1, $this->getJson('/api/subjects')->json('data.data'));
    }

    /* ---------- aislamiento ---------- */

    public function test_un_docente_no_recibe_la_entrada_cacheada_de_otro(): void
    {
        // $docente y $colega: mismo aula A. Se da a $colega un aula más.
        $this->asignarDocente($this->colega, $this->aulaB->id, $this->materia->id);

        $this->actingAs($this->colega, 'sanctum');
        $this->assertCount(2, $this->getJson('/api/groups')->json('data.data'));

        // El docente comparte URL y centro, pero NO sus aulas: no puede ver la entrada del colega.
        $this->actingAs($this->docente, 'sanctum');
        $ids = collect($this->getJson('/api/groups')->json('data.data'))->pluck('id')->all();
        $this->assertSame([$this->aulaA->id], $ids);

        // Lo mismo por el resumen del personal.
        $this->actingAs($this->colega, 'sanctum');
        $this->getJson('/api/dashboard/staff-overview?include=groups')->assertOk();
        $this->actingAs($this->docente, 'sanctum');
        $grupos = collect($this->getJson('/api/dashboard/staff-overview?include=groups')->json('data.groups'))->pluck('id')->all();
        $this->assertSame([$this->aulaA->id], $grupos);
    }

    public function test_un_centro_no_recibe_la_entrada_cacheada_de_otro(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/groups')->assertOk();
        $this->getJson('/api/subjects')->assertOk();

        $otro = Institution::factory()->create();
        $adminAjeno = User::factory()->admin()->create(['institution_id' => $otro->id, 'status' => 'active']);
        Group::factory()->create(['institution_id' => $otro->id, 'name' => 'Aula del otro centro']);

        $this->actingAs($adminAjeno, 'sanctum');
        $nombres = collect($this->getJson('/api/groups')->json('data.data'))->pluck('name')->all();

        $this->assertSame(['Aula del otro centro'], $nombres);
        $this->assertSame([], $this->getJson('/api/subjects')->json('data.data'), 'Las materias del otro centro se filtraron.');
    }

    public function test_los_filtros_de_la_consulta_forman_parte_de_la_clave(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $todas = count($this->getJson('/api/groups')->json('data.data'));
        $filtradas = $this->getJson('/api/groups?grade=' . $this->aulaA->grade)->json('data.data');

        $this->assertSame(2, $todas);
        $this->assertNotEmpty($filtradas);
        $this->assertLessThanOrEqual($todas, count($filtradas));
        foreach ($filtradas as $aula) {
            $this->assertSame($this->aulaA->grade, $aula['grade']);
        }
    }

    public function test_sin_centro_no_se_cachea_nada(): void
    {
        $this->assertSame('calculado', TenantCache::remember(null, TenantCache::CATALOGO, 'x', 60, fn () => 'calculado'));
        $this->assertSame('otro', TenantCache::remember(null, TenantCache::CATALOGO, 'x', 60, fn () => 'otro'));
    }

    /* ---------- panel del alumno: calendario compartido por aula ---------- */

    private function aviso(string $titulo, Group $aula): CalendarEvent
    {
        return CalendarEvent::create([
            'institution_id' => $this->centro->id,
            'title'          => $titulo,
            'start_at'       => now()->addDay(),
            'end_at'         => now()->addDay()->addHour(),
            'event_type'     => 'activity',
            'group_id'       => $aula->id,
            'created_by'     => $this->docente->id,
        ]);
    }

    /** @return array<int,string> */
    private function titulosDelPanel(User $alumno): array
    {
        $this->actingAs($alumno, 'sanctum');

        return collect($this->getJson('/api/dashboard/student-overview')->assertOk()->json('data.calendar'))->pluck('title')->all();
    }

    public function test_dos_alumnos_de_la_misma_aula_comparten_los_avisos_cacheados(): void
    {
        $this->aviso('Aviso del aula A', $this->aulaA);
        $otro = $this->nuevoAlumno($this->aulaA);

        $this->titulosDelPanel($this->alumnoA); // calienta

        $this->actingAs($otro, 'sanctum');
        $n = $this->consultasSobre('calendar_events', fn () => $this->getJson('/api/dashboard/student-overview')->assertOk());
        $this->assertSame(0, $n, 'El segundo alumno del aula volvió a consultar los avisos.');
        $this->assertSame(['Aviso del aula A'], $this->titulosDelPanel($otro));
    }

    public function test_un_alumno_no_recibe_los_avisos_cacheados_de_otra_aula(): void
    {
        $this->aviso('Solo aula A', $this->aulaA);
        $this->aviso('Solo aula B', $this->aulaB);

        $this->assertSame(['Solo aula A'], $this->titulosDelPanel($this->alumnoA));
        $this->assertSame(['Solo aula B'], $this->titulosDelPanel($this->alumnoB));
    }

    public function test_un_aviso_nuevo_llega_al_panel_sin_esperar_a_que_caduque(): void
    {
        $this->assertSame([], $this->titulosDelPanel($this->alumnoA));

        $this->aviso('Recién creado', $this->aulaA);

        $this->assertSame(['Recién creado'], $this->titulosDelPanel($this->alumnoA));
    }

    public function test_renombrar_el_examen_de_un_aviso_se_ve_en_el_panel(): void
    {
        $evento = $this->aviso('Prueba', $this->aulaA);
        $evento->update(['exam_id' => $this->examen->id]);
        $this->titulosDelPanel($this->alumnoA);

        $this->examen->update(['title' => 'Título nuevo del examen']);

        $this->actingAs($this->alumnoA, 'sanctum');
        $eventos = $this->getJson('/api/dashboard/student-overview')->json('data.calendar');
        $this->assertSame('Título nuevo del examen', $eventos[0]['exam']['title']);
    }

    public function test_el_centro_del_alumno_sale_de_la_cache_y_se_refresca_al_guardarlo(): void
    {
        $this->actingAs($this->alumnoA, 'sanctum');
        $this->getJson('/api/dashboard/student-overview')->assertOk();

        $n = $this->consultasSobre('institutions', fn () => $this->getJson('/api/dashboard/student-overview')->assertOk());
        $this->assertSame(0, $n);

        $this->centro->update(['name' => 'Centro con otro nombre']);

        $this->assertSame('Centro con otro nombre', $this->getJson('/api/dashboard/student-overview')->json('data.profile.user.institution.name'));
    }
}
