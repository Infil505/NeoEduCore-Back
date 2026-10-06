<?php

namespace Tests\Feature\Academic;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `GET /dashboard/staff-overview` es el endpoint que alimenta el panel del
 * profesor (estudiantes, materias, grupos, exámenes). Antes de esta prueba no
 * filtraba nada por docente: cualquier profesor veía el mismo padrón completo
 * de la institución que un admin, el mismo bug que `StudentController::index`
 * y `GroupController::index` ya tenían resuelto desde hace tiempo.
 */
class StaffOverviewTest extends TestCase
{
    use ApiAuth;

    public function test_docente_solo_ve_sus_estudiantes_materias_y_grupos_asignados(): void
    {
        $institution = Institution::factory()->create();
        $teacherA = $this->signInTeacher(['institution_id' => $institution->id]);
        $teacherB = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $studentA = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentA->id, 'institution_id' => $institution->id]);
        ['group' => $groupA, 'subject' => $subjectA] = $this->darAccesoDocenteA($teacherA, $studentA->id, $institution->id);

        $studentB = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentB->id, 'institution_id' => $institution->id]);
        $this->darAccesoDocenteA($teacherB, $studentB->id, $institution->id);

        $res = $this->getJson('/api/dashboard/staff-overview');

        $res->assertOk();
        $data = $res->json('data');

        $this->assertSame([$studentA->id], collect($data['students'])->pluck('user_id')->all());
        $this->assertSame([$subjectA->id], collect($data['subjects'])->pluck('id')->all());
        $this->assertSame([$groupA->id], collect($data['groups'])->pluck('id')->all());
    }

    public function test_docente_solo_ve_los_examenes_que_el_mismo_creo(): void
    {
        $institution = Institution::factory()->create();
        $teacherA = $this->signInTeacher(['institution_id' => $institution->id]);
        $teacherB = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $examA = Exam::factory()->create(['institution_id' => $institution->id, 'created_by_teacher_id' => $teacherA->id]);
        Exam::factory()->create(['institution_id' => $institution->id, 'created_by_teacher_id' => $teacherB->id]);

        $res = $this->getJson('/api/dashboard/staff-overview');

        $res->assertOk();
        $this->assertSame([$examA->id], collect($res->json('data.exams'))->pluck('id')->all());
    }

    public function test_admin_sigue_viendo_todo_sin_filtrar(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $teacherA = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $teacherB = User::factory()->teacher()->create(['institution_id' => $institution->id]);

        $studentA = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentA->id, 'institution_id' => $institution->id]);
        $this->darAccesoDocenteA($teacherA, $studentA->id, $institution->id);

        $studentB = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $studentB->id, 'institution_id' => $institution->id]);
        $this->darAccesoDocenteA($teacherB, $studentB->id, $institution->id);

        $res = $this->getJson('/api/dashboard/staff-overview');

        $res->assertOk();
        $studentIds = collect($res->json('data.students'))->pluck('user_id')->sort()->values()->all();
        $this->assertSame(collect([$studentA->id, $studentB->id])->sort()->values()->all(), $studentIds);
    }
}
