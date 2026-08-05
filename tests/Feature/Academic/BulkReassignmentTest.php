<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

class BulkReassignmentTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    /** Crea N estudiantes de la institución de prueba. */
    private function estudiantes(int $n): array
    {
        return collect(range(1, $n))->map(function () {
            $user = User::factory()->student()->create([
                'institution_id' => $this->institution->id,
            ]);
            Student::factory()->create([
                'user_id'        => $user->id,
                'institution_id' => $this->institution->id,
            ]);

            return $user->id;
        })->all();
    }

    private function grupo(array $overrides = []): Group
    {
        return Group::factory()->create(array_merge([
            'institution_id' => $this->institution->id,
        ], $overrides));
    }

    private function membresiaActiva(string $groupId, string $studentId): bool
    {
        return DB::table('group_students')
            ->where('group_id', $groupId)
            ->where('student_user_id', $studentId)
            ->whereNull('left_at')
            ->exists();
    }

    /* =========================
     |  Permisos
     ========================= */

    public function test_teacher_cannot_bulk_reassign(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);
        $destino = $this->grupo();

        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $this->estudiantes(1),
            'to_group_id'      => $destino->id,
        ])->assertForbidden();

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $this->estudiantes(1),
            'subject_ids'      => [],
            'mode'             => 'replace',
        ])->assertForbidden();
    }

    /* =========================
     |  Reasignación de grupo
     ========================= */

    public function test_moves_students_by_explicit_list_and_closes_previous_membership(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $origen  = $this->grupo(['grade' => 7, 'section' => 'A', 'group_code' => '7A']);
        $destino = $this->grupo(['grade' => 8, 'section' => 'B', 'group_code' => '8B']);
        $ids     = $this->estudiantes(3);

        // Alta previa en el grupo origen
        DB::table('group_students')->insert(array_map(fn ($id) => [
            'institution_id'  => $this->institution->id,
            'group_id'        => $origen->id,
            'student_user_id' => $id,
            'joined_at'       => now(),
            'left_at'         => null,
        ], $ids));

        $res = $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $ids,
            'to_group_id'      => $destino->id,
        ]);

        $res->assertOk();
        $this->assertSame(3, $res->json('data.moved'));
        $this->assertSame(3, $res->json('data.memberships_closed'));
        $this->assertSame([], $res->json('data.skipped'));

        foreach ($ids as $id) {
            $this->assertTrue($this->membresiaActiva($destino->id, $id), 'Debe estar activo en destino');
            $this->assertFalse($this->membresiaActiva($origen->id, $id), 'No debe seguir activo en origen');

            // El historial se conserva: la fila del origen sigue, con left_at
            $this->assertDatabaseHas('group_students', [
                'group_id'        => $origen->id,
                'student_user_id' => $id,
            ]);
        }
    }

    public function test_moves_whole_source_group_when_given_from_group_id(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $origen  = $this->grupo();
        $destino = $this->grupo();
        $ids     = $this->estudiantes(4);

        DB::table('group_students')->insert(array_map(fn ($id) => [
            'institution_id'  => $this->institution->id,
            'group_id'        => $origen->id,
            'student_user_id' => $id,
            'joined_at'       => now(),
            'left_at'         => null,
        ], $ids));

        $res = $this->postJson('/api/bulk/reassign-group', [
            'from_group_id' => $origen->id,
            'to_group_id'   => $destino->id,
        ]);

        $res->assertOk();
        $this->assertSame(4, $res->json('data.moved'));
    }

    public function test_recounts_student_count_of_both_groups(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $origen  = $this->grupo(['student_count' => 99]);
        $destino = $this->grupo(['student_count' => 0]);
        $ids     = $this->estudiantes(2);

        DB::table('group_students')->insert(array_map(fn ($id) => [
            'institution_id'  => $this->institution->id,
            'group_id'        => $origen->id,
            'student_user_id' => $id,
            'joined_at'       => now(),
            'left_at'         => null,
        ], $ids));

        $this->postJson('/api/bulk/reassign-group', [
            'from_group_id' => $origen->id,
            'to_group_id'   => $destino->id,
        ])->assertOk();

        // El origen quedó vacío y el contador viejo (99) debe corregirse
        $this->assertSame(0, (int) $origen->fresh()->student_count);
        $this->assertSame(2, (int) $destino->fresh()->student_count);
    }

    public function test_syncs_denormalized_student_fields_with_target_group(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $destino = $this->grupo(['grade' => 9, 'section' => 'C', 'group_code' => '9C']);
        $ids     = $this->estudiantes(2);

        Student::query()->whereIn('user_id', $ids)->update([
            'grade' => 6, 'section' => 'A', 'group_code' => '6A',
        ]);

        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $ids,
            'to_group_id'      => $destino->id,
        ])->assertOk();

        foreach ($ids as $id) {
            $this->assertDatabaseHas('students', [
                'user_id'    => $id,
                'grade'      => 9,
                'section'    => 'C',
                'group_code' => '9C',
            ]);
        }
    }

    /**
     * Caso repitente: se queda en el mismo grado pero pasa al grupo del año
     * siguiente. Cambia `year` aunque grade y section sean idénticos.
     */
    public function test_repeating_student_moves_to_same_grade_next_year_group(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $anterior = $this->grupo(['grade' => 7, 'section' => 'A', 'year' => 2026, 'group_code' => '7A-2026']);
        $nuevo    = $this->grupo(['grade' => 7, 'section' => 'A', 'year' => 2027, 'group_code' => '7A-2027']);
        $ids      = $this->estudiantes(1);

        DB::table('group_students')->insert([[
            'institution_id'  => $this->institution->id,
            'group_id'        => $anterior->id,
            'student_user_id' => $ids[0],
            'joined_at'       => now()->subYear(),
            'left_at'         => null,
        ]]);

        $res = $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $ids,
            'to_group_id'      => $nuevo->id,
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('data.moved'));

        $this->assertTrue($this->membresiaActiva($nuevo->id, $ids[0]));
        $this->assertFalse($this->membresiaActiva($anterior->id, $ids[0]));

        // Mismo grado y sección, pero el año debe haberse actualizado
        $this->assertDatabaseHas('students', [
            'user_id'    => $ids[0],
            'grade'      => 7,
            'section'    => 'A',
            'year'       => 2027,
            'group_code' => '7A-2027',
        ]);

        $this->assertSame(0, (int) $anterior->fresh()->student_count);
        $this->assertSame(1, (int) $nuevo->fresh()->student_count);
    }

    public function test_sync_can_be_disabled(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $destino = $this->grupo(['grade' => 9, 'section' => 'C']);
        $ids     = $this->estudiantes(1);

        Student::query()->whereIn('user_id', $ids)->update(['grade' => 6, 'section' => 'A']);

        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids'    => $ids,
            'to_group_id'         => $destino->id,
            'sync_student_fields' => false,
        ])->assertOk();

        $this->assertDatabaseHas('students', ['user_id' => $ids[0], 'grade' => 6]);
    }

    public function test_students_already_in_target_are_not_counted_as_moved(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $destino = $this->grupo();
        $ids     = $this->estudiantes(2);

        DB::table('group_students')->insert([[
            'institution_id'  => $this->institution->id,
            'group_id'        => $destino->id,
            'student_user_id' => $ids[0],
            'joined_at'       => now()->subYear(),
            'left_at'         => null,
        ]]);

        $res = $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $ids,
            'to_group_id'      => $destino->id,
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('data.moved'));
        $this->assertSame(1, $res->json('data.already_in_group'));
    }

    public function test_unknown_students_are_reported_as_skipped_not_fatal(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $destino  = $this->grupo();
        $ids      = $this->estudiantes(1);
        $fantasma = '00000000-0000-4000-8000-000000000000';

        $res = $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => [...$ids, $fantasma],
            'to_group_id'      => $destino->id,
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('data.moved'));
        $this->assertSame([$fantasma], $res->json('data.skipped'));
    }

    public function test_cannot_move_into_a_group_from_another_institution(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $otra   = Institution::factory()->create();
        $ajeno  = Group::factory()->create(['institution_id' => $otra->id]);

        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $this->estudiantes(1),
            'to_group_id'      => $ajeno->id,
        ])->assertNotFound();
    }

    public function test_list_and_source_group_are_mutually_exclusive(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $this->estudiantes(1),
            'from_group_id'    => $this->grupo()->id,
            'to_group_id'      => $this->grupo()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('student_user_ids');
    }

    /* =========================
     |  Reasignación de materias
     ========================= */

    public function test_replace_leaves_exactly_the_given_subjects(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $ids   = $this->estudiantes(2);
        $vieja = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $a     = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $b     = Subject::factory()->create(['institution_id' => $this->institution->id]);

        // Plan previo: solo la materia vieja
        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $ids,
            'subject_ids'      => [$vieja->id],
            'mode'             => 'add',
        ])->assertOk();

        $res = $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $ids,
            'subject_ids'      => [$a->id, $b->id],
            'mode'             => 'replace',
        ]);

        $res->assertOk();

        foreach ($ids as $id) {
            $materias = DB::table('student_subjects')
                ->where('student_user_id', $id)
                ->pluck('subject_id')
                ->all();

            sort($materias);
            $esperadas = [$a->id, $b->id];
            sort($esperadas);

            $this->assertSame($esperadas, $materias);
        }
    }

    public function test_add_does_not_remove_existing_subjects(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $ids = $this->estudiantes(1);
        $a   = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $b   = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $ids,
            'subject_ids'      => [$a->id],
            'mode'             => 'add',
        ])->assertOk();

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $ids,
            'subject_ids'      => [$b->id],
            'mode'             => 'add',
        ])->assertOk();

        $this->assertSame(2, DB::table('student_subjects')
            ->where('student_user_id', $ids[0])->count());
    }

    public function test_add_is_idempotent(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $ids = $this->estudiantes(1);
        $a   = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $payload = [
            'student_user_ids' => $ids,
            'subject_ids'      => [$a->id],
            'mode'             => 'add',
        ];

        $this->postJson('/api/bulk/reassign-subjects', $payload)->assertOk();
        $segunda = $this->postJson('/api/bulk/reassign-subjects', $payload);

        $segunda->assertOk();
        $this->assertSame(0, $segunda->json('data.enrolled'), 'La 2a vez no debe inscribir nada nuevo');
        $this->assertSame(1, DB::table('student_subjects')
            ->where('student_user_id', $ids[0])->count());
    }

    public function test_remove_only_unenrolls_the_given_subjects(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $ids = $this->estudiantes(1);
        $a   = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $b   = Subject::factory()->create(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $ids,
            'subject_ids'      => [$a->id, $b->id],
            'mode'             => 'add',
        ])->assertOk();

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $ids,
            'subject_ids'      => [$a->id],
            'mode'             => 'remove',
        ])->assertOk();

        $this->assertDatabaseMissing('student_subjects', [
            'student_user_id' => $ids[0], 'subject_id' => $a->id,
        ]);
        $this->assertDatabaseHas('student_subjects', [
            'student_user_id' => $ids[0], 'subject_id' => $b->id,
        ]);
    }

    public function test_subject_from_another_institution_is_rejected(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $otra  = Institution::factory()->create();
        $ajena = Subject::factory()->create(['institution_id' => $otra->id]);

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $this->estudiantes(1),
            'subject_ids'      => [$ajena->id],
            'mode'             => 'add',
        ])->assertStatus(422)->assertJsonValidationErrors('subject_ids');
    }

    public function test_add_requires_at_least_one_subject(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $this->estudiantes(1),
            'subject_ids'      => [],
            'mode'             => 'add',
        ])->assertStatus(422)->assertJsonValidationErrors('subject_ids');
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => $this->estudiantes(1),
            'subject_ids'      => [],
            'mode'             => 'borrar_todo',
        ])->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    public function test_subjects_can_be_reassigned_by_source_group(): void
    {
        $this->signInAdmin(['institution_id' => $this->institution->id]);

        $origen = $this->grupo();
        $ids    = $this->estudiantes(3);
        $a      = Subject::factory()->create(['institution_id' => $this->institution->id]);

        DB::table('group_students')->insert(array_map(fn ($id) => [
            'institution_id'  => $this->institution->id,
            'group_id'        => $origen->id,
            'student_user_id' => $id,
            'joined_at'       => now(),
            'left_at'         => null,
        ], $ids));

        $res = $this->postJson('/api/bulk/reassign-subjects', [
            'from_group_id' => $origen->id,
            'subject_ids'   => [$a->id],
            'mode'          => 'add',
        ]);

        $res->assertOk();
        $this->assertSame(3, $res->json('data.enrolled'));
    }
}
