<?php

namespace Tests\Feature\Academic;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Progreso de un docente elegido por el admin: materias que imparte, dominio
 * por tema de sus estudiantes y sus exámenes — mismos agregados que ya ve el
 * propio docente de "lo suyo", ahora parametrizados por un `teacherUserId`
 * que el admin elige, no por quien está autenticado.
 */
class TeacherProgressTest extends TestCase
{
    use ApiAuth;

    public function test_admin_ve_las_materias_de_un_docente_elegido(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $student = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $student->id, 'institution_id' => $institution->id]);

        ['subject' => $subject] = $this->darAccesoDocenteA($teacher, $student->id, $institution->id);

        $res = $this->getJson("/api/analytics/teachers/{$teacher->id}/subjects");

        $res->assertOk();
        $this->assertSame([$subject->id], collect($res->json('data'))->pluck('id')->all());
    }

    public function test_admin_ve_el_dominio_por_tema_de_un_docente_elegido(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $teacher = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $res = $this->getJson("/api/reports/teachers/{$teacher->id}/topics");

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['threshold', 'min_answers', 'topics']]);
    }

    public function test_admin_recibe_404_si_el_id_no_es_docente(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $student = User::factory()->student()->create(['institution_id' => $institution->id]);

        $this->getJson("/api/analytics/teachers/{$student->id}/subjects")->assertStatus(404);
        $this->getJson("/api/reports/teachers/{$student->id}/topics")->assertStatus(404);
    }

    public function test_admin_recibe_404_con_docente_de_otra_institucion(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $otherInstitution = Institution::factory()->create();
        $foreignTeacher = User::factory()->teacher()->create(['institution_id' => $otherInstitution->id]);

        $this->getJson("/api/analytics/teachers/{$foreignTeacher->id}/subjects")->assertStatus(404);
    }

    public function test_teacher_id_se_ignora_para_quien_no_es_admin(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $otroDocente = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        Exam::factory()->create([
            'institution_id' => $institution->id,
            'created_by_teacher_id' => $otroDocente->id,
        ]);

        $sinFiltro = $this->getJson('/api/exams')->assertOk()->json('data.data');
        $conFiltro = $this->getJson("/api/exams?teacher_id={$otroDocente->id}")->assertOk()->json('data.data');

        // El parametro no debe cambiar nada para un docente: ve lo mismo con o sin él.
        $this->assertSame(
            collect($sinFiltro)->pluck('id')->sort()->values()->all(),
            collect($conFiltro)->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_admin_filtra_examenes_por_teacher_id(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $teacherA = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $teacherB = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $examA = Exam::factory()->create(['institution_id' => $institution->id, 'created_by_teacher_id' => $teacherA->id]);
        Exam::factory()->create(['institution_id' => $institution->id, 'created_by_teacher_id' => $teacherB->id]);

        $res = $this->getJson("/api/exams?teacher_id={$teacherA->id}");

        $res->assertOk();
        $this->assertSame([$examA->id], collect($res->json('data.data'))->pluck('id')->all());
    }
}
