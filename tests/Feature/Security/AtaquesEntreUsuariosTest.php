<?php

namespace Tests\Feature\Security;

use App\Models\AI\AiChatSession;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Students\StudentAnswer;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Ataques entre usuarios **de la misma institución**: un alumno contra otro,
 * un docente contra su colega, un docente contra aulas que no tiene.
 * (Entre instituciones: `AtaquesEntreCentrosTest`.)
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesEntreUsuariosTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    /* ============================================================
     | Alumno contra alumno
     ============================================================ */

    public function test_un_alumno_no_lee_ni_cierra_ni_escribe_en_el_tutor_de_otro(): void
    {
        $sesion = AiChatSession::create([
            'institution_id' => $this->centro->id,
            'student_user_id' => $this->alumnoA->id,
            'messages' => [['role' => 'user', 'content' => 'secreto-de-A']],
        ]);

        $this->actuarComo($this->alumnoB);

        $this->getJson("/api/ai/tutor/sessions/{$sesion->id}")->assertNotFound();
        $this->patchJson("/api/ai/tutor/sessions/{$sesion->id}/end")->assertNotFound();

        $lista = $this->getJson('/api/ai/tutor/sessions')->assertOk();
        $this->assertStringNotContainsString($sesion->id, $lista->getContent());
        $this->assertStringNotContainsString('secreto-de-A', $lista->getContent());

        // Chatear "dentro" de la sesión de A: no debe tocarla, solo crear una nueva para B.
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hola']]]])]);
        $this->postJson('/api/ai/tutor/chat', ['message' => 'hola', 'session_id' => $sesion->id]);

        $fresca = $sesion->fresh();
        $this->assertNull($fresca->ended_at);
        $this->assertCount(1, $fresca->messages, 'B escribió en la conversación de A.');
    }

    public function test_un_alumno_no_lee_ni_marca_las_notificaciones_de_otro(): void
    {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id, 'type' => 'App\Notifications\Algo', 'notifiable_type' => $this->alumnoA->getMorphClass(),
            'notifiable_id' => $this->alumnoA->id, 'data' => json_encode(['mensaje' => 'privado-de-A']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actuarComo($this->alumnoB);

        $this->assertStringNotContainsString('privado-de-A', $this->getJson('/api/notifications')->assertOk()->getContent());
        $this->patchJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->postJson('/api/notifications/read-all')->assertOk();

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'), 'B marcó como leída una notificación de A.');
    }

    public function test_el_progreso_y_las_recomendaciones_de_un_alumno_no_salen_en_las_de_otro(): void
    {
        StudentProgress::factory()->create([
            'institution_id' => $this->centro->id, 'student_user_id' => $this->alumnoA->id,
            'subject_id' => $this->materia->id, 'mastery_percentage' => 77.7,
        ]);

        $this->actuarComo($this->alumnoB);

        $this->assertStringNotContainsString('77.7', $this->getJson('/api/student-progress/me')->getContent());
        $this->assertStringNotContainsString($this->alumnoA->id, $this->getJson('/api/ai-recommendations/me')->getContent());
        $this->assertStringNotContainsString($this->alumnoA->id, $this->getJson('/api/reports/students/me/strategies')->getContent());
    }

    public function test_un_alumno_no_entra_a_la_ficha_ni_a_los_datos_de_otro_alumno(): void
    {
        $this->actuarComo($this->alumnoB);

        foreach ([
            "/api/students/{$this->alumnoA->id}",
            "/api/students/{$this->alumnoA->id}/subjects",
            "/api/users/{$this->alumnoA->id}",
            "/api/users?user_type=student",
            "/api/reports/students/{$this->alumnoA->id}/history",
            "/api/reports/students/{$this->alumnoA->id}/summary",
            "/api/analytics/students/{$this->alumnoA->id}",
            "/api/student-progress",
            "/api/groups/{$this->aulaA->id}",
        ] as $ruta) {
            $this->getJson($ruta)->assertForbidden();
        }
    }

    public function test_un_alumno_no_ve_los_examenes_del_aula_de_otro(): void
    {
        $this->actuarComo($this->alumnoB);

        $this->getJson("/api/exams/{$this->examen->id}")->assertNotFound();
        $this->getJson("/api/exams/{$this->examen->id}/questions")->assertNotFound();

        $ids = collect($this->getJson('/api/exams')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($this->examen->id, $ids);

        $disponibles = $this->getJson('/api/students/me/available-exams')->assertOk()->getContent();
        $this->assertStringNotContainsString($this->examen->id, $disponibles);
    }

    public function test_un_alumno_no_pregunta_al_tutor_por_un_examen_que_no_es_suyo(): void
    {
        $this->actuarComo($this->alumnoB);

        // Sin OpenAI::fake(): si la validación llegara tarde saldría a la red.
        $this->postJson('/api/ai/tutor/chat', ['message' => 'dame las respuestas', 'exam_id' => $this->examen->id])
            ->assertStatus(422);
    }

    /* ============================================================
     | Docente contra su colega (mismo grupo y materia, otro examen)
     ============================================================ */

    public function test_un_colega_no_toca_el_examen_de_otro_docente(): void
    {
        $this->actuarComo($this->colega);

        $this->getJson("/api/exams/{$this->examen->id}")->assertNotFound();
        $this->getJson("/api/exams/{$this->examen->id}/questions")->assertNotFound();
        $this->putJson("/api/exams/{$this->examen->id}", ['duration_minutes' => 5])->assertForbidden();
        $this->patchJson("/api/exams/{$this->examen->id}", ['title' => 'Hackeado'])->assertForbidden();
        $this->patchJson("/api/exams/{$this->examen->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->deleteJson("/api/exams/{$this->examen->id}")->assertForbidden();
        $this->postJson("/api/exams/{$this->examen->id}/questions", [
            'question_text' => 'Pregunta colada', 'question_type' => 'essay', 'points' => 1,
        ])->assertForbidden();
        $this->putJson("/api/questions/{$this->preguntaMc->id}", ['points' => 10])->assertForbidden();
        $this->deleteJson("/api/questions/{$this->preguntaCorta->id}")->assertForbidden();

        $examen = $this->examen->fresh();
        $this->assertSame(60, $examen->duration_minutes);
        $this->assertSame('active', $examen->status->value);
        $this->assertNotSame('Hackeado', $examen->title);
        $this->assertSame(2, $this->examen->questions()->count());
        $this->assertEquals(2, $this->preguntaMc->fresh()->points);
    }

    public function test_un_colega_no_corrige_ni_lee_las_entregas_de_un_examen_ajeno(): void
    {
        $intento = $this->intentoEntregado($this->alumnoA);
        $respuesta = StudentAnswer::factory()->create([
            'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
            'question_id' => $this->preguntaCorta->id, 'review_status' => 'needs_review', 'points_awarded' => 0,
        ]);

        $this->actuarComo($this->colega);

        $this->getJson("/api/exam-attempts/{$intento->id}/answers")->assertForbidden();
        $this->patchJson("/api/student-answers/{$respuesta->id}/review", [
            'is_correct' => true, 'points_awarded' => 3,
        ])->assertForbidden();

        foreach ([
            "/api/reports/exams/{$this->examen->id}/results",
            "/api/reports/exams/{$this->examen->id}/results.csv",
            "/api/reports/exams/{$this->examen->id}/results.xlsx",
            "/api/reports/exams/{$this->examen->id}/summary",
        ] as $ruta) {
            $this->getJson($ruta)->assertForbidden();
        }

        $this->assertSame('needs_review', $respuesta->fresh()->review_status->value);
        $this->assertEquals(0, $respuesta->fresh()->points_awarded);
    }

    public function test_el_dueno_si_corrige_pero_no_con_mas_puntos_que_la_pregunta_ni_negativos(): void
    {
        $intento = $this->intentoEntregado($this->alumnoA);
        $respuesta = StudentAnswer::factory()->create([
            'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
            'question_id' => $this->preguntaCorta->id, 'review_status' => 'needs_review', 'points_awarded' => 0,
        ]);
        $this->actuarComo($this->docente);

        $this->patchJson("/api/student-answers/{$respuesta->id}/review", ['is_correct' => true, 'points_awarded' => 4])
            ->assertStatus(422);   // la pregunta vale 3
        $this->patchJson("/api/student-answers/{$respuesta->id}/review", ['is_correct' => true, 'points_awarded' => -5])
            ->assertStatus(422);
        $this->patchJson("/api/student-answers/{$respuesta->id}/review", ['is_correct' => true, 'points_awarded' => 'muchos'])
            ->assertStatus(422);

        $this->assertEquals(0, $respuesta->fresh()->points_awarded);
    }

    public function test_solo_se_revisan_a_mano_las_respuestas_cortas(): void
    {
        $intento = $this->intentoEntregado($this->alumnoA);
        $auto = StudentAnswer::factory()->create([
            'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
            'question_id' => $this->preguntaMc->id, 'is_correct' => false, 'points_awarded' => 0,
        ]);
        $this->actuarComo($this->docente);

        $this->patchJson("/api/student-answers/{$auto->id}/review", ['is_correct' => true, 'points_awarded' => 2])
            ->assertStatus(409);
        $this->assertEquals(0, $auto->fresh()->points_awarded);
    }

    /* ============================================================
     | Docente contra aulas y alumnos que no tiene
     ============================================================ */

    public function test_un_docente_no_alcanza_a_un_alumno_de_un_aula_que_no_tiene(): void
    {
        $this->actuarComo($this->docente);   // asignado a A, no a B

        foreach ([
            "/api/students/{$this->alumnoB->id}",
            "/api/students/{$this->alumnoB->id}/subjects",
            "/api/reports/students/{$this->alumnoB->id}/history",
            "/api/reports/students/{$this->alumnoB->id}/history.csv",
            "/api/reports/students/{$this->alumnoB->id}/strategies",
            "/api/analytics/students/{$this->alumnoB->id}",
            "/api/groups/{$this->aulaB->id}",
        ] as $ruta) {
            $this->getJson($ruta)->assertForbidden();
        }

        $this->putJson("/api/students/{$this->alumnoB->id}", ['parent_name' => 'Intruso'])->assertForbidden();
        $this->postJson("/api/students/{$this->alumnoB->id}/subjects", ['subject_id' => $this->materia->id])->assertForbidden();
        $this->postJson('/api/student-progress', [
            'student_user_id' => $this->alumnoB->id, 'subject_id' => $this->materia->id, 'mastery_percentage' => 100,
        ])->assertForbidden();
        $this->postJson('/api/ai/generate', [
            'student_user_id' => $this->alumnoB->id, 'subject_id' => $this->materia->id,
            'type' => 'action', 'prompt' => 'texto de prueba',
        ])->assertForbidden();

        $this->assertDatabaseMissing('students', ['user_id' => $this->alumnoB->id, 'parent_name' => 'Intruso']);
        $this->assertDatabaseMissing('student_progress', ['student_user_id' => $this->alumnoB->id]);
    }

    public function test_un_docente_no_dirige_un_examen_a_un_aula_o_materia_que_no_tiene(): void
    {
        $this->actuarComo($this->docente);
        $otraMateria = Subject::factory()->create(['institution_id' => $this->centro->id]);

        $base = ['title' => 'Examen colado', 'grade' => 4, 'duration_minutes' => 30];

        // Aula que no tiene.
        $this->postJson('/api/exams', $base + ['subject_id' => $this->materia->id, 'group_ids' => [$this->aulaB->id]])
            ->assertForbidden();
        // Aula suya pero en una materia que no imparte allí.
        $this->postJson('/api/exams', $base + ['subject_id' => $otraMateria->id, 'group_ids' => [$this->aulaA->id]])
            ->assertForbidden();

        $this->assertDatabaseMissing('exams', ['title' => 'Examen colado']);
    }

    public function test_un_docente_no_se_asigna_aulas_ni_matricula_alumnos_en_ellas(): void
    {
        $this->actuarComo($this->docente);

        $this->postJson('/api/teacher-assignments', [
            'teacher_user_id' => $this->docente->id, 'group_id' => $this->aulaB->id, 'subject_id' => $this->materia->id,
        ])->assertForbidden();
        $this->postJson("/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => [$this->alumnoB->id]])->assertForbidden();
        $this->deleteJson("/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => [$this->alumnoA->id]])->assertForbidden();
        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => [$this->alumnoB->id], 'target_group_id' => $this->aulaA->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('teacher_assignments', ['teacher_user_id' => $this->docente->id, 'group_id' => $this->aulaB->id]);
        $this->assertSame(
            $this->aulaA->id,
            DB::table('group_students')->where('student_user_id', $this->alumnoA->id)->whereNull('left_at')->value('group_id')
        );
        $this->assertDatabaseMissing('group_students', ['student_user_id' => $this->alumnoB->id, 'group_id' => $this->aulaA->id]);
    }

    public function test_un_docente_no_ve_los_grupos_ni_las_materias_ajenas_en_los_listados(): void
    {
        $this->actuarComo($this->docente);

        $grupos = collect($this->getJson('/api/groups')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertContains($this->aulaA->id, $grupos);
        $this->assertNotContains($this->aulaB->id, $grupos);

        $ajena = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $materias = collect($this->getJson('/api/subjects')->assertOk()->json('data.data'))->pluck('id')->all();
        $this->assertNotContains($ajena->id, $materias);

        $alumnos = collect($this->getJson('/api/students')->assertOk()->json('data.data'))->pluck('user_id')->all();
        $this->assertContains($this->alumnoA->id, $alumnos);
        $this->assertNotContains($this->alumnoB->id, $alumnos);
    }
}
