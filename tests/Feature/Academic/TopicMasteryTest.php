<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use App\Services\Academic\TopicMasteryService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Resources\Chat;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Dominio por tema (decisión D2).
 *
 * Lo que se vigila:
 *
 *  - **Que la normalización agrupe de verdad.** El tema lo teclea cada docente;
 *    si «Fracciones» y «fracciones » contaran por separado, el «por tema» no
 *    serviría para nada. La normalización la hace PostgreSQL en una columna
 *    generada, así que el test la ataca escribiendo por Eloquent y por SQL.
 *  - **Que un tema con dos respuestas no afirme nada.** Bastaría un despiste
 *    para mandarlo al 50 % y colocarlo arriba del todo.
 *  - **Que el docente vea solo lo suyo y sin nombres**, que es lo que [173] le
 *    concede.
 */
class TopicMasteryTest extends TestCase
{
    use ApiAuth;

    public function test_el_mismo_tema_escrito_distinto_cuenta_como_uno_solo(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $student = $this->alumno($institution);
        $exam    = $this->examen($institution);
        $attempt = $this->intento($institution, $exam, $student);

        // Tres formas de escribir el mismo tema, una por pregunta.
        foreach (['Fracciones', 'fracciones ', '  FRACCIONES   '] as $i => $comoLoEscribio) {
            $this->respuesta($institution, $attempt, $exam, $comoLoEscribio, $i === 0, $i + 1);
        }

        $temas = app(TopicMasteryService::class)->porEstudiante($student->id);

        $this->assertCount(1, $temas, 'Las tres grafías deben caer en el mismo tema.');
        $this->assertSame(3, $temas[0]['total']);
        $this->assertSame(1, $temas[0]['correctas']);
        $this->assertSame(33.33, $temas[0]['percentage']);
    }

    /** Los espacios interiores de más también se colapsan. */
    public function test_los_espacios_interiores_no_crean_temas_nuevos(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $student = $this->alumno($institution);
        $exam    = $this->examen($institution);
        $attempt = $this->intento($institution, $exam, $student);

        foreach (['Fracciones equivalentes', 'Fracciones  equivalentes', 'fracciones equivalentes'] as $i => $t) {
            $this->respuesta($institution, $attempt, $exam, $t, false, $i + 1);
        }

        $temas = app(TopicMasteryService::class)->porEstudiante($student->id);

        $this->assertCount(1, $temas);
        $this->assertSame(0.0, $temas[0]['percentage']);
    }

    public function test_un_tema_sin_evidencia_suficiente_no_se_reporta(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $student = $this->alumno($institution);
        $exam    = $this->examen($institution);
        $attempt = $this->intento($institution, $exam, $student);

        // Dos respuestas: por debajo de MINIMO_RESPUESTAS.
        $this->respuesta($institution, $attempt, $exam, 'Decimales', false, 1);
        $this->respuesta($institution, $attempt, $exam, 'Decimales', false, 2);

        $this->assertCount(
            0,
            app(TopicMasteryService::class)->porEstudiante($student->id),
            'Con dos respuestas no se puede afirmar que un tema vaya mal.'
        );
    }

