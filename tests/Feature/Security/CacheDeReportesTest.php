<?php

namespace Tests\Feature\Security;

use App\Models\Admin\User;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\StudentAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * El dominio por tema (`/reports/topics`) recorre todas las respuestas del centro;
 * se cachea en el área `REPORTES`, que se invalida al entregar un examen o revisar
 * una respuesta. Dos cosas no pueden fallar: no servir datos viejos, y no
 * compartir un resultado entre alcances distintos.
 */
class CacheDeReportesTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    private string $tema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
        // Marca propia: la base de tests acumula filas entre tests.
        $this->tema = 'Tema ' . Str::random(8);
    }

    /** Un intento ABIERTO con 3 respuestas (el mínimo para que el tema cuente). */
    private function intentoConRespuestas(User $alumno, bool $correctas): ExamAttempt
    {
        $intento = $this->intentoAbierto($alumno);

        for ($i = 0; $i < 3; $i++) {
            [$pregunta] = $this->preguntaMultiple($this->examen);
            $pregunta->update(['topic' => $this->tema]);

            DB::table('student_answers')->insert([
                'id' => (string) Str::uuid(), 'institution_id' => $this->centro->id, 'attempt_id' => $intento->id,
                'question_id' => $pregunta->id, 'is_correct' => $correctas, 'points_awarded' => $correctas ? 2 : 0,
                'review_status' => 'auto_graded', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $intento;
    }

    private function entregar(ExamAttempt $intento): void
    {
        $intento->update(['submitted_at' => now(), 'grade_status' => 'completed']);
    }

    private function tema(): ?array
    {
        $temas = $this->getJson('/api/reports/topics?limit=50')->assertOk()->json('data.topics');

        return collect($temas)->firstWhere('topic', $this->tema);
    }

    private function consultasASiguiente(callable $peticion): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $peticion();
        $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"student_answers"'))->count();
        DB::disableQueryLog();

        return $n;
    }

    public function test_la_segunda_lectura_sale_de_la_cache(): void
    {
        $this->entregar($this->intentoConRespuestas($this->alumnoA, true));
        $this->actingAs($this->admin, 'sanctum');

        $primera = $this->consultasASiguiente(fn () => $this->tema());
        $segunda = $this->consultasASiguiente(fn () => $this->tema());

        $this->assertGreaterThan(0, $primera);
        $this->assertSame(0, $segunda, 'La segunda lectura debía salir de la caché.');
    }

    public function test_entregar_un_examen_se_ve_enseguida(): void
    {
        $this->entregar($this->intentoConRespuestas($this->alumnoA, true));
        $this->actingAs($this->admin, 'sanctum');
        $antes = $this->tema();
        $this->assertSame(3, $antes['total']);
        $this->assertSame(100.0, (float) $antes['percentage']);

        $this->entregar($this->intentoConRespuestas($this->alumnoB, false));

        $despues = $this->tema();
        $this->assertSame(6, $despues['total']);
        $this->assertSame(50.0, (float) $despues['percentage']);
    }

    public function test_revisar_una_respuesta_se_ve_enseguida(): void
    {
        $intento = $this->intentoConRespuestas($this->alumnoA, true);
        $this->entregar($intento);
        $this->actingAs($this->admin, 'sanctum');
        $this->assertSame(100.0, (float) $this->tema()['percentage']);

        StudentAnswer::query()->where('attempt_id', $intento->id)->first()->update(['is_correct' => false]);

        $this->assertEqualsWithDelta(66.67, (float) $this->tema()['percentage'], 0.01);
    }

    public function test_un_intento_sin_entregar_no_cuenta_y_al_entregarlo_si(): void
    {
        $intento = $this->intentoConRespuestas($this->alumnoA, true);
        $this->actingAs($this->admin, 'sanctum');
        $this->assertNull($this->tema());

        $this->entregar($intento);

        $this->assertSame(3, $this->tema()['total']);
    }

    public function test_el_resultado_de_un_alcance_no_se_comparte_con_otro(): void
    {
        $this->entregar($this->intentoConRespuestas($this->alumnoA, true));

        // El administrador calienta la caché con todo el centro.
        $this->actingAs($this->admin, 'sanctum');
        $this->assertNotNull($this->tema());

        // Un docente sin aulas asignadas no ve a nadie: no hereda lo del administrador.
        $sinAulas = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        $this->actingAs($sinAulas, 'sanctum');
        $this->assertNull($this->tema());

        // El docente del aula de alumnoA sí lo ve.
        $this->actingAs($this->docente, 'sanctum');
        $this->assertSame(3, $this->tema()['total']);
    }
}
