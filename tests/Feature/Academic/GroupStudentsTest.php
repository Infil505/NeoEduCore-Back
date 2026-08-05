<?php

namespace Tests\Feature\Academic;

use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Alta y baja de estudiantes en UN grupo puntual.
 * El movimiento entre grupos vive en BulkReassignmentTest.
 */
class GroupStudentsTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

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

    public function test_teacher_can_add_students_to_a_group(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);
        $ids   = $this->estudiantes(3);

        $res = $this->postJson("/api/groups/{$group->id}/students", [
            'student_user_ids' => $ids,
        ]);

        $res->assertOk();

        foreach ($ids as $id) {
            $this->assertDatabaseHas('group_students', [
                'institution_id'  => $this->institution->id,
                'group_id'        => $group->id,
                'student_user_id' => $id,
                'left_at'         => null,
            ]);
        }

        $this->assertSame(3, (int) $group->fresh()->student_count);
    }

    public function test_adding_the_same_student_twice_is_idempotent(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);
        $ids   = $this->estudiantes(1);

        $this->postJson("/api/groups/{$group->id}/students", ['student_user_ids' => $ids])->assertOk();
        $this->postJson("/api/groups/{$group->id}/students", ['student_user_ids' => $ids])->assertOk();

        $this->assertSame(1, DB::table('group_students')
            ->where('group_id', $group->id)
            ->where('student_user_id', $ids[0])
            ->count());
        $this->assertSame(1, (int) $group->fresh()->student_count);
    }

    public function test_remove_is_a_logical_delete_and_recounts(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);
        $ids   = $this->estudiantes(2);

        $this->postJson("/api/groups/{$group->id}/students", ['student_user_ids' => $ids])->assertOk();

        $res = $this->deleteJson("/api/groups/{$group->id}/students", [
            'student_user_ids' => [$ids[0]],
        ]);

        $res->assertOk();

        // La fila sigue existiendo (historial), pero con left_at
        $fila = DB::table('group_students')
            ->where('group_id', $group->id)
            ->where('student_user_id', $ids[0])
            ->first();

        $this->assertNotNull($fila, 'La membresía debe conservarse como historial');
        $this->assertNotNull($fila->left_at, 'La baja debe ser lógica (left_at)');

        $this->assertSame(1, (int) $group->fresh()->student_count);
    }

    public function test_readding_a_removed_student_reopens_the_membership(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);
        $ids   = $this->estudiantes(1);

        $this->postJson("/api/groups/{$group->id}/students", ['student_user_ids' => $ids])->assertOk();
        $this->deleteJson("/api/groups/{$group->id}/students", ['student_user_ids' => $ids])->assertOk();
        $this->postJson("/api/groups/{$group->id}/students", ['student_user_ids' => $ids])->assertOk();

        $this->assertDatabaseHas('group_students', [
            'group_id'        => $group->id,
            'student_user_id' => $ids[0],
            'left_at'         => null,
        ]);
        $this->assertSame(1, (int) $group->fresh()->student_count);
    }

    public function test_students_from_another_institution_are_ignored(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);

        $otra  = Institution::factory()->create();
        $ajeno = User::factory()->student()->create(['institution_id' => $otra->id]);
        Student::factory()->create(['user_id' => $ajeno->id, 'institution_id' => $otra->id]);

        $this->postJson("/api/groups/{$group->id}/students", [
            'student_user_ids' => [$ajeno->id],
        ])->assertOk();

        $this->assertDatabaseMissing('group_students', [
            'group_id'        => $group->id,
            'student_user_id' => $ajeno->id,
        ]);
        $this->assertSame(0, (int) $group->fresh()->student_count);
    }

    public function test_student_cannot_manage_group_membership(): void
    {
        $this->signInStudent(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);

        $this->postJson("/api/groups/{$group->id}/students", [
            'student_user_ids' => ['00000000-0000-4000-8000-000000000000'],
        ])->assertForbidden();
    }

    public function test_student_user_ids_is_required(): void
    {
        $this->signInTeacher(['institution_id' => $this->institution->id]);

        $group = Group::factory()->create(['institution_id' => $this->institution->id]);

        $this->postJson("/api/groups/{$group->id}/students", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('student_user_ids');
    }
}
