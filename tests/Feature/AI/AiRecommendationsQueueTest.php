<?php

namespace Tests\Feature\AI;

use App\Enums\AiGenerationSource;
use App\Enums\AiRecommendationsStatus;
use App\Exceptions\AiGenerationFailed;
use App\Jobs\GenerateAiRecommendations;
use App\Models\AI\AiRecommendation;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Análisis de IA de un intento, diferido a la cola (decisión D1).
 *
 * El reparto que se comprueba aquí:
 *
 *  - **La entrega no llama a OpenAI.** Guarda plantillas y responde. El pico del
 *    sistema es una clase entera entregando a la vez y no puede depender de un
 *    servicio externo.
 *  - **Abrir los resultados encola el análisis, una sola vez.** Recargar la
 *    pantalla no puede costar otra llamada a la API.
 *  - **El job sustituye, no acumula.** Al terminar, el alumno tiene el análisis
 *    del modelo y no las plantillas debajo.
 *  - **Si el modelo no responde, no se duplica nada** y el intento queda en
 *    `failed`, que es lo que deja al alumno pedir «regenerar» a mano.
 */
class AiRecommendationsQueueTest extends TestCase
{
    use ApiAuth;

    private const RESPUESTA_MODELO = "strength: Dominás el planteo de la operación.\n"
        . "weakness: Te cuesta el común denominador.\n"
        . "action: Practicá cinco sumas de fracciones con distinto denominador.\n"
        . 'resource: Buscá una guía corta de fracciones equivalentes.';

    public function test_la_entrega_del_examen_no_encola_ni_llama_al_modelo(): void
    {
        Queue::fake();

        [$institution, $studentUser, $exam] = $this->escenario();

        $attempt = $this->intentoIniciado($institution, $exam, $studentUser);

        $this->actingAs($studentUser, 'sanctum');

        $this->postJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/submit", ['answers' => []])
            ->assertSuccessful();

        Queue::assertNothingPushed();

        $this->assertNull(
            $attempt->fresh()->ai_recommendations_status,
            'La entrega no debe dejar el intento esperando un análisis que nadie pidió.'
        );
    }

    public function test_abrir_los_resultados_encola_el_analisis_una_sola_vez(): void
    {
        Queue::fake();

        [$institution, $studentUser, $exam] = $this->escenario();
        $attempt = $this->intentoEntregado($institution, $exam, $studentUser);

        $this->actingAs($studentUser, 'sanctum');

        $primera = $this->getJson("/api/exam-attempts/{$attempt->id}/recommendations");
        $primera->assertOk();
        $primera->assertJsonPath('data.status', AiRecommendationsStatus::Preparing->value);

        // Recargar la pantalla no vuelve a encolar.
        $this->getJson("/api/exam-attempts/{$attempt->id}/recommendations")
            ->assertOk()
            ->assertJsonPath('data.status', AiRecommendationsStatus::Preparing->value);

        Queue::assertPushed(GenerateAiRecommendations::class, 1);
    }

    public function test_el_job_sustituye_las_plantillas_por_el_analisis_del_modelo(): void
    {
        [$institution, $studentUser, $exam] = $this->escenario();
        $attempt = $this->intentoEntregado($institution, $exam, $studentUser);

        app()->instance('tenant_id', $institution->id);
        app(\App\Services\AI\AiRecommendationService::class)->generateFromAttempt($attempt);

        $plantillas = AiRecommendation::where('attempt_id', $attempt->id)->count();
        $this->assertGreaterThan(0, $plantillas, 'La entrega debió dejar plantillas.');

        $this->fingirModelo(self::RESPUESTA_MODELO);

        (new GenerateAiRecommendations($attempt->id))
            ->handle(app(\App\Services\AI\AiRecommendationService::class));

        $filas = AiRecommendation::where('attempt_id', $attempt->id)->get();

        $this->assertCount(4, $filas, 'El análisis son cuatro secciones.');
        $this->assertTrue(
            $filas->every(fn (AiRecommendation $r) => $r->generated_by === AiGenerationSource::Ai),
            'No debe quedar ninguna plantilla debajo del análisis.'
        );
        $this->assertSame(
            AiRecommendationsStatus::Ready,
            $attempt->fresh()->ai_recommendations_status
        );
    }

    public function test_si_el_modelo_no_responde_el_job_no_duplica_las_plantillas(): void
    {
        [$institution, $studentUser, $exam] = $this->escenario();
        $attempt = $this->intentoEntregado($institution, $exam, $studentUser);

        app()->instance('tenant_id', $institution->id);
        app(\App\Services\AI\AiRecommendationService::class)->generateFromAttempt($attempt);

        $antes = AiRecommendation::where('attempt_id', $attempt->id)->pluck('id')->sort()->values();

        OpenAI::fake([new \RuntimeException('502 Bad Gateway')]);

        $job = new GenerateAiRecommendations($attempt->id);

        try {
            $job->handle(app(\App\Services\AI\AiRecommendationService::class));
            $this->fail('El job debe propagar el fallo para que el worker reintente.');
        } catch (AiGenerationFailed) {
            // esperado
        }

        $despues = AiRecommendation::where('attempt_id', $attempt->id)->pluck('id')->sort()->values();
        $this->assertEquals($antes, $despues, 'Un fallo del modelo no puede escribir ni borrar nada.');

        // El estado solo pasa a «failed» cuando se agotan los reintentos.
        $this->assertSame(
            AiRecommendationsStatus::Preparing,
            $attempt->fresh()->ai_recommendations_status ?? AiRecommendationsStatus::Preparing
        );

        $job->failed(new \RuntimeException('502 Bad Gateway'));

        $this->assertSame(
            AiRecommendationsStatus::Failed,
            $attempt->fresh()->ai_recommendations_status
        );
    }

    public function test_el_alumno_no_ve_las_recomendaciones_de_un_intento_ajeno(): void
    {
        [$institution, $studentUser, $exam] = $this->escenario();
        $attempt = $this->intentoEntregado($institution, $exam, $studentUser);

        $otro = $this->alumno($institution);
        $this->actingAs($otro, 'sanctum');

        $this->getJson("/api/exam-attempts/{$attempt->id}/recommendations")
            ->assertStatus(403);
    }

    public function test_un_intento_sin_entregar_no_tiene_recomendaciones(): void
    {
        [$institution, $studentUser, $exam] = $this->escenario();
        $attempt = $this->intentoIniciado($institution, $exam, $studentUser);

        $this->actingAs($studentUser, 'sanctum');

        $this->getJson("/api/exam-attempts/{$attempt->id}/recommendations")
            ->assertStatus(409);
    }

    /* =========================
     | Apoyo
     ========================= */

    /** @return array{0: Institution, 1: User, 2: Exam} */
    private function escenario(): array
    {
        $institution = Institution::factory()->create();
        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $subject->id,
        ]);

        // Sin preguntas, el submit corta en 409 antes de llegar a nada de esto.
        \App\Models\Exams\Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $exam->id,
        ]);

        return [$institution, $this->alumno($institution), $exam];
    }

    private function alumno(Institution $institution): User
    {
        $user = User::factory()->student()->create(['institution_id' => $institution->id]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
        ]);

        return $user;
    }

    private function intentoIniciado(Institution $institution, Exam $exam, User $student): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
            'submitted_at'    => null,
        ]);
    }

    private function intentoEntregado(Institution $institution, Exam $exam, User $student): ExamAttempt
    {
        return ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
            'score'           => 4,
            'max_score'       => 10,
        ]);
    }

    private function fingirModelo(string $contenido): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $contenido]]],
            ]),
        ]);
    }
}
