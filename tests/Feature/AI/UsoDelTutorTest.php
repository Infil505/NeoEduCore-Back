<?php

namespace Tests\Feature\AI;

use App\Models\AI\AiChatSession;
use App\Models\AI\AiRecommendation;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `GET /reports/ai/tutor-usage`: el docente ve el uso del tutor de SUS estudiantes, no el de todo el
 * centro; en lugar del ranking nominal (que el informe [173] le niega) recibe el uso por aula.
 */
class UsoDelTutorTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;
    private User $docente;
    private Group $aulaA;
    private Group $aulaB;
    private Subject $suya;
    private Subject $ajena;
    private User $enA;
    private User $enB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->centro->id]);

        $this->aulaA = Group::factory()->create(['institution_id' => $this->centro->id, 'name' => 'Aula A']);
        $this->aulaB = Group::factory()->create(['institution_id' => $this->centro->id, 'name' => 'Aula B']);
        $this->suya = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->ajena = Subject::factory()->create(['institution_id' => $this->centro->id]);
        $this->asignarDocente($this->docente, $this->aulaA->id, $this->suya->id);

        $this->enA = $this->alumnoEn($this->aulaA);
        $this->enB = $this->alumnoEn($this->aulaB);
    }

    private function alumnoEn(Group $aula, bool $sigue = true): User
    {
        $alumno = User::factory()->student()->create(['institution_id' => $this->centro->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $this->centro->id]);
        $this->matricularEnGrupo($alumno->id, $aula->id, $this->centro->id);

        if (!$sigue) {
            DB::table('group_students')->where('student_user_id', $alumno->id)->update(['left_at' => now()]);
        }

        return $alumno;
    }

    private function sesion(User $alumno, int $mensajes, bool $cerrada = false, ?Institution $centro = null): void
    {
        AiChatSession::create([
            'institution_id' => ($centro ?? $this->centro)->id,
            'student_user_id' => $alumno->id,
            'messages' => array_fill(0, $mensajes, ['role' => 'user', 'content' => 'hola']),
            'ended_at' => $cerrada ? now() : null,
        ]);
    }

    private function recomendacion(User $alumno, Subject $materia, string $tipo): void
    {
        AiRecommendation::create([
            'institution_id' => $this->centro->id, 'student_user_id' => $alumno->id, 'subject_id' => $materia->id,
            'recommendation_type' => $tipo, 'recommendation_text' => 'x', 'generated_at' => now(),
        ]);
    }

    public function test_el_docente_solo_cuenta_el_uso_de_sus_estudiantes(): void
    {
        $this->sesion($this->enA, 4);
        $this->sesion($this->enA, 2, cerrada: true);
        $this->sesion($this->enB, 10); // de otra aula: no es suyo

        $datos = $this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data');

        $this->assertSame('teacher', $datos['scope']);
        $this->assertSame(2, $datos['sessions']['total_sessions']);
        $this->assertSame(1, $datos['sessions']['active_sessions']);
        $this->assertSame(1, $datos['sessions']['closed_sessions']);
        $this->assertSame(1, $datos['sessions']['unique_students']);
        $this->assertEquals(6, $datos['sessions']['total_messages']);
    }

    public function test_los_tipos_de_recomendacion_son_de_sus_estudiantes_y_de_sus_materias(): void
    {
        $this->recomendacion($this->enA, $this->suya, 'weakness');
        $this->recomendacion($this->enA, $this->suya, 'weakness');
        $this->recomendacion($this->enA, $this->suya, 'action');
        $this->recomendacion($this->enA, $this->ajena, 'strength'); // materia que no imparte
        $this->recomendacion($this->enB, $this->suya, 'resource');  // alumno de otra aula

        $tipos = collect($this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data.top_recommendation_types'))
            ->pluck('total', 'type')->all();

        $this->assertSame(['weakness' => 2, 'action' => 1], $tipos);
    }

    public function test_el_docente_recibe_el_uso_por_aula_y_no_el_ranking_nominal(): void
    {
        $this->sesion($this->enA, 3);
        $this->sesion($this->enB, 5);
        $this->alumnoEn($this->aulaA);                    // otro alumno del aula A que no lo usa
        $this->alumnoEn($this->aulaA, sigue: false);      // quien ya se fue no cuenta

        $datos = $this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data');

        $this->assertArrayNotHasKey('top_students_by_usage', $datos);
        $this->assertCount(1, $datos['usage_by_group'], 'Solo las aulas que tiene asignadas.');
        $this->assertSame(
            ['name' => 'Aula A', 'students' => 2, 'students_using' => 1, 'sessions' => 1, 'messages' => 3],
            collect($datos['usage_by_group'][0])->only(['name', 'students', 'students_using', 'sessions', 'messages'])->all()
        );
    }

    public function test_el_administrador_ve_todo_el_centro_con_el_ranking_nominal(): void
    {
        $this->sesion($this->enA, 3);
        $this->sesion($this->enB, 5);

        $this->signInAdmin(['institution_id' => $this->centro->id]);
        $datos = $this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data');

        $this->assertSame('center', $datos['scope']);
        $this->assertSame(2, $datos['sessions']['total_sessions']);
        $this->assertCount(2, $datos['top_students_by_usage']);
        $this->assertSame(['Aula A', 'Aula B'], collect($datos['usage_by_group'])->pluck('name')->all());
    }

    public function test_lo_de_otro_centro_no_entra_en_ninguno(): void
    {
        $otro = Institution::factory()->create();
        $ajeno = User::factory()->student()->create(['institution_id' => $otro->id]);
        Student::factory()->create(['user_id' => $ajeno->id, 'institution_id' => $otro->id]);
        $this->sesion($ajeno, 50, centro: $otro);

        $this->assertSame(0, $this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data.sessions.total_sessions'));

        $this->signInAdmin(['institution_id' => $this->centro->id]);
        $this->assertSame(0, $this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data.sessions.total_sessions'));
    }

    public function test_sin_uso_los_totales_son_cero_y_no_nulos(): void
    {
        $datos = $this->getJson('/api/reports/ai/tutor-usage')->assertOk()->json('data');

        $this->assertEquals(0, $datos['sessions']['total_messages']);
        $this->assertSame(0, $datos['sessions']['total_sessions']);
        $this->assertSame([], $datos['top_recommendation_types']);
    }

    public function test_un_estudiante_no_puede_verlo(): void
    {
        $this->actingAs($this->enA, 'sanctum');

        $this->getJson('/api/reports/ai/tutor-usage')->assertForbidden();
    }
}
