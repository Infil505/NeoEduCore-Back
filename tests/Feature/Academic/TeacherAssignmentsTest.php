<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Asignación docente → grupo → materia, y el alcance que se deriva de ella.
 *
 * El modelo anterior derivaba «mis estudiantes» de la autoría del examen, así
 * que un docente se concedía acceso solo: bastaba crear un borrador dirigido a
 * cualquier grupo de la institución para leer el progreso, los informes y las
 * recomendaciones de IA de ese alumnado. Los tests de este archivo fijan que
 * esa vía está cerrada y que la única fuente de alcance es `teacher_assignments`.
 */
class TeacherAssignmentsTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    private function alumno(): User
    {
        $user = User::factory()->student()->create(['institution_id' => $this->institution->id]);
        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $this->institution->id,
        ]);

        return $user;
    }

    private function grupo(): Group
    {
        return Group::factory()->create(['institution_id' => $this->institution->id]);
    }

    private function materia(): Subject
    {
        return Subject::factory()->create(['institution_id' => $this->institution->id]);
    }

    // -------------------------------------------------------------------------
    // Gestión de la asignación — solo admin
    // -------------------------------------------------------------------------

    public function test_admin_creates_the_cartesian_product_of_groups_and_subjects(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $docente  = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
        $grupos   = [$this->grupo()->id, $this->grupo()->id];
        $materias = [$this->materia()->id, $this->materia()->id, $this->materia()->id];

        $this->postJson('/api/teacher-assignments', [
            'teacher_user_id' => $docente->id,
            'group_ids'       => $grupos,
            'subject_ids'     => $materias,
        ])->assertCreated();

        // 2 grupos x 3 materias = 6 filas
        $this->assertSame(6, DB::table('teacher_assignments')
            ->where('teacher_user_id', $docente->id)
            ->count());
    }

    public function test_repeating_the_assignment_is_idempotent_and_keeps_the_original_date(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $docente = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
        $grupo   = $this->grupo();
        $materia = $this->materia();

        $cuerpo = [
            'teacher_user_id' => $docente->id,
            'group_ids'       => [$grupo->id],
            'subject_ids'     => [$materia->id],
        ];

        $this->postJson('/api/teacher-assignments', $cuerpo)->assertCreated();
        $original = DB::table('teacher_assignments')->where('teacher_user_id', $docente->id)->value('assigned_at');

        $this->postJson('/api/teacher-assignments', $cuerpo)->assertCreated();

        $this->assertSame(1, DB::table('teacher_assignments')->where('teacher_user_id', $docente->id)->count());
        $this->assertSame($original, DB::table('teacher_assignments')->where('teacher_user_id', $docente->id)->value('assigned_at'));
    }

    /** Es la razón de ser de la tabla: nadie se asigna a sí mismo. */
    public function test_teacher_cannot_assign_themselves(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $this->postJson('/api/teacher-assignments', [
            'teacher_user_id' => $docente->id,
            'group_ids'       => [$this->grupo()->id],
            'subject_ids'     => [$this->materia()->id],
        ])->assertForbidden();

        $this->assertSame(0, DB::table('teacher_assignments')
            ->where('teacher_user_id', $docente->id)
            ->count());
    }

    public function test_teacher_cannot_list_or_delete_assignments(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $this->getJson('/api/teacher-assignments')->assertForbidden();
        $this->deleteJson('/api/teacher-assignments/bulk', [
            'teacher_user_id' => '00000000-0000-4000-8000-000000000000',
        ])->assertForbidden();
    }

    public function test_cannot_assign_a_user_who_is_not_a_teacher(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $alumno = $this->alumno();

        $this->postJson('/api/teacher-assignments', [
            'teacher_user_id' => $alumno->id,
            'group_ids'       => [$this->grupo()->id],
            'subject_ids'     => [$this->materia()->id],
        ])->assertStatus(422);
    }

    public function test_cannot_assign_a_teacher_from_another_institution(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $otra    = Institution::factory()->create();
        $ajeno   = User::factory()->teacher()->create(['institution_id' => $otra->id]);

        $this->postJson('/api/teacher-assignments', [
            'teacher_user_id' => $ajeno->id,
            'group_ids'       => [$this->grupo()->id],
            'subject_ids'     => [$this->materia()->id],
        ])->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // El alcance que se deriva
    // -------------------------------------------------------------------------

    /**
     * El caso que motivó todo el cambio: sin asignación no se ve nada del
     * alumno, por ninguna de las seis vías que antes lo exponían.
     */
    public function test_teacher_without_assignment_is_blocked_on_every_student_endpoint(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $alumno = $this->alumno();

        $this->getJson("/api/students/{$alumno->id}")->assertForbidden();
        $this->getJson("/api/reports/students/{$alumno->id}/history")->assertForbidden();
        $this->getJson("/api/reports/students/{$alumno->id}/summary")->assertForbidden();
        $this->getJson("/api/reports/students/{$alumno->id}/strategies")->assertForbidden();
        $this->getJson("/api/analytics/students/{$alumno->id}")->assertForbidden();
        $this->getJson("/api/students/{$alumno->id}/subjects")->assertForbidden();
    }

    public function test_teacher_with_assignment_reaches_their_student(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $alumno = $this->alumno();
        $this->darAccesoDocenteA($docente, $alumno->id, $this->institution->id);

        $this->getJson("/api/students/{$alumno->id}")->assertOk();
        $this->getJson("/api/reports/students/{$alumno->id}/history")->assertOk();
        $this->getJson("/api/analytics/students/{$alumno->id}")->assertOk();
    }

    public function test_student_index_only_lists_students_of_assigned_groups(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $mio   = $this->alumno();
        $ajeno = $this->alumno();

        $this->darAccesoDocenteA($docente, $mio->id, $this->institution->id);

        $res = $this->getJson('/api/students')->assertOk();
        $ids = collect($res->json('data.data'))->pluck('user_id')->all();

        $this->assertContains($mio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    /** Retirar la asignación corta el acceso en el acto. */
    public function test_removing_the_assignment_cuts_access_immediately(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $alumno = $this->alumno();
        $this->darAccesoDocenteA($docente, $alumno->id, $this->institution->id);

        $this->getJson("/api/students/{$alumno->id}")->assertOk();

        DB::table('teacher_assignments')->where('teacher_user_id', $docente->id)->delete();

        $this->getJson("/api/students/{$alumno->id}")->assertForbidden();
    }

    /**
     * El acceso sigue a la matrícula activa: si el alumno deja el grupo, el
     * docente de esa sección deja de verlo, incluido su historial.
     */
    public function test_a_student_who_left_the_group_is_no_longer_reachable(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $alumno = $this->alumno();
        ['group' => $grupo] = $this->darAccesoDocenteA($docente, $alumno->id, $this->institution->id);

        $this->getJson("/api/students/{$alumno->id}")->assertOk();

        DB::table('group_students')
            ->where('group_id', $grupo->id)
            ->where('student_user_id', $alumno->id)
            ->update(['left_at' => now()]);

        $this->getJson("/api/students/{$alumno->id}")->assertForbidden();
    }

    public function test_teacher_cannot_upsert_progress_of_a_student_outside_their_groups(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $alumno  = $this->alumno();
        $materia = $this->materia();

        $this->postJson('/api/student-progress', [
            'student_user_id'    => $alumno->id,
            'subject_id'         => $materia->id,
            'mastery_percentage' => 90,
        ])->assertForbidden();

        $this->assertDatabaseMissing('student_progress', ['student_user_id' => $alumno->id]);
    }

    public function test_progress_index_hides_students_outside_the_assigned_groups(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $mio     = $this->alumno();
        $ajeno   = $this->alumno();
        $materia = $this->materia();

        $this->darAccesoDocenteA($docente, $mio->id, $this->institution->id);

        foreach ([$mio, $ajeno] as $alumno) {
            StudentProgress::factory()->create([
                'institution_id'     => $this->institution->id,
                'student_user_id'    => $alumno->id,
                'subject_id'         => $materia->id,
                'mastery_percentage' => 70,
            ]);
        }

        $res = $this->getJson('/api/student-progress')->assertOk();
        $ids = collect($res->json('data.data'))->pluck('student_user_id')->all();

        $this->assertContains($mio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);
    }

    // -------------------------------------------------------------------------
    // Exámenes: ya no se puede apuntar a un grupo ajeno
    // -------------------------------------------------------------------------

    private function cuerpoExamen(string $subjectId, array $groupIds): array
    {
        return [
            'title'            => 'Diagnóstico',
            'subject_id'       => $subjectId,
            'grade'            => 8,
            'duration_minutes' => 45,
            'group_ids'        => $groupIds,
        ];
    }

    /**
     * La vía de autoconcesión, cerrada: crear un borrador dirigido a un grupo
     * ajeno ya no cuela, y además no deja el examen creado a medias.
     */
    public function test_teacher_cannot_target_a_group_they_are_not_assigned_to(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $materia = $this->materia();
        $ajeno   = $this->grupo();

        $this->postJson('/api/exams', $this->cuerpoExamen($materia->id, [$ajeno->id]))
            ->assertForbidden();

        // Acotado a esta materia: los datos persisten entre tests de la clase.
        $this->assertSame(0, DB::table('exams')->where('subject_id', $materia->id)->count());
        $this->assertSame(0, DB::table('exam_targets')->where('group_id', $ajeno->id)->count());
    }

    /** La asignación es por grupo Y materia: el grupo correcto no basta. */
    public function test_assignment_to_the_group_does_not_authorize_another_subject(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $grupo         = $this->grupo();
        $materiaPropia = $this->materia();
        $materiaAjena  = $this->materia();

        $this->asignarDocente($docente, $grupo->id, $materiaPropia->id);

        $this->postJson('/api/exams', $this->cuerpoExamen($materiaAjena->id, [$grupo->id]))
            ->assertForbidden();
    }

    public function test_teacher_can_target_their_assigned_group_and_subject(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $grupo   = $this->grupo();
        $materia = $this->materia();

        $this->asignarDocente($docente, $grupo->id, $materia->id);

        $res = $this->postJson('/api/exams', $this->cuerpoExamen($materia->id, [$grupo->id]))
            ->assertCreated();

        $this->assertDatabaseHas('exam_targets', [
            'exam_id'  => $res->json('data.id'),
            'group_id' => $grupo->id,
        ]);
    }

    /** El mismo control en la edición, no solo al crear. */
    public function test_teacher_cannot_retarget_an_exam_to_a_foreign_group(): void
    {
        $docente = $this->signInTeacher(['institution_id' => $this->institution->id]);

        $grupo   = $this->grupo();
        $materia = $this->materia();
        $ajeno   = $this->grupo();

        $this->asignarDocente($docente, $grupo->id, $materia->id);

        $examId = $this->postJson('/api/exams', $this->cuerpoExamen($materia->id, [$grupo->id]))
            ->assertCreated()
            ->json('data.id');

        $this->putJson("/api/exams/{$examId}", ['group_ids' => [$ajeno->id]])
            ->assertForbidden();

        // El destino original se conserva: la edición se rechaza entera.
        $this->assertDatabaseHas('exam_targets', [
            'exam_id'  => $examId,
            'group_id' => $grupo->id,
        ]);
        $this->assertDatabaseMissing('exam_targets', [
            'exam_id'  => $examId,
            'group_id' => $ajeno->id,
        ]);
    }

    /** El admin no está sujeto a asignaciones: gestiona toda su institución. */
    public function test_admin_can_target_any_group_of_the_institution(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $grupo   = $this->grupo();
        $materia = $this->materia();

        $this->postJson('/api/exams', $this->cuerpoExamen($materia->id, [$grupo->id]))
            ->assertCreated();
    }
}
