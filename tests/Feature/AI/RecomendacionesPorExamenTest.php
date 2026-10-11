<?php

namespace Tests\Feature\AI;

use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Services\AI\AiRecommendationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Las recomendaciones salen del EXAMEN concreto, no de una plantilla única.
 *
 * Quejas que motivan esto: «están muy generalizadas». Las plantillas decían
 * «repasa los temas donde fallaste» sin decir cuáles; el modelo, cuando
 * respondía, no sabía cómo le había ido al alumno en las pruebas anteriores.
 */
class RecomendacionesPorExamenTest extends TestCase
{
    use ApiAuth;

    private const SECRETO = 'RESPUESTA-CORRECTA-SECRETA';

    private Institution $centro;
    private User $alumno;
    private Subject $materia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro = Institution::factory()->create();
        $this->alumno = User::factory()->student()->create([
            'institution_id' => $this->centro->id, 'status' => 'active', 'full_name' => 'Mariana Solís Vargas',
        ]);
        Student::factory()->create(['user_id' => $this->alumno->id, 'institution_id' => $this->centro->id, 'grade' => 4]);
        $this->materia = Subject::factory()->create(['institution_id' => $this->centro->id, 'name' => 'Matemáticas']);

        // El servicio se llama aquí sin petición: el tenant lo pone quien lo invoca
        // (el middleware en HTTP, el job en la cola).
        app()->instance('tenant_id', $this->centro->id);
    }

    /**
     * Un intento entregado. `$temas`: tema => lista de aciertos por pregunta.
     * Ej.: ['Fracciones' => [false, false, true], 'Decimales' => [true]]
     *
     * @param array<string,array<int,bool>> $temas
     */
    private function intento(string $titulo, array $temas, ?string $indicador = null, ?\DateTimeInterface $cuando = null): ExamAttempt
    {
        $examen = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id, 'title' => $titulo, 'status' => 'completed',
            'created_by_teacher_id' => User::factory()->teacher()->create(['institution_id' => $this->centro->id])->id,
        ]);

        $total = array_sum(array_map('count', $temas));
        $correctas = array_sum(array_map(fn ($r) => count(array_filter($r)), $temas));

        $intento = ExamAttempt::create([
            'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'student_user_id' => $this->alumno->id,
            'attempt_number' => 1, 'started_at' => now()->subHour(), 'submitted_at' => $cuando ?? now()->subMinutes(5),
            'score' => $correctas, 'max_score' => $total, 'grade_status' => 'graded',
        ]);

        $orden = 1;
        foreach ($temas as $tema => $resultados) {
            foreach ($resultados as $ok) {
                $pregunta = Question::factory()->create([
                    'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'question_type' => 'short_answer',
                    'question_text' => "Pregunta {$orden} de {$tema}", 'topic' => $tema, 'indicator' => $indicador,
                    'correct_answer_text' => self::SECRETO, 'points' => 1, 'order_index' => $orden++,
                ]);
                DB::table('student_answers')->insert([
                    'id' => (string) Str::uuid(), 'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
                    'question_id' => $pregunta->id, 'answer_text' => $ok ? 'bien' : 'mal-' . $orden, 'is_correct' => $ok,
                    'points_awarded' => $ok ? 1 : 0, 'review_status' => 'auto_graded', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $intento->fresh();
    }

    /** @return array<string,string> tipo => texto */
    private function plantillas(ExamAttempt $intento): array
    {
        return collect(app(AiRecommendationService::class)->generateFromAttempt($intento))
            ->mapWithKeys(fn ($r) => [$r->recommendation_type->value => $r->recommendation_text])->all();
    }

    /* ---------- plantillas (sin modelo) ---------- */

    public function test_un_resultado_bajo_nombra_el_examen_y_los_temas_que_fallo(): void
    {
        $i = $this->intento('Prueba de fracciones', ['Fracciones' => [false, false, true], 'Decimales' => [false, true]], 'Compara fracciones');

        $r = $this->plantillas($i);

        $this->assertStringContainsString('«Prueba de fracciones»', $r['weakness']);
        $this->assertStringContainsString('acertaste 2 de 5', $r['weakness']);
        $this->assertStringContainsString('Fracciones (2 de 3)', $r['weakness']);
        $this->assertStringContainsString('Decimales (1 de 2)', $r['weakness']);
        $this->assertStringContainsString('Compara fracciones', $r['weakness']);
        $this->assertStringNotContainsString('Repasa conceptos base', $r['weakness'], 'Era la plantilla genérica.');
    }

    public function test_el_tema_mas_fallado_va_primero(): void
    {
        $i = $this->intento('Examen mixto', ['Decimales' => [false], 'Fracciones' => [false, false, false]]);

        $texto = $this->plantillas($i)['weakness'];

        $this->assertLessThan(strpos($texto, 'Decimales'), strpos($texto, 'Fracciones'));
    }

    public function test_un_resultado_medio_indica_que_repasar(): void
    {
        // 8 de 10 = 80 %: «buen desempeño» pero con un tema flojo concreto.
        $i = $this->intento('Prueba de ciencias', ['Células' => [true, true, true, true, true, true, true, true], 'Plantas' => [false, false]]);

        $texto = $this->plantillas($i)['action'];

        $this->assertStringContainsString('«Prueba de ciencias»', $texto);
        $this->assertStringContainsString('«Plantas»', $texto);
        $this->assertStringContainsString('fallaste 2 de 2', $texto);
    }

    public function test_un_resultado_alto_reconoce_lo_que_domina(): void
    {
        $i = $this->intento('Prueba final', ['Fracciones' => [true, true], 'Decimales' => [true, true]]);

        $r = $this->plantillas($i);

        $this->assertStringContainsString('acertaste 4 de 4', $r['strength']);
        $this->assertStringContainsString('Dominas bien: Fracciones, Decimales', $r['strength']);
    }

    public function test_sin_temas_en_las_preguntas_cae_al_texto_de_siempre_pero_con_el_examen(): void
    {
        $i = $this->intento('Prueba sin temas', ['' => [false, false, true]]);

        $texto = $this->plantillas($i)['weakness'];

        $this->assertStringContainsString('«Prueba sin temas»', $texto);
        $this->assertStringContainsString('acertaste 1 de 3', $texto);
        $this->assertStringContainsString('Repasa conceptos base', $texto);
    }

    public function test_el_recurso_del_catalogo_prefiere_el_que_habla_del_tema_que_fallo(): void
    {
        $aula = Group::factory()->create(['institution_id' => $this->centro->id]);
        DB::table('group_students')->insert([
            'id' => (string) Str::uuid(), 'institution_id' => $this->centro->id, 'group_id' => $aula->id,
            'student_user_id' => $this->alumno->id, 'joined_at' => now(), 'left_at' => null,
        ]);

        $docente = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);
        $crear = function (string $titulo, ?string $descripcion = null) use ($aula, $docente) {
            $r = StudyResource::create([
                'institution_id' => $this->centro->id, 'title' => $titulo, 'description' => $descripcion, 'resource_type' => 'video',
                'url' => 'https://www.youtube.com/watch?v=' . Str::random(11), 'subject_id' => $this->materia->id, 'created_by' => $docente->id,
                'difficulty' => 'basic', 'language' => 'es',
            ]);
            $r->groups()->attach($aula->id, ['institution_id' => $this->centro->id]);

            return $r;
        };

        $crear('Geometría para empezar');                                   // el más reciente, pero de otro tema
        $delTema = $crear('Fracciones paso a paso', 'Compara fracciones con dibujos');

        $i = $this->intento('Prueba de fracciones', ['Fracciones' => [false, false, false]]);
        $recomendaciones = collect(app(AiRecommendationService::class)->generateFromAttempt($i));

        $recurso = $recomendaciones->first(fn ($r) => $r->recommendation_type->value === 'resource');
        $this->assertSame($delTema->title, $recurso->resource['title'] ?? null);
    }

    /* ---------- prompt del modelo ---------- */

    private function fingir(string $texto = "strength: bien\nweakness: mal\naction: haz\nresource: lee"): void
    {
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => $texto]]]])]);
    }

    private function promptEnviado(): string
    {
        $prompt = '';
        OpenAI::assertSent(Chat::class, function (string $m, array $p) use (&$prompt) {
            $prompt = $p['messages'][1]['content'];

            return true;
        });

        return $prompt;
    }

    public function test_el_modelo_recibe_el_historial_de_la_materia_y_los_temas_recurrentes(): void
    {
        // Dos pruebas anteriores de la misma materia, fallando siempre fracciones.
        $this->intento('Prueba de septiembre', ['Fracciones' => [false, false, false], 'Decimales' => [true]], null, now()->subDays(40));
        $this->intento('Prueba de octubre', ['Fracciones' => [false, false, true], 'Decimales' => [true]], null, now()->subDays(20));
        $actual = $this->intento('Prueba de noviembre', ['Fracciones' => [false, false, true]], null, now()->subMinute());
        $this->fingir();

        app(AiRecommendationService::class)->regenerateForAttempt($actual, '', true);

        $prompt = $this->promptEnviado();
        $this->assertStringContainsString('Exámenes anteriores de la materia', $prompt);
        $this->assertStringContainsString('«Prueba de septiembre» 25 %', $prompt);
        $this->assertStringContainsString('«Prueba de octubre» 50 %', $prompt);
        $this->assertStringContainsString('Temas que le cuestan de forma recurrente en la materia: Fracciones', $prompt);
        $this->assertStringNotContainsString('Prueba de noviembre» ', explode('Exámenes anteriores de la materia', $prompt)[1] ?? '', 'El intento actual no es historial.');
    }

    public function test_el_primer_examen_de_la_materia_no_inventa_historial(): void
    {
        $actual = $this->intento('Primera prueba', ['Fracciones' => [false, true]]);
        $this->fingir();

        app(AiRecommendationService::class)->regenerateForAttempt($actual, '', true);

        $this->assertStringNotContainsString('Exámenes anteriores de la materia', $this->promptEnviado());
    }

    public function test_el_prompt_no_lleva_la_respuesta_correcta_ni_el_nombre(): void
    {
        $this->intento('Anterior', ['Fracciones' => [false, false, false]], null, now()->subDays(10));
        $actual = $this->intento('Actual', ['Fracciones' => [false, true]]);
        $this->fingir();

        app(AiRecommendationService::class)->regenerateForAttempt($actual, '', true);

        $prompt = $this->promptEnviado();
        $this->assertStringNotContainsString(self::SECRETO, $prompt);
        $this->assertStringNotContainsString('Mariana', $prompt);
    }

    public function test_el_historial_de_otro_alumno_no_se_cuela(): void
    {
        $otro = User::factory()->student()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $otro->id, 'institution_id' => $this->centro->id]);

        $examenAjeno = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id, 'title' => 'Prueba privada del compañero',
            'status' => 'completed', 'created_by_teacher_id' => User::factory()->teacher()->create(['institution_id' => $this->centro->id])->id,
        ]);
        ExamAttempt::create([
            'institution_id' => $this->centro->id, 'exam_id' => $examenAjeno->id, 'student_user_id' => $otro->id,
            'attempt_number' => 1, 'submitted_at' => now()->subDay(), 'score' => 1, 'max_score' => 10, 'grade_status' => 'graded',
        ]);

        $actual = $this->intento('Actual', ['Fracciones' => [false, true]]);
        $this->fingir();

        app(AiRecommendationService::class)->regenerateForAttempt($actual, '', true);

        $this->assertStringNotContainsString('Prueba privada del compañero', $this->promptEnviado());
    }

    /* ---------- por la API real ---------- */

    public function test_al_entregar_un_examen_las_recomendaciones_hablan_de_sus_temas_y_no_filtran_la_respuesta(): void
    {
        $docente = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);
        $examen = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id, 'title' => 'Prueba de fracciones',
            'created_by_teacher_id' => $docente->id, 'status' => 'active', 'max_attempts' => 3, 'duration_minutes' => 60,
            'available_from' => now()->subDay(), 'available_until' => now()->addDay(),
        ]);

        $respuestas = [];
        foreach (['Fracciones', 'Fracciones', 'Decimales'] as $i => $tema) {
            $q = Question::factory()->create([
                'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'question_type' => 'multiple_choice',
                'question_text' => "Pregunta {$i}", 'topic' => $tema, 'indicator' => 'Compara fracciones', 'order_index' => $i, 'points' => 1,
            ]);
            $buena = \App\Models\Exams\QuestionOption::create([
                'institution_id' => $this->centro->id, 'question_id' => $q->id, 'option_index' => 0,
                'option_text' => 'OPCION-CORRECTA-SECRETA', 'is_correct' => true,
            ]);
            $mala = \App\Models\Exams\QuestionOption::create([
                'institution_id' => $this->centro->id, 'question_id' => $q->id, 'option_index' => 1,
                'option_text' => 'Distractor', 'is_correct' => false,
            ]);
            // Falla las dos de fracciones y acierta la de decimales.
            $respuestas[] = ['question_id' => $q->id, 'selected_option_ids' => [$tema === 'Fracciones' ? $mala->id : $buena->id]];
        }

        $this->actingAs($this->alumno, 'sanctum');
        $intento = ExamAttempt::create([
            'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'student_user_id' => $this->alumno->id,
            'attempt_number' => 1, 'started_at' => now()->subMinutes(5), 'score' => 0, 'max_score' => 0, 'grade_status' => 'pending',
        ]);

        $res = $this->postJson("/api/exams/{$examen->id}/attempts/{$intento->id}/submit", ['answers' => $respuestas])->assertSuccessful();

        $textos = collect($res->json('data.recommendations'))->pluck('recommendation_text')->implode("
");
        $this->assertStringContainsString('«Prueba de fracciones»', $textos);
        $this->assertStringContainsString('Fracciones', $textos);
        $this->assertStringContainsString('acertaste 1 de 3', $textos);

        // La respuesta de la entrega no arrastra las preguntas ni la opción correcta.
        $this->assertStringNotContainsString('OPCION-CORRECTA-SECRETA', $res->getContent());
        $this->assertArrayNotHasKey('answers', $res->json('data.attempt'));
    }
}
