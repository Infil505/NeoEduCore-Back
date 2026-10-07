<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Inscribir a un estudiante en una materia no es una decisión libre del
 * docente: solo puede hacerlo para la materia que el admin ya le asignó en el
 * grupo de ese estudiante (`teacher_assignments`). Antes `enroll()` solo
 * comprobaba que el estudiante fuera suyo, sin mirar la materia, así que un
 * docente podía inscribirlo en cualquier materia del catálogo.
 */
class StudentSubjectAssignmentTest extends TestCase
{
    use ApiAuth;

    public function test_docente_inscribe_al_estudiante_en_la_materia_que_le_imparte(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);

        ['subject' => $materia] = $this->darAccesoDocenteA($docente, $alumno->id, $institution->id);

        $res = $this->postJson("/api/students/{$alumno->id}/subjects", ['subject_id' => $materia->id]);

        $res->assertCreated();
        $this->assertDatabaseHas('student_subjects', ['student_user_id' => $alumno->id, 'subject_id' => $materia->id]);
    }

    public function test_docente_no_puede_inscribir_en_una_materia_que_no_imparte_a_ese_grupo(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);

        // El docente sí alcanza al estudiante (otra materia, mismo grupo)...
        $this->darAccesoDocenteA($docente, $alumno->id, $institution->id);

        // ...pero esta materia no se la asignó el admin para ese grupo.
        $materiaAjena = Subject::factory()->create(['institution_id' => $institution->id]);

        $res = $this->postJson("/api/students/{$alumno->id}/subjects", ['subject_id' => $materiaAjena->id]);

        $res->assertForbidden();
        $this->assertDatabaseMissing('student_subjects', ['student_user_id' => $alumno->id, 'subject_id' => $materiaAjena->id]);
    }

    public function test_docente_no_puede_inscribir_con_una_materia_que_imparte_en_otro_grupo(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);
        $this->darAccesoDocenteA($docente, $alumno->id, $institution->id);

        // El docente imparte esta materia, pero en OTRO grupo (no en el del alumno).
        $otroGrupo = Group::factory()->create(['institution_id' => $institution->id]);
        $materiaEnOtroGrupo = Subject::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $otroGrupo->id, $materiaEnOtroGrupo->id);

        $res = $this->postJson("/api/students/{$alumno->id}/subjects", ['subject_id' => $materiaEnOtroGrupo->id]);

        $res->assertForbidden();
    }

    public function test_assignable_subjects_solo_devuelve_lo_que_el_docente_puede_asignar(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);
        ['subject' => $materiaAsignable] = $this->darAccesoDocenteA($docente, $alumno->id, $institution->id);

        // Materia que el docente imparte, pero en otro grupo: no debe salir.
        $otroGrupo = Group::factory()->create(['institution_id' => $institution->id]);
        $materiaEnOtroGrupo = Subject::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $otroGrupo->id, $materiaEnOtroGrupo->id);

        // Materia del catálogo que no tiene nada que ver: tampoco debe salir.
        Subject::factory()->create(['institution_id' => $institution->id]);

        $res = $this->getJson("/api/students/{$alumno->id}/assignable-subjects");

        $res->assertOk();
        $this->assertSame([$materiaAsignable->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_admin_ve_el_catalogo_completo_en_assignable_subjects(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);

        Subject::factory()->count(3)->create(['institution_id' => $institution->id]);

        $res = $this->getJson("/api/students/{$alumno->id}/assignable-subjects");

        $res->assertOk();
        $this->assertCount(3, $res->json('data'));
    }

    /**
     * El estudiante hereda las materias de su sección: lo que el admin asignó
     * en Docencia a esa sección, sin inscribirlo una por una.
     */
    public function test_el_estudiante_hereda_las_materias_de_su_seccion(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $seccion = Group::factory()->create(['institution_id' => $institution->id, 'section' => '5-1']);
        $otra    = Group::factory()->create(['institution_id' => $institution->id, 'section' => '6-1']);
        $mate    = Subject::factory()->create(['institution_id' => $institution->id, 'name' => 'Matemáticas']);
        $ingles  = Subject::factory()->create(['institution_id' => $institution->id, 'name' => 'Inglés']);

        $docente = User::factory()->teacher()->create(['institution_id' => $institution->id, 'full_name' => 'Laura Docente']);
        $this->asignarDocente($docente, $seccion->id, $mate->id);
        $this->asignarDocente($docente, $otra->id, $ingles->id); // de otra sección: no la hereda

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $institution->id]);
        $this->matricularEnGrupo($alumno->id, $seccion->id, $institution->id);

        $res = $this->getJson("/api/students/{$alumno->id}/subjects")->assertOk();

        $this->assertSame(['Matemáticas'], collect($res->json('data'))->pluck('name')->all());
        $res->assertJsonPath('data.0.origin', 'seccion')
            ->assertJsonPath('data.0.section', '5-1')
            ->assertJsonPath('data.0.teachers.0', 'Laura Docente');
    }
}
