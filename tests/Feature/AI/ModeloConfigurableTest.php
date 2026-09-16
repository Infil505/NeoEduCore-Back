<?php

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiRecommendations;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use App\Models\Students\StudentProgress;
use App\Services\AI\AiRecommendationService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `OPENAI_MODEL` manda en los tres caminos que llaman a OpenAI.
 *
 * El tutor leía `openai.model` y los otros dos —recomendaciones y el prompt
 * libre del docente— leían `services.openai.model`, una clave que **no existe**
 * en `config/services.php`. La llamada devolvía null, caía al literal
 * `'gpt-4o-mini'` del segundo argumento y no fallaba nunca: cambiar de modelo
 * por entorno movía uno de los tres y dejaba los otros dos donde estaban, sin
 * un solo aviso.
 *
 * Es el tipo de fallo que solo se ve mirando, así que lo fija un test: el día
 * que suba el precio o salga un modelo mejor, el cambio tiene que alcanzar a
 * los tres.
 */
class ModeloConfigurableTest extends TestCase
{
    use ApiAuth;

    private const MODELO = 'gpt-4o-mini-2099-ficticio';

    protected function setUp(): void
    {
        parent::setUp();

        config(['openai.model' => self::MODELO]);
    }

    public function test_el_tutor_usa_el_modelo_configurado(): void
    {
        $this->alumno();
        $this->fingirModelo('Las fracciones son partes de un entero.');

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Explicame las fracciones'])->assertOk();

        $this->assertModeloEnviado();
    }

    public function test_el_diagnostico_usa_el_modelo_configurado(): void
    {
        $this->alumno();
        $this->fingirModelo('Vas bien, sigue practicando.');

        $this->getJson('/api/ai/tutor/diagnosis')->assertOk();

        $this->assertModeloEnviado();
    }

    public function test_las_recomendaciones_usan_el_modelo_configurado(): void
    {
        $institution = Institution::factory()->create();
        $subject     = Subject::factory()->create(['institution_id' => $institution->id]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $subject->id,
        ]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);

        Student::factory()->create([
            'user_id'        => $studentUser->id,
            'institution_id' => $institution->id,
            'grade'          => 4,
        ]);

        $attempt = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentUser->id,
            'score'           => 3,
            'max_score'       => 10,
        ]);

        app()->instance('tenant_id', $institution->id);

        $pregunta = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $exam->id,
        ]);

        StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id'     => $attempt->id,
            'question_id'    => $pregunta->id,
            'is_correct'     => false,
            'answer_text'    => '2/6',
        ]);

        $this->fingirModelo("strength: Bien.\nweakness: Repasa.\naction: Practica.\nresource: Guía.");

        (new GenerateAiRecommendations($attempt->id))->handle(app(AiRecommendationService::class));

        $this->assertModeloEnviado();
    }

    public function test_el_prompt_libre_del_docente_usa_el_modelo_configurado(): void
    {
        $teacher = $this->signInTeacher();

        app()->instance('tenant_id', $teacher->institution_id);

        $subject = Subject::factory()->create(['institution_id' => $teacher->institution_id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $teacher->institution_id]);

        Student::factory()->create([
            'user_id'        => $studentUser->id,
            'institution_id' => $teacher->institution_id,
        ]);

        $this->fingirModelo('Refuerza la comprensión de lectura con textos cortos.');

        $this->postJson('/api/ai/generate', [
            'student_user_id' => $studentUser->id,
            'subject_id'      => $subject->id,
            'type'            => 'action',
            'prompt'          => 'Sugerime una acción para este estudiante.',
        ])->assertCreated();

        $this->assertModeloEnviado();
    }

    /* =========================
     | Apoyo
     ========================= */

    private function assertModeloEnviado(): void
    {
        OpenAI::assertSent(
            Chat::class,
            fn (string $metodo, array $parametros): bool => ($parametros['model'] ?? null) === self::MODELO
        );
    }

    private function alumno(): User
    {
        $institution = Institution::factory()->create();

        $user = User::factory()->student()->create(['institution_id' => $institution->id]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
            'grade'          => 4,
        ]);

        // Con progreso: sin una sola materia registrada, `getDiagnosis()` corta
        // antes de llamar al modelo y devuelve el texto de bienvenida, así que
        // no habría petición que mirar.
        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        StudentProgress::create([
            'institution_id'     => $institution->id,
            'student_user_id'    => $user->id,
            'subject_id'         => $subject->id,
            'mastery_percentage' => 42,
            'updated_at'         => now(),
        ]);

        $this->actingAs($user, 'sanctum');
        app()->instance('tenant_id', $institution->id);

        return $user;
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
