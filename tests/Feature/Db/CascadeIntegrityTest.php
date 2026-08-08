<?php

namespace Tests\Feature\Db;

use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Integridad referencial y cascadas, según el modelo de datos del informe TFG.
 *
 * Estos casos NO estaban cubiertos: los tests de borrado existentes eliminaban
 * entidades vacías, así que nadie detectó que `DELETE /subjects`, `/groups`,
 * `/exams` y `/users` devolvían **500** en cuanto la entidad tenía contenido
 * real, pese a que la documentación afirmaba que cascadeaban.
 *
 * Cada test monta la cadena completa antes de borrar.
 */
class CascadeIntegrityTest extends TestCase
{
    use ApiAuth;

    private Institution $inst;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inst = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $this->inst->id]);
        $this->teacher = User::factory()->teacher()->create(['institution_id' => $this->inst->id]);
    }

    /**
     * Monta materia → examen → 2 preguntas → opciones → grupo con alumno →
     * asignación de examen → intento → respuesta → opción marcada → progreso.
     */
    private function escenarioCompleto(): array
    {
        $subject = Subject::factory()->create(['institution_id' => $this->inst->id]);
        $group   = Group::factory()->create(['institution_id' => $this->inst->id]);

        $exam = Exam::factory()->create([
            'institution_id'        => $this->inst->id,
            'subject_id'            => $subject->id,
            'created_by_teacher_id' => $this->teacher->id,
        ]);

        $preguntas = [];
        foreach ([0, 1] as $i) {
            $q = Question::factory()->create([
                'institution_id' => $this->inst->id,
                'exam_id'        => $exam->id,
                'question_type'  => 'true_false',
                'order_index'    => $i,
            ]);
            $opt = QuestionOption::create([
                'institution_id' => $this->inst->id, 'question_id' => $q->id,
                'option_index' => 0, 'option_text' => 'Verdadero', 'is_correct' => true,
            ]);
            $preguntas[] = [$q, $opt];
        }
        [$q1, $opt1] = $preguntas[0];

        $studentUser = User::factory()->student()->create(['institution_id' => $this->inst->id]);
        Student::factory()->create([
            'user_id' => $studentUser->id, 'institution_id' => $this->inst->id,
        ]);

        DB::table('group_students')->insert([
            'institution_id' => $this->inst->id, 'group_id' => $group->id,
            'student_user_id' => $studentUser->id, 'joined_at' => now(), 'left_at' => null,
        ]);
        DB::table('exam_targets')->insert([
            'institution_id' => $this->inst->id, 'exam_id' => $exam->id, 'group_id' => $group->id,
        ]);

        $attempt = ExamAttempt::factory()->create([
            'institution_id' => $this->inst->id, 'exam_id' => $exam->id,
            'student_user_id' => $studentUser->id, 'submitted_at' => now(),
        ]);
        $answer = StudentAnswer::create([
            'institution_id' => $this->inst->id, 'attempt_id' => $attempt->id,
            'question_id' => $q1->id, 'is_correct' => true, 'points_awarded' => 1,
        ]);
        DB::table('student_answer_options')->insert([
            'institution_id' => $this->inst->id,
            'student_answer_id' => $answer->id, 'option_id' => $opt1->id,
        ]);
        StudentProgress::create([
            'institution_id' => $this->inst->id, 'student_user_id' => $studentUser->id,
            'subject_id' => $subject->id, 'mastery_percentage' => 70, 'updated_at' => now(),
        ]);

        return compact('subject', 'group', 'exam', 'attempt', 'answer', 'studentUser')
            + ['q1' => $q1, 'opt1' => $opt1];
    }

    private function existe(string $tabla, string $col, string $id): bool
    {
        return DB::table($tabla)->where($col, $id)->exists();
    }

    public function test_deleting_a_subject_cascades_the_whole_chain(): void
    {
        $e = $this->escenarioCompleto();

        $this->deleteJson("/api/subjects/{$e['subject']->id}")->assertNoContent();

        $this->assertFalse($this->existe('subjects', 'id', $e['subject']->id));
        $this->assertFalse($this->existe('exams', 'id', $e['exam']->id));
        $this->assertFalse($this->existe('questions', 'id', $e['q1']->id));
        $this->assertFalse($this->existe('question_options', 'id', (string) $e['opt1']->id));
        $this->assertFalse($this->existe('exam_attempts', 'id', $e['attempt']->id));
        $this->assertFalse($this->existe('student_answers', 'id', $e['answer']->id));
        $this->assertFalse($this->existe('student_answer_options', 'student_answer_id', $e['answer']->id));

        // El estudiante y el grupo NO se borran: solo el contenido de la materia
        $this->assertTrue($this->existe('students', 'user_id', $e['studentUser']->id));
        $this->assertTrue($this->existe('groups', 'id', $e['group']->id));
    }

    public function test_deleting_a_group_cascades_memberships_and_targets(): void
    {
        $e = $this->escenarioCompleto();

        $this->deleteJson("/api/groups/{$e['group']->id}")->assertNoContent();

        $this->assertFalse($this->existe('groups', 'id', $e['group']->id));
        $this->assertFalse($this->existe('group_students', 'group_id', $e['group']->id));
        $this->assertFalse($this->existe('exam_targets', 'group_id', $e['group']->id));

        // Ni el examen ni los estudiantes desaparecen con el grupo
        $this->assertTrue($this->existe('exams', 'id', $e['exam']->id));
        $this->assertTrue($this->existe('students', 'user_id', $e['studentUser']->id));
    }

    public function test_deleting_an_exam_cascades_questions_and_attempts(): void
    {
        $e = $this->escenarioCompleto();

        $this->deleteJson("/api/exams/{$e['exam']->id}")->assertNoContent();

        $this->assertFalse($this->existe('exams', 'id', $e['exam']->id));
        $this->assertFalse($this->existe('questions', 'id', $e['q1']->id));
        $this->assertFalse($this->existe('question_options', 'id', (string) $e['opt1']->id));
        $this->assertFalse($this->existe('exam_attempts', 'id', $e['attempt']->id));
        $this->assertFalse($this->existe('student_answers', 'id', $e['answer']->id));

        // La materia sobrevive al borrado de uno de sus exámenes
        $this->assertTrue($this->existe('subjects', 'id', $e['subject']->id));
    }

    public function test_deleting_a_student_user_cascades_their_activity(): void
    {
        $e = $this->escenarioCompleto();

        $this->deleteJson("/api/users/{$e['studentUser']->id}")->assertSuccessful();

        $this->assertFalse($this->existe('users', 'id', $e['studentUser']->id));
        $this->assertFalse($this->existe('students', 'user_id', $e['studentUser']->id));
        $this->assertFalse($this->existe('exam_attempts', 'student_user_id', $e['studentUser']->id));
        $this->assertFalse($this->existe('student_answers', 'id', $e['answer']->id));
        $this->assertFalse($this->existe('student_progress', 'student_user_id', $e['studentUser']->id));
        $this->assertFalse($this->existe('group_students', 'student_user_id', $e['studentUser']->id));

        // El examen y sus preguntas no son del alumno: siguen ahí
        $this->assertTrue($this->existe('exams', 'id', $e['exam']->id));
        $this->assertTrue($this->existe('questions', 'id', $e['q1']->id));
    }

    public function test_deleting_a_teacher_keeps_their_exams(): void
    {
        $e = $this->escenarioCompleto();

        $this->deleteJson("/api/users/{$this->teacher->id}")->assertSuccessful();

        // Divergencia deliberada vs el TFG: SET NULL en vez de bloquear,
        // para poder dar de baja a un docente sin perder sus exámenes.
        $this->assertTrue($this->existe('exams', 'id', $e['exam']->id));
        $this->assertNull(DB::table('exams')->where('id', $e['exam']->id)->value('created_by_teacher_id'));
    }

    /**
     * Regresión: `study_resources.created_by` era la última FK a `users(id)` en
     * NO ACTION, así que borrar a un docente que hubiera subido **un solo**
     * recurso devolvía 500 — `UserController::destroy()` no maneja violaciones
     * de FK, la excepción de PostgreSQL salía tal cual.
     *
     * El test anterior no lo veía porque `escenarioCompleto()` monta exámenes
     * para el docente pero no recursos de estudio; es exactamente el mismo punto
     * ciego que dejó pasar los 500 de `/subjects`, `/groups` y `/exams`.
     */
    public function test_deleting_a_teacher_keeps_the_study_resources_they_uploaded(): void
    {
        $recurso = StudyResource::factory()->create([
            'institution_id' => $this->inst->id,
            'created_by'     => $this->teacher->id,
        ]);

        $this->deleteJson("/api/users/{$this->teacher->id}")->assertSuccessful();

        // El material queda en el centro; solo se pierde la autoría.
        $this->assertTrue($this->existe('study_resources', 'id', $recurso->id));
        $this->assertNull(DB::table('study_resources')->where('id', $recurso->id)->value('created_by'));
    }

    /* =========================
     |  Integridad de la relación con students
     ========================= */

    public function test_an_exam_attempt_requires_a_real_student_profile(): void
    {
        $e = $this->escenarioCompleto();

        // El TFG modela exam_attempts.student_user_id -> students(user_id).
        // Un docente NO tiene perfil de estudiante: la BD debe rechazarlo.
        $this->expectException(\Illuminate\Database\QueryException::class);

        ExamAttempt::factory()->create([
            'institution_id'  => $this->inst->id,
            'exam_id'         => $e['exam']->id,
            'student_user_id' => $this->teacher->id,
            'submitted_at'    => now(),
        ]);
    }

    public function test_progress_requires_a_real_student_profile(): void
    {
        $e = $this->escenarioCompleto();

        $this->expectException(\Illuminate\Database\QueryException::class);

        StudentProgress::create([
            'institution_id'     => $this->inst->id,
            'student_user_id'    => $this->teacher->id,
            'subject_id'         => $e['subject']->id,
            'mastery_percentage' => 50,
            'updated_at'         => now(),
        ]);
    }

    public function test_tenant_columns_without_a_real_institution_are_rejected(): void
    {
        $e = $this->escenarioCompleto();
        $fantasma = '00000000-0000-4000-8000-0000000000ff';

        // Las 3 FK de institution_id que faltaban respecto al modelo TFG
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('student_answers')->insert([
            'id'              => (string) \Illuminate\Support\Str::orderedUuid(),
            'institution_id'  => $fantasma,
            'attempt_id'      => $e['attempt']->id,
            'question_id'     => $e['q1']->id,
            'is_correct'      => false,
            'points_awarded'  => 0,
        ]);
    }

    /**
     * Las dos últimas columnas `institution_id` sin restricción.
     *
     * Quedaron fuera de `align_fk_constraints_with_tfg_model` porque el esquema de
     * diseño del TFG tampoco las declaraba, y la decisión se anotó como abierta
     * («coherencia vs. fidelidad al documento»). Al fijarse que **manda el sistema
     * y el informe se ajusta**, la duda desaparece: se cierran como las otras.
     */
    public function test_exam_attempts_require_a_real_institution(): void
    {
        $e = $this->escenarioCompleto();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('exam_attempts')->insert([
            'id'              => (string) \Illuminate\Support\Str::orderedUuid(),
            'institution_id'  => '00000000-0000-4000-8000-0000000000ff',
            'exam_id'         => $e['exam']->id,
            'student_user_id' => $e['studentUser']->id,
            'attempt_number'  => 9,
        ]);
    }

    public function test_student_progress_requires_a_real_institution(): void
    {
        $e = $this->escenarioCompleto();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('student_progress')->insert([
            'id'                 => (string) \Illuminate\Support\Str::orderedUuid(),
            'institution_id'     => '00000000-0000-4000-8000-0000000000ff',
            'student_user_id'    => $e['studentUser']->id,
            'subject_id'         => $e['subject']->id,
            'mastery_percentage' => 10,
            'updated_at'         => now(),
        ]);
    }

    /**
     * Con `students.institution_id` y `group_students.institution_id` en NO ACTION,
     * borrar una institución con alumnos habría fallado con 500 — el mismo fallo
     * que costó descubrir en abril con materias, grupos y exámenes. Hoy no hay
     * endpoint que lo dispare, y por eso se arregla ahora y no cuando lo haya.
     */
    public function test_deleting_an_institution_cascades_its_whole_tenant(): void
    {
        $e = $this->escenarioCompleto();
        $institutionId = $this->inst->id;

        DB::table('institutions')->where('id', $institutionId)->delete();

        foreach (['students', 'group_students', 'exam_attempts', 'student_progress', 'exams', 'subjects', 'groups'] as $tabla) {
            $this->assertFalse(
                DB::table($tabla)->where('institution_id', $institutionId)->exists(),
                "Quedaron filas de {$tabla} tras borrar la institución"
            );
        }

        // `users.institution_id` es SET NULL, no CASCADE: dar de baja un centro no
        // borra a las personas. Es deliberado y lo pide también el modelo del TFG.
        $this->assertTrue($this->existe('users', 'id', $e['studentUser']->id));
        $this->assertNull(DB::table('users')->where('id', $e['studentUser']->id)->value('institution_id'));
    }
}
