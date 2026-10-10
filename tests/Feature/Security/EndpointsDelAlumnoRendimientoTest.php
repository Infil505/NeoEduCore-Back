<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\StudyResource;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Consultas de los endpoints del alumno. Con la base remota cada una cuesta
 * ~0,5 s, así que el número no puede crecer sin que alguien lo note.
 */
class EndpointsDelAlumnoRendimientoTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    private function consultas(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_el_panel_del_alumno_no_pide_el_usuario_otra_vez(): void
    {
        $this->montarEscenario();
        $this->actingAs($this->alumnoA, 'sanctum');
        $this->getJson('/api/dashboard/student-overview')->assertOk();

        $this->assertLessThanOrEqual(7, $this->consultas('/api/dashboard/student-overview'));
    }

    public function test_los_recursos_del_alumno_son_dos_consultas_y_sin_aulas(): void
    {
        $this->montarEscenario();
        $recurso = StudyResource::create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id, 'title' => 'rec',
            'resource_type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abc', 'created_by' => $this->docente->id,
        ]);
        $recurso->syncGroups([$this->aulaA->id]);
        $this->actingAs($this->alumnoA, 'sanctum');
        $this->getJson('/api/study-resources')->assertOk(); // calienta el catálogo

        // Autor y materia vienen en la misma consulta (LEFT JOIN): una sola.
        $this->assertLessThanOrEqual(1, $this->consultas('/api/study-resources'));

        $fila = $this->getJson('/api/study-resources')->json('data.data.0');
        $this->assertSame($this->materia->id, $fila['subject']['id']);
        $this->assertArrayNotHasKey('groups', $fila);
        $this->assertArrayNotHasKey('email', $fila['creator']);
    }

    public function test_el_calendario_del_alumno_sale_de_cache_y_se_renueva_con_un_aviso_nuevo(): void
    {
        $this->montarEscenario();
        $this->actingAs($this->alumnoA, 'sanctum');
        $this->getJson('/api/calendar-events')->assertOk();

        $this->assertLessThanOrEqual(1, $this->consultas('/api/calendar-events'));

        CalendarEvent::create([
            'institution_id' => $this->centro->id, 'title' => 'Aviso nuevo', 'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(), 'event_type' => 'activity',
            'group_id' => $this->aulaA->id, 'created_by' => $this->docente->id,
        ]);

        $titulos = collect($this->getJson('/api/calendar-events')->json('data.data'))->pluck('title');
        $this->assertTrue($titulos->contains('Aviso nuevo'));
    }

    public function test_el_calendario_no_se_comparte_con_alumnos_de_otra_aula(): void
    {
        $this->montarEscenario();
        CalendarEvent::create([
            'institution_id' => $this->centro->id, 'title' => 'Solo aula A', 'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(), 'event_type' => 'activity',
            'group_id' => $this->aulaA->id, 'created_by' => $this->docente->id,
        ]);

        $this->actingAs($this->alumnoA, 'sanctum');
        $this->assertTrue(collect($this->getJson('/api/calendar-events')->json('data.data'))->pluck('title')->contains('Solo aula A'));

        $this->actingAs($this->alumnoB, 'sanctum');
        $this->assertFalse(collect($this->getJson('/api/calendar-events')->json('data.data'))->pluck('title')->contains('Solo aula A'));
    }

    public function test_las_estrategias_se_piden_en_una_sola_consulta_de_recomendaciones(): void
    {
        $this->montarEscenario();
        $this->actingAs($this->alumnoA, 'sanctum');
        $this->getJson('/api/reports/students/me/strategies')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/reports/students/me/strategies')->assertOk();
        $sobreRecomendaciones = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'from "ai_recommendations"') || str_contains($q['query'], 'ai_recommendations"'))
            ->count();
        DB::disableQueryLog();

        $this->assertSame(1, $sobreRecomendaciones, 'Las cuatro categorías deben salir de una sola consulta.');
    }

    public function test_el_examen_del_docente_no_hace_consulta_de_visibilidad_ni_de_materia(): void
    {
        $this->montarEscenario();
        $this->actingAs($this->docente, 'sanctum');
        $url = '/api/exams/' . $this->examen->id;
        $this->getJson($url)->assertOk(); // calienta el catálogo

        $this->assertLessThanOrEqual(5, $this->consultas($url));
        $this->assertSame($this->materia->id, $this->getJson($url)->json('data.subject.id'));

        // Un colega con el mismo grupo y materia sigue sin verlo.
        $this->actingAs($this->colega, 'sanctum');
        $this->getJson($url)->assertNotFound();
    }

    public function test_el_analisis_del_alumno_para_el_docente_usa_el_catalogo_de_materias(): void
    {
        $this->montarEscenario();
        $this->intentoEntregado($this->alumnoA);
        $this->actingAs($this->docente, 'sanctum');
        $url = '/api/analytics/students/' . $this->alumnoA->id;
        $this->getJson($url)->assertOk();

        $this->assertLessThanOrEqual(7, $this->consultas($url));
    }

    public function test_los_listados_del_personal_traen_sus_relaciones_en_una_sola_consulta(): void
    {
        $this->montarEscenario();
        CalendarEvent::create([
            'institution_id' => $this->centro->id, 'title' => 'Aviso', 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(),
            'event_type' => 'exam', 'group_id' => $this->aulaA->id, 'exam_id' => $this->examen->id, 'created_by' => $this->docente->id,
        ]);
        $this->actingAs($this->docente, 'sanctum');

        foreach (['/api/calendar-events', '/api/exams', '/api/students?per_page=20'] as $url) {
            $this->getJson($url)->assertOk(); // calienta cachés
            $this->assertLessThanOrEqual(2, $this->consultas($url), $url);
        }

        // El JSON es el de siempre: el autor, el aula y el examen siguen anidados.
        $evento = $this->getJson('/api/calendar-events')->json('data.data.0');
        $this->assertSame($this->docente->id, $evento['creator']['id']);
        $this->assertSame($this->aulaA->id, $evento['group']['id']);
        $this->assertSame($this->examen->id, $evento['exam']['id']);
        $this->assertSame(['id', 'full_name'], array_keys($evento['creator']));
        $this->assertArrayNotHasKey('creator__id', $evento);

        $examen = collect($this->getJson('/api/exams')->json('data.data'))->firstWhere('id', $this->examen->id);
        $this->assertSame($this->materia->id, $examen['subject']['id']);
        $this->assertSame(['id', 'full_name'], array_keys($examen['teacher']));
    }
}
