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
use App\Services\AI\RegistroPorGrado;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * El tutor le escribe distinto a 1.º que a 6.º.
 *
 * Entre primero y sexto de primaria hay seis años de diferencia lectora, y el
 * sistema los trataba igual: el chat mandaba «grado 3» con una instrucción
 * genérica —«adapta el nivel de detalle al perfil»— y **el diagnóstico y las
 * recomendaciones post-examen ni siquiera sabían el grado**. Para 1.º eso no
 * es un matiz de estilo: a esa edad muchos apenas leen con fluidez, así que un
 * párrafo denso no llega a leerse.
 *
 * Lo que se comprueba es que la instrucción de registro **llega a las tres
 * superficies** y que cambia con el grado.
 */
class RegistroPorGradoTest extends TestCase
{
    use ApiAuth;

    public function test_cada_franja_tiene_su_registro_y_son_distintos(): void
    {
        $registro = app(RegistroPorGrado::class);

        $primero = $registro->para(1);
        $tercero = $registro->para(3);
        $sexto   = $registro->para(6);

        $this->assertNotSame($primero, $tercero);
        $this->assertNotSame($tercero, $sexto);

        // 1.º y 2.º comparten franja; 2.º y 3.º no.
        $this->assertSame($primero, $registro->para(2));
        $this->assertNotSame($registro->para(2), $registro->para(3));
    }

    /**
     * La etapa se le dice al modelo con la edad, no solo con la etiqueta:
     * «primaria» cambia de país a país, «6 a 12 años» no. Y sale de
     * configuración porque estaba escrita a mano en los tres prompts, que es la
     * misma trampa que tenían los rangos de grado.
     */
    public function test_los_tres_prompts_describen_la_etapa_desde_la_configuracion(): void
    {
        config(['academic.etapa' => 'una etapa inventada para el test']);

        $alumno = $this->alumnoEnGrado(3);
        $this->fingirModelo('Vale.');

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        $this->assertSeEnvio('una etapa inventada para el test');
    }

    public function test_sin_grado_se_usa_el_registro_conservador(): void
    {
        $this->assertSame(
            (string) config('openai.tutor.registro.sin_grado'),
            app(RegistroPorGrado::class)->para(null)
        );
    }

    public function test_el_chat_manda_el_registro_del_grado_del_alumno(): void
    {
        $this->alumnoEnGrado(1);
        $this->fingirModelo('Las fracciones son partes de un entero.');

        $this->postJson('/api/ai/tutor/chat', ['message' => '¿Qué es una fracción?'])->assertOk();

        $this->assertSeEnvio(app(RegistroPorGrado::class)->para(1));
    }

    public function test_el_diagnostico_manda_el_registro_y_antes_no_lo_hacia(): void
    {
        $alumno = $this->alumnoEnGrado(6);

        StudentProgress::factory()->create([
            'institution_id'     => $alumno->institution_id,
            'student_user_id'    => $alumno->id,
            'subject_id'         => Subject::factory()->create(['institution_id' => $alumno->institution_id])->id,
            'mastery_percentage' => 45,
        ]);

        $this->fingirModelo('Vas bien, sigue practicando.');

        $this->getJson('/api/ai/tutor/diagnosis')->assertOk();

        $this->assertSeEnvio(app(RegistroPorGrado::class)->para(6));
    }

    public function test_las_recomendaciones_post_examen_mandan_el_registro(): void
    {
        $alumno = $this->alumnoEnGrado(2);
        $centro = Institution::query()->findOrFail($alumno->institution_id);

        $examen = Exam::factory()->create([
            'institution_id' => $centro->id,
            'subject_id'     => Subject::factory()->create(['institution_id' => $centro->id])->id,
        ]);

        $intento = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $centro->id,
            'exam_id'         => $examen->id,
            'student_user_id' => $alumno->id,
            'score'           => 3,
            'max_score'       => 10,
        ]);

        $pregunta = Question::factory()->create([
            'institution_id' => $centro->id,
            'exam_id'        => $examen->id,
            'topic'          => 'Sumas llevando',
        ]);

        StudentAnswer::factory()->create([
            'institution_id' => $centro->id,
            'attempt_id'     => $intento->id,
            'question_id'    => $pregunta->id,
            'is_correct'     => false,
        ]);

        $this->fingirModelo("strength: bien\nweakness: mal\naction: practica\nresource: guía");

        (new GenerateAiRecommendations($intento->id))
            ->handle(app(\App\Services\AI\AiRecommendationService::class));

        $this->assertSeEnvio(app(RegistroPorGrado::class)->para(2));
    }

    /* =========================
     | Apoyo
     ========================= */

    private function alumnoEnGrado(int $grado): User
    {
        $centro = Institution::factory()->create();

        $user = User::factory()->student()->create(['institution_id' => $centro->id]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $centro->id,
            'grade'          => $grado,
        ]);

        $this->actingAs($user, 'sanctum');
        app()->instance('tenant_id', $centro->id);

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

    /** Se compara con un trozo reconocible: el texto completo lleva comillas escapadas en el JSON. */
    private function assertSeEnvio(string $registro): void
    {
        $aguja = mb_substr($registro, 0, 60);

        OpenAI::assertSent(Chat::class, function (string $metodo, array $parametros) use ($aguja): bool {
            return str_contains(json_encode($parametros, JSON_UNESCAPED_UNICODE), $aguja);
        });
    }
}
