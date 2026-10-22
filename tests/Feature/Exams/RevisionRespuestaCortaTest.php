<?php

namespace Tests\Feature\Exams;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * La revisión manual de una respuesta corta es binaria: correcta = todos los puntos de la
 * pregunta, incorrecta = 0. Los puntos los fija el servidor; los que mande el cliente deben coincidir.
 */
class RevisionRespuestaCortaTest extends TestCase
{
    use ApiAuth;

    private User $docente;
    private StudentAnswer $respuesta;
    private ExamAttempt $intento;

    protected function setUp(): void
    {
        parent::setUp();

        $centro = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $centro->id]);
        $alumno = User::factory()->student()->create(['institution_id' => $centro->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $centro->id]);

        $exam = Exam::factory()->create(['institution_id' => $centro->id, 'created_by_teacher_id' => $this->docente->id, 'status' => 'active']);
        $pregunta = Question::factory()->create([
            'institution_id' => $centro->id, 'exam_id' => $exam->id, 'question_type' => 'short_answer', 'points' => 5, 'correct_answer_text' => 'Madrid',
        ]);
        $this->intento = ExamAttempt::create([
            'institution_id' => $centro->id, 'exam_id' => $exam->id, 'student_user_id' => $alumno->id, 'attempt_number' => 1,
            'started_at' => now()->subMinutes(5), 'submitted_at' => now(), 'score' => 0, 'max_score' => 5, 'grade_status' => 'pending',
        ]);
        $this->respuesta = StudentAnswer::create([
            'institution_id' => $centro->id, 'attempt_id' => $this->intento->id, 'question_id' => $pregunta->id,
            'answer_text' => 'la capital', 'is_correct' => false, 'points_awarded' => 0, 'answered_at' => now(), 'review_status' => 'needs_review',
        ]);
    }

    private function revisar(array $cuerpo)
    {
        $this->actingAs($this->docente, 'sanctum');

        return $this->patchJson("/api/student-answers/{$this->respuesta->id}/review", $cuerpo);
    }

    public function test_correcta_sin_puntos_da_los_de_la_pregunta(): void
    {
        $this->revisar(['is_correct' => true])->assertOk();

        $this->assertEquals(5, $this->respuesta->fresh()->points_awarded);
        $this->assertEquals(5, $this->intento->fresh()->score);
        $this->assertSame('completed', $this->intento->fresh()->grade_status->value);
    }

    public function test_incorrecta_da_cero_con_o_sin_puntos(): void
    {
        $this->revisar(['is_correct' => false])->assertOk();
        $this->assertEquals(0, $this->respuesta->fresh()->points_awarded);

        $this->revisar(['is_correct' => false, 'points_awarded' => 0])->assertOk();
        $this->assertEquals(0, $this->intento->fresh()->score);
    }

    public function test_puntos_a_medias_o_contradictorios_se_rechazan_sin_tocar_nada(): void
    {
        foreach ([['is_correct' => true, 'points_awarded' => 3], ['is_correct' => true, 'points_awarded' => 0], ['is_correct' => false, 'points_awarded' => 5], ['is_correct' => true, 'points_awarded' => 99]] as $cuerpo) {
            $this->revisar($cuerpo)->assertStatus(422);
        }

        $this->assertSame('needs_review', $this->respuesta->fresh()->review_status->value);
        $this->assertEquals(0, $this->intento->fresh()->score);
    }
}
