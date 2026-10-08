<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\StudyResource;
use App\Models\Students\Student;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * **Paneles de inicio y ficha del alumno** (revisión del merge de Joseph, J1 y J3).
 *
 * J1: los paneles `/dashboard/*-overview` tienen que aplicar la misma regla de
 * visibilidad que `/study-resources` y `/calendar-events`; si no, son la puerta
 * trasera para ver lo que los listados esconden.
 * J3: curso, sección y aula salen de la matrícula. Nadie los cambia editando la
 * ficha, y el alumno tampoco puede tocar la suya ni la de un compañero.
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesAlOverviewYALaFichaTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function recursoDe($autor, array $aulas): StudyResource
    {
        $recurso = StudyResource::factory()->create(['institution_id' => $this->centro->id, 'created_by' => $autor->id]);
        $recurso->syncGroups(array_map(fn ($g) => $g->id, $aulas));

        return $recurso;
    }

    private function eventoDe($autor, $aula): CalendarEvent
    {
        return CalendarEvent::factory()->create([
            'institution_id' => $this->centro->id, 'created_by' => $autor->id, 'group_id' => $aula->id,
        ]);
    }

    /* ---------------- J1: /dashboard/staff-overview ---------------- */

    public function test_el_docente_no_ve_en_su_panel_los_recursos_ni_avisos_de_un_colega(): void
    {
        $mio    = $this->recursoDe($this->docente, [$this->aulaA]);
        $ajeno  = $this->recursoDe($this->colega, [$this->aulaA]);   // mismo aula y materia
        $miEv   = $this->eventoDe($this->docente, $this->aulaA);
        $ajenoEv = $this->eventoDe($this->colega, $this->aulaA);

        Sanctum::actingAs($this->docente);
        $res = $this->getJson('/api/dashboard/staff-overview')->assertOk();

        $recursos = collect($res->json('data.resources'))->pluck('id');
        $eventos  = collect($res->json('data.calendar'))->pluck('id');

        $this->assertTrue($recursos->contains($mio->id));
        $this->assertFalse($recursos->contains($ajeno->id), 'El panel del docente muestra un recurso de un colega.');
        $this->assertTrue($eventos->contains($miEv->id));
        $this->assertFalse($eventos->contains($ajenoEv->id), 'El panel del docente muestra un aviso de un colega.');
    }

    public function test_el_panel_del_docente_no_trae_alumnos_de_aulas_que_no_tiene_asignadas(): void
    {
        Sanctum::actingAs($this->docente);
        $res = $this->getJson('/api/dashboard/staff-overview')->assertOk();
        $cuerpo = $res->getContent();

        $this->assertStringNotContainsString($this->alumnoB->email, $cuerpo, 'El panel del docente trae el correo de un alumno de otra aula.');
        $this->assertContains($this->alumnoA->id, collect($res->json('data.users'))->pluck('id')->all(), 'Sus propios alumnos sí deben salir.');
        $this->assertStringNotContainsString('password_hash', $cuerpo);
    }

    /* ---------------- J1: /dashboard/student-overview ---------------- */

    public function test_el_alumno_no_ve_en_su_panel_avisos_de_otra_aula(): void
    {
        $paraA = $this->eventoDe($this->docente, $this->aulaA);
        $paraB = $this->eventoDe($this->docente, $this->aulaB);

        Sanctum::actingAs($this->alumnoB);
        $res = $this->getJson('/api/dashboard/student-overview')->assertOk();
        $ids = collect($res->json('data.calendar'))->pluck('id');

        $this->assertTrue($ids->contains($paraB->id));
        $this->assertFalse($ids->contains($paraA->id), 'El panel del alumno B muestra un aviso del aula A.');
    }

    public function test_del_autor_de_un_aviso_el_alumno_solo_recibe_id_y_nombre(): void
    {
        $this->eventoDe($this->docente, $this->aulaA);

        Sanctum::actingAs($this->alumnoA);
        $creador = $this->getJson('/api/dashboard/student-overview')->assertOk()->json('data.calendar.0.creator');

        $this->assertEqualsCanonicalizing(['id', 'full_name'], array_keys($creador));
    }

    /* ---------------- J3: la ficha no mueve de aula ---------------- */

    public function test_el_alumno_no_edita_ni_su_ficha_ni_la_de_un_companero(): void
    {
        $antes = Student::where('user_id', $this->alumnoB->id)->first()->only(['grade', 'section', 'group_code', 'student_code']);

        Sanctum::actingAs($this->alumnoA);
        foreach ([$this->alumnoA, $this->alumnoB] as $objetivo) {
            foreach (['grade' => 9, 'section' => 'Z', 'group_code' => $this->aulaA->group_code, 'student_code' => 'HACK-1'] as $campo => $valor) {
                $res = $this->putJson("/api/students/{$objetivo->id}", [$campo => $valor]);
                $this->assertContains($res->status(), [403, 404, 422], "Un alumno pudo mandar {$campo} a la ficha de {$objetivo->id} ({$res->status()}).");
            }
        }

        $despues = Student::where('user_id', $this->alumnoB->id)->first()->only(['grade', 'section', 'group_code', 'student_code']);
        $this->assertSame($antes, $despues);
        $this->assertDatabaseMissing('students', ['student_code' => 'HACK-1']);
    }

    public function test_cambiar_el_aula_por_la_ficha_no_cambia_la_matricula(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/students/{$this->alumnoB->id}", ['group_code' => $this->aulaA->group_code, 'section' => $this->aulaA->section])
            ->assertStatus(422);

        $this->assertDatabaseHas('group_students', [
            'student_user_id' => $this->alumnoB->id, 'group_id' => $this->aulaB->id,
        ]);
        $this->assertDatabaseMissing('group_students', [
            'student_user_id' => $this->alumnoB->id, 'group_id' => $this->aulaA->id,
        ]);
    }
}
