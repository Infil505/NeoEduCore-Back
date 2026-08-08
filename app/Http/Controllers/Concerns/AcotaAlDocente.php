<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserType;
use Illuminate\Support\Facades\DB;

/**
 * Alcance de un docente, resuelto **siempre** desde `teacher_assignments`.
 *
 * Existe por la misma razón que `Exam::scopeVisibleTo()`: que haya una sola
 * definición de «mis estudiantes» y no se olvide al añadir un endpoint. La
 * versión anterior de esta regla estaba repetida a mano en seis controladores,
 * cada uno comparando contra `exams.created_by_teacher_id`, y en cuatro sitios
 * más directamente no estaba —los informes por estudiante y las analíticas no
 * comprobaban nada, así que cualquier docente leía el historial completo de
 * cualquier alumno de la institución—.
 *
 * La cadena es: docente → `teacher_assignments` → grupo → `group_students` →
 * estudiante. El docente no interviene en ningún eslabón: las asignaciones las
 * crea el admin.
 *
 * **Membresía activa.** Solo cuentan los estudiantes con `left_at IS NULL`. Un
 * alumno que cambia de sección deja de ser visible para el docente de la
 * anterior, incluidos sus resultados pasados. Es deliberado —el acceso sigue a
 * la matrícula, no al histórico— y es lo contrario de lo que hace
 * `Exam::scopeVisibleTo()`, que sí conserva a quien se fue porque ahí la
 * pregunta es «¿puede presentar este examen?» y no «¿quién puede ver sus datos?».
 */
trait AcotaAlDocente
{
    protected function esDocente(?object $user): bool
    {
        return $user !== null
            && $user->user_type instanceof UserType
            && $user->user_type === UserType::Teacher;
    }

    /**
     * Subconsulta con los `group_id` que el docente tiene asignados.
     */
    protected function gruposDelDocente(string $teacherUserId)
    {
        return DB::table('teacher_assignments')
            ->select('group_id')
            ->where('teacher_user_id', $teacherUserId)
            ->where('institution_id', app('tenant_id'));
    }

    /**
     * Subconsulta con los `subject_id` que el docente imparte.
     */
    protected function materiasDelDocente(string $teacherUserId)
    {
        return DB::table('teacher_assignments')
            ->select('subject_id')
            ->where('teacher_user_id', $teacherUserId)
            ->where('institution_id', app('tenant_id'));
    }

    /**
     * Subconsulta con los `student_user_id` que el docente alcanza.
     */
    protected function estudiantesDelDocente(string $teacherUserId)
    {
        return DB::table('group_students as gs')
            ->select('gs.student_user_id')
            ->join('teacher_assignments as ta', 'ta.group_id', '=', 'gs.group_id')
            ->whereNull('gs.left_at')
            ->where('ta.teacher_user_id', $teacherUserId)
            ->where('ta.institution_id', app('tenant_id'));
    }

    /**
     * ¿El docente tiene asignado algún grupo donde este estudiante esté activo?
     */
    protected function docenteAlcanzaEstudiante(object $docente, string $studentUserId): bool
    {
        return $this->estudiantesDelDocente($docente->id)
            ->where('gs.student_user_id', $studentUserId)
            ->exists();
    }

    /**
     * ¿El docente puede dirigir un examen de esta materia a este grupo?
     *
     * Exige la fila completa (grupo **y** materia): estar asignado a 7-A en
     * Matemáticas no habilita a mandarles un examen de Lengua.
     */
    protected function docenteAlcanzaGrupoEnMateria(object $docente, string $groupId, string $subjectId): bool
    {
        return DB::table('teacher_assignments')
            ->where('teacher_user_id', $docente->id)
            ->where('group_id', $groupId)
            ->where('subject_id', $subjectId)
            ->where('institution_id', app('tenant_id'))
            ->exists();
    }

    /**
     * Acota una consulta a los estudiantes del docente. No toca la consulta si
     * quien mira no es docente (admin ve toda su institución vía TenantScoped).
     *
     * @param  string  $columna  columna con el uuid del estudiante en la tabla consultada
     */
    protected function acotarAEstudiantesDelDocente($query, ?object $user, string $columna = 'student_user_id')
    {
        if (!$this->esDocente($user)) {
            return $query;
        }

        return $query->whereIn($columna, $this->estudiantesDelDocente($user->id));
    }

    /**
     * Respuesta 403 uniforme. El mensaje no distingue «no existe» de «no es
     * tuyo» a propósito: decir cuál de las dos es filtra la existencia de
     * estudiantes ajenos.
     */
    protected function noAutorizadoPorAsignacion()
    {
        return response()->json([
            'message' => 'No autorizado: no estás asignado a ningún grupo de este estudiante.',
        ], 403);
    }
}
