<?php

namespace Tests\Feature\AI;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\AI\AiOutputValidator;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Qué sale hacia OpenAI y qué llega de vuelta al alumno.
 *
 * Dos compromisos del informe que el código no cumplía:
 *
 * - **[173] / [394] — datos personales.** El `full_name` del estudiante viajaba
 *   dentro del prompt en cada turno de chat y en el diagnóstico. `AiOutputValidator`
 *   no lo veía: filtra PII en la salida del modelo, nunca en la entrada.
 * - **[173] — lista blanca.** «Materiales externos mediante una lista blanca de
 *   recursos educativos verificados», sin distinguir canal. La comprobación solo
 *   se aplicaba al JSON estructurado de las recomendaciones; un enlace escrito en
 *   prosa por el tutor llegaba intacto a un niño de primaria.
 */
class AiTutorPrivacyTest extends TestCase
{
    use ApiAuth;

    private const NOMBRE = 'Mariana Solís Vargas';

    private function estudiante(): User
    {
        $institution = Institution::factory()->create();

        $user = $this->signInStudent([
            'institution_id' => $institution->id,
            'full_name'      => self::NOMBRE,
        ]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
            'grade'          => 4,
        ]);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        StudentProgress::create([
            'institution_id'     => $institution->id,
            'student_user_id'    => $user->id,
            'subject_id'         => $subject->id,
            'mastery_percentage' => 42,
            'updated_at'         => now(),
        ]);

        return $user;
    }

    private function fingirRespuesta(string $contenido): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $contenido]]],
            ]),
        ]);
    }

    /** ¿Alguna de las peticiones enviadas a OpenAI contenía este texto? */
    private function assertNoSeEnvio(string $aguja): void
    {
        OpenAI::assertNotSent(Chat::class, function (string $method, array $parametros) use ($aguja): bool {
            return str_contains(json_encode($parametros, JSON_UNESCAPED_UNICODE), $aguja);
        });
    }

    public function test_the_student_name_never_reaches_openai_from_the_chat(): void
    {
        $this->estudiante();
        $this->fingirRespuesta('Vamos a repasar las fracciones paso a paso.');

        $this->postJson('/api/ai/tutor/chat', ['message' => 'No entiendo las fracciones'])
            ->assertOk();

        $this->assertNoSeEnvio(self::NOMBRE);
        // Ni el nombre completo ni el de pila suelto.
        $this->assertNoSeEnvio('Mariana');
    }

    public function test_the_student_name_never_reaches_openai_from_the_diagnosis(): void
    {
        $this->estudiante();
        $this->fingirRespuesta('Vas por buen camino; refuerza la materia con menor dominio.');

        $this->getJson('/api/ai/tutor/diagnosis')->assertOk();

        $this->assertNoSeEnvio(self::NOMBRE);
        $this->assertNoSeEnvio('Mariana');
    }

    /**
     * El contexto pedagógico sí debe viajar: si no, la personalización que
     * promete [263] deja de existir y el arreglo de privacidad habría vaciado la
     * funcionalidad en vez de acotarla.
     */
    public function test_the_pedagogical_context_still_reaches_openai(): void
    {
        $this->estudiante();
        $this->fingirRespuesta('Con gusto.');

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Ayuda'])->assertOk();

        OpenAI::assertSent(Chat::class, function (string $method, array $parametros): bool {
            $enviado = json_encode($parametros, JSON_UNESCAPED_UNICODE);

            return str_contains($enviado, 'grado 4') && str_contains($enviado, '42');
        });
    }

    public function test_a_link_outside_the_whitelist_is_stripped_from_the_reply(): void
    {
        $this->estudiante();
        $this->fingirRespuesta('Mira este video: https://sitio-cualquiera.example/video para practicar.');

        $respuesta = $this->postJson('/api/ai/tutor/chat', ['message' => 'Dame un video'])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringNotContainsString('sitio-cualquiera.example', $respuesta);
        $this->assertStringContainsString(AiOutputValidator::URL_BLOQUEADA, $respuesta);
        // El resto de la explicación sobrevive: no se descarta la respuesta entera.
        $this->assertStringContainsString('para practicar', $respuesta);
    }

    public function test_a_whitelisted_link_survives_in_the_reply(): void
    {
        $this->estudiante();
        $this->fingirRespuesta('Repasa aquí: https://es.khanacademy.org/math/fracciones y cuéntame.');

        $respuesta = $this->postJson('/api/ai/tutor/chat', ['message' => 'Dame material'])
            ->assertOk()
            ->json('data.reply');

        $this->assertStringContainsString('https://es.khanacademy.org/math/fracciones', $respuesta);
        $this->assertStringNotContainsString(AiOutputValidator::URL_BLOQUEADA, $respuesta);
    }
}
