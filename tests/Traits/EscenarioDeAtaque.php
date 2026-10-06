<?php

namespace Tests\Traits;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use Laravel\Sanctum\Sanctum;

/**
 * Escenario para los tests de ataque (`tests/Feature/Security/Ataques*`).
 *
 * Un centro con dos aulas, un examen activo enviado solo al aula A, y los
 * personajes de siempre en un ataque: el docente dueño del examen, un colega
 * con el MISMO grupo y materia, un administrador, y un alumno en cada aula.
 *
 * Todo lo que hacen estos tests es **intentar lo que no se debe**; un test
 * en verde significa que el sistema se defendió. Uno en rojo es un hallazgo.
 */
trait EscenarioDeAtaque
{
    protected Institution $centro;
    protected User $admin;
    protected User $docente;
    protected User $colega;       // mismo grupo y materia que $docente, examen distinto
    protected User $alumnoA;      // aula A: el examen es suyo
    protected User $alumnoB;      // aula B: el examen NO es suyo
    protected Group $aulaA;
    protected Group $aulaB;
    protected Subject $materia;
    protected Exam $examen;
    protected Question $preguntaMc;
    protected Question $preguntaCorta;
    protected int $opcionCorrecta;
    protected int $opcionIncorrecta;

    protected function montarEscenario(): void
    {
        $this->centro = Institution::factory()->create();

        $this->admin   = User::factory()->admin()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        $this->docente = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        $this->colega  = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);

        $this->aulaA   = Group::factory()->create(['institution_id' => $this->centro->id]);
        $this->aulaB   = Group::factory()->create(['institution_id' => $this->centro->id]);
        $this->materia = Subject::factory()->create(['institution_id' => $this->centro->id]);

        foreach ([$this->docente, $this->colega] as $d) {
            $this->asignarDocente($d, $this->aulaA->id, $this->materia->id);
        }

        $this->alumnoA = $this->nuevoAlumno($this->aulaA);
        $this->alumnoB = $this->nuevoAlumno($this->aulaB);

        $this->examen = Exam::factory()->create([
            'institution_id'        => $this->centro->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $this->materia->id,
            'status'                => 'active',
            'max_attempts'          => 1,
            'duration_minutes'      => 60,
            'allow_review_after_submission' => false,
        ]);
        $this->examen->syncGroups([$this->aulaA->id]);

        [$this->preguntaMc, $this->opcionCorrecta, $this->opcionIncorrecta] = $this->preguntaMultiple($this->examen);

        $this->preguntaCorta = Question::factory()->shortAnswer()->create([
            'institution_id'      => $this->centro->id,
            'exam_id'             => $this->examen->id,
            'correct_answer_text' => 'respuesta-secreta',
            'points'              => 3,
            'order_index'         => 2,
        ]);
    }

    protected function nuevoAlumno(Group $aula): User
    {
        $user = User::factory()->student()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $this->centro->id]);
        $this->matricularEnGrupo($user->id, $aula->id, $this->centro->id);

        return $user;
    }

    /** @return array{0:Question,1:int,2:int}  [pregunta, id opción correcta, id opción incorrecta] */
    protected function preguntaMultiple(Exam $exam, int $puntos = 2): array
    {
        $pregunta = Question::factory()->create([
            'institution_id' => $this->centro->id,
            'exam_id'        => $exam->id,
            'question_type'  => 'multiple_choice',
            'points'         => $puntos,
        ]);

        $correcta = QuestionOption::create([
            'institution_id' => $this->centro->id, 'question_id' => $pregunta->id,
            'option_index' => 1, 'option_text' => 'Correcta', 'is_correct' => true,
        ]);
        $incorrecta = null;
        foreach ([2, 3, 4] as $i) {
            $o = QuestionOption::create([
                'institution_id' => $this->centro->id, 'question_id' => $pregunta->id,
                'option_index' => $i, 'option_text' => "Distractor {$i}", 'is_correct' => false,
            ]);
            $incorrecta ??= $o;
        }

        return [$pregunta, $correcta->id, $incorrecta->id];
    }

    protected function intentoAbierto(User $alumno, ?Exam $exam = null): ExamAttempt
    {
        $exam ??= $this->examen;

        return ExamAttempt::create([
            'institution_id'  => $this->centro->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $alumno->id,
            'attempt_number'  => 1,
            'started_at'      => now(),
            'submitted_at'    => null,
            'score'           => 0,
            'max_score'       => (float) $exam->questions()->sum('points'),
            'grade_status'    => 'pending',
        ]);
    }

    protected function intentoEntregado(User $alumno, ?Exam $exam = null): ExamAttempt
    {
        $intento = $this->intentoAbierto($alumno, $exam);
        $intento->update(['submitted_at' => now(), 'grade_status' => 'completed']);

        return $intento->fresh();
    }

    protected function actuarComo(User $usuario): void
    {
        Sanctum::actingAs($usuario);
    }

    /** Respuesta mínima válida para el examen del escenario. */
    protected function respuestasValidas(?int $opcion = null): array
    {
        return [
            ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$opcion ?? $this->opcionIncorrecta]],
            ['question_id' => $this->preguntaCorta->id, 'answer_text' => 'cualquier cosa'],
        ];
    }
}
