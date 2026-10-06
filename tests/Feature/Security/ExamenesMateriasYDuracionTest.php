<?php

namespace Tests\Feature\Security;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Tercera pasada de la revisión de roles (05/10/2026), a partir de lo pedido
 * por el centro:
 *
 *  - Un docente solo ve las materias que el administrador le asignó.
 *  - Entre docentes no se ve lo que tiene cada uno: los exámenes, tampoco.
 *  - Ningún examen puede durar más de lo que fija el director.
 */
class ExamenesMateriasYDuracionTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;
    private User $docente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro  = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->centro->id]);
    }

    /* ---------------- materias ---------------- */

    public function test_docente_solo_ve_las_materias_que_tiene_asignadas(): void
    {
        $mia   = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $ajena = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->asignarDocente($this->docente, $this->grupo()->id, $mia->id);

        $ids = collect($this->getJson('/api/subjects')->assertOk()->json('data.data'))->pluck('id')->all();

        $this->assertContains($mia->id, $ids);
        $this->assertNotContains($ajena->id, $ids);

        $this->getJson("/api/subjects/{$mia->id}")->assertOk();
        $this->getJson("/api/subjects/{$ajena->id}")->assertForbidden();
    }

    public function test_docente_sin_asignaciones_no_ve_ninguna_materia(): void
    {
        Subject::factory()->count(2)->create(['institution_id' => $this->centro->id]);

        $this->assertCount(0, $this->getJson('/api/subjects')->assertOk()->json('data.data'));
    }

    public function test_el_admin_ve_todo_el_catalogo(): void
    {
        $a = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $b = Subject::factory()->create(['institution_id' => $this->centro->id]);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $ids = collect($this->getJson('/api/subjects')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    /* ---------------- exámenes entre docentes ---------------- */

    public function test_un_docente_no_ve_los_examenes_de_otro_ni_con_el_mismo_grupo_y_materia(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $grupo   = $this->grupo();
        $colega  = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);

        // Los dos dan la misma materia al mismo grupo: aun así no se ven.
        $this->asignarDocente($this->docente, $grupo->id, $materia->id);
        $this->asignarDocente($colega, $grupo->id, $materia->id);

        $suyo  = $this->examen($this->docente, $materia);
        $deOtro = $this->examen($colega, $materia);
        Question::factory()->create(['institution_id' => $this->centro->id, 'exam_id' => $deOtro->id]);

        $ids = collect($this->getJson('/api/exams')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($suyo->id, $ids);
        $this->assertNotContains($deOtro->id, $ids);

        $this->getJson("/api/exams/{$suyo->id}")->assertOk();
        $this->getJson("/api/exams/{$deOtro->id}")->assertNotFound();
        $this->getJson("/api/exams/{$deOtro->id}/questions")->assertNotFound();
    }

    public function test_el_admin_ve_los_examenes_de_todos_los_docentes(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $a = $this->examen($this->docente, $materia);
        $b = $this->examen(User::factory()->teacher()->create(['institution_id' => $this->centro->id]), $materia);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $ids = collect($this->getJson('/api/exams')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    /* ---------------- duración máxima ---------------- */

    public function test_ningun_examen_puede_pasar_el_maximo_que_fija_el_director(): void
    {
        $this->centro->update(['settings' => ['max_exam_duration' => 60]]);

        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $base = ['title' => 'Parcial', 'subject_id' => $materia->id, 'grade' => 4];

        $this->postJson('/api/exams', $base + ['duration_minutes' => 61])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');

        $this->postJson('/api/exams', $base + ['duration_minutes' => 60])->assertCreated();
    }

    public function test_la_edicion_tampoco_puede_superar_el_maximo(): void
    {
        $this->centro->update(['settings' => ['max_exam_duration' => 60]]);
        $exam = $this->examen($this->docente, Subject::factory()->create(['institution_id' => $this->centro->id]));

        $this->putJson("/api/exams/{$exam->id}", ['duration_minutes' => 90])
            ->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    }

    public function test_si_el_director_baja_el_limite_el_borrador_no_se_puede_publicar_sin_acortarlo(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $grupo   = $this->grupo();
        $this->asignarDocente($this->docente, $grupo->id, $materia->id);

        $exam = $this->examen($this->docente, $materia, 120);
        Question::factory()->create(['institution_id' => $this->centro->id, 'exam_id' => $exam->id]);

        $this->centro->update(['settings' => ['max_exam_duration' => 90]]);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'published'])
            ->assertStatus(422);
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    public function test_un_examen_publicado_por_encima_del_limite_no_se_puede_activar(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->asignarDocente($this->docente, $this->grupo()->id, $materia->id);

        // Publicado cuando el límite lo permitía; el director lo bajó después
        // (no había exámenes activos, así que pudo hacerlo).
        $exam = $this->examen($this->docente, $materia, 120);
        $exam->update(['status' => 'published']);
        $this->centro->update(['settings' => ['max_exam_duration' => 90]]);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '90'));
        $this->assertSame('published', $exam->fresh()->status->value);

        // Acortado al límite, ya se puede activar.
        $this->putJson("/api/exams/{$exam->id}", ['duration_minutes' => 90])->assertOk();
        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();
    }

    public function test_un_examen_sin_autor_solo_lo_ve_el_administrador(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $huerfano = $this->examen($this->docente, $materia);
        $huerfano->update(['created_by_teacher_id' => null]);   // cuenta del autor borrada

        $this->getJson("/api/exams/{$huerfano->id}")->assertNotFound();
        $ids = collect($this->getJson('/api/exams')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($huerfano->id, $ids);

        $this->signInAdmin(['institution_id' => $this->centro->id]);
        $this->getJson("/api/exams/{$huerfano->id}")->assertOk();
    }

    public function test_sin_ajuste_propio_rige_el_valor_por_defecto_de_la_institucion(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $base = ['title' => 'Parcial', 'subject_id' => $materia->id, 'grade' => 4];

        $this->postJson('/api/exams', $base + ['duration_minutes' => Institution::$defaultSettings['max_exam_duration'] + 1])
            ->assertStatus(422);
    }

    /* ---------------- reajustar el máximo ---------------- */

    public function test_el_maximo_no_se_cambia_mientras_haya_examenes_activos(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $activo  = $this->examen($this->docente, $materia);
        $activo->update(['status' => 'active']);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->putJson('/api/system/config', ['max_exam_duration' => 90])
            ->assertStatus(409)->assertJsonPath('examenes_activos', 1);

        $this->assertArrayNotHasKey('max_exam_duration', Institution::find($this->centro->id)->settings ?? []);

        // Otros ajustes no se bloquean, ni reenviar el mismo valor.
        $this->putJson('/api/system/config', ['language' => 'en'])->assertOk();
        $this->putJson('/api/system/config', ['max_exam_duration' => Institution::$defaultSettings['max_exam_duration']])->assertOk();
    }

    public function test_el_maximo_se_cambia_cuando_los_examenes_estan_inactivos_o_ya_pasaron(): void
    {
        $materia = Subject::factory()->create(['institution_id' => $this->centro->id]);

        // Borrador, publicado sin abrir, completado y activo con la ventana vencida.
        foreach (['draft', 'published', 'completed'] as $estado) {
            $this->examen($this->docente, $materia)->update(['status' => $estado]);
        }
        $this->examen($this->docente, $materia)->update([
            'status' => 'active', 'available_until' => now()->subDay(),
        ]);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->putJson('/api/system/config', ['max_exam_duration' => 90])
            ->assertOk()->assertJsonPath('data.config.max_exam_duration', 90);
    }

    public function test_un_activo_de_otra_institucion_no_bloquea_el_cambio(): void
    {
        $otro = Institution::factory()->create();
        $docenteAjeno = User::factory()->teacher()->create(['institution_id' => $otro->id]);
        Exam::factory()->create([
            'institution_id' => $otro->id, 'created_by_teacher_id' => $docenteAjeno->id, 'status' => 'active',
        ]);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->putJson('/api/system/config', ['max_exam_duration' => 90])->assertOk();
    }

    /* ---------------- helpers ---------------- */

    private function grupo(): Group
    {
        return Group::factory()->create(['institution_id' => $this->centro->id]);
    }

    private function examen(User $autor, Subject $materia, int $minutos = 45): Exam
    {
        return Exam::factory()->create([
            'institution_id'        => $this->centro->id,
            'created_by_teacher_id' => $autor->id,
            'subject_id'            => $materia->id,
            'duration_minutes'      => $minutos,
        ]);
    }
}
