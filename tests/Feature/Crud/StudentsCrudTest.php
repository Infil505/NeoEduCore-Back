<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\User;
use App\Models\Admin\Institution;
use App\Models\Students\Student;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class StudentsCrudTest extends TestCase
{
    use ApiAuth;

    public function test_list_students(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        // Crear estudiantes
        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $res = $this->getJson('/api/students');

        $res->assertOk();
        // Verificar que devuelve una lista
        $this->assertTrue(is_array($res->json('data') ?? $res->json()));
    }

    public function test_show_student(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

        $res = $this->getJson("/api/students/{$student->user_id}");

        $res->assertOk();
        $this->assertNotNull($res->json('data.user_id') ?? $res->json('user_id'));
    }

    public function test_update_student(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

        // El docente edita perfil y datos de aprendizaje de SUS alumnos.
        $res = $this->putJson("/api/students/{$student->user_id}", [
            'parent_name' => 'Nuevo Acudiente',
            'learning_style' => 'visual',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('students', [
            'user_id' => $studentUser->id,
            'parent_name' => 'Nuevo Acudiente',
        ]);
    }

    /**
     * Curso, sección, código de estudiante y aula los cambia el administrador.
     * El docente recibe 403 —no se ignoran en silencio— y nada cambia.
     */
    public function test_teacher_cannot_change_grade_section_code_or_group_of_a_student(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
            'grade' => 4,
            'student_code' => 'EST-ORIGINAL',
        ]);
        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

        foreach (['grade' => 6, 'section' => 'B', 'student_code' => 'EST-NUEVO', 'group_code' => '6B2026'] as $campo => $valor) {
            $this->putJson("/api/students/{$student->user_id}", [$campo => $valor, 'parent_name' => 'Otro'])
                ->assertForbidden()
                ->assertJsonPath('campos_solo_admin.0', $campo);
        }

        $this->assertDatabaseHas('students', [
            'user_id' => $studentUser->id,
            'grade' => 4,
            'student_code' => 'EST-ORIGINAL',
        ]);
        $this->assertDatabaseMissing('students', ['user_id' => $studentUser->id, 'parent_name' => 'Otro']);
    }

    /**
     * Curso, sección y aula salen de la matrícula: ni el administrador los
     * escribe por la ficha (se movería la etiqueta, no al alumno). 422 y nada cambia.
     */
    public function test_admin_cannot_change_grade_section_or_group_through_the_profile(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student = Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id, 'grade' => 4]);

        foreach (['grade' => 5, 'section' => 'B', 'group_code' => '5B2026'] as $campo => $valor) {
            $this->putJson("/api/students/{$student->user_id}", [$campo => $valor])
                ->assertStatus(422)
                ->assertJsonPath('campos_de_matricula.0', $campo);
        }

        $this->assertDatabaseHas('students', ['user_id' => $studentUser->id, 'grade' => 4]);

        // El código de estudiante sí lo edita el administrador.
        $this->putJson("/api/students/{$student->user_id}", ['student_code' => 'EST-0500'])->assertOk();
        $this->assertDatabaseHas('students', ['user_id' => $studentUser->id, 'student_code' => 'EST-0500']);
    }

    public function test_student_me_endpoint(): void
    {
        $institution = Institution::factory()->create();
        
        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        // Actuar como el estudiante
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/students/me');

        $res->assertOk();
    }

    /**
     * `student_code` es único por institución: `PUT /students/{id}` debe dar 422
     * y no un 500 al chocar contra la constraint. Antes no validaba nada.
     */
    public function test_update_rejects_a_student_code_already_used_in_the_institution(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInAdmin(['institution_id' => $institution->id]);

        $ocupadoUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id'        => $ocupadoUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-OCUPADO',
        ]);

        $otroUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $otro = Student::factory()->create([
            'user_id'        => $otroUser->id,
            'institution_id' => $institution->id,
            'student_code'   => 'EST-LIBRE',
        ]);

        $this->putJson("/api/students/{$otro->user_id}", ['student_code' => 'EST-OCUPADO'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_code');

        // Conservar el propio código no es un duplicado de sí mismo.
        $this->putJson("/api/students/{$otro->user_id}", ['student_code' => 'EST-LIBRE'])
            ->assertOk();
    }

    /** El mismo código en otra institución no estorba. */
    public function test_update_allows_a_student_code_used_by_another_institution(): void
    {
        $otra = Institution::factory()->create();
        $ajenoUser = User::factory()->student()->create(['institution_id' => $otra->id]);
        Student::factory()->create([
            'user_id'        => $ajenoUser->id,
            'institution_id' => $otra->id,
            'student_code'   => 'EST-AJENO',
        ]);

        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $mioUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $mio = Student::factory()->create([
            'user_id'        => $mioUser->id,
            'institution_id' => $institution->id,
        ]);

        $this->putJson("/api/students/{$mio->user_id}", ['student_code' => 'EST-AJENO'])
            ->assertOk();
    }

    /** El estado del alumno lo cambia solo el administrador. */
    public function test_teacher_cannot_set_student_status(): void
    {
        $institution = Institution::factory()->create();
        $teacher = $this->signInTeacher(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student = Student::factory()->create(['user_id' => $studentUser->id, 'institution_id' => $institution->id]);
        $this->darAccesoDocenteA($teacher, $student->user_id, $institution->id);

        $this->patchJson("/api/students/{$student->user_id}/status", ['status' => 'inactive'])
            ->assertForbidden();
        $this->assertDatabaseMissing('students', ['user_id' => $studentUser->id, 'status' => 'inactive']);
    }

    public function test_set_student_status(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $studentUser = User::factory()->student()->create([
            'institution_id' => $institution->id,
        ]);
        $student = Student::factory()->create([
            'user_id' => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        $res = $this->patchJson("/api/students/{$student->user_id}/status", [
            'status' => 'inactive',
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('students', [
            'user_id' => $studentUser->id,
            'status' => 'inactive',
        ]);
    }

    /** «Resultados»: el estudiante ve solo SUS intentos entregados, con nota. */
    public function test_student_sees_only_his_submitted_attempts(): void
    {
        $institution = Institution::factory()->create();
        $yo = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $yo->id, 'institution_id' => $institution->id]);
        $otro = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $otro->id, 'institution_id' => $institution->id]);

        $exam = \App\Models\Exams\Exam::factory()->create(['institution_id' => $institution->id, 'title' => 'Parcial 1']);
        \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id, 'exam_id' => $exam->id, 'student_user_id' => $yo->id, 'score' => 8, 'max_score' => 10,
        ]);
        // En curso (sin entregar) y de otro alumno: no salen.
        \App\Models\Exams\ExamAttempt::factory()->create([
            'institution_id' => $institution->id, 'exam_id' => $exam->id, 'student_user_id' => $yo->id, 'submitted_at' => null,
        ]);
        \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id, 'exam_id' => $exam->id, 'student_user_id' => $otro->id,
        ]);

        $this->actingAs($yo, 'sanctum');

        $this->getJson('/api/students/me/attempts')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exam_title', 'Parcial 1')
            ->assertJsonPath('data.0.percentage', 80);
    }

    /**
     * Regresión del 13/10/2026: la lista unió examen y materia en la consulta de los
     * intentos (antes eran 3 viajes a la base). Tiene que seguir dando la materia, no
     * mezclar un examen de OTRO centro aunque comparta el id de materia, y no volver a
     * las consultas extra (con la base remota cada una son ~0,5 s de un worker).
     */
    public function test_my_attempts_returns_subject_in_two_queries_without_leaking_other_centers(): void
    {
        $institution = Institution::factory()->create();
        $yo = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create(['user_id' => $yo->id, 'institution_id' => $institution->id]);

        $subject = \App\Models\Academic\Subject::factory()->create(['institution_id' => $institution->id, 'name' => 'Matemática']);
        $exam = \App\Models\Exams\Exam::factory()->create([
            'institution_id' => $institution->id, 'title' => 'Parcial 1', 'subject_id' => $subject->id,
        ]);
        \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $institution->id, 'exam_id' => $exam->id, 'student_user_id' => $yo->id,
        ]);

        // Otro centro con un examen distinto: jamás debe aparecer en la lista de este alumno.
        $otroCentro = Institution::factory()->create();
        $ajeno = User::factory()->student()->create(['institution_id' => $otroCentro->id]);
        Student::factory()->create(['user_id' => $ajeno->id, 'institution_id' => $otroCentro->id]);
        $examAjeno = \App\Models\Exams\Exam::factory()->create(['institution_id' => $otroCentro->id, 'title' => 'Examen ajeno']);
        \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $otroCentro->id, 'exam_id' => $examAjeno->id, 'student_user_id' => $ajeno->id,
        ]);

        $this->actingAs($yo, 'sanctum');

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $res = $this->getJson('/api/students/me/attempts');
        $consultas = count(\Illuminate\Support\Facades\DB::getQueryLog());

        $res->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.exam_title', 'Parcial 1')
            ->assertJsonPath('data.0.subject', 'Matemática');
        $this->assertStringNotContainsString('Examen ajeno', $res->getContent());
        // Con las cachés en frío (el test): centro activo + lista + catálogo de materias.
        // En producción las dos de caché no llegan a la base. Antes eran 4 (exámenes y
        // materias por separado), así que volver a `with()` rompe este tope.
        $this->assertLessThanOrEqual(
            3,
            $consultas,
            "attempts hizo {$consultas} consultas: " . json_encode(array_map(
                fn ($q) => substr($q['query'], 0, 90),
                \Illuminate\Support\Facades\DB::getQueryLog()
            ))
        );
    }
}
