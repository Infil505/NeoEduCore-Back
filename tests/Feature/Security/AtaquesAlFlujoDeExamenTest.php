<?php

namespace Tests\Feature\Security;

use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\StudentAnswer;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Un alumno que intenta hacer trampa.
 *
 * Cada test es un ataque. **Verde = el sistema se defendió; rojo = hallazgo.**
 * No se toca código de la aplicación: esto solo mira.
 */
class AtaquesAlFlujoDeExamenTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function url(string $ruta, ?ExamAttempt $intento = null): string
    {
        return "/api/exams/{$this->examen->id}/attempts" . ($intento ? "/{$intento->id}" : '') . $ruta;
    }

    /* ============================================================
     | Intentos de más
     ============================================================ */

    /**
     * Con `max_attempts = 1`, abrir varios intentos a la vez y entregarlos
     * todos. `start` solo cuenta los intentos YA entregados, así que mientras
     * ninguno se entregue puede abrir los que quiera.
     */
    public function test_no_se_supera_el_maximo_de_intentos_abriendo_varios_en_paralelo(): void
    {
        $this->actuarComo($this->alumnoA);

        $primero = $this->postJson($this->url('/start'))->assertCreated()->json('data.id');
        $segundo = $this->postJson($this->url('/start'));

        $abiertos = ExamAttempt::where('exam_id', $this->examen->id)->where('student_user_id', $this->alumnoA->id)->count();

        $this->assertLessThanOrEqual(
            1, $abiertos,
            "max_attempts=1 y el alumno abrió {$abiertos} intentos (el 2.º start respondió {$segundo->getStatusCode()})."
        );
    }

    public function test_entregar_dos_intentos_abiertos_no_da_dos_notas_con_un_solo_intento_permitido(): void
    {
        $a = $this->intentoAbierto($this->alumnoA);
        $b = $this->intentoAbierto($this->alumnoA);
        $b->update(['attempt_number' => 2]);

        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/submit', $a), ['answers' => $this->respuestasValidas()]);
        $segunda = $this->postJson($this->url('/submit', $b), ['answers' => $this->respuestasValidas()]);

        $entregados = ExamAttempt::where('exam_id', $this->examen->id)
            ->where('student_user_id', $this->alumnoA->id)->whereNotNull('submitted_at')->count();

        $this->assertLessThanOrEqual(
            1, $entregados,
            "max_attempts=1 y hay {$entregados} intentos entregados (el 2.º submit respondió {$segunda->getStatusCode()})."
        );
    }

    public function test_un_intento_entregado_no_se_vuelve_a_entregar_ni_cambia_su_nota(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas($this->opcionIncorrecta)])->assertOk();
        $nota = $intento->fresh()->score;

        // Segundo envío con la respuesta correcta: no debe mejorar la nota.
        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas($this->opcionCorrecta)])
            ->assertStatus(409);
        $this->assertEquals($nota, $intento->fresh()->score);
    }

    /* ============================================================
     | Inflar la nota
     ============================================================ */

    public function test_campos_extra_en_la_entrega_no_inflan_la_nota(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        $this->postJson($this->url('/submit', $intento), [
            'score' => 999, 'max_score' => 1, 'grade_status' => 'completed', 'student_user_id' => $this->alumnoB->id,
            'answers' => [
                [
                    'question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$this->opcionIncorrecta],
                    'is_correct' => true, 'points_awarded' => 99, 'review_status' => 'reviewed',
                ],
                [
                    'question_id' => $this->preguntaCorta->id, 'answer_text' => 'mal',
                    'is_correct' => true, 'points_awarded' => 99,
                ],
            ],
        ])->assertOk();

        $fresco = $intento->fresh();
        $this->assertEquals(0, $fresco->score, 'La nota salió de lo que mandó el alumno, no de la corrección.');
        $this->assertSame($this->alumnoA->id, $fresco->student_user_id);
        $this->assertSame(0, StudentAnswer::where('attempt_id', $intento->id)->where('is_correct', true)->count());
    }

    public function test_una_opcion_correcta_de_otra_pregunta_no_puntua(): void
    {
        [, $correctaAjena] = $this->preguntaMultiple($this->examen);   // otra pregunta del mismo examen
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        $this->postJson($this->url('/submit', $intento), ['answers' => [
            ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$correctaAjena]],
            ['question_id' => $this->preguntaCorta->id, 'answer_text' => 'x'],
        ]])->assertOk();

        $this->assertEquals(0, $intento->fresh()->score, 'Se aceptó como correcta la opción de otra pregunta.');
    }

    public function test_responder_preguntas_de_otro_examen_no_crea_respuestas_ajenas(): void
    {
        $otro = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->colega->id,
            'subject_id' => $this->materia->id, 'status' => 'active',
        ]);
        [$preguntaAjena, $correctaAjena] = $this->preguntaMultiple($otro, 50);

        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        $this->postJson($this->url('/submit', $intento), ['answers' => array_merge($this->respuestasValidas(), [
            ['question_id' => $preguntaAjena->id, 'selected_option_ids' => [$correctaAjena]],
        ])]);

        $this->assertSame(
            0, StudentAnswer::where('attempt_id', $intento->id)->where('question_id', $preguntaAjena->id)->count(),
            'Se guardó una respuesta a una pregunta que no es de este examen.'
        );
        $this->assertLessThanOrEqual($this->examen->questions()->sum('points'), (float) $intento->fresh()->score);
    }

    public function test_responder_dos_veces_la_misma_pregunta_no_suma_dos_veces(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        $this->postJson($this->url('/submit', $intento), ['answers' => [
            ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$this->opcionCorrecta]],
            ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$this->opcionCorrecta]],
            ['question_id' => $this->preguntaCorta->id, 'answer_text' => 'x'],
        ]])->assertOk();

        $this->assertEquals($this->preguntaMc->points, $intento->fresh()->score);
        $this->assertSame(1, StudentAnswer::where('attempt_id', $intento->id)->where('question_id', $this->preguntaMc->id)->count());
    }

    public function test_seleccionar_varias_opciones_en_una_pregunta_de_opcion_unica_se_rechaza(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        // "Marcar todas": con las 4 opciones seleccionadas, alguna será la correcta.
        $todas = [$this->opcionCorrecta, $this->opcionIncorrecta, $this->opcionIncorrecta + 1, $this->opcionIncorrecta + 2];

        $this->postJson($this->url('/submit', $intento), ['answers' => [
            ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => $todas],
            ['question_id' => $this->preguntaCorta->id, 'answer_text' => 'x'],
        ]])->assertStatus(422);

        $this->assertNull($intento->fresh()->submitted_at);
    }

    /* ============================================================
     | Los intentos son de su dueño
     ============================================================ */

    public function test_un_alumno_no_toca_el_intento_de_otro(): void
    {
        $ajeno = $this->intentoAbierto($this->alumnoA);
        // Para que el examen sea "suyo" y el ataque llegue al control de dueño:
        $this->examen->syncGroups([$this->aulaA->id, $this->aulaB->id]);
        $this->actuarComo($this->alumnoB);

        $this->getJson($this->url('', $ajeno))->assertNotFound();
        $this->postJson($this->url('/submit', $ajeno), ['answers' => []])->assertNotFound();
        $this->patchJson($this->url('/pause', $ajeno))->assertNotFound();
        $this->patchJson($this->url('/resume', $ajeno))->assertNotFound();
        $this->getJson("/api/exam-attempts/{$ajeno->id}/recommendations")->assertForbidden();
        $this->postJson("/api/exam-attempts/{$ajeno->id}/recommendations/regenerate")->assertForbidden();

        $this->assertNull($ajeno->fresh()->submitted_at);
        $this->assertNull($ajeno->fresh()->paused_at);
    }

    public function test_el_numero_de_intento_en_la_url_no_sirve_para_colar_otro_examen(): void
    {
        $otro = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->colega->id,
            'subject_id' => $this->materia->id, 'status' => 'active',
        ]);
        $otro->syncGroups([$this->aulaA->id]);
        $delOtro = $this->intentoAbierto($this->alumnoA, $otro);
        $this->actuarComo($this->alumnoA);

        // El intento es suyo, pero de OTRO examen: la ruta mezcla los dos ids.
        $this->postJson($this->url('/submit', $delOtro), ['answers' => $this->respuestasValidas()])->assertNotFound();
        $this->getJson($this->url('', $delOtro))->assertNotFound();
    }

    /* ============================================================
     | Tiempo y estado
     ============================================================ */

    public function test_no_se_entrega_pasado_el_tiempo_aunque_se_haya_pausado_mucho_antes(): void
    {
        // 60 min de examen; empezó hace 3 h y estuvo 10 min pausado en total.
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['started_at' => now()->subHours(3), 'total_paused_seconds' => 600]);

        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas()])
            ->assertStatus(409);
    }

    /**
     * **Pausa ilimitada.** El alumno pausa, estudia durante horas y entrega con
     * el intento aún pausado: el plazo se estira justo lo que dura la pausa
     * (`pausedSoFar += now - paused_at`), así que nunca vence mientras esté en
     * pausa. Si la pausa es una función del producto, este test documenta que no
     * tiene tope; si no lo es, es una vía para consultar material.
     */
    public function test_un_intento_pausado_durante_horas_no_se_puede_entregar_como_si_nada(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['started_at' => now()->subHours(5), 'paused_at' => now()->subHours(4)]);

        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas()])
            ->assertStatus(409);
    }

    public function test_el_alumno_no_acumula_pausa_para_ganar_tiempo(): void
    {
        // Pausa de 2 h reanudada: el plazo se estira 2 h. Un tope razonable de
        // pausa total no debería permitir semejante ampliación sobre 60 min.
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['started_at' => now()->subHours(3), 'paused_at' => now()->subHours(2)]);

        $this->actuarComo($this->alumnoA);
        $this->patchJson($this->url('/resume', $intento))->assertOk();

        $this->assertLessThanOrEqual(
            30 * 60, (int) $intento->fresh()->total_paused_seconds,
            'Una sola pausa sumó ' . (int) ($intento->fresh()->total_paused_seconds / 60) . ' min sin ningún tope.'
        );
    }

    public function test_no_se_inicia_un_examen_que_no_esta_activo(): void
    {
        $this->actuarComo($this->alumnoA);

        foreach (['draft', 'published', 'completed'] as $estado) {
            $this->examen->update(['status' => $estado]);
            $res = $this->postJson($this->url('/start'));

            $this->assertContains($res->getStatusCode(), [404, 409], "Se pudo iniciar un examen en estado {$estado}.");
        }
        $this->assertSame(0, ExamAttempt::where('exam_id', $this->examen->id)->count());
    }

    public function test_no_se_inicia_fuera_de_la_ventana_de_disponibilidad(): void
    {
        $this->actuarComo($this->alumnoA);

        $this->examen->update(['available_from' => now()->addDay(), 'available_until' => null]);
        $this->postJson($this->url('/start'))->assertStatus(409);

        $this->examen->update(['available_from' => null, 'available_until' => now()->subMinute()]);
        $this->postJson($this->url('/start'))->assertStatus(409);

        $this->assertSame(0, ExamAttempt::where('exam_id', $this->examen->id)->count());
    }

    public function test_no_se_inicia_un_examen_que_no_existe_ni_con_un_id_mal_formado(): void
    {
        $this->actuarComo($this->alumnoA);

        $this->postJson('/api/exams/00000000-0000-4000-8000-000000000000/attempts/start')->assertNotFound();
        $this->assertLessThan(500, $this->postJson('/api/exams/no-es-un-uuid/attempts/start')->getStatusCode());
    }

    /* ============================================================
     | Fuga de respuestas correctas
     ============================================================ */

    public function test_el_alumno_no_ve_las_respuestas_correctas_en_ninguna_ruta(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);

        foreach ([
            "/api/exams/{$this->examen->id}",
            "/api/exams/{$this->examen->id}/questions",
            "/api/exams/{$this->examen->id}/attempts/{$intento->id}",
            '/api/students/me/available-exams',
        ] as $ruta) {
            $cuerpo = $this->getJson($ruta)->getContent();

            $this->assertStringNotContainsString('respuesta-secreta', $cuerpo, "Filtra la respuesta corta en {$ruta}");
            $this->assertStringNotContainsString('"is_correct":true', $cuerpo, "Filtra una opción correcta en {$ruta}");
            $this->assertStringNotContainsString('correct_answer_text', $cuerpo, "Expone correct_answer_text en {$ruta}");
        }
    }

    public function test_con_la_revision_desactivada_el_alumno_no_ve_su_correccion_tras_entregar(): void
    {
        $intento = $this->intentoAbierto($this->alumnoA);
        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas()])->assertOk();

        $res = $this->getJson($this->url('', $intento))->assertOk();

        $this->assertFalse($res->json('meta.review_shown'));
        $this->assertStringNotContainsString('respuesta-secreta', $res->getContent());
        $this->assertStringNotContainsString('correct_answer_snapshot', $res->getContent());
    }

    /* ============================================================
     | Rutas del docente que el alumno no debe tocar
     ============================================================ */

    public function test_el_alumno_no_corrige_ni_lista_respuestas_ni_cambia_el_examen(): void
    {
        $intento = $this->intentoEntregado($this->alumnoA);
        $respuesta = StudentAnswer::factory()->create([
            'institution_id' => $this->centro->id, 'attempt_id' => $intento->id, 'question_id' => $this->preguntaCorta->id,
        ]);
        $this->actuarComo($this->alumnoA);

        $this->patchJson("/api/student-answers/{$respuesta->id}/review", ['is_correct' => true, 'points_awarded' => 3])->assertForbidden();
        $this->getJson("/api/exam-attempts/{$intento->id}/answers")->assertForbidden();
        $this->patchJson("/api/exams/{$this->examen->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->putJson("/api/exams/{$this->examen->id}", ['duration_minutes' => 1])->assertForbidden();
        $this->deleteJson("/api/exams/{$this->examen->id}")->assertForbidden();
        $this->putJson("/api/questions/{$this->preguntaMc->id}", ['points' => 10])->assertForbidden();
        $this->deleteJson("/api/questions/{$this->preguntaCorta->id}")->assertForbidden();
        $this->postJson("/api/exams/{$this->examen->id}/questions", [])->assertForbidden();

        $this->assertNotSame('reviewed', $respuesta->fresh()->review_status);
        $this->assertDatabaseHas('exams', ['id' => $this->examen->id, 'duration_minutes' => 60]);
    }
}
