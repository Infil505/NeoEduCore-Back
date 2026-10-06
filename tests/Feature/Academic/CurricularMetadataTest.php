<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Services\AI\AiRecommendationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Metadatos curriculares del ítem y materia del recurso (decisión D2).
 *
 * Dos cosas distintas conviven aquí porque son la misma decisión:
 *
 *  - **`questions` gana tema, indicador y dificultad**, que es lo que el banco
 *    de 60 ítems (E2) necesita para existir.
 *  - **`study_resources` gana `subject_id`**, y con él `recursoSugerido()` deja
 *    de proponerle a quien reprueba Ciencias una guía de Español. Es el ejemplo
 *    de [263] y lo que la Figura 10 llama «recursos personalizados».
 */
class CurricularMetadataTest extends TestCase
{
    /** Aula donde `alumno()` matricula al estudiante: los recursos sugeridos tienen que estar enviados a ella. */
    private ?Group $aula = null;

    use ApiAuth;

    public function test_se_puede_crear_una_pregunta_con_tema_indicador_y_dificultad(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam        = $this->examen($institution, $teacher);

        $res = $this->postJson("/api/exams/{$exam->id}/questions", [
            'question_text' => '¿Cuánto es 1/2 + 1/4?',
            'question_type' => 'multiple_choice',
            'points'        => 2,
            'topic'         => 'Fracciones equivalentes',
            'indicator'     => 'MAT.3.2 Resuelve sumas de fracciones con distinto denominador',
            'difficulty'    => 'intermediate',
            'options'       => [
                ['option_text' => '3/4', 'is_correct' => true],
                ['option_text' => '2/6', 'is_correct' => false],
                ['option_text' => '1/6', 'is_correct' => false],
                ['option_text' => '2/4', 'is_correct' => false],
            ],
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.topic', 'Fracciones equivalentes');
        $res->assertJsonPath('data.difficulty', 'intermediate');

        $this->assertDatabaseHas('questions', [
            'id'        => $res->json('data.id'),
            'indicator' => 'MAT.3.2 Resuelve sumas de fracciones con distinto denominador',
        ]);
    }

    public function test_una_dificultad_inventada_se_rechaza(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam        = $this->examen($institution, $teacher);

        $this->postJson("/api/exams/{$exam->id}/questions", [
            'question_text' => '¿Cuánto es 2 + 2?',
            'question_type' => 'short_answer',
            'points'        => 1,
            'correct_answer_text' => '4',
            'difficulty'    => 'imposible',
        ])->assertStatus(422);
    }

    /** Los metadatos son opcionales: si lo fueran obligatorios, todo examen ya creado rompería. */
    public function test_una_pregunta_sin_metadatos_se_sigue_creando(): void
    {
        $institution = Institution::factory()->create();
        $teacher     = $this->signInTeacher(['institution_id' => $institution->id]);
        $exam        = $this->examen($institution, $teacher);

        $this->postJson("/api/exams/{$exam->id}/questions", [
            'question_text' => '¿Cuánto es 2 + 2?',
            'question_type' => 'short_answer',
            'points'        => 1,
            'correct_answer_text' => '4',
        ])->assertCreated();
    }

    public function test_la_columna_normalizada_la_calcula_la_base_y_no_el_codigo(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $exam = $this->examen($institution);

        // Escritura directa por Eloquent, sin pasar por el controlador: la
        // normalización no puede depender de qué vía se use para escribir.
        $question = Question::factory()->create([
            'institution_id' => $institution->id,
            'exam_id'        => $exam->id,
            'topic'          => '  Fracciones   Equivalentes ',
        ]);

        $this->assertSame(
            'fracciones equivalentes',
            $question->fresh()->topic_normalized,
            'topic_normalized debe salir de la columna generada.'
        );
    }

    public function test_un_recurso_puede_llevar_materia_y_se_puede_filtrar_por_ella(): void
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);

        $mate   = Subject::factory()->create(['institution_id' => $institution->id]);
        $lengua = Subject::factory()->create(['institution_id' => $institution->id]);

        // Un recurso con materia solo llega a aulas donde el docente la imparte.
        $aula = \App\Models\Academic\Group::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $aula->id, $mate->id);

        $res = $this->postJson('/api/study-resources', [
            'title'         => 'Guía de fracciones',
            'resource_type' => 'article',
            'url'           => 'https://es.khanacademy.org/math/fracciones',
            'subject_id'    => $mate->id,
        ]);

        $res->assertCreated();
        $res->assertJsonPath('data.subject_id', $mate->id);

