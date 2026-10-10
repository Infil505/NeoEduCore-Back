<?php

namespace App\Services\Students;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materias de un estudiante.
 *
 * Son la suma de dos fuentes:
 *  - **De su sección (heredadas)**: las que el administrador asignó, con su
 *    docente, a la sección donde el estudiante está matriculado ahora
 *    (`teacher_assignments` × `group_students`). Es la vía normal: el admin
 *    organiza la sección y el estudiante hereda sus materias sin que nadie
 *    tenga que inscribirlo una por una.
 *  - **Individuales**: las inscritas a mano en `student_subjects` (optativas,
 *    adecuaciones…).
 *
 * Hasta el 07/10/2026 solo se leía `student_subjects`, que nadie llenaba, así
 * que el estudiante aparecía sin materias aunque su sección las tuviera.
 */
class StudentSubjectsService
{
    /**
     * @return Collection<int, array{subject_id:string, name:string, origin:string, section:?string, teachers:array<int,string>, enrolled_at:?string}>
     */
    public function materias(string $studentUserId, string $institutionId): Collection
    {
        // Las dos vías en UNA consulta (UNION ALL): con la base remota cada viaje
        // cuesta ~0,5 s y este servicio corre en cada carga del panel del alumno.
        $deSeccion = DB::table('group_students as gs')
            ->join('teacher_assignments as ta', function ($join) {
                $join->on('ta.group_id', '=', 'gs.group_id')->on('ta.institution_id', '=', 'gs.institution_id');
            })
            ->join('subjects as s', 's.id', '=', 'ta.subject_id')
            ->join('groups as g', 'g.id', '=', 'gs.group_id')
            ->leftJoin('users as u', 'u.id', '=', 'ta.teacher_user_id')
            ->where('gs.institution_id', $institutionId)
            ->where('gs.student_user_id', $studentUserId)
            ->whereNull('gs.left_at')
            ->select(['s.id as subject_id', 's.name', 'g.section', 'gs.joined_at as enrolled_at', 'u.full_name as teacher', DB::raw("'seccion' as origin")]);

        $individuales = DB::table('student_subjects as ss')
            ->join('subjects as s', 's.id', '=', 'ss.subject_id')
            ->where('ss.institution_id', $institutionId)
            ->where('ss.student_user_id', $studentUserId)
            ->select(['s.id as subject_id', 's.name', DB::raw('null::varchar as section'), 'ss.enrolled_at', DB::raw('null::varchar as teacher'), DB::raw("'individual' as origin")]);

        $filas = $deSeccion->unionAll($individuales)->get()->groupBy('origin');

        $deSeccion = ($filas->get('seccion') ?? collect())
            ->groupBy('subject_id')
            ->map(fn (Collection $f) => [
                'subject_id'  => $f->first()->subject_id,
                'name'        => $f->first()->name,
                'origin'      => 'seccion',
                'section'     => $f->first()->section,
                'teachers'    => $f->pluck('teacher')->filter()->unique()->values()->all(),
                'enrolled_at' => $f->first()->enrolled_at,
            ]);

        $individuales = ($filas->get('individual') ?? collect())
            ->keyBy('subject_id')
            ->map(fn ($fila) => [
                'subject_id'  => $fila->subject_id,
                'name'        => $fila->name,
                'origin'      => 'individual',
                'section'     => null,
                'teachers'    => [],
                'enrolled_at' => $fila->enrolled_at,
            ]);

        // Si una materia viene por las dos vías, manda la de la sección: tiene
        // docente y no se puede quitar a un solo estudiante.
        return $individuales->merge($deSeccion)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }
}
