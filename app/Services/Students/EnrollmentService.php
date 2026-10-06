<?php

namespace App\Services\Students;

use Illuminate\Support\Facades\DB;

/**
 * Matrícula de estudiantes en aulas (`group_students`). La comparten el alta
 * individual (`POST /register`) y la carga masiva.
 */
class EnrollmentService
{
    /**
     * Abre (o reabre) la matrícula de un estudiante en un aula.
     *
     * `upsert` con `left_at = NULL` en conflicto: si el estudiante ya estuvo en
     * esa aula y se fue, se reactiva la fila original conservando su
     * `joined_at`, igual que hace `GroupController::addStudents()`.
     */
    public function abrirMatricula(string $studentUserId, string $groupId, string $institutionId): void
    {
        DB::table('group_students')->upsert(
            [[
                'institution_id'  => $institutionId,
                'group_id'        => $groupId,
                'student_user_id' => $studentUserId,
                'joined_at'       => now(),
                'left_at'         => null,
            ]],
            ['group_id', 'student_user_id'],
            ['left_at']
        );
    }

    /** Recalcula `groups.student_count` con las matrículas abiertas (RN-STU-012). */
    public function recontarAula(string $groupId, string $institutionId): void
    {
        DB::table('groups')
            ->where('institution_id', $institutionId)
            ->where('id', $groupId)
            ->update([
                'student_count' => DB::table('group_students')
                    ->where('institution_id', $institutionId)
                    ->where('group_id', $groupId)
                    ->whereNull('left_at')
                    ->count(),
                'updated_at' => now(),
            ]);
    }
}
