<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Recursos de estudio y avisos del calendario (regla del centro, 05/10/2026):
 *
 *  - Son actividades **del docente**: solo él los crea; el administrador no.
 *  - Los envía a las aulas que elige **de las que el administrador le asignó**:
 *    con una sola aula va por defecto, con varias tiene que elegir.
 *  - Entre docentes no se ve lo que tiene cada uno.
 *  - El estudiante ve solo lo enviado a su aula.
 */
class RecursosYEventosPorAulaTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;
    private User $docente;
    private Group $aulaA;
    private Group $aulaB;
    private Subject $mate;

    private const URL = 'https://es.khanacademy.org/math/fracciones';

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro  = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->centro->id]);
        $this->aulaA   = Group::factory()->create(['institution_id' => $this->centro->id]);
        $this->aulaB   = Group::factory()->create(['institution_id' => $this->centro->id]);
        $this->mate    = Subject::factory()->create(['institution_id' => $this->centro->id]);
    }

    /* =====================================================
     | Quién crea
     ===================================================== */

    public function test_el_administrador_no_crea_recursos_ni_eventos(): void
    {
        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->postJson('/api/study-resources', $this->recurso(['group_ids' => [$this->aulaA->id]]))->assertForbidden();
        $this->postJson('/api/calendar-events', $this->evento(['group_id' => $this->aulaA->id]))->assertForbidden();
    }

    /* =====================================================
     | A qué aulas se envía
     ===================================================== */

    public function test_con_una_sola_aula_asignada_va_por_defecto(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);

        $res = $this->postJson('/api/study-resources', $this->recurso())->assertCreated();
        $this->assertSame([$this->aulaA->id], collect($res->json('data.groups'))->pluck('id')->all());

        $this->postJson('/api/calendar-events', $this->evento())->assertCreated()
            ->assertJsonPath('data.group_id', $this->aulaA->id);
    }

    public function test_con_varias_aulas_tiene_que_elegir_y_se_le_ofrecen_las_suyas(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $this->asignarDocente($this->docente, $this->aulaB->id, $this->mate->id);
        $ajena = Group::factory()->create(['institution_id' => $this->centro->id]);

        foreach (['/api/study-resources' => $this->recurso(), '/api/calendar-events' => $this->evento()] as $ruta => $cuerpo) {
            $res = $this->postJson($ruta, $cuerpo)->assertStatus(422)->assertJsonValidationErrors('group_ids');

            $ofrecidas = collect($res->json('aulas_disponibles'))->pluck('id')->all();
            $this->assertEqualsCanonicalizing([$this->aulaA->id, $this->aulaB->id], $ofrecidas);
            $this->assertNotContains($ajena->id, $ofrecidas);
        }
    }

    public function test_no_puede_enviar_a_un_aula_que_no_tiene_asignada(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);

        $this->postJson('/api/study-resources', $this->recurso(['group_ids' => [$this->aulaA->id, $this->aulaB->id]]))
            ->assertForbidden()->assertJsonPath('grupos_no_asignados.0', $this->aulaB->id);
        $this->postJson('/api/calendar-events', $this->evento(['group_ids' => [$this->aulaB->id]]))
            ->assertForbidden();

        $this->assertDatabaseMissing('study_resource_groups', ['group_id' => $this->aulaB->id]);
        $this->assertDatabaseMissing('calendar_events', ['group_id' => $this->aulaB->id]);
    }

    public function test_sin_ningun_aula_asignada_no_puede_enviar_nada(): void
    {
        $this->postJson('/api/study-resources', $this->recurso())->assertForbidden();
        $this->postJson('/api/calendar-events', $this->evento())->assertForbidden();
    }

    public function test_un_recurso_con_materia_solo_llega_a_aulas_donde_el_docente_la_imparte(): void
    {
        $otraMateria = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $this->asignarDocente($this->docente, $this->aulaB->id, $otraMateria->id);

        $this->postJson('/api/study-resources', $this->recurso([
            'subject_id' => $this->mate->id, 'group_ids' => [$this->aulaB->id],
        ]))->assertForbidden();

        $this->postJson('/api/study-resources', $this->recurso([
            'subject_id' => $this->mate->id, 'group_ids' => [$this->aulaA->id],
        ]))->assertCreated();
    }

    public function test_un_evento_a_varias_aulas_crea_uno_por_aula(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $this->asignarDocente($this->docente, $this->aulaB->id, $this->mate->id);

        $res = $this->postJson('/api/calendar-events', $this->evento(['group_ids' => [$this->aulaA->id, $this->aulaB->id]]))
            ->assertCreated();

        $this->assertCount(2, $res->json('data'));
        $this->assertEqualsCanonicalizing(
            [$this->aulaA->id, $this->aulaB->id],
            collect($res->json('data'))->pluck('group_id')->all()
        );
    }

    public function test_un_evento_siempre_tiene_aula_y_solo_se_mueve_a_una_suya(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $id = $this->postJson('/api/calendar-events', $this->evento())->assertCreated()->json('data.id');

        $this->putJson("/api/calendar-events/{$id}", ['group_id' => null])->assertStatus(422);
        $this->putJson("/api/calendar-events/{$id}", ['group_id' => $this->aulaB->id])->assertForbidden();
        $this->assertSame($this->aulaA->id, CalendarEvent::findOrFail($id)->group_id);
    }

    public function test_un_evento_solo_enlaza_examenes_propios(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $colega  = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);
        $deOtro  = Exam::factory()->create(['institution_id' => $this->centro->id, 'created_by_teacher_id' => $colega->id]);
        $suyo    = Exam::factory()->create(['institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id]);

        $this->postJson('/api/calendar-events', $this->evento(['exam_id' => $deOtro->id]))->assertNotFound();
        $this->postJson('/api/calendar-events', $this->evento(['exam_id' => $suyo->id]))->assertCreated();
    }

    /* =====================================================
     | Quién ve qué
     ===================================================== */

    public function test_entre_docentes_no_se_ve_lo_que_tiene_cada_uno(): void
    {
        $colega = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);
        $suyo   = $this->recursoEn($this->docente, [$this->aulaA]);
        $deOtro = $this->recursoEn($colega, [$this->aulaA]);
        $evSuyo = $this->eventoEn($this->docente, $this->aulaA);
        $evOtro = $this->eventoEn($colega, $this->aulaA);

        $recursos = collect($this->getJson('/api/study-resources')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($suyo->id, $recursos);
        $this->assertNotContains($deOtro->id, $recursos);
        $this->getJson("/api/study-resources/{$suyo->id}")->assertOk();
        $this->getJson("/api/study-resources/{$deOtro->id}")->assertNotFound();

        $eventos = collect($this->getJson('/api/calendar-events')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($evSuyo->id, $eventos);
        $this->assertNotContains($evOtro->id, $eventos);
        $this->getJson("/api/calendar-events/{$evOtro->id}")->assertNotFound();
    }

    public function test_el_estudiante_ve_solo_lo_enviado_a_su_aula(): void
    {
        $enA = $this->recursoEn($this->docente, [$this->aulaA]);
        $enB = $this->recursoEn($this->docente, [$this->aulaB]);
        $sinAula = $this->recursoEn($this->docente, []);
        $evA = $this->eventoEn($this->docente, $this->aulaA);
        $evB = $this->eventoEn($this->docente, $this->aulaB);
        $evHuerfano = CalendarEvent::factory()->create([
            'institution_id' => $this->centro->id, 'created_by' => $this->docente->id, 'group_id' => null,
        ]);

        $alumnoA = $this->alumnoEn($this->aulaA);
        Sanctum::actingAs($alumnoA);

        $recursos = collect($this->getJson('/api/study-resources')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$enA->id], $recursos);
        $this->getJson("/api/study-resources/{$enA->id}")->assertOk();
        $this->getJson("/api/study-resources/{$enB->id}")->assertNotFound();
        $this->getJson("/api/study-resources/{$sinAula->id}")->assertNotFound();

        $eventos = collect($this->getJson('/api/calendar-events')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertSame([$evA->id], $eventos);
        $this->assertNotContains($evB->id, $eventos);
        $this->assertNotContains($evHuerfano->id, $eventos, 'Un evento sin aula no llega a ningún estudiante.');
        $this->getJson("/api/calendar-events/{$evB->id}")->assertNotFound();
    }

    public function test_un_recurso_enviado_a_varias_aulas_lo_ven_las_dos(): void
    {
        $recurso = $this->recursoEn($this->docente, [$this->aulaA, $this->aulaB]);

        foreach ([$this->aulaA, $this->aulaB] as $aula) {
            Sanctum::actingAs($this->alumnoEn($aula));
            $this->getJson("/api/study-resources/{$recurso->id}")->assertOk();
        }
    }

    public function test_el_alumno_que_deja_el_aula_deja_de_verlo(): void
    {
        $recurso = $this->recursoEn($this->docente, [$this->aulaA]);
        $alumno  = $this->alumnoEn($this->aulaA);
        Sanctum::actingAs($alumno);
        $this->getJson("/api/study-resources/{$recurso->id}")->assertOk();

        \Illuminate\Support\Facades\DB::table('group_students')
            ->where('student_user_id', $alumno->id)->update(['left_at' => now()]);

        $this->getJson("/api/study-resources/{$recurso->id}")->assertNotFound();
    }

    public function test_el_estudiante_no_ve_el_correo_del_docente(): void
    {
        $recurso = $this->recursoEn($this->docente, [$this->aulaA]);
        $this->eventoEn($this->docente, $this->aulaA);
        Sanctum::actingAs($this->alumnoEn($this->aulaA));

        foreach (['/api/study-resources', '/api/calendar-events', "/api/study-resources/{$recurso->id}"] as $ruta) {
            $res = $this->getJson($ruta)->assertOk();
            $this->assertStringNotContainsString($this->docente->email, $res->getContent(), $ruta);
        }
    }

    public function test_el_administrador_ve_todo_y_puede_retirarlo(): void
    {
        $colega = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);
        $r1 = $this->recursoEn($this->docente, [$this->aulaA]);
        $r2 = $this->recursoEn($colega, [$this->aulaB]);
        $e1 = $this->eventoEn($this->docente, $this->aulaA);
        $e2 = $this->eventoEn($colega, $this->aulaB);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $recursos = collect($this->getJson('/api/study-resources')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($r1->id, $recursos);
        $this->assertContains($r2->id, $recursos);
        $eventos = collect($this->getJson('/api/calendar-events')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($e1->id, $eventos);
        $this->assertContains($e2->id, $eventos);

        $this->deleteJson("/api/study-resources/{$r2->id}")->assertNoContent();
        $this->deleteJson("/api/calendar-events/{$e2->id}")->assertNoContent();
    }

    public function test_cambiar_la_materia_de_un_recurso_revalida_sus_aulas(): void
    {
        $otraMateria = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $id = $this->postJson('/api/study-resources', $this->recurso(['subject_id' => $this->mate->id]))
            ->assertCreated()->json('data.id');

        // Esa aula no tiene a este docente dando la otra materia.
        $this->putJson("/api/study-resources/{$id}", ['subject_id' => $otraMateria->id])->assertForbidden();
        $this->assertSame($this->mate->id, StudyResource::findOrFail($id)->subject_id);
    }

    public function test_cambiar_las_aulas_de_un_recurso_cambia_quien_lo_ve(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $this->asignarDocente($this->docente, $this->aulaB->id, $this->mate->id);
        $id = $this->postJson('/api/study-resources', $this->recurso(['group_ids' => [$this->aulaA->id]]))
            ->assertCreated()->json('data.id');

        $alumnoA = $this->alumnoEn($this->aulaA);
        $alumnoB = $this->alumnoEn($this->aulaB);

        // Lo mueve de A a B: el de A lo pierde y el de B lo gana.
        Sanctum::actingAs($this->docente);
        $this->putJson("/api/study-resources/{$id}", ['group_ids' => [$this->aulaB->id]])->assertOk();

        Sanctum::actingAs($alumnoA);
        $this->getJson("/api/study-resources/{$id}")->assertNotFound();
        Sanctum::actingAs($alumnoB);
        $this->getJson("/api/study-resources/{$id}")->assertOk();

        // Y no puede moverlo a un aula que no tiene.
        $ajena = Group::factory()->create(['institution_id' => $this->centro->id]);
        Sanctum::actingAs($this->docente);
        $this->putJson("/api/study-resources/{$id}", ['group_ids' => [$ajena->id]])->assertForbidden();
        $this->assertDatabaseMissing('study_resource_groups', ['group_id' => $ajena->id]);
    }

    public function test_el_docente_mueve_un_evento_a_otra_de_sus_aulas(): void
    {
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->mate->id);
        $this->asignarDocente($this->docente, $this->aulaB->id, $this->mate->id);
        $evento = $this->eventoEn($this->docente, $this->aulaA);

        $this->putJson("/api/calendar-events/{$evento->id}", ['group_id' => $this->aulaB->id])
            ->assertOk()->assertJsonPath('data.group_id', $this->aulaB->id);

        Sanctum::actingAs($this->alumnoEn($this->aulaA));
        $this->getJson("/api/calendar-events/{$evento->id}")->assertNotFound();
    }

    public function test_el_administrador_solo_puede_poner_aulas_de_su_institucion(): void
    {
        $recurso = $this->recursoEn($this->docente, [$this->aulaA]);
        $evento  = $this->eventoEn($this->docente, $this->aulaA);
        $deOtroCentro = Group::factory()->create(['institution_id' => Institution::factory()->create()->id]);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->putJson("/api/study-resources/{$recurso->id}", ['group_ids' => [$deOtroCentro->id]])
            ->assertStatus(422)->assertJsonPath('grupos_invalidos.0', $deOtroCentro->id);
        $this->putJson("/api/calendar-events/{$evento->id}", ['group_id' => $deOtroCentro->id])
            ->assertStatus(422);

        // Y dentro de la institución puede reordenar sin ser asignado.
        $this->putJson("/api/calendar-events/{$evento->id}", ['group_id' => $this->aulaB->id])->assertOk();
    }

    /* =====================================================
     | Helpers
     ===================================================== */

    private function recurso(array $extra = []): array
    {
        return array_merge([
            'title' => 'Guía de fracciones',
            'resource_type' => 'article',
            'url' => self::URL,
        ], $extra);
    }

    private function evento(array $extra = []): array
    {
        return array_merge([
            'title' => 'Prueba corta',
            'start_at' => now()->addDays(3)->toDateTimeString(),
            'end_at' => now()->addDays(3)->addHour()->toDateTimeString(),
            'event_type' => 'activity',
        ], $extra);
    }

    /** @param  array<int,Group>  $aulas */
    private function recursoEn(User $autor, array $aulas): StudyResource
    {
        $recurso = StudyResource::factory()->create([
            'institution_id' => $this->centro->id,
            'created_by' => $autor->id,
        ]);
        $recurso->syncGroups(array_map(fn (Group $g) => $g->id, $aulas));

        return $recurso;
    }

    private function eventoEn(User $autor, Group $aula): CalendarEvent
    {
        return CalendarEvent::factory()->create([
            'institution_id' => $this->centro->id,
            'created_by' => $autor->id,
            'group_id' => $aula->id,
        ]);
    }

    private function alumnoEn(Group $aula): User
    {
        $user = User::factory()->student()->create(['institution_id' => $this->centro->id]);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $this->centro->id]);
        $this->matricularEnGrupo($user->id, $aula->id, $this->centro->id);

        return $user;
    }
}
