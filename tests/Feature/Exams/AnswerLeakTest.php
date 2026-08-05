<?php

namespace Tests\Feature\Exams;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Un estudiante NO debe conocer la respuesta correcta antes de entregar,
 * por ninguna vía.
 *
 * Se detectó (03/08/2026) que `is_correct` y `correct_answer_text` viajaban en
 * la respuesta de tres endpoints accesibles por el alumno. La protección está
 * ahora en los modelos (`$hidden`), así que estos tests cubren las tres rutas
 * conocidas y, sobre todo, fijan la regla para las que vengan.
 */
class AnswerLeakTest extends TestCase
{
    use ApiAuth;

    private Institution $inst;
    private Exam $exam;
    private Question $pregunta;
    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inst = Institution::factory()->create();
        $teacher = User::factory()->teacher()->create(['institution_id' => $this->inst->id]);
        $subject = Subject::factory()->create(['institution_id' => $this->inst->id]);
        $group   = Group::factory()->create(['institution_id' => $this->inst->id]);

        $this->exam = Exam::factory()->create([
            'institution_id' => $this->inst->id,
            'subject_id' => $subject->id,
            'created_by_teacher_id' => $teacher->id,
            'status' => 'active',
            'max_attempts' => 5,
            'duration_minutes' => 120,
            'available_from' => now()->subDay(),
            'available_until' => now()->addDay(),
            'allow_review_after_submission' => false,
        ]);

        $this->pregunta = Question::factory()->create([
            'institution_id' => $this->inst->id,
            'exam_id' => $this->exam->id,
            'question_type' => 'short_answer',
            'correct_answer_text' => 'PARIS_ES_LA_RESPUESTA',
        ]);
        QuestionOption::create([
            'institution_id' => $this->inst->id, 'question_id' => $this->pregunta->id,
            'option_index' => 0, 'option_text' => 'Correcta', 'is_correct' => true,
        ]);
        QuestionOption::create([
            'institution_id' => $this->inst->id, 'question_id' => $this->pregunta->id,
            'option_index' => 1, 'option_text' => 'Incorrecta', 'is_correct' => false,
        ]);