        $listado = $this->getJson('/api/study-resources?subject_id=' . $lengua->id);
        $listado->assertOk();
        $this->assertCount(0, $listado->json('data.data'));
    }

    public function test_no_se_puede_colgar_un_recurso_de_la_materia_de_otro_centro(): void
    {
        $institution = Institution::factory()->create();
        $ajena       = Subject::factory()->create([
            'institution_id' => Institution::factory()->create()->id,
        ]);

        $this->signInTeacher(['institution_id' => $institution->id]);

        $this->postJson('/api/study-resources', [
            'title'         => 'Guía de otro centro',
            'resource_type' => 'article',
            'url'           => 'https://es.khanacademy.org/math/fracciones',
            'subject_id'    => $ajena->id,
        ])->assertStatus(422);
    }

    /**
     * El corazón de K3/K4: antes esta rama devolvía el recurso más reciente que
     * encajara por grado, fuera de la materia que fuera.
     */
    public function test_el_recurso_sugerido_es_de_la_materia_del_examen(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $ciencias = Subject::factory()->create(['institution_id' => $institution->id]);
        $espanol  = Subject::factory()->create(['institution_id' => $institution->id]);

        $student = $this->alumno($institution, 3);

        // El más reciente, del grado correcto, pero de la materia equivocada.
        StudyResource::factory()->enAulas([$this->aula])->create([
            'institution_id' => $institution->id,
            'subject_id'     => $espanol->id,
            'title'          => 'Lectura comprensiva',
            'grade_min'      => 1,
            'grade_max'      => 6,
            'difficulty'     => 'basic',
            'created_at'     => now(),
        ]);

        $esperado = StudyResource::factory()->enAulas([$this->aula])->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
            'title'          => 'El ciclo del agua',
            'grade_min'      => 1,
            'grade_max'      => 6,
            'difficulty'     => 'basic',
            'created_at'     => now()->subMonth(),
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
        ]);

        // Desempeño bajo: es la rama que sugiere recurso de refuerzo.
        $attempt = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
            'score'           => 2,
            'max_score'       => 10,
        ]);

        $recurso = collect(app(AiRecommendationService::class)->generateFromAttempt($attempt))
            ->firstWhere(fn ($r) => $r->recommendation_type->value === 'resource');

        $this->assertNotNull($recurso);
        $this->assertSame($esperado->title, $recurso->resource['title']);
    }

    /**
     * La materia pesa más que el grado. Un material de la asignatura que falló,
     * aunque sea de otro nivel, es más pertinente que uno del grado correcto
     * pero de otra asignatura.
     */
    public function test_si_no_hay_nada_del_grado_vale_otro_nivel_de_la_misma_materia(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $ciencias = Subject::factory()->create(['institution_id' => $institution->id]);
        $espanol  = Subject::factory()->create(['institution_id' => $institution->id]);

        $student = $this->alumno($institution, 2);

        StudyResource::factory()->enAulas([$this->aula])->create([
            'institution_id' => $institution->id,
            'subject_id'     => $espanol->id,
            'title'          => 'Lectura de segundo',
            'grade_min'      => 1,
            'grade_max'      => 3,
            'created_at'     => now(),
        ]);

        $esperado = StudyResource::factory()->enAulas([$this->aula])->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
            'title'          => 'Ciencias de sexto',
            'grade_min'      => 5,
            'grade_max'      => 6,
            'created_at'     => now()->subMonth(),
        ]);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
        ]);

        $attempt = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
            'score'           => 2,
            'max_score'       => 10,
        ]);

        $recurso = collect(app(AiRecommendationService::class)->generateFromAttempt($attempt))
            ->firstWhere(fn ($r) => $r->recommendation_type->value === 'resource');

        $this->assertSame($esperado->title, $recurso->resource['title']);
    }

    /** Un recurso de otra aula no se sugiere: el alumno no lo puede abrir. */
    public function test_no_se_sugiere_un_recurso_de_otra_aula(): void
    {
        $institution = Institution::factory()->create();
        app()->instance('tenant_id', $institution->id);

        $ciencias = Subject::factory()->create(['institution_id' => $institution->id]);
        $student  = $this->alumno($institution, 3);
        $otraAula = Group::factory()->create(['institution_id' => $institution->id]);

        // De la materia, del grado y más reciente, pero enviado a otra aula.
        StudyResource::factory()->enAulas([$otraAula])->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
            'title'          => 'De otra aula',
            'grade_min'      => 1,
            'grade_max'      => 6,
            'created_at'     => now(),
        ]);

        // Y uno sin aula, que no ve ningún estudiante.
        StudyResource::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
            'title'          => 'Sin aula',
            'created_at'     => now(),
        ]);

        $esperado = StudyResource::factory()->enAulas([$this->aula])->create([
            'institution_id' => $institution->id,
            'subject_id'     => $ciencias->id,
            'title'          => 'De su aula',
            'grade_min'      => 1,
            'grade_max'      => 6,
            'created_at'     => now()->subMonth(),
        ]);

        $exam = Exam::factory()->create(['institution_id' => $institution->id, 'subject_id' => $ciencias->id]);
        $attempt = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $student->id,
            'score'           => 2,
            'max_score'       => 10,
        ]);

        $recurso = collect(app(AiRecommendationService::class)->generateFromAttempt($attempt))
            ->firstWhere(fn ($r) => $r->recommendation_type->value === 'resource');

        $this->assertSame($esperado->title, $recurso->resource['title']);
    }

    /* =========================
     | Apoyo
     ========================= */

    private function alumno(Institution $institution, ?int $grade = null): User
    {
        $user = User::factory()->student()->create(['institution_id' => $institution->id]);

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
            'grade'          => $grade,
        ]);

        // Matriculado en un aula: solo se le sugieren recursos enviados a ella.
        $this->aula = Group::factory()->create(['institution_id' => $institution->id]);
        DB::table('group_students')->insert([
            'institution_id'  => $institution->id,
            'group_id'        => $this->aula->id,
            'student_user_id' => $user->id,
            'joined_at'       => now(),
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
}
