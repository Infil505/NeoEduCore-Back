<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\UserType;
use App\Models\Academic\Group;
use Illuminate\Support\Facades\DB;

/**
 * A qué aulas se envía un recurso o un evento (regla del centro, 05/10/2026).
 *
 * Los recursos y los avisos de calendario son actividades **del docente** y
 * llegan solo a las aulas que él elige **de las que el administrador le asignó**
 * (`teacher_assignments`). Es la misma frontera que ya gobierna a los exámenes.
 *
 *  - Si pide aulas concretas, todas tienen que ser suyas: una ajena da 403 y
 *    nombra el aula, en lugar de descartarla en silencio y dejar al docente
 *    creyendo que lo envió.
 *  - Si no pide ninguna y tiene **una sola** aula, se usa esa: no hay nada que
 *    elegir.
 *  - Si no pide ninguna y tiene **varias**, tiene que elegir: 422 con la lista
 *    de sus aulas, para que el frontend la ofrezca.
 *  - Un docente sin ningún aula asignado no puede enviar nada.
 *
 * El admin no crea recursos ni eventos (la ruta es solo de docentes); cuando
 * edita uno ya existente, solo se exige que el aula sea de su institución.
 */
trait ResuelveAulasDestino
{
    /**
     * @param  array<int,string>|null  $pedidas   `group_ids` de la petición
     * @param  string|null  $materiaId  si se indica, solo cuentan las aulas donde
     *                                  el docente imparte **esa** materia
     * @return array<int,string>|\Illuminate\Http\JsonResponse
     */
    protected function resolverAulasDestino(object $user, ?array $pedidas, ?string $materiaId = null)
    {
        $pedidas = array_values(array_unique($pedidas ?? []));

        if ($user->user_type !== UserType::Teacher) {
            // Contra el catálogo de aulas en caché: sin consulta a la base.
            $catalogo = \App\Support\CatalogoGrupos::delCentro($user->institution_id);
            $fuera = array_values(array_filter($pedidas, fn ($id) => ! $catalogo->has($id)));

            if (!empty($fuera)) {
                return response()->json([
                    'message' => 'Algún aula no existe en esta institución.',
                    'grupos_invalidos' => array_values($fuera),
                ], 422);
            }

            return $pedidas;
        }

        $asignadas = $this->aulasAsignadas($user, $materiaId);

        if (empty($pedidas)) {
            if (count($asignadas) === 1) {
                return $asignadas;
            }

            if (empty($asignadas)) {
                return response()->json([
                    'message' => $materiaId
                        ? 'No tienes ningún aula asignada en esta materia.'
                        : 'No tienes ningún aula asignada: el administrador debe asignártela.',
                ], 403);
            }

            return response()->json([
                'message' => 'Indica a qué aulas lo envías (group_ids): tienes más de una asignada.',
                'errors' => ['group_ids' => ['Elige al menos un aula.']],
                'aulas_disponibles' => Group::whereIn('id', $asignadas)
                    ->orderBy('grade')->orderBy('section')
                    ->get(['id', 'name', 'grade', 'section', 'group_code']),
            ], 422);
        }

        $noAsignadas = array_values(array_diff($pedidas, $asignadas));

        if (!empty($noAsignadas)) {
            $nombres = Group::whereIn('id', $noAsignadas)->pluck('name')->all();

            return response()->json([
                'message' => 'No estás asignado a ' . (empty($nombres)
                    ? 'alguna de las aulas indicadas'
                    : implode(', ', $nombres)) . ($materiaId ? ' en esta materia.' : '.'),
                'grupos_no_asignados' => $noAsignadas,
            ], 403);
        }

        return $pedidas;
    }

    /**
     * Aulas que el administrador le asignó al docente (opcionalmente en una materia).
     *
     * @return array<int,string>
     */
    protected function aulasAsignadas(object $docente, ?string $materiaId = null): array
    {
        return DB::table('teacher_assignments')
            ->where('teacher_user_id', $docente->id)
            ->where('institution_id', app('tenant_id'))
            ->when($materiaId, fn ($q) => $q->where('subject_id', $materiaId))
            ->distinct()
            ->pluck('group_id')
            ->all();
    }

    /**
     * Aulas donde el estudiante está matriculado ahora (`left_at IS NULL`).
     */
    protected function aulasDelEstudiante(object $estudiante)
    {
        return DB::table('group_students')
            ->select('group_id')
            ->where('student_user_id', $estudiante->id)
            ->where('institution_id', app('tenant_id'))
            ->whereNull('left_at');
    }
}
