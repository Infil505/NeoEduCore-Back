<?php

namespace Tests\Feature\AI;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * El plan que el docente ve en analíticas es el de UN examen y viene repartido en sus cuatro secciones.
 *
 * Antes solo existía `POST /ai/generate`, que guarda UNA recomendación del tipo elegido: las que se
 * generaban a mano caían todas en la misma sección, y el plan completo solo se creaba cuando el
 * alumno entregaba. `POST /ai/plan` genera las cuatro de una vez para el examen elegido.
 */
class PlanDelExamenTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;
    private User $docente;
    private User $alumno;
    private Group $aula;
    private Subject $materia;
    private Exam $examen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->centro->id]);
        $this->aula = Group::factory()->create(['institution_id' => $this->centro->id]);
        $this->materia = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->asignarDocente($this->docente, $this->aula->id, $this->materia->id);

        $this->alumno = $this->nuevoAlumno();
        $this->examen = $this->examenEntregadoPor($this->alumno);
    }

    private function nuevoAlumno(bool $enElAula = true): User
    {
        $alumno = User::factory()->student()->create(['institution_id' => $this->centro->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $this->centro->id]);

        if ($enElAula) {
            $this->matricularEnGrupo($alumno->id, $this->aula->id, $this->centro->id);
        }

        return $alumno;
    }

    private function examenEntregadoPor(?User $alumno, ?User $autor = null): Exam
    {
        $examen = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id,
            'created_by_teacher_id' => ($autor ?? $this->docente)->id, 'status' => 'completed',
        ]);

        if ($alumno) {
            ExamAttempt::create([
                'institution_id' => $this->centro->id, 'exam_id' => $examen->id, 'student_user_id' => $alumno->id,
                'attempt_number' => 1, 'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(30),
                'score' => 4, 'max_score' => 10, 'grade_status' => 'graded',
            ]);
        }

        return $examen;
    }

    private function fingir(): void
    {
        OpenAI::fake(array_fill(0, 4, CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' => "strength: Buen trabajo con lo básico.\nweakness: Refuerza los temas con más errores.\naction: - Repasa tus errores.\nresource: Un video corto del tema."]]],
        ])));
    }

    public function test_el_docente_genera_el_plan_completo_en_las_cuatro_secciones(): void
    {
        $this->fingir();

        $r = $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $this->examen->id])->assertCreated();

        $tipos = collect($r->json('data'))->pluck('recommendation_type')->sort()->values()->all();
        $this->assertSame(['action', 'resource', 'strength', 'weakness'], $tipos);

        // Y el reporte del plan las reparte una por sección, no todas en la misma.
        $plan = $this->getJson("/api/reports/students/{$this->alumno->id}/strategies?exam_id={$this->examen->id}")->assertOk()->json('data');
        $this->assertSame(
            ['strength' => 1, 'weakness' => 1, 'action' => 1, 'resource' => 1],
            collect($plan['strategies'])->pluck('count', 'key')->all()
        );
    }

    /**
     * El modelo escribe el enlace como `url`, `link` o `href`, y antes solo se validaba `url`: lo que
     * viniera en `link` se guardaba sin pasar por la lista blanca. Además, su bloque JSON salía tal cual
     * en el texto que leen el docente y el alumno.
     */
    public function test_el_recurso_del_modelo_no_cuela_enlaces_ni_deja_el_json_en_el_texto(): void
    {
        OpenAI::fake(array_fill(0, 2, CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' =>
                "strength: Buen trabajo.
weakness: Refuerza los decimales.
action: - Repasa tus errores.
"
                . "resource: Usa este recurso para practicar.
```json
{\"title\": \"Juego\", \"link\": \"https://sitio-inventado.example.com/juego\"}
```"]]],
        ])));

        $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $this->examen->id])->assertCreated();

        $recurso = \App\Models\AI\AiRecommendation::where('student_user_id', $this->alumno->id)
            ->where('recommendation_type', 'resource')->firstOrFail();

        $this->assertStringNotContainsString('{', $recurso->recommendation_text);
        $this->assertStringNotContainsString('json', strtolower($recurso->recommendation_text));
        $this->assertStringContainsString('Usa este recurso para practicar', $recurso->recommendation_text);
        $this->assertStringNotContainsString('sitio-inventado', json_encode($recurso->resource));
        $this->assertArrayNotHasKey('link', (array) $recurso->resource);
    }

    /** La forma que mandó el modelo en la pantalla del docente: una LISTA de recursos, uno con enlace bloqueado. */
    public function test_una_lista_de_recursos_del_modelo_se_queda_con_el_primero_permitido(): void
    {
        OpenAI::fake(array_fill(0, 2, CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' =>
                "strength: Buen trabajo.
weakness: Refuerza los decimales.
action: - Repasa tus errores.
"
                . "resource: Puedes usar juegos en línea como Khan Academy o Prodigy.
`json
"
                . '{"resources": [{"name": "Prodigy", "type": "juego", "link": "https://sitio-inventado.example.com/p"}, '
                . '{"name": "Khan Academy", "type": "sitio web", "link": "https://www.khanacademy.org/math/arithmetic/fractions"}]}']]],
        ])));

        $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $this->examen->id])->assertCreated();

        $recurso = \App\Models\AI\AiRecommendation::where('student_user_id', $this->alumno->id)
            ->where('recommendation_type', 'resource')->firstOrFail();

        $this->assertSame('Khan Academy', $recurso->resource['title']);
        $this->assertSame('https://www.khanacademy.org/math/arithmetic/fractions', $recurso->resource['url']);
        $this->assertArrayNotHasKey('link', $recurso->resource);
        $this->assertStringNotContainsString('{', $recurso->getRawOriginal('recommendation_text'), 'Ni siquiera se guarda con el JSON.');
        $this->assertStringContainsString('Khan Academy o Prodigy', $recurso->recommendation_text);
    }

    public function test_el_plan_se_puede_pedir_de_un_solo_examen(): void
    {
        $this->fingir();
        $otro = $this->examenEntregadoPor($this->alumno);

        $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $this->examen->id])->assertCreated();

        $deEste = $this->getJson("/api/reports/students/{$this->alumno->id}/strategies?exam_id={$this->examen->id}")->json('data.totals.total');
        $deOtro = $this->getJson("/api/reports/students/{$this->alumno->id}/strategies?exam_id={$otro->id}")->json('data.totals.total');
        $todos  = $this->getJson("/api/reports/students/{$this->alumno->id}/strategies")->json('data.totals.total');

        $this->assertSame(4, $deEste);
        $this->assertSame(0, $deOtro, 'El plan de un examen no trae lo de otro.');
        $this->assertSame(4, $todos);
    }

    public function test_sin_intento_entregado_no_hay_plan(): void
    {
        OpenAI::fake([]);
        $sinEntregar = $this->nuevoAlumno();

        $this->postJson('/api/ai/plan', ['student_user_id' => $sinEntregar->id, 'exam_id' => $this->examen->id])
            ->assertStatus(409);

        OpenAI::assertNothingSent();
    }

    public function test_un_docente_no_genera_el_plan_del_examen_de_otro(): void
    {
        OpenAI::fake([]);
        $otro = User::factory()->teacher()->create(['institution_id' => $this->centro->id]);
        $ajeno = $this->examenEntregadoPor($this->alumno, $otro);

        $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $ajeno->id])->assertForbidden();

        OpenAI::assertNothingSent();
    }

    public function test_un_docente_no_genera_el_plan_de_un_alumno_que_no_alcanza(): void
    {
        OpenAI::fake([]);
        $fuera = $this->nuevoAlumno(false);
        ExamAttempt::create([
            'institution_id' => $this->centro->id, 'exam_id' => $this->examen->id, 'student_user_id' => $fuera->id,
            'attempt_number' => 1, 'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(30),
            'score' => 5, 'max_score' => 10, 'grade_status' => 'graded',
        ]);

        $this->postJson('/api/ai/plan', ['student_user_id' => $fuera->id, 'exam_id' => $this->examen->id])->assertForbidden();

        OpenAI::assertNothingSent();
    }

    public function test_un_examen_de_otro_centro_es_404(): void
    {
        OpenAI::fake([]);
        $otroCentro = Institution::factory()->create();
        $ajeno = Exam::factory()->create([
            'institution_id' => $otroCentro->id,
            'created_by_teacher_id' => User::factory()->teacher()->create(['institution_id' => $otroCentro->id])->id,
        ]);

        $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $ajeno->id])->assertNotFound();
    }

    public function test_un_estudiante_no_puede_pedir_el_plan(): void
    {
        OpenAI::fake([]);
        $this->actingAs($this->alumno, 'sanctum');

        $this->postJson('/api/ai/plan', ['student_user_id' => $this->alumno->id, 'exam_id' => $this->examen->id])->assertForbidden();
    }

    public function test_los_datos_son_obligatorios(): void
    {
        $this->postJson('/api/ai/plan', [])->assertStatus(422)->assertJsonValidationErrors(['student_user_id', 'exam_id']);
    }
}
