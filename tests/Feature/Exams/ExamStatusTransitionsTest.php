<?php

namespace Tests\Feature\Exams;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `PATCH /api/exams/{exam}/status` — las transiciones del examen.
 *
 * La ruta **no existía** hasta el 08/08/2026: `setStatus()` estaba escrito con
 * todas sus reglas pero no lo llamaba ninguna ruta, y `update()` no acepta
 * `status`. El efecto era que ningún examen podía salir de `draft` y, como
 * `Exam::scopeVisibleTo()` exige `active` para el alumnado, **nadie podía
 * presentar un examen por API**.
 *
 * La suite no lo detectaba porque los 12 sitios que necesitan un examen activo
 * lo crean con `Exam::factory()`, saltándose la API. Por eso estos casos van
 * contra el endpoint y no contra el modelo.
 */
class ExamStatusTransitionsTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;
    private User $docente;
    private Subject $materia;
    private Group $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->institution->id]);
        $this->materia = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $this->grupo   = Group::factory()->create(['institution_id' => $this->institution->id]);

        $this->asignarDocente($this->docente, $this->grupo->id, $this->materia->id);
    }

    private function examen(string $status = 'draft', bool $conPreguntas = true, bool $conGrupo = true): Exam
    {
        $exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $this->materia->id,
            'status'                => $status,
        ]);

        if ($conPreguntas) {
            Question::factory()->create([
                'institution_id' => $this->institution->id,
                'exam_id'        => $exam->id,
            ]);
        }

        if ($conGrupo) {
            $exam->syncGroups([$this->grupo->id]);
        }

        return $exam;
    }

    private function cambiar(Exam $exam, string $status)
    {
        return $this->patchJson("/api/exams/{$exam->id}/status", ['status' => $status]);
    }

    // -------------------------------------------------------------------------
    // El camino feliz, que es lo que estaba roto
    // -------------------------------------------------------------------------

    public function test_an_exam_can_travel_the_whole_lifecycle(): void
    {
        $exam = $this->examen();

        $this->cambiar($exam, 'published')->assertOk();
        $this->assertSame('published', $exam->fresh()->status->value);

        $this->cambiar($exam, 'active')->assertOk();
        $this->assertSame('active', $exam->fresh()->status->value);

        $this->cambiar($exam, 'completed')->assertOk();
        $this->assertSame('completed', $exam->fresh()->status->value);
    }

    /** Publicado puede volver a borrador para corregirlo. */
    public function test_published_can_go_back_to_draft(): void
    {
        $exam = $this->examen('published');

        $this->cambiar($exam, 'draft')->assertOk();
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    // -------------------------------------------------------------------------
    // Reglas de transición
    // -------------------------------------------------------------------------

    public function test_cannot_jump_from_draft_to_active(): void
    {
        $exam = $this->examen();

        $this->cambiar($exam, 'active')->assertStatus(409);
        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    public function test_completed_is_a_dead_end(): void
    {
        $exam = $this->examen('completed');

        foreach (['draft', 'published', 'active'] as $destino) {
            $this->cambiar($exam, $destino)->assertStatus(409);
        }

        $this->assertSame('completed', $exam->fresh()->status->value);
    }

    public function test_cannot_publish_an_exam_without_questions(): void
    {
        $exam = $this->examen(conPreguntas: false);

        $this->cambiar($exam, 'published')
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'sin preguntas'));
    }

    public function test_cannot_activate_after_the_availability_window_closed(): void
    {
        $exam = $this->examen('published');
        $exam->update(['available_until' => now()->subDay()]);

        $this->cambiar($exam, 'active')
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'ventana'));
    }

    public function test_an_invalid_status_value_is_rejected(): void
    {
        $exam = $this->examen();

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'inventado'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // -------------------------------------------------------------------------
    // Permisos
    // -------------------------------------------------------------------------

    public function test_a_teacher_cannot_change_the_status_of_someone_elses_exam(): void
    {
        $ajeno = $this->examen();

        $otro = $this->signInTeacher(['institution_id' => $this->institution->id]);
        $this->asignarDocente($otro, $this->grupo->id, $this->materia->id);

        $this->cambiar($ajeno, 'published')->assertForbidden();
        $this->assertSame('draft', $ajeno->fresh()->status->value);
    }

    public function test_a_student_cannot_change_the_status(): void
    {
        $exam = $this->examen();

        $this->signInStudent(['institution_id' => $this->institution->id]);

        $this->cambiar($exam, 'published')->assertForbidden();
    }

    /**
     * El hueco que se cerró de paso: los grupos se fijan al crear el examen,
     * pero es al publicarlo cuando llega al alumnado. Si entre una cosa y otra
     * el admin le retira el grupo, publicar ya no debe colar.
     */
    public function test_publishing_is_blocked_if_the_teacher_lost_the_assignment(): void
    {
        $exam = $this->examen();

        \Illuminate\Support\Facades\DB::table('teacher_assignments')
            ->where('teacher_user_id', $this->docente->id)
            ->delete();

        $this->cambiar($exam, 'published')
            ->assertForbidden()
            ->assertJsonStructure(['message', 'grupos_no_asignados']);

        $this->assertSame('draft', $exam->fresh()->status->value);
    }

    /** El admin no está sujeto a asignaciones. */
    public function test_admin_can_change_the_status_of_any_exam(): void
    {
        $exam = $this->examen();

        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->cambiar($exam, 'published')->assertOk();
    }

    // -------------------------------------------------------------------------
    // El efecto real: el alumnado
    // -------------------------------------------------------------------------

    /**
     * La razón por la que esto importaba: hasta que el examen no está `active`,
     * el alumnado no lo ve. Con la ruta ausente, nunca llegaba a estarlo.
     */
    public function test_the_exam_only_reaches_the_student_once_active(): void
    {
        $exam = $this->examen();

        $alumnoUser = User::factory()->student()->create(['institution_id' => $this->institution->id]);
        \App\Models\Students\Student::factory()->create([
            'user_id'        => $alumnoUser->id,
            'institution_id' => $this->institution->id,
        ]);
        $this->matricularEnGrupo($alumnoUser->id, $this->grupo->id, $this->institution->id);

        $verComoAlumno = function () use ($alumnoUser) {
            $this->actingAs($alumnoUser, 'sanctum');

            return collect($this->getJson('/api/exams')->json('data.data'))->pluck('id')->all();
        };

        $this->assertNotContains($exam->id, $verComoAlumno(), 'un borrador no debe verse');

        $this->actingAs($this->docente, 'sanctum');
        $this->cambiar($exam, 'published')->assertOk();
        $this->assertNotContains($exam->id, $verComoAlumno(), 'publicado tampoco: hace falta activo');

        $this->actingAs($this->docente, 'sanctum');
        $this->cambiar($exam, 'active')->assertOk();
        $this->assertContains($exam->id, $verComoAlumno(), 'activo sí debe verse');
    }
}
