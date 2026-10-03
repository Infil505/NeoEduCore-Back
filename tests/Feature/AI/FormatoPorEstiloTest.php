<?php

namespace Tests\Feature\AI;

use App\Enums\LearningStyle;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\AI\FormatoPorEstilo;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;

/**
 * O2 — el estilo de aprendizaje decide la FORMA de la respuesta del tutor.
 *
 * Antes solo cambiaba el tono del prompt y la respuesta era el mismo bloque de
 * texto para todos. Se comprueba que la instrucción de formato llega al chat y
 * al diagnóstico, que cada estilo pide algo distinto, y que la respuesta le
 * dice al frontend cómo presentarla (`presentation`).
 */
class FormatoPorEstiloTest extends TestCase
{
    public function test_cada_estilo_pide_un_formato_distinto(): void
    {
        $formato = app(FormatoPorEstilo::class);

        $textos = array_map(fn (LearningStyle $e) => $formato->para($e), LearningStyle::cases());

        $this->assertNotContains(null, $textos, 'Todo estilo del enum necesita su texto en config/openai.php');
        $this->assertCount(count($textos), array_unique($textos));
        $this->assertNull($formato->para(null), 'Sin estilo no se añade instrucción');
    }

    public function test_el_estilo_auditivo_pide_texto_que_se_pueda_leer_en_voz_alta(): void
    {
        // Es el que más se separa: lo va a pronunciar un lector de voz, que no
        // sabe qué hacer con una tabla o un emoji.
        $texto = app(FormatoPorEstilo::class)->para(LearningStyle::Auditivo);

        $this->assertStringContainsString('voz alta', $texto);
        $this->assertStringContainsString('sin emojis', $texto);
    }

    public function test_el_chat_manda_el_formato_y_devuelve_la_presentacion(): void
    {
        $this->alumnoConEstilo(LearningStyle::Visual);
        $this->fingirModelo('1. Mira la pizza → 2. Córtala en 4.');

        $res = $this->postJson('/api/ai/tutor/chat', ['message' => '¿Qué es un cuarto?'])->assertOk();

        $this->assertSame('visual', $res->json('data.presentation'));
        $this->assertSeEnvio(app(FormatoPorEstilo::class)->para(LearningStyle::Visual));
    }

    public function test_el_diagnostico_manda_el_formato_y_devuelve_la_presentacion(): void
    {
        $alumno = $this->alumnoConEstilo(LearningStyle::Auditivo);

        StudentProgress::factory()->create([
            'institution_id'     => $alumno->institution_id,
            'student_user_id'    => $alumno->id,
            'subject_id'         => Subject::factory()->create(['institution_id' => $alumno->institution_id])->id,
            'mastery_percentage' => 45,
        ]);

        $this->fingirModelo('Vas bien. Primero repasa las sumas. Después, las restas.');

        $res = $this->getJson('/api/ai/tutor/diagnosis')->assertOk();

        $this->assertSame('auditivo', $res->json('data.presentation'));
        $this->assertSeEnvio(app(FormatoPorEstilo::class)->para(LearningStyle::Auditivo));
    }

    public function test_sin_estilo_la_presentacion_es_null(): void
    {
        $this->alumnoConEstilo(null);
        $this->fingirModelo('Hola, ¿en qué te ayudo?');

        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        $this->assertArrayHasKey('presentation', $res->json('data'));
        $this->assertNull($res->json('data.presentation'));
    }

    private function alumnoConEstilo(?LearningStyle $estilo): User
    {
        $centro = Institution::factory()->create();
        $user   = User::factory()->student()->create(['institution_id' => $centro->id]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $centro->id,
            'grade'          => 3,
            'learning_style' => $estilo?->value,
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
    private function assertSeEnvio(string $texto): void
    {
        $aguja = mb_substr($texto, 0, 60);

        OpenAI::assertSent(Chat::class, function (string $metodo, array $parametros) use ($aguja): bool {
            return str_contains(json_encode($parametros, JSON_UNESCAPED_UNICODE), $aguja);
        });
    }
}
