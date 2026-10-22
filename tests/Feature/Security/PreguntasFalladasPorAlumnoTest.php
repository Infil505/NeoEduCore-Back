<?php

namespace Tests\Feature\Security;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `GET /api/reports/students/{id}/failed-questions`: lo que más falló un estudiante (reporte individual).
 *
 * Es el dato más fino del alumno (qué contestó mal y qué respondió), así que lleva el doble filtro de las
 * estrategias: el docente alcanza al alumno por su grupo asignado y, además, solo ve las materias que imparte.
 */
class PreguntasFalladasPorAlumnoTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;
    private User $docente;
    private User $alumno;
    private Subject $mia;
    private Subject $ajena;

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro  = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->centro->id]);
        $this->alumno  = $this->nuevoAlumno($this->centro);

        $acceso      = $this->darAccesoDocenteA($this->docente, $this->alumno->id, $this->centro->id);
        $this->mia   = $acceso['subject'];
        $this->ajena = Subject::factory()->create(['institution_id' => $this->centro->id]);
    }

    private function nuevoAlumno(Institution $centro): User
    {
        $user = User::factory()->student()->create(['institution_id' => $centro->id]);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $centro->id]);

        return $user;
    }

    private function url(?User $alumno = null, string $query = ''): string
    {
        return '/api/reports/students/' . ($alumno ?? $this->alumno)->id . '/failed-questions' . $query;
    }

    /** Crea examen + intento entregado del alumno; devuelve [exam, attempt]. */
    private function intento(Subject $materia, ?\DateTimeInterface $entregado = null, ?User $alumno = null): array
    {
        $exam = Exam::factory()->create(['institution_id' => $this->centro->id, 'subject_id' => $materia->id]);
        $attempt = $this->intentoDe($exam, $entregado, $alumno);

        return [$exam, $attempt];
    }

    private function intentoDe(Exam $exam, ?\DateTimeInterface $entregado = null, ?User $alumno = null, int $numero = 1): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'institution_id'  => $this->centro->id,
            'exam_id'         => $exam->id,
            'student_user_id' => ($alumno ?? $this->alumno)->id,
            'attempt_number'  => $numero,
            'submitted_at'    => $entregado ?? now(),
            'score'           => 5,
            'max_score'       => 10,
        ]);
    }

    /** Una pregunta del examen y la respuesta del alumno. */
    private function respuesta(Exam $exam, ExamAttempt $attempt, bool $correcta, float $puntos = 2, float $obtenidos = 0, ?string $tema = 'Fracciones', string $revision = 'auto_graded', ?string $texto = null): Question
    {
        $pregunta = Question::factory()->create([
            'institution_id' => $this->centro->id,
            'exam_id'        => $exam->id,
            'points'         => $puntos,
            'topic'          => $tema,
            'question_text'  => $texto ?? 'Pregunta ' . Str::random(6),
        ]);

        DB::table('student_answers')->insert([
            'id'             => (string) Str::uuid(),
            'institution_id' => $this->centro->id,
            'attempt_id'     => $attempt->id,
            'question_id'    => $pregunta->id,
            'answer_text'    => 'lo que contestó',
            'is_correct'     => $correcta,
            'points_awarded' => $correcta ? $puntos : $obtenidos,
            'review_status'  => $revision,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return $pregunta;
    }

    /* ---------------- lo que devuelve ---------------- */

    public function test_devuelve_lo_fallado_ordenado_por_puntos_perdidos_y_los_temas(): void
    {
        [$exam, $attempt] = $this->intento($this->mia);
        $pequena = $this->respuesta($exam, $attempt, false, puntos: 2, obtenidos: 1, tema: 'Fracciones');
        $grande  = $this->respuesta($exam, $attempt, false, puntos: 5, obtenidos: 0, tema: 'Fracciones');
        $this->respuesta($exam, $attempt, true, puntos: 5, tema: 'Geometría');

        $data = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame([$grande->id, $pequena->id], array_column($data['questions'], 'question_id'));
        $this->assertEquals(5.0, $data['questions'][0]['points_lost']);
        $this->assertEquals(1.0, $data['questions'][1]['points_lost']);
        $this->assertSame('lo que contestó', $data['questions'][0]['answer']);
        $this->assertSame($this->alumno->id, $data['student']['user_id']);

        $topics = collect($data['topics'])->keyBy('topic');
        $this->assertSame(2, $topics['Fracciones']['wrong']);
        $this->assertSame(100.0, (float) $topics['Fracciones']['error_rate']);
        $this->assertArrayNotHasKey('Geometría', $topics->all(), 'Un tema sin fallos no es un tema flojo.');
    }

    public function test_lo_pendiente_de_revision_no_cuenta_como_fallo(): void
    {
        [$exam, $attempt] = $this->intento($this->mia);
        $this->respuesta($exam, $attempt, false, revision: 'needs_review');

        $this->getJson($this->url())->assertOk()->assertJsonPath('data.questions', []);
    }

    public function test_solo_cuenta_el_intento_mas_reciente_de_cada_examen(): void
    {
        $exam    = Exam::factory()->create(['institution_id' => $this->centro->id, 'subject_id' => $this->mia->id]);
        $viejo   = $this->intentoDe($exam, now()->subDays(3), numero: 1);
        $reciente = $this->intentoDe($exam, now()->subDay(), numero: 2);
        $this->respuesta($exam, $viejo, false, texto: 'solo en el intento viejo');
        $actual = $this->respuesta($exam, $reciente, false, texto: 'en el intento reciente');

        $ids = array_column($this->getJson($this->url())->assertOk()->json('data.questions'), 'question_id');

        $this->assertSame([$actual->id], $ids);
    }

    public function test_el_tope_se_respeta_y_se_valida(): void
    {
        [$exam, $attempt] = $this->intento($this->mia);
        foreach (range(1, 3) as $_) {
            $this->respuesta($exam, $attempt, false);
        }

        $this->assertCount(2, $this->getJson($this->url(query: '?limit=2'))->assertOk()->json('data.questions'));
        $this->getJson($this->url(query: '?limit=0'))->assertStatus(422);
        $this->getJson($this->url(query: '?limit=999'))->assertStatus(422);
        $this->getJson($this->url(query: '?limit=abc'))->assertStatus(422);
    }

    /* ---------------- alcance ---------------- */

    public function test_el_docente_no_ve_las_materias_que_no_imparte(): void
    {
        [$examMio, $intentoMio] = $this->intento($this->mia);
        [$examAjeno, $intentoAjeno] = $this->intento($this->ajena);
        $mia   = $this->respuesta($examMio, $intentoMio, false, tema: 'Mi tema');
        $this->respuesta($examAjeno, $intentoAjeno, false, tema: 'Tema ajeno');

        $data = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame([$mia->id], array_column($data['questions'], 'question_id'));
        $this->assertSame(['Mi tema'], array_column($data['topics'], 'topic'));
    }

    public function test_el_docente_sin_asignacion_a_ese_alumno_recibe_403(): void
    {
        $otro = $this->nuevoAlumno($this->centro);
        [$exam, $attempt] = $this->intento($this->mia, alumno: $otro);
        $this->respuesta($exam, $attempt, false);

        $this->getJson($this->url($otro))->assertForbidden();
    }

    public function test_el_admin_ve_todas_las_materias_de_su_centro(): void
    {
        [$examMio, $intentoMio] = $this->intento($this->mia);
        [$examAjeno, $intentoAjeno] = $this->intento($this->ajena);
        $this->respuesta($examMio, $intentoMio, false);
        $this->respuesta($examAjeno, $intentoAjeno, false);

        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->assertCount(2, $this->getJson($this->url())->assertOk()->json('data.questions'));
    }

    public function test_un_admin_de_otro_centro_no_alcanza_al_alumno(): void
    {
        [$exam, $attempt] = $this->intento($this->mia);
        $this->respuesta($exam, $attempt, false);

        $otroCentro = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $otroCentro->id]);

        $this->getJson($this->url())->assertNotFound();
    }

    public function test_el_estudiante_no_puede_consultarlo_ni_siquiera_el_suyo(): void
    {
        $this->signInStudent(['institution_id' => $this->centro->id]);

        $this->getJson($this->url())->assertForbidden();
    }

    public function test_sin_sesion_es_401(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson($this->url())->assertUnauthorized();
    }

    public function test_un_id_que_no_es_uuid_no_llega_a_la_consulta(): void
    {
        $this->getJson('/api/reports/students/no-es-un-uuid/failed-questions')->assertNotFound();
        $this->getJson("/api/reports/students/{$this->alumno->id}'%20OR%201=1--/failed-questions")->assertNotFound();
    }
}
