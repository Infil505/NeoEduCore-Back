<?php

namespace Tests\Feature\Perf;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Presupuesto de queries por endpoint.
 *
 * Doble propósito:
 *
 * 1. **Guardia anti-N+1.** El coste de un endpoint debe ser constante respecto
 *    al volumen de datos. Cada caso monta un dataset pequeño y otro grande y
 *    exige el MISMO número de queries: si alguien introduce un N+1, el test
 *    falla aunque la respuesta siga siendo correcta.
 *
 * 2. **Insumo del modelo de concurrencia.** Las queries por request son el
 *    driver del techo de concurrencia (ver ANALISIS_CONCURRENCIA.md). Correr
 *    con `--filter=QueryBudget` e inspeccionar los budgets da el perfil actual.
 *
 * Los presupuestos son deliberadamente holgados (~+3 sobre lo medido) para no
 * volverse frágiles ante cambios menores; lo que detectan es el crecimiento
 * proporcional al dataset, no una query de más.
 */
class QueryBudgetTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    /** Cuenta las queries que dispara un callable. */
    private function contarQueries(callable $fn): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        $fn();

        // Laravel no expone un "unlisten"; el contador se reinicia por test
        // porque el contenedor se reconstruye en cada setUp().
        return $n;
    }

    private function estudiantes(int $n, ?Group $group = null): array
    {
        $ids = [];

        for ($i = 0; $i < $n; $i++) {
            $user = User::factory()->student()->create(['institution_id' => $this->institution->id]);
            Student::factory()->create([
                'user_id'        => $user->id,
                'institution_id' => $this->institution->id,
            ]);
            $ids[] = $user->id;
        }

        if ($group) {
            DB::table('group_students')->insert(array_map(fn ($id) => [
                'institution_id'  => $this->institution->id,
                'group_id'        => $group->id,
                'student_user_id' => $id,
                'joined_at'       => now(),
                'left_at'         => null,
            ], $ids));
        }

        return $ids;
    }

    /* =========================
     |  Lecturas
     ========================= */

    public function test_subjects_index_cost_is_flat(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        Subject::factory()->count(2)->create(['institution_id' => $this->institution->id]);
        $pocas = $this->contarQueries(fn () => $this->getJson('/api/subjects')->assertOk());

        Subject::factory()->count(30)->create(['institution_id' => $this->institution->id]);
        $muchas = $this->contarQueries(fn () => $this->getJson('/api/subjects')->assertOk());

        // withCount('exams') es una subquery, no una query por materia
        $this->assertSame($pocas, $muchas, "N+1 en /subjects: {$pocas} → {$muchas} queries");
        $this->assertLessThanOrEqual(6, $muchas, "Presupuesto excedido: {$muchas} queries");
    }

    public function test_students_index_cost_is_flat(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->estudiantes(2);
        $pocos = $this->contarQueries(fn () => $this->getJson('/api/students')->assertOk());

        $this->estudiantes(25);
        $muchos = $this->contarQueries(fn () => $this->getJson('/api/students')->assertOk());

        $this->assertSame($pocos, $muchos, "N+1 en /students: {$pocos} → {$muchos} queries");
        $this->assertLessThanOrEqual(8, $muchos, "Presupuesto excedido: {$muchos} queries");
    }

    public function test_institution_analytics_cost_is_flat(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $this->estudiantes(2);
        $pocos = $this->contarQueries(fn () => $this->getJson('/api/analytics/institution')->assertOk());

        foreach ($this->estudiantes(20) as $id) {
            StudentProgress::create([
                'institution_id'     => $this->institution->id,
                'student_user_id'    => $id,
                'subject_id'         => $subject->id,
                'mastery_percentage' => 70,
                'updated_at'         => now(),
            ]);
        }
        $muchos = $this->contarQueries(fn () => $this->getJson('/api/analytics/institution')->assertOk());

        $this->assertSame($pocos, $muchos, "N+1 en /analytics/institution: {$pocos} → {$muchos}");
        $this->assertLessThanOrEqual(12, $muchos, "Presupuesto excedido: {$muchos} queries");
    }

    /* =========================
     |  Escrituras masivas
     |  Aquí está el riesgo real: si el coste crece con el tamaño del lote,
     |  una promoción de 500 alumnos tumba la BD.
     ========================= */

    public function test_bulk_group_reassignment_cost_is_flat_per_batch_size(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $origenA  = Group::factory()->create(['institution_id' => $this->institution->id]);
        $destinoA = Group::factory()->create(['institution_id' => $this->institution->id]);
        $this->estudiantes(3, $origenA);

        $lotePequeno = $this->contarQueries(function () use ($origenA, $destinoA) {
            $this->postJson('/api/bulk/reassign-group', [
                'from_group_id' => $origenA->id,
                'to_group_id'   => $destinoA->id,
            ])->assertOk();
        });

        $origenB  = Group::factory()->create(['institution_id' => $this->institution->id]);
        $destinoB = Group::factory()->create(['institution_id' => $this->institution->id]);
        $this->estudiantes(40, $origenB);

        $loteGrande = $this->contarQueries(function () use ($origenB, $destinoB) {
            $this->postJson('/api/bulk/reassign-group', [
                'from_group_id' => $origenB->id,
                'to_group_id'   => $destinoB->id,
            ])->assertOk();
        });

        // 3 estudiantes y 40 deben costar lo mismo: todo son operaciones de
        // conjunto (upsert batch, UPDATE con subquery), no bucles.
        $this->assertSame(
            $lotePequeno,
            $loteGrande,
            "El coste de reassign-group crece con el lote: {$lotePequeno} → {$loteGrande} queries"
        );
        $this->assertLessThanOrEqual(15, $loteGrande, "Presupuesto excedido: {$loteGrande} queries");
    }

    public function test_bulk_subject_reassignment_cost_is_flat_per_batch_size(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $materias = Subject::factory()->count(3)->create(['institution_id' => $this->institution->id])
            ->pluck('id')->all();

        $pocos = $this->estudiantes(3);
        $lotePequeno = $this->contarQueries(function () use ($pocos, $materias) {
            $this->postJson('/api/bulk/reassign-subjects', [
                'student_user_ids' => $pocos,
                'subject_ids'      => $materias,
                'mode'             => 'replace',
            ])->assertOk();
        });

        $muchos = $this->estudiantes(40);
        $loteGrande = $this->contarQueries(function () use ($muchos, $materias) {
            $this->postJson('/api/bulk/reassign-subjects', [
                'student_user_ids' => $muchos,
                'subject_ids'      => $materias,
                'mode'             => 'replace',
            ])->assertOk();
        });

        // 120 inscripciones (40×3) en el mismo número de queries que 9
        $this->assertSame(
            $lotePequeno,
            $loteGrande,
            "El coste de reassign-subjects crece con el lote: {$lotePequeno} → {$loteGrande}"
        );
        $this->assertLessThanOrEqual(12, $loteGrande, "Presupuesto excedido: {$loteGrande} queries");
    }

    public function test_reset_progress_cost_is_flat_per_batch_size(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $crearProgreso = function (array $ids) use ($subject) {
            foreach ($ids as $id) {
                StudentProgress::create([
                    'institution_id'     => $this->institution->id,
                    'student_user_id'    => $id,
                    'subject_id'         => $subject->id,
                    'mastery_percentage' => 80,
                    'updated_at'         => now(),
                ]);
            }
        };

        $pocos = $this->estudiantes(3);
        $crearProgreso($pocos);
        $lotePequeno = $this->contarQueries(function () use ($pocos) {
            $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => $pocos])->assertOk();
        });

        $muchos = $this->estudiantes(40);
        $crearProgreso($muchos);
        $loteGrande = $this->contarQueries(function () use ($muchos) {
            $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => $muchos])->assertOk();
        });

        $this->assertSame(
            $lotePequeno,
            $loteGrande,
            "El coste de reset-progress crece con el lote: {$lotePequeno} → {$loteGrande}"
        );
        $this->assertLessThanOrEqual(12, $loteGrande, "Presupuesto excedido: {$loteGrande} queries");
    }

    public function test_group_membership_add_cost_is_flat_per_batch_size(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $grupoA = Group::factory()->create(['institution_id' => $this->institution->id]);
        $pocos  = $this->estudiantes(3);

        $lotePequeno = $this->contarQueries(function () use ($grupoA, $pocos) {
            $this->postJson("/api/groups/{$grupoA->id}/students", [
                'student_user_ids' => $pocos,
            ])->assertOk();
        });

        $grupoB = Group::factory()->create(['institution_id' => $this->institution->id]);
        $muchos = $this->estudiantes(40);

        $loteGrande = $this->contarQueries(function () use ($grupoB, $muchos) {
            $this->postJson("/api/groups/{$grupoB->id}/students", [
                'student_user_ids' => $muchos,
            ])->assertOk();
        });

        $this->assertSame(
            $lotePequeno,
            $loteGrande,
            "El coste de addStudents crece con el lote: {$lotePequeno} → {$loteGrande}"
        );
        $this->assertLessThanOrEqual(12, $loteGrande, "Presupuesto excedido: {$loteGrande} queries");
    }

    /**
     * Exportación CSV de resultados.
     *
     * El informe del TFG exige generar un reporte de 1000 estudiantes en menos
     * de 5 s. Medido con 1000 intentos reales: 3.790 ms → **1.068 ms** tras
     * cambiar `cursor()` por `lazy()`. `cursor()` **ignora el eager loading**
     * (va fila a fila, no puede resolver los ids por adelantado), así que el
     * `with(['student.user'])` no se aplicaba y cada fila costaba 2 queries.
     */
    public function test_csv_export_does_not_scale_with_row_count(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $teacher = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'subject_id'            => $subject->id,
            'created_by_teacher_id' => $teacher->id,
        ]);

        $crearIntentos = function (int $n) use ($exam) {
            foreach ($this->estudiantes($n) as $id) {
                ExamAttempt::factory()->create([
                    'institution_id'  => $this->institution->id,
                    'exam_id'         => $exam->id,
                    'student_user_id' => $id,
                    'submitted_at'    => now(),
                    'score'           => 15,
                    'max_score'       => 20,
                ]);
            }
        };

        $descargar = function () use ($exam) {
            $res = $this->get("/api/reports/exams/{$exam->id}/results.csv");
            $res->assertOk();
            // streamDownload no ejecuta el callback hasta que se consume
            $res->streamedContent();
        };

        $crearIntentos(3);
        $pocos = $this->contarQueries($descargar);

        $crearIntentos(40);
        $muchos = $this->contarQueries($descargar);

        // 43 filas no deben costar más queries que 3: el eager load va por lotes
        $this->assertSame(
            $pocos,
            $muchos,
            "El CSV vuelve a tener N+1: 3 filas={$pocos} queries, 43 filas={$muchos}. "
            . '¿Se cambió lazy() por cursor()?'
        );
    }

    /**
     * El envío de examen es la operación más cara del sistema y la que marca
     * el pico de concurrencia (todos los alumnos entregan a la vez).
     */
    public function test_exam_submit_cost_is_flat_per_question_count(): void
    {
        $teacher = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);

        $medir = function (int $preguntas) use ($teacher): int {
            $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);
            $exam = Exam::factory()->create([
                'institution_id'        => $this->institution->id,
                'subject_id'            => $subject->id,
                'created_by_teacher_id' => $teacher->id,
                'status'                => 'active',
                'max_attempts'          => 5,
                'duration_minutes'      => 120,
                'available_from'        => now()->subDay(),
                'available_until'       => now()->addDay(),
            ]);

            $payload = [];
            for ($i = 0; $i < $preguntas; $i++) {
                $q = \App\Models\Exams\Question::factory()->create([
                    'institution_id' => $this->institution->id,
                    'exam_id'        => $exam->id,
                    'question_type'  => 'true_false',
                    'order_index'    => $i,
                ]);
                $correcta = \App\Models\Exams\QuestionOption::create([
                    'institution_id' => $this->institution->id, 'question_id' => $q->id,
                    'option_index' => 0, 'option_text' => 'Verdadero', 'is_correct' => true,
                ]);
                \App\Models\Exams\QuestionOption::create([
                    'institution_id' => $this->institution->id, 'question_id' => $q->id,
                    'option_index' => 1, 'option_text' => 'Falso', 'is_correct' => false,
                ]);

                $payload[] = [
                    'question_id'         => $q->id,
                    'selected_option_ids' => [$correcta->id],
                ];
            }

            $studentUser = User::factory()->student()->create(['institution_id' => $this->institution->id]);
            Student::factory()->create([
                'user_id' => $studentUser->id, 'institution_id' => $this->institution->id,
            ]);
            $this->actingAs($studentUser, 'sanctum');

            $attempt = ExamAttempt::factory()->create([
                'institution_id'  => $this->institution->id,
                'exam_id'         => $exam->id,
                'student_user_id' => $studentUser->id,
                'started_at'      => now()->subMinutes(5),
                'submitted_at'    => null,
                'score'           => 0,
                'max_score'       => 0,
            ]);

            return $this->contarQueries(function () use ($exam, $attempt, $payload) {
                $this->postJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/submit", [
                    'answers' => $payload,
                ])->assertSuccessful();
            });
        };

        $con3  = $medir(3);
        $con15 = $medir(15);

        // El submit es la operación de pico del sistema: al cerrarse la ventana
        // del examen todas las entregas llegan en ráfaga, y cada query es un
        // round-trip de red. Por eso `gradeAttempt` acumula las filas y hace
        // INSERT por lotes: el coste debe ser CONSTANTE respecto al nº de
        // preguntas. (Antes era `29 + 3·N`; ver ANALISIS_CONCURRENCIA.md §5.1.)
        $this->assertSame(
            $con3,
            $con15,
            sprintf(
                'El submit volvió a escalar con el nº de preguntas: '
                . '3 preguntas=%d, 15 preguntas=%d queries.',
                $con3,
                $con15
            )
        );

        // Medido 31/07/2026: 22 queries, constantes. De esas, solo 2 son la
        // corrección (los dos INSERT por lotes); el resto es coste fijo del
        // endpoint (auth, tenant, validación, recálculo de progreso,
        // recomendaciones). Ese coste fijo es ahora la siguiente palanca.
        $this->assertLessThanOrEqual(
            26,
            $con15,
            "Presupuesto excedido en el submit: {$con15} queries"
        );
    }
}
