<?php

namespace Tests\Feature\Exams;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Autoguardado del borrador y entrega automática al agotarse el tiempo.
 *
 * Lo que decide la seguridad y la justicia de la nota: el borrador es del alumno y de nadie más,
 * no se acepta pasado el plazo, y el servidor solo entrega lo vencido (plazo + espera) con lo que
 * el alumno tenía guardado, sin inventar ni conservar nada inválido.
 */
class EntregaAutomaticaDeIntentosTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;
    private User $docente;
    private User $alumno;
    private Exam $exam;
    private Question $pregunta;
    private Question $abierta;
    private QuestionOption $correcta;
    private QuestionOption $incorrecta;

    protected function setUp(): void
    {
        parent::setUp();

        config(['academic.exam.grace_seconds' => 30, 'academic.exam.auto_submit_delay_seconds' => 120]);

        $this->institution = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->institution->id]);
        $materia = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $aula = Group::factory()->create(['institution_id' => $this->institution->id]);
        $this->asignarDocente($this->docente, $aula->id, $materia->id);

        $this->alumno = User::factory()->student()->create(['institution_id' => $this->institution->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $this->alumno->id, 'institution_id' => $this->institution->id, 'adecuacion_type' => null]);
        $this->matricularEnGrupo($this->alumno->id, $aula->id, $this->institution->id);

        $this->exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $materia->id,
            'status'                => 'active',
            'duration_minutes'      => 30,
            'max_attempts'          => 1,
            'available_from'        => null,
            'available_until'       => null,
        ]);
        $this->exam->syncGroups([$aula->id]);

        $this->pregunta = Question::factory()->create([
            'institution_id' => $this->institution->id, 'exam_id' => $this->exam->id,
            'question_type' => 'multiple_choice', 'points' => 5, 'order_index' => 1,
        ]);
        $this->correcta = QuestionOption::create([
            'institution_id' => $this->institution->id, 'question_id' => $this->pregunta->id, 'option_index' => 0, 'option_text' => 'Sí', 'is_correct' => true,
        ]);
        $this->incorrecta = QuestionOption::create([
            'institution_id' => $this->institution->id, 'question_id' => $this->pregunta->id, 'option_index' => 1, 'option_text' => 'No', 'is_correct' => false,
        ]);
        $this->abierta = Question::factory()->create([
            'institution_id' => $this->institution->id, 'exam_id' => $this->exam->id,
            'question_type' => 'short_answer', 'points' => 5, 'order_index' => 2, 'correct_answer_text' => 'Madrid',
        ]);
    }

    /** Intento abierto que empezó hace `$minutos` minutos. */
    private function intento(int $minutos): ExamAttempt
    {
        return ExamAttempt::create([
            'institution_id'  => $this->institution->id,
            'exam_id'         => $this->exam->id,
            'student_user_id' => $this->alumno->id,
            'attempt_number'  => 1,
            'started_at'      => now()->subMinutes($minutos),
            'score'           => 0,
            'max_score'       => 10,
            'grade_status'    => 'pending',
        ]);
    }

    private function borrador(ExamAttempt $intento, array $answers)
    {
        $this->actingAs($this->alumno, 'sanctum');

        return $this->putJson("/api/exams/{$this->exam->id}/attempts/{$intento->id}/draft", ['answers' => $answers]);
    }

    public function test_el_alumno_guarda_y_recupera_su_borrador(): void
    {
        $intento = $this->intento(5);
        $answers = [['question_id' => $this->pregunta->id, 'selected_option_ids' => [$this->correcta->id]]];

        $this->borrador($intento, $answers)->assertOk()->assertJsonPath('data.answers', 1);

        $this->getJson("/api/exams/{$this->exam->id}/attempts/{$intento->id}/draft")
            ->assertOk()
            ->assertJsonPath('data.answers.0.question_id', $this->pregunta->id);

        // El borrador no viaja en el JSON normal del intento.
        $this->assertArrayNotHasKey('draft_answers', $intento->fresh()->toArray());
    }

    public function test_el_intento_devuelve_su_plazo_sin_la_gracia_y_la_hora_del_servidor(): void
    {
        $this->actingAs($this->alumno, 'sanctum');
        $res = $this->postJson("/api/exams/{$this->exam->id}/attempts/start")->assertCreated();

        // 30 min de examen; la gracia de red (30 s) no se cuenta en lo que ve el alumno.
        $plazo = \Illuminate\Support\Carbon::parse($res->json('data.deadline_at'));
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($plazo, false), 5);
        $this->assertNotNull($res->json('data.server_time'));

        $id = $res->json('data.id');
        $this->getJson("/api/exams/{$this->exam->id}/attempts/{$id}/draft")
            ->assertOk()
            ->assertJsonPath('data.deadline_at', $res->json('data.deadline_at'));

        // Con adecuación de acceso (x1,25, redondeada a minutos: 38) el plazo se alarga: lo calcula el servidor, no el cliente.
        Student::where('user_id', $this->alumno->id)->update(['adecuacion_type' => 'acceso']);
        $this->getJson("/api/exams/{$this->exam->id}/attempts/{$id}/draft")
            ->assertJsonPath('data.deadline_at', now()->parse($res->json('data.deadline_at'))->addSeconds(480)->toISOString());
    }

    public function test_el_borrador_es_solo_del_dueno_y_no_se_acepta_entregado_ni_vencido(): void
    {
        $intento = $this->intento(5);

        $otro = User::factory()->student()->create(['institution_id' => $this->institution->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $otro->id, 'institution_id' => $this->institution->id]);
        $this->actingAs($otro, 'sanctum');
        $this->putJson("/api/exams/{$this->exam->id}/attempts/{$intento->id}/draft", ['answers' => []])->assertNotFound();
        $this->getJson("/api/exams/{$this->exam->id}/attempts/{$intento->id}/draft")->assertNotFound();

        $this->actingAs($this->docente, 'sanctum');
        $this->putJson("/api/exams/{$this->exam->id}/attempts/{$intento->id}/draft", ['answers' => []])->assertForbidden();

        // Pasado el plazo (duración + gracia) ya no se guarda.
        $vencido = $this->intento(40);
        $vencido->update(['attempt_number' => 2]);
        $this->borrador($vencido, [])->assertStatus(409);

        // Entregado: tampoco.
        $intento->update(['submitted_at' => now()]);
        $this->borrador($intento, [])->assertStatus(409);
    }

    public function test_entrega_el_borrador_pasado_el_plazo_mas_la_espera(): void
    {
        $intento = $this->intento(5);
        $this->borrador($intento, [
            ['question_id' => $this->pregunta->id, 'selected_option_ids' => [$this->correcta->id]],
            ['question_id' => $this->abierta->id, 'answer_text' => 'Madrid'],
        ])->assertOk();

        // Plazo = 30 min + 30 s de gracia; con 2 min de espera → antes de 32,5 min no se toca.
        $intento->update(['started_at' => now()->subMinutes(32)]);
        $this->artisan('exams:cerrar-intentos-vencidos')->assertSuccessful();
        $this->assertNull($intento->fresh()->submitted_at);

        $intento->update(['started_at' => now()->subMinutes(34)]);
        $this->artisan('exams:cerrar-intentos-vencidos')->assertSuccessful();

        $hecho = $intento->fresh()->makeVisible('draft_answers');
        $this->assertNotNull($hecho->submitted_at);
        $this->assertEquals(10.0, (float) $hecho->score);
        $this->assertNull($hecho->draft_answers);
        $this->assertSame(2, DB::table('student_answers')->where('attempt_id', $intento->id)->count());

        // Segunda pasada: nada que hacer, no duplica respuestas.
        $this->artisan('exams:cerrar-intentos-vencidos')->assertSuccessful();
        $this->assertSame(2, DB::table('student_answers')->where('attempt_id', $intento->id)->count());
    }

    public function test_sin_borrador_se_entrega_en_blanco_y_lo_invalido_se_descarta(): void
    {
        $sinNada = $this->intento(40);
        $this->artisan('exams:cerrar-intentos-vencidos')->assertSuccessful();

        $this->assertNotNull($sinNada->fresh()->submitted_at);
        $this->assertEquals(0.0, (float) $sinNada->fresh()->score);
        $this->assertSame(2, DB::table('student_answers')->where('attempt_id', $sinNada->id)->count());

        // Borrador con una opción que no es de la pregunta y una pregunta ajena: se descarta, no rompe.
        $otraMateria = Question::factory()->create(['institution_id' => $this->institution->id, 'exam_id' => Exam::factory()->create(['institution_id' => $this->institution->id])->id]);
        $raro = ExamAttempt::create([
            'institution_id' => $this->institution->id, 'exam_id' => $this->exam->id, 'student_user_id' => $this->alumno->id,
            'attempt_number' => 2, 'started_at' => now()->subMinutes(40), 'score' => 0, 'max_score' => 10, 'grade_status' => 'pending',
            'draft_answers' => [
                ['question_id' => $this->pregunta->id, 'selected_option_ids' => [999999999]],
                ['question_id' => $otraMateria->id, 'answer_text' => 'x'],
            ],
        ]);
        $this->artisan('exams:cerrar-intentos-vencidos')->assertSuccessful();

        $this->assertNotNull($raro->fresh()->submitted_at);
        $this->assertEquals(0.0, (float) $raro->fresh()->score);
        $this->assertSame(0, DB::table('student_answer_options')
            ->whereIn('student_answer_id', DB::table('student_answers')->where('attempt_id', $raro->id)->pluck('id'))->count());
    }

    public function test_no_toca_intentos_ya_entregados_ni_examenes_sin_duracion(): void
    {
        $entregado = $this->intento(60);
        $entregado->update(['submitted_at' => now()->subMinutes(30), 'score' => 7]);

        $this->exam->update(['duration_minutes' => null]);
        $sinLimite = ExamAttempt::create([
            'institution_id' => $this->institution->id, 'exam_id' => $this->exam->id, 'student_user_id' => $this->alumno->id,
            'attempt_number' => 2, 'started_at' => now()->subDay(), 'score' => 0, 'max_score' => 10, 'grade_status' => 'pending',
        ]);

        $this->artisan('exams:cerrar-intentos-vencidos')->assertSuccessful();

        $this->assertEquals(7.0, (float) $entregado->fresh()->score);
        $this->assertNull($sinLimite->fresh()->submitted_at);
    }
}
