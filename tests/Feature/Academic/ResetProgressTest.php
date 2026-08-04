<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\Students\StudentProgressService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Reseteo de progreso para repitentes.
 *
 * Lo importante no es que el dominio quede en 0 (eso es trivial), sino que
 * SIGA en 0 después de un recálculo desde intentos: `reset_at` es la marca
 * que hace el reseteo duradero.
 */
class ResetProgressTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    private function estudiante(): string
    {
        $user = User::factory()->student()->create(['institution_id' => $this->institution->id]);
        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $this->institution->id,
        ]);

        return $user->id;
    }

    private function progreso(string $studentId, string $subjectId, float $pct): void
    {
        StudentProgress::create([
            'institution_id'     => $this->institution->id,
            'student_user_id'    => $studentId,
            'subject_id'         => $subjectId,
            'mastery_percentage' => $pct,
            'updated_at'         => now(),
        ]);
    }

    /** Intento enviado en el pasado, con nota alta. */
    private function intentoViejo(string $studentId, Exam $exam): ExamAttempt
    {
        return ExamAttempt::factory()->create([
            'institution_id'  => $this->institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $studentId,
            'submitted_at'    => now()->subYear(),
            'score'           => 90,
            'max_score'       => 100,
        ]);
    }

    private function examen(Subject $subject): Exam
    {
        $teacher = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);

        return Exam::factory()->create([
            'institution_id'         => $this->institution->id,
            'subject_id'             => $subject->id,
            'created_by_teacher_id'  => $teacher->id,
        ]);
    }

    /* =========================
     |  Permisos y validación
     ========================= */

    public function test_teacher_cannot_reset_progress(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => [$this->estudiante()],
        ])->assertForbidden();
    }

    public function test_list_and_source_group_are_mutually_exclusive(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => [$this->estudiante()],
            'from_group_id'    => $group->id,
        ])->assertStatus(422)->assertJsonValidationErrors('student_user_ids');
    }

    public function test_subject_from_another_institution_is_rejected(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $otra  = Institution::factory()->create();
        $ajena = Subject::factory()->create(['institution_id' => $otra->id]);

        $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => [$this->estudiante()],
            'subject_ids'      => [$ajena->id],
        ])->assertStatus(422)->assertJsonValidationErrors('subject_ids');
    }

    /* =========================
     |  Comportamiento
     ========================= */

    public function test_resets_all_subjects_when_no_subject_given(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $id = $this->estudiante();
        $a  = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $b  = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $this->progreso($id, $a->id, 85);
        $this->progreso($id, $b->id, 70);

        $res = $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => [$id]]);

        $res->assertOk();
        $this->assertSame(2, $res->json('data.progress_reset'));

        foreach ([$a->id, $b->id] as $subjectId) {
            $fila = DB::table('student_progress')
                ->where('student_user_id', $id)
                ->where('subject_id', $subjectId)
                ->first();

            $this->assertSame('0.00', (string) $fila->mastery_percentage);
            $this->assertNotNull($fila->reset_at, 'Debe quedar la marca de corte');
        }
    }

    public function test_can_reset_only_the_given_subjects(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $id = $this->estudiante();
        $a  = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $b  = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $this->progreso($id, $a->id, 85);
        $this->progreso($id, $b->id, 70);

        $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => [$id],
            'subject_ids'      => [$a->id],
        ])->assertOk();

        $this->assertSame('0.00', (string) DB::table('student_progress')
            ->where('student_user_id', $id)->where('subject_id', $a->id)->value('mastery_percentage'));

        // La otra materia queda intacta
        $otra = DB::table('student_progress')
            ->where('student_user_id', $id)->where('subject_id', $b->id)->first();
        $this->assertSame('70.00', (string) $otra->mastery_percentage);
        $this->assertNull($otra->reset_at);
    }

    public function test_overall_average_is_recomputed(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $id = $this->estudiante();
        $a  = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $this->progreso($id, $a->id, 90);
        Student::query()->where('user_id', $id)->update(['overall_average' => 90]);

        $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => [$id]])->assertOk();

        $this->assertSame('0.00', (string) Student::query()->where('user_id', $id)->value('overall_average'));
    }

    public function test_attempt_history_is_preserved(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $id      = $this->estudiante();
        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $exam    = $this->examen($subject);
        $intento = $this->intentoViejo($id, $exam);

        $this->progreso($id, $subject->id, 90);

        $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => [$id]])->assertOk();

        // El historial académico NO se borra
        $this->assertDatabaseHas('exam_attempts', [
            'id'    => $intento->id,
            'score' => 90,
        ]);
    }

    /**
     * El test que de verdad importa: sin `reset_at`, este recálculo
     * restauraría el 90% del intento del año pasado.
     */
    public function test_reset_survives_a_recalculation_from_attempts(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $id      = $this->estudiante();
        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $exam    = $this->examen($subject);

        $this->intentoViejo($id, $exam);
        $this->progreso($id, $subject->id, 90);

        $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => [$id]])->assertOk();

        // Simula lo que ocurre al enviar un examen o revisar una respuesta
        app(StudentProgressService::class)->recalcFromAttempts($id, $subject->id);

        $this->assertSame('0.00', (string) DB::table('student_progress')
            ->where('student_user_id', $id)->where('subject_id', $subject->id)->value('mastery_percentage'),
            'El intento anterior al corte no debe volver a contar');
    }

    public function test_attempts_after_the_reset_do_count_again(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $id      = $this->estudiante();
        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $exam    = $this->examen($subject);

        $this->intentoViejo($id, $exam);
        $this->progreso($id, $subject->id, 90);

        $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => [$id]])->assertOk();

        // Examen del ciclo nuevo, posterior al corte
        ExamAttempt::factory()->create([
            'institution_id'  => $this->institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $id,
            'submitted_at'    => now()->addMinute(),
            'score'           => 50,
            'max_score'       => 100,
        ]);

        app(StudentProgressService::class)->recalcFromAttempts($id, $subject->id);

        // 50, no el promedio de 90 y 50 (=70): el viejo quedó fuera del corte
        $this->assertSame('50.00', (string) DB::table('student_progress')
            ->where('student_user_id', $id)->where('subject_id', $subject->id)->value('mastery_percentage'));
    }

    public function test_can_reset_a_whole_group(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $group   = Group::factory()->create(['institution_id' => $this->institution->id]);
        $subject = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $ids = collect(range(1, 3))->map(fn () => $this->estudiante())->all();

        foreach ($ids as $id) {
            $this->progreso($id, $subject->id, 80);
            DB::table('group_students')->insert([[
                'institution_id'  => $this->institution->id,
                'group_id'        => $group->id,
                'student_user_id' => $id,
                'joined_at'       => now(),
                'left_at'         => null,
            ]]);
        }

        $res = $this->postJson('/api/bulk/reset-progress', ['from_group_id' => $group->id]);

        $res->assertOk();
        $this->assertSame(3, $res->json('data.affected_students'));
        $this->assertSame(3, $res->json('data.progress_reset'));
    }

    public function test_unknown_students_are_reported_as_skipped(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $fantasma = '00000000-0000-4000-8000-000000000001';

        $res = $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => [$this->estudiante(), $fantasma],
        ]);

        $res->assertOk();
        $this->assertSame([$fantasma], $res->json('data.skipped'));
    }
}