        $this->studentUser = User::factory()->student()->create(['institution_id' => $this->inst->id]);
        Student::factory()->create([
            'user_id' => $this->studentUser->id, 'institution_id' => $this->inst->id,
        ]);
        DB::table('group_students')->insert([
            'institution_id' => $this->inst->id, 'group_id' => $group->id,
            'student_user_id' => $this->studentUser->id, 'joined_at' => now(), 'left_at' => null,
        ]);
        DB::table('exam_targets')->insert([
            'institution_id' => $this->inst->id,
            'exam_id' => $this->exam->id, 'group_id' => $group->id,
        ]);
    }

    /** Busca las cadenas delatoras en el JSON crudo, mire donde mire. */
    private function assertSinRespuestas(string $json, string $donde): void
    {
        $this->assertStringNotContainsString('is_correct', $json, "{$donde} filtra `is_correct`");
        $this->assertStringNotContainsString('correct_answer_text', $json, "{$donde} filtra `correct_answer_text`");
        $this->assertStringNotContainsString('PARIS_ES_LA_RESPUESTA', $json, "{$donde} filtra el texto de la respuesta");
    }

    /* =========================
     |  Las tres vías conocidas
     ========================= */

    public function test_questions_endpoint_hides_answers_from_students(): void
    {
        $this->actingAs($this->studentUser, 'sanctum');

        $res = $this->getJson("/api/exams/{$this->exam->id}/questions");
        $res->assertOk();

        $this->assertSinRespuestas($res->getContent(), 'GET /exams/{id}/questions');
    }

    public function test_exam_show_hides_answers_from_students(): void
    {
        $this->actingAs($this->studentUser, 'sanctum');

        $res = $this->getJson("/api/exams/{$this->exam->id}");
        $res->assertOk();

        $this->assertSinRespuestas($res->getContent(), 'GET /exams/{id}');
    }

    public function test_attempt_view_hides_answers_before_submitting(): void
    {
        $this->actingAs($this->studentUser, 'sanctum');

        $attemptId = $this->postJson("/api/exams/{$this->exam->id}/attempts/start")
            ->assertCreated()->json('data.id');

        // Intento EN CURSO: la vía más peligrosa, el alumno ya está examinándose
        $res = $this->getJson("/api/exams/{$this->exam->id}/attempts/{$attemptId}");
        $res->assertOk();

        $this->assertSinRespuestas($res->getContent(), 'GET /exams/{id}/attempts/{id} en curso');
    }

    /* =========================
     |  Entre intentos
     ========================= */

    public function test_review_is_hidden_after_submitting_when_disabled(): void
    {
        $this->actingAs($this->studentUser, 'sanctum');

        $attemptId = $this->postJson("/api/exams/{$this->exam->id}/attempts/start")
            ->assertCreated()->json('data.id');

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [[
                'question_id' => $this->pregunta->id,
                'answer_text' => 'una respuesta cualquiera',
            ]],
        ])->assertOk();

        $res = $this->getJson("/api/exams/{$this->exam->id}/attempts/{$attemptId}");
        $res->assertOk();

        // allow_review_after_submission = false: con max_attempts=5, revelar la
        // corrección aquí sería filtrar las respuestas para el intento siguiente
        $this->assertFalse($res->json('meta.review_shown'));
        $this->assertSinRespuestas($res->getContent(), 'revisión con la opción desactivada');
        $this->assertStringNotContainsString('correct_answer_snapshot', $res->getContent());
    }

    public function test_review_is_shown_after_submitting_when_enabled(): void
    {
        $this->exam->update(['allow_review_after_submission' => true]);
        $this->actingAs($this->studentUser, 'sanctum');

        $attemptId = $this->postJson("/api/exams/{$this->exam->id}/attempts/start")
            ->assertCreated()->json('data.id');

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [[
                'question_id' => $this->pregunta->id,
                'answer_text' => 'una respuesta cualquiera',
            ]],
        ])->assertOk();

        $res = $this->getJson("/api/exams/{$this->exam->id}/attempts/{$attemptId}");
        $res->assertOk();

        // Con la revisión habilitada el alumno sí ve la corrección de SUS
        // respuestas, que es el propósito de la opción
        $this->assertTrue($res->json('meta.review_shown'));
        $this->assertStringContainsString('correct_answer_snapshot', $res->getContent());
    }

    /* =========================
     |  Quien sí debe verlas
     ========================= */

    public function test_teacher_still_sees_the_answers(): void
    {
        $this->signInTeacher(['institution_id' => $this->inst->id]);

        $res = $this->getJson("/api/exams/{$this->exam->id}/questions");
        $res->assertOk();

        $this->assertStringContainsString('is_correct', $res->getContent());
        $this->assertStringContainsString('PARIS_ES_LA_RESPUESTA', $res->getContent());
    }

    public function test_admin_still_sees_the_answers_on_exam_show(): void
    {
        $this->signInAdmin(['institution_id' => $this->inst->id]);

        $res = $this->getJson("/api/exams/{$this->exam->id}");
        $res->assertOk();

        $this->assertStringContainsString('is_correct', $res->getContent());
        $this->assertStringContainsString('PARIS_ES_LA_RESPUESTA', $res->getContent());
    }

    public function test_grading_still_works_with_hidden_fields(): void
    {
        // `$hidden` solo afecta a la serialización: la corrección lee los
        // atributos directamente y debe seguir puntuando bien.
        $this->actingAs($this->studentUser, 'sanctum');

        $attemptId = $this->postJson("/api/exams/{$this->exam->id}/attempts/start")
            ->assertCreated()->json('data.id');

        $this->postJson("/api/exams/{$this->exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [[
                'question_id' => $this->pregunta->id,
                'answer_text' => 'PARIS_ES_LA_RESPUESTA',
            ]],
        ])->assertOk();

        // La respuesta era la correcta: debe haberse calificado como tal
        $this->assertTrue((bool) DB::table('student_answers')
            ->where('attempt_id', $attemptId)->value('is_correct'));
    }
}
