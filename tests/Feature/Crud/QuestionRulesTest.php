<?php

namespace Tests\Feature\Crud;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Reglas de negocio de las preguntas por tipo, al crear y al editar, y quién
 * puede tocarlas.
 *
 * `QuestionsCrudTest` cubre el camino feliz. Aquí van los rechazos: cada tipo
 * tiene una forma fija (4 opciones, 2, texto o nada) y exactamente una opción
 * correcta, y un docente solo toca las preguntas de sus propios exámenes.
 */
class QuestionRulesTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;
    private User $docente;
    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->institution->id]);
        $this->exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'status'                => 'draft',
        ]);
    }

    private function opciones(int $n, int $correctas = 1): array
    {
        return array_map(fn ($i) => [
            'option_index' => $i,
            'option_text'  => "Opción {$i}",
            'is_correct'   => $i < $correctas,
        ], range(0, $n - 1));
    }

    private function crear(array $cuerpo)
    {
        return $this->postJson("/api/exams/{$this->exam->id}/questions", $cuerpo + [
            'question_text' => 'Enunciado de prueba',
            'points'        => 1,
        ]);
    }

    private function pregunta(string $tipo, bool $conOpciones = true): Question
    {
        $q = Question::factory()->create([
            'institution_id'      => $this->institution->id,
            'exam_id'             => $this->exam->id,
            'question_type'       => $tipo,
            'correct_answer_text' => $tipo === 'short_answer' ? 'París' : null,
        ]);

        if ($conOpciones) {
            foreach ($this->opciones($tipo === 'multiple_choice' ? 4 : 2) as $o) {
                QuestionOption::create($o + ['institution_id' => $this->institution->id, 'question_id' => $q->id]);
            }
        }

        return $q;
    }

    /* =========================
     |  Crear: forma por tipo
     ========================= */

    public function test_each_type_rejects_the_wrong_shape(): void
    {
        $casos = [
            'short_answer sin respuesta'  => ['question_type' => 'short_answer'],
            'short_answer con opciones'   => ['question_type' => 'short_answer', 'correct_answer_text' => 'X', 'options' => $this->opciones(2)],
            'multiple_choice con 3'       => ['question_type' => 'multiple_choice', 'options' => $this->opciones(3)],
            'multiple_choice sin opciones'=> ['question_type' => 'multiple_choice'],
            'true_false con 4'            => ['question_type' => 'true_false', 'options' => $this->opciones(4)],
            'essay con opciones'          => ['question_type' => 'essay', 'options' => $this->opciones(2)],
            'dos correctas'               => ['question_type' => 'multiple_choice', 'options' => $this->opciones(4, 2)],
            'ninguna correcta'            => ['question_type' => 'true_false', 'options' => $this->opciones(2, 0)],
        ];

        foreach ($casos as $caso => $cuerpo) {
            $this->crear($cuerpo)->assertStatus(422, $caso);
        }

        $this->assertSame(0, $this->exam->questions()->count(), 'Ningún caso rechazado debe dejar la pregunta creada');
    }

    public function test_each_type_accepts_its_valid_shape_and_appends_at_the_end(): void
    {
        $this->crear(['question_type' => 'true_false', 'options' => $this->opciones(2)])->assertCreated();
        $this->crear(['question_type' => 'short_answer', 'correct_answer_text' => 'Lima'])->assertCreated();
        $essay = $this->crear(['question_type' => 'essay'])->assertCreated();

        // Sin order_index, va detrás de la última.
        $this->assertSame(3, $essay->json('data.order_index'));
    }

    public function test_a_teacher_cannot_add_questions_to_someone_elses_exam(): void
    {
        Sanctum::actingAs(User::factory()->teacher()->create(['institution_id' => $this->institution->id]));

        $this->crear(['question_type' => 'essay'])->assertForbidden();
    }

    /* =========================
     |  Editar
     ========================= */

    public function test_update_enforces_the_same_rules(): void
    {
        $corta = $this->pregunta('short_answer', false);
        $this->putJson("/api/questions/{$corta->id}", ['correct_answer_text' => ''])->assertStatus(422);
        $this->putJson("/api/questions/{$corta->id}", ['options' => $this->opciones(2)])->assertStatus(422);

        $mc = $this->pregunta('multiple_choice');
        $this->putJson("/api/questions/{$mc->id}", ['options' => $this->opciones(2)])->assertStatus(422);
        $this->putJson("/api/questions/{$mc->id}", ['options' => $this->opciones(4, 2)])->assertStatus(422);
    }

    public function test_update_replaces_the_options_of_a_true_false_question(): void
    {
        $tf = $this->pregunta('true_false');

        $nuevas = [
            ['option_index' => 0, 'option_text' => 'Verdadero', 'is_correct' => false],
            ['option_index' => 1, 'option_text' => 'Falso', 'is_correct' => true],
        ];

        $this->putJson("/api/questions/{$tf->id}", ['options' => $nuevas, 'topic' => 'Fracciones'])->assertOk();

        $this->assertSame(['Verdadero', 'Falso'], $tf->options()->orderBy('option_index')->pluck('option_text')->all());
        $this->assertSame('Falso', $tf->options()->where('is_correct', true)->value('option_text'));
        $this->assertSame('Fracciones', $tf->fresh()->topic);
    }

    public function test_a_teacher_cannot_edit_or_delete_someone_elses_question(): void
    {
        $q = $this->pregunta('essay', false);
        $this->pregunta('essay', false); // que no sea la última

        Sanctum::actingAs(User::factory()->teacher()->create(['institution_id' => $this->institution->id]));

        $this->putJson("/api/questions/{$q->id}", ['question_text' => 'Cambiado'])->assertForbidden();
        $this->deleteJson("/api/questions/{$q->id}")->assertForbidden();

        $this->assertDatabaseHas('questions', ['id' => $q->id]);
    }

    /* =========================
     |  Borrar
     ========================= */

    public function test_the_last_question_of_an_exam_cannot_be_deleted(): void
    {
        $unica = $this->pregunta('essay', false);

        $this->deleteJson("/api/questions/{$unica->id}")->assertStatus(409);

        $otra = $this->pregunta('essay', false);
        $this->deleteJson("/api/questions/{$otra->id}")->assertNoContent();
        $this->assertDatabaseMissing('questions', ['id' => $otra->id]);
    }

    /* =========================
     |  Listar
     ========================= */

    public function test_a_randomized_exam_still_returns_all_its_questions(): void
    {
        $this->exam->update(['randomize_questions' => true]);
        foreach (range(1, 5) as $i) {
            $this->pregunta('essay', false);
        }

        $ids = collect($this->getJson("/api/exams/{$this->exam->id}/questions")->assertOk()->json('data'))->pluck('id');

        $this->assertCount(5, $ids);
        $this->assertCount(5, $ids->unique());
    }
}
