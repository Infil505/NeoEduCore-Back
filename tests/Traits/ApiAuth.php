<?php

namespace Tests\Traits;

use App\Models\Admin\User;
use App\Models\Admin\Institution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait ApiAuth
{
    /**
     * Asigna un docente a un grupo para una materia.
     *
     * Hace falta en cualquier test que actúe como docente sobre datos de
     * estudiantes: desde `teacher_assignments`, un docente sin asignación no
     * alcanza a nadie. Antes esto salía de haber creado un examen dirigido al
     * grupo, que es justo lo que se dejó de aceptar.
     */
    protected function asignarDocente(User $docente, string $groupId, string $subjectId): void
    {
        DB::table('teacher_assignments')->insertOrIgnore([[
            'id'              => (string) Str::uuid(),
            'institution_id'  => $docente->institution_id,
            'teacher_user_id' => $docente->id,
            'group_id'        => $groupId,
            'subject_id'      => $subjectId,
            'assigned_at'     => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]]);
    }

    /**
     * Monta la cadena completa que da a un docente acceso a un estudiante:
     * grupo + materia, asignación del docente y matrícula del alumno.
     *
     * Devuelve el grupo y la materia creados por si el test necesita usarlos
     * (por ejemplo para dirigirle un examen).
     *
     * @return array{group: \App\Models\Academic\Group, subject: \App\Models\Academic\Subject}
     */
    protected function darAccesoDocenteA(User $docente, string $studentUserId, string $institutionId): array
    {
        $group   = \App\Models\Academic\Group::factory()->create(['institution_id' => $institutionId]);
        $subject = \App\Models\Academic\Subject::factory()->create(['institution_id' => $institutionId]);

        $this->asignarDocente($docente, $group->id, $subject->id);
        $this->matricularEnGrupo($studentUserId, $group->id, $institutionId);

        return ['group' => $group, 'subject' => $subject];
    }

    /**
     * Matricula a un estudiante en un grupo (membresía activa).
     */
    protected function matricularEnGrupo(string $studentUserId, string $groupId, string $institutionId): void
    {
        DB::table('group_students')->insertOrIgnore([[
            'id'              => (string) Str::uuid(),
            'institution_id'  => $institutionId,
            'group_id'        => $groupId,
            'student_user_id' => $studentUserId,
            'joined_at'       => now(),
            'left_at'         => null,
        ]]);
    }

    protected function signInTeacher(array $overrides = []): User
    {
        $institutionId = $overrides['institution_id'] ?? null;

        $institution = $institutionId
            ? Institution::query()->findOrFail($institutionId)
            : Institution::factory()->create();

        // ⚠️ Importante: si te pasan institution_id en overrides, lo respetamos
        $user = User::factory()->teacher()->create(array_merge([
            'institution_id' => $institution->id,
            'password_hash' => Hash::make('Abcdefg1'),
            'status' => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }

    protected function signInAdmin(array $overrides = []): User
    {
        $institutionId = $overrides['institution_id'] ?? null;

        $institution = $institutionId
            ? Institution::query()->findOrFail($institutionId)
            : Institution::factory()->create();

        $user = User::factory()->admin()->create(array_merge([
            'institution_id' => $institution->id,
            'password_hash' => Hash::make('Abcdefg1'),
            'status' => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Superadmin: sin institución, porque es externo a todas.
     */
    protected function signInSuperAdmin(array $overrides = []): User
    {
        $user = User::factory()->superAdmin()->create(array_merge([
            'institution_id' => null,
            'password_hash'  => Hash::make('Abcdefg1'),
            'status'         => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }

    protected function signInStudent(array $overrides = []): User
    {
        $institutionId = $overrides['institution_id'] ?? null;

        $institution = $institutionId
            ? Institution::query()->findOrFail($institutionId)
            : Institution::factory()->create();

        $user = User::factory()->student()->create(array_merge([
            'institution_id' => $institution->id,
            'password_hash' => Hash::make('Abcdefg1'),
            'status' => 'active',
        ], $overrides));

        Sanctum::actingAs($user);

        return $user;
    }
}