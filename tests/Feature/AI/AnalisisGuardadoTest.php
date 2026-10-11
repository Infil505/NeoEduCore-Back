<?php

namespace Tests\Feature\AI;

use App\Models\Admin\User;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\QuestionOption;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * La lectura del análisis de un examen redactada por IA («Redactar con IA» en analíticas) se GUARDA: al volver a entrar
 * se muestra sin pedirla otra vez, y solo se vuelve a pagar una llamada si cambiaron los datos o el docente la pide.
 * Antes solo vivía 12 horas en la caché.
 */
class AnalisisGuardadoTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    /** @var array<int,User> */
    private array $alumnos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();

        $this->preguntaMc->update(['topic' => 'Fracciones', 'indicator' => 'Compara fracciones']);
        $this->preguntaCorta->update(['topic' => 'Decimales']);

        $this->alumnos = [$this->alumnoA, $this->nuevoAlumno($this->aulaA), $this->nuevoAlumno($this->aulaA)];

        $distractor = QuestionOption::where('question_id', $this->preguntaMc->id)->where('option_index', 2)->value('id');
        $this->entrega($this->alumnos[0], $this->opcionCorrecta, true, 'x', 2);
        $this->entrega($this->alumnos[1], $distractor, false, 'x', 0);

        $this->actingAs($this->docente, 'sanctum');
    }

    private function entrega(User $alumno, int $opcion, bool $mcOk, string $corta, float $puntos): ExamAttempt
    {
        $intento = $this->intentoEntregado($alumno);
        $intento->update(['score' => $puntos, 'max_score' => 5, 'grade_status' => 'graded']);

        $idMc = (string) Str::uuid();
        DB::table('student_answers')->insert([
            'id' => $idMc, 'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
            'question_id' => $this->preguntaMc->id, 'is_correct' => $mcOk, 'points_awarded' => $mcOk ? 2 : 0,
            'review_status' => 'auto_graded', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('student_answer_options')->insert(['institution_id' => $this->centro->id, 'student_answer_id' => $idMc, 'option_id' => $opcion]);
        DB::table('student_answers')->insert([
            'id' => (string) Str::uuid(), 'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
            'question_id' => $this->preguntaCorta->id, 'answer_text' => $corta, 'is_correct' => false, 'points_awarded' => 0,
            'review_status' => 'auto_graded', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $intento;
    }

    private function fingir(string $resumen = 'La clase flojea en fracciones.'): void
    {
        OpenAI::fake(array_fill(0, 3, CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' =>
                "resumen: {$resumen}\ndificultades:\n- Comparar fracciones\nacciones:\n- Repasar con material concreto\natencion: Vigila los decimales."]]],
        ])));
    }

    private function leer(): array
    {
        return $this->getJson("/api/reports/exams/{$this->examen->id}/analysis")->assertOk()->json('data.narrative');
    }

    private function redactar(array $cuerpo = []): array
    {
        return $this->postJson("/api/reports/exams/{$this->examen->id}/analysis/ai", $cuerpo)->assertOk()->json('data.narrative');
    }

    private function guardadas(): int
    {
        return DB::table('exam_analysis_narratives')->where('exam_id', $this->examen->id)->count();
    }

    public function test_al_entrar_por_primera_vez_se_ve_la_calculada(): void
    {
        $n = $this->leer();

        $this->assertSame('heuristic', $n['source']);
        $this->assertFalse($n['stale']);
        $this->assertNull($n['generated_at']);
        $this->assertSame(0, $this->guardadas());
    }

    public function test_lo_redactado_se_guarda_y_al_volver_a_entrar_se_ve_sin_llamar_a_openai(): void
    {
        $this->fingir();
        $redactada = $this->redactar();
        $this->assertSame('ai', $redactada['source']);
        $this->assertSame(1, $this->guardadas());

        // Otra visita, ahora sin modelo y con la caché vacía: lo guardado está en la base, no en la caché.
        OpenAI::fake([]);
        Cache::flush();

        $n = $this->leer();

        $this->assertSame('ai', $n['source']);
        $this->assertSame('La clase flojea en fracciones.', $n['summary']);
        $this->assertFalse($n['stale']);
        $this->assertNotNull($n['generated_at']);
        OpenAI::assertNothingSent();
    }

    public function test_con_los_mismos_datos_pedirla_otra_vez_no_cuesta_otra_llamada(): void
    {
        $this->fingir();
        $this->redactar();

        OpenAI::fake([]);
        Cache::flush();

        $n = $this->redactar();

        $this->assertSame('ai', $n['source']);
        $this->assertSame(1, $this->guardadas());
        OpenAI::assertNothingSent();
    }

    public function test_forzar_vuelve_a_redactar_y_sustituye_la_anterior(): void
    {
        $this->fingir('Primera lectura.');
        $this->redactar();

        $this->fingir('Segunda lectura.');
        $n = $this->redactar(['force' => true]);

        $this->assertSame('Segunda lectura.', $n['summary']);
        $this->assertSame(1, $this->guardadas(), 'Una por examen: la nueva sustituye a la anterior.');
        $this->assertSame('Segunda lectura.', $this->leer()['summary']);
    }

    public function test_si_cambian_los_datos_lo_guardado_se_muestra_como_desactualizado(): void
    {
        $this->fingir();
        $this->redactar();

        // Una entrega nueva cambia los datos del análisis.
        $this->entrega($this->alumnos[2], $this->opcionCorrecta, true, 'y', 2);
        Cache::flush();

        $n = $this->leer();

        $this->assertSame('ai', $n['source'], 'Se sigue mostrando: es mejor que volver a la calculada.');
        $this->assertTrue($n['stale']);

        // Al redactarla de nuevo con los datos nuevos, queda al día.
        $this->fingir('Lectura con la tercera entrega.');
        $nueva = $this->redactar();

        $this->assertSame('Lectura con la tercera entrega.', $nueva['summary']);
        $this->assertFalse($nueva['stale']);
        $this->assertFalse($this->leer()['stale']);
    }

    public function test_si_el_modelo_falla_no_se_pierde_lo_ya_redactado(): void
    {
        $this->fingir();
        $this->redactar();

        $this->entrega($this->alumnos[2], $this->opcionCorrecta, true, 'y', 2);
        Cache::flush();
        OpenAI::fake([new \RuntimeException('sin red')]);

        $n = $this->redactar();

        $this->assertSame('ai', $n['source']);
        $this->assertSame('La clase flojea en fracciones.', $n['summary']);
        $this->assertTrue($n['stale'], 'Es la anterior, y se dice.');
        $this->assertSame('La clase flojea en fracciones.', DB::table('exam_analysis_narratives')->where('exam_id', $this->examen->id)->value(DB::raw("narrative->>'summary'")));
    }

    public function test_se_lee_la_respuesta_aunque_el_modelo_ponga_las_etiquetas_en_negrita(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' =>
            "**resumen:** La clase flojea en decimales.

**dificultades:**
- **Suma de decimales:** solo el 12 % acertó.

## Acciones:
- Usar material concreto.

**atención:** Vigilar los decimales."]]]])]);

        $n = $this->redactar();

        $this->assertSame('ai', $n['source']);
        $this->assertSame('La clase flojea en decimales.', $n['summary']);
        $this->assertSame(['Suma de decimales: solo el 12 % acertó.'], $n['difficulties']);
        $this->assertSame(['Usar material concreto.'], $n['actions']);
        $this->assertSame('Vigilar los decimales.', $n['attention']);
    }

    public function test_si_la_respuesta_del_modelo_no_se_puede_leer_no_se_guarda_como_ia(): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Claro, aquí tienes un análisis general sin el formato pedido.']]]])]);

        $n = $this->redactar();

        $this->assertSame('heuristic', $n['source']);
        $this->assertSame(0, $this->guardadas());
    }

    public function test_solo_se_puede_redactar_dos_veces_por_examen(): void
    {
        config(['openai.docente.analysis_ai_per_exam' => 2]);

        $this->fingir('Primera.');
        $this->assertSame(['used' => 1, 'limit' => 2, 'remaining' => 1], $this->redactar()['ai_usage']);

        $this->fingir('Segunda.');
        $this->assertSame(['used' => 2, 'limit' => 2, 'remaining' => 0], $this->redactar(['force' => true])['ai_usage']);

        // La tercera ni llega al modelo: 429 y la lectura guardada sigue ahí.
        OpenAI::fake([]);
        $this->postJson("/api/reports/exams/{$this->examen->id}/analysis/ai", ['force' => true])
            ->assertStatus(429)
            ->assertJsonPath('ai_usage.remaining', 0);
        OpenAI::assertNothingSent();

        $n = $this->leer();
        $this->assertSame('Segunda.', $n['summary']);
        $this->assertSame(0, $n['ai_usage']['remaining']);
    }

    public function test_ver_lo_guardado_o_el_fallo_del_modelo_no_gastan_una_redaccion(): void
    {
        config(['openai.docente.analysis_ai_per_exam' => 2]);

        // Un fallo del modelo no cuenta.
        OpenAI::fake([new \RuntimeException('sin red')]);
        $this->assertSame(0, $this->redactar()['ai_usage']['used']);

        $this->fingir();
        $this->redactar();

        // Pedirla con los mismos datos devuelve lo guardado y tampoco cuenta.
        OpenAI::fake([]);
        Cache::flush();
        $this->assertSame(1, $this->redactar()['ai_usage']['used']);
        $this->assertSame(1, $this->leer()['ai_usage']['used']);
    }

    public function test_si_ya_hay_una_redaccion_en_curso_la_segunda_no_llega_al_modelo(): void
    {
        config(['openai.docente.analysis_ai_per_exam' => 2]);

        // Otra petición del mismo examen sostiene el candado (está esperando al modelo).
        $candado = Cache::lock("exam-analysis-ai:{$this->examen->id}", 60);
        $this->assertTrue($candado->get());

        OpenAI::fake([]);
        $this->postJson("/api/reports/exams/{$this->examen->id}/analysis/ai", ['force' => true])->assertStatus(429);
        OpenAI::assertNothingSent();
        $this->assertSame(0, $this->guardadas());

        // Al terminar la primera, la siguiente ya puede.
        $candado->release();
        $this->fingir();
        $this->assertSame('ai', $this->redactar(['force' => true])['source']);
    }

    public function test_con_el_tope_en_cero_no_hay_limite(): void
    {
        config(['openai.docente.analysis_ai_per_exam' => 0]);

        foreach ([1, 2, 3] as $i) {
            $this->fingir("Lectura {$i}.");
            $this->assertSame("Lectura {$i}.", $this->redactar(['force' => true])['summary']);
        }

        $this->assertNull($this->leer()['ai_usage']['remaining']);
    }

    public function test_si_el_modelo_falla_y_no_habia_nada_guardado_queda_la_calculada(): void
    {
        OpenAI::fake([new \RuntimeException('sin red')]);

        $n = $this->redactar();

        $this->assertSame('heuristic', $n['source']);
        $this->assertSame(0, $this->guardadas());
    }

    public function test_un_estudiante_no_puede_pedirla(): void
    {
        OpenAI::fake([]);
        $this->actingAs($this->alumnoA, 'sanctum');

        $this->postJson("/api/reports/exams/{$this->examen->id}/analysis/ai")->assertForbidden();

        OpenAI::assertNothingSent();
    }
}
