<?php

namespace Tests\Feature\Perf;

use App\Models\Academic\CalendarEvent;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Presupuesto de consultas de las ESCRITURAS (con la base remota cada viaje cuesta ~0,4 s) y
 * equivalencia de lo que se escribe «en una sentencia» con lo que dejaba la ruta de siempre.
 *
 * El token no se cuenta (`actingAs` lo salta) y las transacciones (BEGIN/COMMIT) tampoco: cada una
 * son dos viajes más, así que donde hay transacción el tope lo dice.
 */
class EscriturasEnLineaTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    /** Las consultas de la última medición, para que un fallo diga cuáles fueron. */
    private array $sql = [];

    private bool $midiendo = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Un solo oyente por test (no se pueden quitar): solo anota mientras se mide.
        DB::listen(function ($e) {
            if ($this->midiendo) {
                $this->sql[] = preg_replace('/\s+/', ' ', substr($e->sql, 0, 140));
            }
        });
        // Las colas corren en línea en los tests: sin esto, el trabajo del worker contaría como
        // consultas de la petición.
        \Illuminate\Support\Facades\Queue::fake();
    }

    private function consultas(callable $peticion): int
    {
        $this->sql = [];
        $this->midiendo = true;
        $peticion();
        $this->midiendo = false;

        return count($this->sql);
    }

    private function tope(int $maximo, int $n, string $que = ''): void
    {
        $this->assertLessThanOrEqual($maximo, $n, "{$que} {$n} consultas (tope {$maximo}):
  " . implode("
  ", $this->sql));
    }

    private function pregunta(array $extra = []): array
    {
        return $extra + [
            'question_text' => '¿Cuánto es 2+2?', 'question_type' => 'multiple_choice', 'points' => 2, 'topic' => 'suma',
            'options' => [
                ['option_index' => 1, 'option_text' => '4', 'is_correct' => true],
                ['option_index' => 2, 'option_text' => '5', 'is_correct' => false],
                ['option_index' => 3, 'option_text' => '6', 'is_correct' => false],
            ],
        ];
    }

    /* =========================
     |  Preguntas
     ========================= */

    public function test_crear_una_pregunta_es_una_sentencia_y_devuelve_lo_mismo_que_el_listado(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $borrador = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id,
            'subject_id' => $this->materia->id, 'status' => 'draft',
        ]);
        $this->actuarComo($this->docente);

        // Binding del examen + la sentencia de la pregunta.
        $n = $this->consultas(fn () => $res = $this->postJson("/api/exams/{$borrador->id}/questions", $this->pregunta())->assertCreated());
        $this->tope(2, $n);

        $creada = $this->postJson("/api/exams/{$borrador->id}/questions", $this->pregunta(['question_text' => 'Segunda', 'topic' => null]))
            ->assertCreated()->json('data');
        $listada = collect($this->getJson("/api/exams/{$borrador->id}/questions")->json('data'))->firstWhere('id', $creada['id']);

        // Mismo contenido que el listado; el orden sigue al último (1, 2) y las opciones salen con id.
        $this->assertSame(2, $creada['order_index']);
        $this->assertCount(3, $creada['options']);
        $this->assertTrue($creada['options'][0]['is_correct']);
        $this->assertSame($listada['options'], $creada['options']);
        foreach (['question_text', 'question_type', 'points', 'topic', 'order_index', 'exam_id'] as $campo) {
            $this->assertSame($listada[$campo], $creada[$campo], $campo);
        }
        $this->assertSame($this->centro->id, Question::find($creada['id'])->institution_id);
    }

    public function test_una_pregunta_de_respuesta_corta_y_una_con_orden_explicito(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->docente);

        $corta = $this->postJson("/api/exams/{$this->examen->id}/questions", [
            'question_text' => 'Explica', 'question_type' => 'short_answer', 'points' => 3, 'correct_answer_text' => 'esto',
            'order_index' => 9,
        ])->assertCreated()->json('data');

        $this->assertSame([], $corta['options']);
        $this->assertSame(9, $corta['order_index']);
        $this->assertSame('esto', $corta['correct_answer_text']);
    }

    public function test_editar_y_borrar_una_pregunta_son_dos_consultas(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $borrador = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id,
            'subject_id' => $this->materia->id, 'status' => 'draft',
        ]);
        $this->actuarComo($this->docente);
        $a = $this->postJson("/api/exams/{$borrador->id}/questions", $this->pregunta())->json('data.id');
        $b = $this->postJson("/api/exams/{$borrador->id}/questions", $this->pregunta(['question_text' => 'Otra']))->json('data.id');

        // La pregunta con su examen, opciones e intentos en una consulta + el UPDATE.
        $n = $this->consultas(fn () => $this->putJson("/api/questions/{$a}", ['question_text' => 'Editada'])->assertOk());
        $this->tope(2, $n);
        $this->assertSame('Editada', $this->putJson("/api/questions/{$a}", ['question_text' => 'Editada'])->json('data.question_text'));
        $this->assertCount(3, $this->putJson("/api/questions/{$a}", ['question_text' => 'Editada'])->json('data.options'));

        // Reemplazar las opciones (varias sentencias en una transacción) devuelve las nuevas, con id.
        $nuevas = $this->putJson("/api/questions/{$a}", ['options' => [
            ['option_index' => 1, 'option_text' => 'Sí', 'is_correct' => true],
            ['option_index' => 2, 'option_text' => 'No', 'is_correct' => false],
        ]])->assertOk()->json('data.options');
        $this->assertSame(['Sí', 'No'], array_column($nuevas, 'option_text'));
        $this->assertSame(2, DB::table('question_options')->where('question_id', $a)->count());

        $n = $this->consultas(fn () => $this->deleteJson("/api/questions/{$b}")->assertNoContent());
        $this->tope(2, $n);

        // La última no se borra; con intentos tampoco.
        $this->deleteJson("/api/questions/{$a}")->assertStatus(409);
    }

    /* =========================
     |  Aulas, avisos, materias
     ========================= */

    public function test_matricular_y_dar_de_baja_en_un_aula_es_una_sentencia_y_deja_bien_el_recuento(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->admin);

        $otroCentro = Institution::factory()->create();
        $ajeno = User::factory()->student()->create(['institution_id' => $otroCentro->id]);
        Student::factory()->create(['user_id' => $ajeno->id, 'institution_id' => $otroCentro->id]);
        $nuevo = $this->nuevoAlumno($this->aulaB);   // empieza en el aula B
        $ids = [$nuevo->id, $ajeno->id];

        $n = $this->consultas(fn () => $this->postJson("/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => $ids])->assertOk());
        $this->assertSame(1, $n);

        // Se matricula al del centro y NO al ajeno; el recuento cuenta las matrículas activas (alumnoA + nuevo).
        $res = $this->postJson("/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => $ids])->assertOk();
        $this->assertSame(2, $res->json('data.group.student_count'));
        $this->assertSame(0, DB::table('group_students')->where('student_user_id', $ajeno->id)->count());

        // Dar de baja a quien no está no cambia nada; a quien está, baja el recuento.
        $res = $this->deleteJson("/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => [$nuevo->id, $ajeno->id]])->assertOk();
        $this->assertSame(1, $res->json('data.group.student_count'));
        $this->assertSame(1, DB::table('groups')->where('id', $this->aulaA->id)->value('student_count'));

        // Reactivar conserva la fila (y el recuento vuelve a 2); un aula que no existe es 404.
        $res = $this->postJson("/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => [$nuevo->id]])->assertOk();
        $this->assertSame(2, $res->json('data.group.student_count'));
        $this->postJson('/api/groups/00000000-0000-4000-8000-000000000000/students', ['student_user_ids' => [$nuevo->id]])->assertNotFound();
        // El aula de OTRO centro tampoco se toca.
        $aulaAjena = \App\Models\Academic\Group::factory()->create(['institution_id' => $otroCentro->id]);
        $this->postJson("/api/groups/{$aulaAjena->id}/students", ['student_user_ids' => [$nuevo->id]])->assertNotFound();
    }

    public function test_crear_y_editar_un_aviso_no_lee_lo_que_ya_sabe(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->docente);

        $cuerpo = [
            'title' => 'Aviso', 'start_at' => now()->addDay()->toIso8601String(), 'end_at' => now()->addDay()->addHour()->toIso8601String(),
            'event_type' => 'activity', 'group_id' => $this->aulaA->id, 'exam_id' => $this->examen->id,
        ];
        $this->postJson('/api/calendar-events', $cuerpo)->assertCreated(); // calienta cachés

        $res = null;
        $n = $this->consultas(function () use ($cuerpo, &$res) {
            $res = $this->postJson('/api/calendar-events', $cuerpo)->assertCreated();
        });
        // Examen visible + aulas asignadas + el INSERT + el aviso a la cola.
        $this->tope(4, $n);

        $evento = $res->json('data.0');
        $this->assertSame($this->docente->full_name, $evento['creator']['full_name']);
        $this->assertSame($this->aulaA->id, $evento['group']['id']);
        $this->assertSame($this->examen->title, $evento['exam']['title']);
        $this->assertArrayNotHasKey('email', $evento['creator']);

        $n = $this->consultas(fn () => $this->putJson("/api/calendar-events/{$evento['id']}", ['title' => 'Nuevo'])->assertOk());
        $this->tope(3, $n);
        $editado = $this->putJson("/api/calendar-events/{$evento['id']}", ['title' => 'Nuevo'])->json('data');
        $this->assertSame('Nuevo', $editado['title']);
        $this->assertSame($this->examen->title, $editado['exam']['title']);
    }

    public function test_crear_y_editar_un_examen_responde_con_su_materia_docente_y_aulas(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->docente);

        $cuerpo = ['title' => 'Nuevo examen', 'subject_id' => $this->materia->id, 'grade' => 5, 'duration_minutes' => 30, 'group_ids' => [$this->aulaA->id]];
        $this->postJson('/api/exams', $cuerpo)->assertCreated(); // calienta cachés

        $res = null;
        $n = $this->consultas(function () use ($cuerpo, &$res) {
            $res = $this->postJson('/api/exams', $cuerpo)->assertCreated();
        });
        // Aulas asignadas + INSERT del examen + INSERT de las aulas.
        $this->tope(3, $n);

        $examen = $res->json('data');
        $this->assertSame(['id', 'name'], array_keys($examen['subject']));
        $this->assertSame($this->docente->full_name, $examen['teacher']['full_name']);
        $this->assertSame($this->aulaA->id, $examen['groups'][0]['id']);
        $this->assertSame($examen['id'], $examen['groups'][0]['pivot']['exam_id']);
        $this->assertSame($this->aulaA->id, $examen['groups'][0]['pivot']['group_id']);

        // Una materia que no es del centro se rechaza con 422, como antes.
        $this->postJson('/api/exams', ['subject_id' => '00000000-0000-4000-8000-000000000000'] + $cuerpo)->assertStatus(422);

        $editado = $this->putJson("/api/exams/{$examen['id']}", ['title' => 'Cambiado'])->assertOk()->json('data');
        $this->assertSame('Cambiado', $editado['title']);
        $this->assertSame($this->aulaA->id, $editado['groups'][0]['id']);
        $this->assertSame($this->materia->name, $editado['subject']['name']);
    }

    public function test_un_recurso_nuevo_responde_con_autor_materia_y_aulas(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->docente);

        $cuerpo = ['title' => 'Recurso', 'resource_type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abc123',
            'subject_id' => $this->materia->id, 'group_ids' => [$this->aulaA->id]];
        $this->postJson('/api/study-resources', $cuerpo)->assertCreated();

        $res = null;
        $n = $this->consultas(function () use ($cuerpo, &$res) {
            $res = $this->postJson('/api/study-resources', $cuerpo)->assertCreated();
        });
        $this->tope(3, $n);

        $recurso = $res->json('data');
        $this->assertSame($this->docente->full_name, $recurso['creator']['full_name']);
        $this->assertSame($this->materia->name, $recurso['subject']['name']);
        $this->assertSame($this->aulaA->id, $recurso['groups'][0]['id']);
        $this->assertSame($recurso['id'], $recurso['groups'][0]['pivot']['study_resource_id']);

        $this->putJson("/api/study-resources/{$recurso['id']}", ['title' => 'Otro'])->assertOk()
            ->assertJsonPath('data.title', 'Otro')->assertJsonPath('data.groups.0.id', $this->aulaA->id);
    }

    public function test_la_asignacion_docente_responde_con_aulas_y_materias_en_una_lectura(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->admin);
        $docente = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);

        $cuerpo = ['teacher_user_id' => $docente->id, 'group_ids' => [$this->aulaA->id], 'subject_ids' => [$this->materia->id]];
        $this->postJson('/api/teacher-assignments', $cuerpo)->assertCreated();

        $res = null;
        $n = $this->consultas(function () use ($cuerpo, &$res) {
            $res = $this->postJson('/api/teacher-assignments', $cuerpo)->assertCreated();
        });
        // El docente + el INSERT + las asignaciones con aula y materia unidas.
        $this->tope(3, $n);

        $fila = $res->json('data.asignaciones.0');
        $this->assertSame($this->aulaA->id, $fila['group']['id']);
        $this->assertSame($this->materia->name, $fila['subject']['name']);

        // Un aula o materia de otro centro no cuenta.
        $this->postJson('/api/teacher-assignments', ['group_ids' => ['00000000-0000-4000-8000-000000000000']] + $cuerpo)->assertStatus(422);
    }

    /* =========================
     |  Alumnado
     ========================= */

    public function test_progreso_y_matricula_individual_son_tres_consultas_o_menos(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->docente);
        $id = $this->alumnoA->id;

        $this->postJson('/api/student-progress', ['student_user_id' => $id, 'subject_id' => $this->materia->id, 'mastery_percentage' => 60])->assertCreated();
        $n = $this->consultas(fn () => $this->postJson('/api/student-progress', ['student_user_id' => $id, 'subject_id' => $this->materia->id, 'mastery_percentage' => 75])->assertCreated());
        $this->tope(3, $n);
        $fila = $this->postJson('/api/student-progress', ['student_user_id' => $id, 'subject_id' => $this->materia->id, 'mastery_percentage' => 80])->json('data');
        $this->assertSame($this->alumnoA->full_name, $fila['student']['user']['full_name']);
        $this->assertSame($this->materia->id, $fila['subject']['id']);
        $this->assertEquals(80, StudentProgress::where('student_user_id', $id)->first()->mastery_percentage);
        $this->assertSame(1, StudentProgress::where('student_user_id', $id)->count(), 'el upsert no duplica');

        // Matrícula individual: dos veces → 201 y 409, sin duplicar la fila.
        $n = $this->consultas(fn () => $this->postJson("/api/students/{$id}/subjects", ['subject_id' => $this->materia->id])->assertCreated());
        $this->tope(3, $n);
        $this->postJson("/api/students/{$id}/subjects", ['subject_id' => $this->materia->id])->assertStatus(409);
        $this->assertSame(1, DB::table('student_subjects')->where('student_user_id', $id)->count());

        // Un docente sin acceso al alumno, 403; una materia que no es del centro, 422.
        $this->postJson("/api/students/{$this->alumnoB->id}/subjects", ['subject_id' => $this->materia->id])->assertForbidden();
        $this->postJson("/api/students/{$id}/subjects", ['subject_id' => '00000000-0000-4000-8000-000000000000'])->assertStatus(422);

        // Editar la ficha: el alumno con su usuario y el UPDATE.
        $n = $this->consultas(fn () => $this->putJson("/api/students/{$id}", ['parent_name' => 'Padre'])->assertOk());
        $this->tope(2, $n);
        $this->putJson("/api/students/{$id}", ['parent_name' => 'Padre'])
            ->assertJsonPath('data.parent_name', 'Padre')->assertJsonPath('data.user.full_name', $this->alumnoA->full_name);
    }

    /* =========================
     |  Intentos
     ========================= */

    public function test_pausar_reanudar_y_entregar_un_intento_hacen_pocas_consultas(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->alumnoA);

        $intento = $this->postJson("/api/exams/{$this->examen->id}/attempts/start")->assertCreated()->json('data');
        $base = "/api/exams/{$this->examen->id}/attempts/{$intento['id']}";

        $n = $this->consultas(fn () => $this->patchJson("{$base}/pause")->assertOk());
        $this->tope(2, $n);
        $n = $this->consultas(fn () => $this->patchJson("{$base}/resume")->assertOk());
        $this->tope(2, $n);

        // Entregar: intento+examen+alumno, preguntas con opciones, calificar (1), progreso (1), recomendaciones.
        $respuestas = ['answers' => [
            ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$this->opcionCorrecta]],
            ['question_id' => $this->preguntaCorta->id, 'answer_text' => 'respuesta-secreta'],
        ]];
        $res = null;
        $n = $this->consultas(function () use ($base, $respuestas, &$res) {
            $res = $this->postJson("{$base}/submit", $respuestas)->assertOk();
        });
        $this->tope(8, $n);

        // Lo escrito en la sentencia única: respuestas, opciones y la nota del intento.
        $this->assertSame(2, DB::table('student_answers')->where('attempt_id', $intento['id'])->count());
        $this->assertSame(1, DB::table('student_answer_options')->whereIn(
            'student_answer_id',
            DB::table('student_answers')->where('attempt_id', $intento['id'])->pluck('id')
        )->count());
        $fila = DB::table('exam_attempts')->where('id', $intento['id'])->first();
        $this->assertNotNull($fila->submitted_at);
        $this->assertEquals(5, $fila->score);          // 2 + 3
        $this->assertSame('completed', $fila->grade_status);
        $this->assertEquals(100, $res->json('data.percentage'));
        $this->assertNotEmpty($res->json('data.recommendations'));
        $this->assertEquals(100, StudentProgress::where('student_user_id', $this->alumnoA->id)->value('mastery_percentage'));
        $this->assertSame(1, (int) Student::where('user_id', $this->alumnoA->id)->value('exams_completed_count'));

        // Entregar dos veces sigue siendo 409; el intento de otro alumno, 404.
        $this->postJson("{$base}/submit", $respuestas)->assertStatus(409);
        $this->actuarComo($this->alumnoB);
        $this->postJson("{$base}/submit", $respuestas)->assertNotFound();
        $this->patchJson("{$base}/pause")->assertNotFound();
    }

    public function test_revisar_una_respuesta_corta_recalcula_la_nota_y_el_progreso(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $intento = $this->intentoAbierto($this->alumnoA);
        $respuesta = \App\Models\Students\StudentAnswer::create([
            'institution_id' => $this->centro->id, 'attempt_id' => $intento->id, 'question_id' => $this->preguntaCorta->id,
            'answer_text' => 'casi', 'is_correct' => false, 'points_awarded' => 0, 'answered_at' => now(), 'review_status' => 'needs_review',
        ]);
        $intento->update(['submitted_at' => now(), 'grade_status' => 'pending', 'max_score' => 5]);

        $this->actuarComo($this->docente);
        $res = null;
        $n = $this->consultas(function () use ($respuesta, &$res) {
            $res = $this->patchJson("/api/student-answers/{$respuesta->id}/review", ['is_correct' => true, 'points_awarded' => 3])->assertOk();
        });
        $this->tope(6, $n);

        $this->assertSame('reviewed', $res->json('data.studentAnswer.review_status'));
        $this->assertArrayNotHasKey('question', $res->json('data.studentAnswer'));
        $this->assertEquals(3, $res->json('data.attempt.score'));
        $this->assertSame('completed', $res->json('data.attempt.grade_status'));
        $this->assertEquals(100, StudentProgress::where('student_user_id', $this->alumnoA->id)->value('mastery_percentage')); // 3 de 3 puntos de lo contestado

        // Un docente ajeno no revisa; un id inexistente es 404.
        $this->actuarComo($this->colega);
        $this->patchJson("/api/student-answers/{$respuesta->id}/review", ['is_correct' => true, 'points_awarded' => 3])->assertForbidden();
        $this->patchJson('/api/student-answers/00000000-0000-4000-8000-000000000000/review', ['is_correct' => true, 'points_awarded' => 1])->assertNotFound();
    }

    public function test_activar_un_examen_publica_su_calendario_con_una_sentencia(): void
    {
        $this->montarEscenario();
        Institution::estaActiva($this->centro->id); // se cachea: la primera petición de un test pagaría la consulta
        $this->actuarComo($this->docente);

        $examen = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id, 'subject_id' => $this->materia->id,
            'status' => 'published', 'duration_minutes' => 30, 'available_from' => null, 'available_until' => null,
        ]);
        $examen->syncGroups([$this->aulaA->id, $this->aulaB->id]);
        $this->asignarDocente($this->docente, $this->aulaB->id, $this->materia->id);
        // Un evento a mano del aula A: no se duplica.
        CalendarEvent::create([
            'institution_id' => $this->centro->id, 'title' => 'A mano', 'start_at' => now(), 'end_at' => now()->addHour(),
            'event_type' => 'exam', 'exam_id' => $examen->id, 'group_id' => $this->aulaA->id, 'created_by' => $this->docente->id,
        ]);

        $this->patchJson("/api/exams/{$examen->id}/status", ['status' => 'active'])->assertOk();

        $eventos = CalendarEvent::where('exam_id', $examen->id)->get();
        $this->assertCount(2, $eventos);
        $this->assertEqualsCanonicalizing([$this->aulaA->id, $this->aulaB->id], $eventos->pluck('group_id')->all());
        $nuevo = $eventos->firstWhere('group_id', $this->aulaB->id);
        $this->assertSame('Examen: ' . $examen->title, $nuevo->title);
        $this->assertSame($this->materia->name, $nuevo->description);
        $this->assertSame($this->docente->id, $nuevo->created_by);
        $this->assertSame($this->centro->id, $nuevo->institution_id);
    }
}
