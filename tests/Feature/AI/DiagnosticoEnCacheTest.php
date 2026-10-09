<?php

namespace Tests\Feature\AI;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * El diagnóstico del tutor se cachea POR CONTENIDO (hash del prompt).
 *
 * Antes cada visita a la pantalla llamaba al modelo —segundos y créditos— para
 * devolver casi siempre lo mismo. Ahora, con el mismo progreso, sale de la
 * caché; si el alumno avanza, el prompt cambia y se pide uno nuevo sin tener
 * que invalidar nada a mano.
 */
class DiagnosticoEnCacheTest extends TestCase
{
    use ApiAuth;

    private function respuesta(string $texto): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $texto]]],
        ]);
    }

    private function alumno(int $dominio = 55): array
    {
        $centro = Institution::factory()->create();
        $user = User::factory()->student()->create(['institution_id' => $centro->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $centro->id]);
        $materia = Subject::factory()->create(['institution_id' => $centro->id, 'name' => 'Matemáticas']);
        $progreso = StudentProgress::create([
            'institution_id' => $centro->id, 'student_user_id' => $user->id, 'subject_id' => $materia->id,
            'mastery_percentage' => $dominio, 'updated_at' => now(),
        ]);

        return [$user, $progreso];
    }

    private function diagnostico(User $alumno): string
    {
        $this->actingAs($alumno, 'sanctum');

        return (string) $this->getJson('/api/ai/tutor/diagnosis')->assertOk()->json('data.diagnosis');
    }

    public function test_con_el_mismo_progreso_el_modelo_se_llama_una_sola_vez(): void
    {
        [$alumno] = $this->alumno();
        OpenAI::fake([$this->respuesta('Vas muy bien en Matemáticas, sigue practicando.')]);

        $primero = $this->diagnostico($alumno);
        $segundo = $this->diagnostico($alumno);
        $tercero = $this->diagnostico($alumno);

        $this->assertStringContainsString('Vas muy bien', $primero);
        $this->assertSame($primero, $segundo);
        $this->assertSame($primero, $tercero);
        OpenAI::assertSent(Chat::class, 1);
    }

    public function test_si_el_progreso_cambia_se_pide_un_diagnostico_nuevo(): void
    {
        [$alumno, $progreso] = $this->alumno(40);
        OpenAI::fake([
            $this->respuesta('Primer diagnóstico: refuerza fracciones.'),
            $this->respuesta('Segundo diagnóstico: ya dominas fracciones.'),
        ]);

        $antes = $this->diagnostico($alumno);

        $progreso->update(['mastery_percentage' => 90]);

        $despues = $this->diagnostico($alumno);

        $this->assertStringContainsString('Primer diagnóstico', $antes);
        $this->assertStringContainsString('Segundo diagnóstico', $despues);
        OpenAI::assertSent(Chat::class, 2);
    }

    public function test_la_respuesta_de_reserva_no_se_cachea(): void
    {
        [$alumno] = $this->alumno();
        // 1.ª llamada: el modelo falla → texto de reserva. 2.ª: el modelo ya responde.
        OpenAI::fake([new \RuntimeException('sin créditos'), $this->respuesta('Ahora sí responde el modelo.')]);

        $reserva = $this->diagnostico($alumno);
        $normal = $this->diagnostico($alumno);

        $this->assertStringNotContainsString('Ahora sí responde el modelo', $reserva);
        $this->assertStringContainsString('Ahora sí responde el modelo', $normal);
        OpenAI::assertSent(Chat::class, 2);
    }

    public function test_un_alumno_no_recibe_el_diagnostico_cacheado_de_otro(): void
    {
        [$ana] = $this->alumno(55);
        [$beto] = $this->alumno(55); // mismo progreso, otra persona
        OpenAI::fake([$this->respuesta('Diagnóstico de Ana.'), $this->respuesta('Diagnóstico de Beto.')]);

        $this->assertStringContainsString('de Ana', $this->diagnostico($ana));
        $this->assertStringContainsString('de Beto', $this->diagnostico($beto));
    }
}
