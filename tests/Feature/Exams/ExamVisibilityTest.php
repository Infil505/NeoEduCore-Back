<?php

namespace Tests\Feature\Exams;

use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Qué exámenes puede ver cada rol en las rutas de **lectura compartida**
 * (`/exams`, `/exams/{id}`, `/exams/{id}/questions`).
 *
 * Estas rutas sirven a admin, docente y estudiante con el mismo middleware, y
 * hasta el 05/08/2026 no estrechaban nada por rol: un alumno listaba el catálogo
 * completo de su institución —borradores incluidos— y, con los ids que ese mismo
 * listado le daba, leía los enunciados de cualquier examen antes de presentarlo.
 * Las respuestas correctas nunca se filtraron (eso lo cubre `AnswerLeakTest`),
 * pero conocer las preguntas de antemano invalida igual el diagnóstico.
 *
 * Complementa a `AnswerLeakTest`: aquel vigila *qué campos* se revelan de una
 * pregunta; este, *a qué exámenes* se llega siquiera.
 */
class ExamVisibilityTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->create([
            'institution_id' => $this->institution->id,
        ]);
    }

    public function test_student_does_not_see_draft_exams_in_the_catalog(): void
    {
        $borrador = $this->examen('BORRADOR', 'draft');
        $activo   = $this->examen('ACTIVO', 'active');

        $alumno = $this->alumnoConGrupo([$activo, $borrador]);

        $res = $this->getJson('/api/exams')->assertOk();

        $res->assertSee('ACTIVO');
        $res->assertDontSee('BORRADOR');
        $this->assertSame(1, $res->json('data.total'));
    }

    public function test_student_cannot_read_questions_of_an_unassigned_exam(): void
    {
        $ajeno = $this->examen('EXAMEN_AJENO', 'active');
        Question::factory()->create([
            'institution_id' => $this->institution->id,
            'exam_id' => $ajeno->id,
            'question_text' => 'ENUNCIADO_QUE_NO_LE_TOCA',
        ]);

        // El alumno tiene grupo, pero el examen no está asignado a ese grupo
        $this->alumnoConGrupo([]);

        $this->getJson("/api/exams/{$ajeno->id}")->assertNotFound();
        $this->getJson("/api/exams/{$ajeno->id}/questions")->assertNotFound();
    }

    public function test_student_cannot_read_questions_of_a_draft_exam_of_their_own_group(): void
    {
        $borrador = $this->examen('BORRADOR', 'draft');
        Question::factory()->create([
            'institution_id' => $this->institution->id,
            'exam_id' => $borrador->id,
            'question_text' => 'ENUNCIADO_SIN_PUBLICAR',
        ]);

        // Asignado a su grupo, pero todavía sin publicar
        $this->alumnoConGrupo([$borrador]);

        $this->getJson("/api/exams/{$borrador->id}")->assertNotFound();
        $this->getJson("/api/exams/{$borrador->id}/questions")->assertNotFound();
    }

    public function test_student_reads_an_active_exam_assigned_to_their_group(): void
    {
        $examen = $this->examen('MI_EXAMEN', 'active');
        Question::factory()->create([
            'institution_id' => $this->institution->id,
            'exam_id' => $examen->id,
            'question_text' => 'ENUNCIADO_LEGITIMO',
        ]);

        $this->alumnoConGrupo([$examen]);

        $this->getJson("/api/exams/{$examen->id}")->assertOk()->assertSee('ENUNCIADO_LEGITIMO');
        $this->getJson("/api/exams/{$examen->id}/questions")->assertOk()->assertSee('ENUNCIADO_LEGITIMO');
    }

    public function test_exam_outside_its_availability_window_is_not_visible(): void
    {
        $caducado = $this->examen('YA_CERRADO', 'active');
        $caducado->update([
            'available_from'  => now()->subDays(10),
            'available_until' => now()->subDay(),
        ]);

        $this->alumnoConGrupo([$caducado]);

        $this->getJson("/api/exams/{$caducado->id}")->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/exams')->json('data.total'));
    }

    public function test_student_does_not_see_the_teachers_email(): void
    {
        $examen = $this->examen('MI_EXAMEN', 'active');
        $this->alumnoConGrupo([$examen]);

        foreach (["/api/exams", "/api/exams/{$examen->id}"] as $ruta) {
            $res = $this->getJson($ruta)->assertOk();
            $res->assertDontSee($this->teacher->email);
            $res->assertSee($this->teacher->full_name);
        }
    }

    public function test_student_does_not_see_the_exam_configuration(): void
    {
        $examen = $this->examen('MI_EXAMEN', 'active');
        $this->alumnoConGrupo([$examen]);

        $data = $this->getJson("/api/exams/{$examen->id}")->assertOk()->json('data');

        foreach (['max_attempts', 'randomize_questions', 'allow_review_after_submission', 'show_results_immediately'] as $campo) {
            $this->assertArrayNotHasKey($campo, $data, "El estudiante no debe ver `{$campo}`");
        }
    }

    /** El estrechamiento es solo para el estudiante: el docente gestiona todo. */
    public function test_teacher_still_sees_drafts_and_the_full_exam_record(): void
    {
        $borrador = $this->examen('BORRADOR', 'draft');
        Question::factory()->create([
            'institution_id' => $this->institution->id,
            'exam_id' => $borrador->id,
            'question_text' => 'ENUNCIADO_EN_PREPARACION',
        ]);

        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $res = $this->getJson('/api/exams')->assertOk();
        $res->assertSee('BORRADOR');
        $res->assertSee($this->teacher->email);

        $data = $this->getJson("/api/exams/{$borrador->id}")->assertOk()->json('data');
        $this->assertArrayHasKey('max_attempts', $data);

        $this->getJson("/api/exams/{$borrador->id}/questions")
            ->assertOk()
            ->assertSee('ENUNCIADO_EN_PREPARACION');
    }

    /* ===================== helpers ===================== */

    private function examen(string $titulo, string $status): Exam
    {
        return Exam::factory()->create([
            'institution_id' => $this->institution->id,
            'created_by_teacher_id' => $this->teacher->id,
            'title' => $titulo,
            'status' => $status,
        ]);
    }

    /**
     * Crea un alumno autenticado, lo mete en un grupo y asigna a ese grupo los
     * exámenes indicados.
     *
     * @param  array<int,Exam>  $examenes
     */
    private function alumnoConGrupo(array $examenes): User
    {
        $grupo = Group::factory()->create(['institution_id' => $this->institution->id]);

        foreach ($examenes as $examen) {
            $examen->syncGroups([$grupo->id]);
        }

        $alumno = $this->signInStudent(['institution_id' => $this->institution->id]);
        Student::factory()->create([
            'user_id' => $alumno->id,
            'institution_id' => $this->institution->id,
        ]);

        DB::table('group_students')->insert([
            'institution_id'  => $this->institution->id,
            'group_id'        => $grupo->id,
            'student_user_id' => $alumno->id,
            'joined_at'       => now(),
            'left_at'         => null,
        ]);

        return $alumno;
    }
}