    /** Sin `topic`, el tema no existe: los ítems sin etiquetar se ignoran. */
    public function test_las_preguntas_sin_tema_no_aparecen(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $student = $this->alumno($institution);
        $exam    = $this->examen($institution);
        $attempt = $this->intento($institution, $exam, $student);

        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $attempt, $exam, null, false, $i);
        }

        $this->assertCount(0, app(TopicMasteryService::class)->porEstudiante($student->id));
    }

    public function test_los_temas_salen_del_mas_flojo_al_mas_solido(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $student = $this->alumno($institution);
        $exam    = $this->examen($institution);
        $attempt = $this->intento($institution, $exam, $student);

        $orden = 1;
        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $attempt, $exam, 'Geometría', true, $orden++);
        }
        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $attempt, $exam, 'Fracciones', false, $orden++);
        }

        $temas = app(TopicMasteryService::class)->porEstudiante($student->id);

        $this->assertSame(['Fracciones', 'Geometría'], $temas->pluck('topic')->all());
    }

    public function test_el_docente_solo_ve_los_temas_de_sus_grupos_y_sin_nombres(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);

        $mio   = $this->alumno($institution);
        $ajeno = $this->alumno($institution);

        $this->darAccesoDocenteA($teacher, $mio->id, $institution->id);

        $exam = $this->examen($institution, $teacher);

        $suyo  = $this->intento($institution, $exam, $mio);
        $otro  = $this->intento($institution, $exam, $ajeno);

        $orden = 1;
        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $suyo, $exam, 'Fracciones', false, $orden++);
        }
        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $otro, $exam, 'Sintaxis', false, $orden++);
        }

        $res = $this->getJson('/api/reports/topics');

        $res->assertOk();
        $this->assertSame(['Fracciones'], collect($res->json('data.topics'))->pluck('topic')->all());

        // Agregado: ni un identificador de alumno en la respuesta.
        $cuerpo = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($mio->id, $cuerpo);
        $this->assertStringNotContainsString($ajeno->id, $cuerpo);
    }

    public function test_el_admin_ve_los_temas_de_toda_su_institucion(): void
    {
        $institution = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $institution->id]);

        $a = $this->alumno($institution);
        $b = $this->alumno($institution);

        $exam = $this->examen($institution);

        $orden = 1;
        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $this->intento($institution, $exam, $a), $exam, 'Fracciones', false, $orden++);
        }
        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $this->intento($institution, $exam, $b), $exam, 'Sintaxis', false, $orden++);
        }

        $temas = collect($this->getJson('/api/reports/topics')->assertOk()->json('data.topics'))
            ->pluck('topic')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Fracciones', 'Sintaxis'], $temas);
    }

    /**
     * El diagnóstico del tutor deja de hablar solo de materias. Es lo que separa
     * «Español 45 %» de «te cuesta la comprensión de lectura», que es el ejemplo
     * literal de [263].
     *
     * Se comprueba además que el tema viaja **sin** el nombre del alumno: un
     * tema es contenido curricular, no un dato identificativo, y la regla de
     * `SIN_DATOS_IDENTIFICATIVOS` sigue en pie.
     */
    public function test_el_diagnostico_menciona_los_temas_flojos_y_nunca_el_nombre(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $student = $this->alumno($institution);
        $student->update(['full_name' => 'Mariana Solís Vargas']);

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);
        $exam    = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $subject->id,
        ]);
        $attempt = $this->intento($institution, $exam, $student);

        foreach (range(1, 3) as $i) {
            $this->respuesta($institution, $attempt, $exam, 'Comprensión de lectura', false, $i);
        }

        // El diagnóstico se apoya en el progreso por materia: sin él, corta antes.
        \App\Models\Students\StudentProgress::factory()->create([
            'institution_id'     => $institution->id,
            'student_user_id'    => $student->id,
            'subject_id'         => $subject->id,
            'mastery_percentage' => 45,
        ]);

        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Vamos a reforzar la lectura.']]],
            ]),
        ]);

        app(\App\Services\AI\AiTutorService::class)->getDiagnosis($student->id);

        OpenAI::assertSent(Chat::class, function (string $method, array $parametros): bool {
            $enviado = json_encode($parametros, JSON_UNESCAPED_UNICODE);

            return str_contains($enviado, 'Comprensión de lectura')
                && !str_contains($enviado, 'Mariana');
        });
    }

    public function test_el_estudiante_no_entra_al_reporte_de_temas(): void
    {
        $institution = Institution::factory()->create();
        $student     = $this->alumno($institution);

        $this->actingAs($student, 'sanctum');

        $this->getJson('/api/reports/topics')->assertStatus(403);
    }

    /* =========================
     | Apoyo
     ========================= */

    private function alumno(Institution $institution): User
    {
        $user = User::factory()->student()->create(['institution_id' => $institution->id]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
        ]);

        return $user;
    }

    private function examen(Institution $institution, ?User $teacher = null): Exam
    {
        return Exam::factory()->create([
            'institution_id'        => $institution->id,
            'subject_id'            => Subject::factory()->create(['institution_id' => $institution->id])->id,
            'created_by_teacher_id' => $teacher?->id,
        ]);
    }

    private function intento(Institution $institution, Exam $exam, User $student): ExamAttempt
    {
        return ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
        ]);
    }

    private function respuesta(
        Institution $institution,
        ExamAttempt $attempt,
        Exam $exam,
        ?string $topic,
        bool $correcta,
        int $orden
    ): void {
        $question = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $exam->id,
            'topic'          => $topic,
            'order_index'    => $orden,
        ]);

        StudentAnswer::factory()->create([
            'institution_id' => $institution->id,
            'attempt_id'     => $attempt->id,
            'question_id'    => $question->id,
            'is_correct'     => $correcta,
        ]);
    }
}
