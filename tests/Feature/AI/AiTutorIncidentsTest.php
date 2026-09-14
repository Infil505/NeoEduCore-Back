<?php

namespace Tests\Feature\AI;

use App\Enums\AiIncidentStage;
use App\Enums\AiIncidentType;
use App\Models\AI\AiTutorIncident;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Aviso de IA (D4) y registro de incidencias del tutor (D5).
 *
 * Las dos decisiones salen del mismo sitio del informe: [397] pide avisar de que
 * responde un modelo, y [173] pide registrar incidencias y superar el 75 % de
 * mensajes validados. Lo segundo era, hasta ahora, un requisito **no medible**:
 * los bloqueos solo dejaban un `Log::warning`.
 *
 * Lo que más importa comprobar aquí, y por eso tiene test propio: **que el
 * registro no guarde el texto que provocó la incidencia**. Una tabla de bloqueos
 * por datos personales que almacenara el dato personal sería peor que no tenerla.
 */
class AiTutorIncidentsTest extends TestCase
{
    use ApiAuth;

    private const TELEFONO = 'Escribime a mi celular +506 88887777 y lo vemos.';

    public function test_la_respuesta_del_chat_avisa_de_que_la_escribio_una_ia(): void
    {
        $student = $this->alumno();
        $this->fingirModelo('Las fracciones son partes de un entero.');

        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Explicame las fracciones']);

        $res->assertOk();
        $this->assertSame(
            (string) config('openai.tutor.notice'),
            $res->json('data.ai_notice'),
            'El aviso de [397] viaja en la respuesta, no lo pone el frontend.'
        );
    }

    public function test_el_diagnostico_tambien_avisa(): void
    {
        $this->alumno();
        $this->fingirModelo('Vas bien en matemáticas, sigue practicando la lectura.');

        $res = $this->getJson('/api/ai/tutor/diagnosis');

        $res->assertOk();
        $this->assertSame((string) config('openai.tutor.notice'), $res->json('data.ai_notice'));
    }

    public function test_un_bloqueo_por_datos_personales_queda_registrado(): void
    {
        $student = $this->alumno();
        $this->fingirModelo(self::TELEFONO);

        $this->postJson('/api/ai/tutor/chat', ['message' => '¿Cómo te contacto?'])->assertOk();

        $incidencia = $this->incidenciaDe($student);

        $this->assertNotNull($incidencia, 'El bloqueo por PII debe dejar fila, no solo un log.');
        $this->assertSame(AiIncidentType::Pii, $incidencia->type);
        $this->assertSame(AiIncidentStage::Chat, $incidencia->stage);
        $this->assertSame($student->id, $incidencia->student_user_id);
        $this->assertSame($student->institution_id, $incidencia->institution_id);
    }

    /**
     * El corazón de D5: se registra **qué** pasó, nunca **qué decía**.
     */
    public function test_la_incidencia_no_guarda_el_texto_que_la_provoco(): void
    {
        $student = $this->alumno();
        $this->fingirModelo(self::TELEFONO);

        $this->postJson('/api/ai/tutor/chat', ['message' => '¿Cómo te contacto?'])->assertOk();

        $fila = json_encode($this->incidenciaDe($student)->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('88887777', $fila);
        $this->assertStringNotContainsString('celular', $fila);
    }

    public function test_un_enlace_fuera_de_la_lista_blanca_se_registra_aunque_la_respuesta_se_entregue(): void
    {
        $student = $this->alumno();
        $this->fingirModelo('Mirá este video: https://sitio-cualquiera.example/video para repasar.');

        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Recomiéndame un video'])->assertOk();

        // La respuesta llega, solo pierde el enlace.
        $this->assertStringContainsString('[enlace no permitido]', $res->json('data.reply'));

        $this->assertSame(AiIncidentType::BlockedUrl, $this->incidenciaDe($student)?->type);
    }

    public function test_un_fallo_de_openai_se_registra_como_incidencia_del_modelo(): void
    {
        $student = $this->alumno();
        OpenAI::fake([new \RuntimeException('502 Bad Gateway')]);

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        $this->assertSame(AiIncidentType::ModelError, $this->incidenciaDe($student)?->type);
    }

    public function test_el_superadmin_ve_las_metricas_agregadas_y_ningun_alumno(): void
    {
        $student = $this->alumno();
        $this->fingirModelo(self::TELEFONO);
        $this->postJson('/api/ai/tutor/chat', ['message' => '¿Cómo te contacto?'])->assertOk();

        $this->signInSuperAdmin();

        $res = $this->getJson('/api/platform/ai-tutor-metrics?institution_id=' . $student->institution_id);

        $res->assertOk();
        $res->assertJsonPath('data.by_type.pii', 1);
        $res->assertJsonPath('data.totals.validation_incidents', 1);
        $res->assertJsonPath('data.totals.threshold', 75);

        // Agregados: ni el id del alumno ni su nombre salen por aquí.
        $cuerpo = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($student->id, $cuerpo);
        $this->assertStringNotContainsString($student->full_name, $cuerpo);
    }

    /**
     * Sin mensajes no hay incumplimiento. Devolver 0 % pintaría de rojo a un
     * centro recién creado, que es ruido y no información.
     */
    public function test_sin_mensajes_la_tasa_es_del_cien_por_cien(): void
    {
        // Centro recién creado, sin una sola conversación.
        $reciente = Institution::factory()->create();

        $this->signInSuperAdmin();

        $res = $this->getJson('/api/platform/ai-tutor-metrics?institution_id=' . $reciente->id)->assertOk();

        $res->assertJsonPath('data.totals.validation_pass_rate', 100);
        $res->assertJsonPath('data.totals.meets_threshold', true);
        // Los tipos sin incidencias salen en 0, no ausentes.
        $res->assertJsonPath('data.by_type.model_error', 0);
    }

    public function test_el_admin_de_un_centro_no_entra_a_las_metricas_de_plataforma(): void
    {
        $this->signInAdmin();

        $this->getJson('/api/platform/ai-tutor-metrics')->assertStatus(403);
    }

    public function test_el_docente_tampoco_entra(): void
    {
        $this->signInTeacher();

        $this->getJson('/api/platform/ai-tutor-metrics')->assertStatus(403);
    }

    /* =========================
     | Apoyo
     ========================= */

    private function alumno(): User
    {
        $institution = Institution::factory()->create();

        $user = User::factory()->student()->create([
            'institution_id' => $institution->id,
            'full_name'      => 'Mariana Solís Vargas',
        ]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
        ]);

        $this->actingAs($user, 'sanctum');
        app()->instance('tenant_id', $institution->id);

        return $user;
    }

    /**
     * El esquema se carga una vez por proceso, así que los datos sobreviven
     * entre tests de la misma clase. Cada aserción se acota al centro que creó
     * su propio test.
     */
    private function incidenciaDe(User $student): ?AiTutorIncident
    {
        return AiTutorIncident::where('institution_id', $student->institution_id)->first();
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
