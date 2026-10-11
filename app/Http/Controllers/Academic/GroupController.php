<?php

namespace App\Http\Controllers\Academic;

use App\Support\TenantCache;
use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Controller;
use App\Models\Academic\Group;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Support\RelacionesEnLinea;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    use AcotaAlDocente;

    /**
     * Listar grupos
     */
    public function index(Request $request)
    {
        $query = Group::query()->orderByDesc('year')->orderBy('grade')->orderBy('section');

        // Docente: solo los grupos que tiene asignados.
        if ($this->esDocente($request->user())) {
            $query->whereIn('id', $this->gruposDelDocente($request->user()->id));
        }

        if ($request->filled('grade')) {
            $query->where('grade', (int) $request->input('grade'));
        }

        if ($request->filled('section')) {
            $query->where('section', strtoupper($request->string('section')->toString()));
        }

        if ($request->filled('year')) {
            $query->where('year', (int) $request->input('year'));
        }

        // Casi no cambia y lo pide cada pantalla: caché por centro. El docente ve
        // solo SUS aulas, así que su id va en la clave; el resto comparte entrada.
        // Se invalida al tocar aulas, asignaciones o el recuento de alumnado.
        $user = $request->user();
        $alcance = $this->esDocente($user) ? 'd' . $user->id : 'a';

        $datos = TenantCache::remember(
            $user->institution_id,
            TenantCache::CATALOGO,
            "groups:{$alcance}:" . $this->huellaDeConsulta($request),
            300,
            fn () => $this->paginar($query, $request)->toArray()
        );

        return response()->json(['data' => $datos]);
    }

    /**
     * Crear grupo
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'grade' => ['required', 'integer', 'between:' . config('academic.grade_min') . ',' . config('academic.grade_max')],
            // No hay un tope real de secciones por ley: el MEP regula el tamano
            // de cada seccion (Resolucion MEP-0248-2026), no cuantas letras puede
            // tener un grado. Una escuela rural puede tener una sola seccion sin
            // letra ("Unica") y una grande en San Jose puede pasar de "D". Fijar
            // ['A','B','C','D'] era una suposicion de secundaria que no aplicaba
            // ni a primaria ni a como cada centro nombra realmente sus secciones.
            'section' => ['required', 'string', 'min:1', 'max:20'],

            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'group_code' => ['nullable', 'string', 'max:40'],
        ]);

        $group = Group::create([
            'name' => trim($data['name']),
            'grade' => (int) $data['grade'],
            'section' => strtoupper($data['section']),
            'year' => $data['year'] ?? (int) date('Y'),
            'group_code' => $data['group_code'] ?? null,
            'student_count' => 0,
        ]);

        return response()->json([
            'data' => $group,
        ], 201);
    }

    /**
     * Ver grupo + estudiantes activos
     */
    public function show(Group $group, Request $request)
    {
        // Este endpoint devuelve la lista nominal del grupo: es el que más
        // directamente expone alumnado ajeno si no se acota.
        if ($this->esDocente($request->user())
            && !$this->gruposDelDocente($request->user()->id)->where('group_id', $group->id)->exists()) {
            return response()->json([
                'message' => 'No autorizado: no estás asignado a este grupo.',
            ], 403);
        }

        // De cada estudiante solo lo que muestra la lista del aula: antes cada fila
        // llevaba la ficha entera (fecha de nacimiento, acudiente…) y el usuario completo.
        // El usuario unido en la misma consulta (`RelacionesEnLinea`), no una más: con la base
        // remota cada viaje cuesta ~0,4 s.
        $students = $group->students()
            ->leftJoin('users as user', function ($join) {
                $join->on('user.id', '=', 'students.user_id')->on('user.institution_id', '=', 'students.institution_id');
            })
            ->wherePivotNull('left_at')
            ->get([
                'students.user_id', 'students.student_code', 'students.section', 'students.status',
                'user.id as user__id', 'user.full_name as user__full_name', 'user.email as user__email', 'user.status as user__status',
            ]);
        RelacionesEnLinea::hidratar($students, ['user' => User::class]);

        return response()->json([
            'data' => [
                'group' => $group,
                'students' => $students,
            ],
        ]);
    }

    /**
     * Actualizar grupo
     */
    public function update(Request $request, Group $group)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'grade' => ['sometimes', 'integer', 'between:' . config('academic.grade_min') . ',' . config('academic.grade_max')],
            'section' => ['sometimes', 'string', 'min:1', 'max:20'],

            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'group_code' => ['nullable', 'string', 'max:40'],
        ]);

        if (isset($data['section'])) {
            $data['section'] = strtoupper($data['section']);
        }
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        $group->fill($data);
        $group->save();

        return response()->json([
            'data' => $group,
        ]);
    }

    /**
     * Eliminar grupo.
     * Cascada DB: group_students (membresías) y exam_targets (asignaciones).
     * Los estudiantes y exámenes NO se eliminan.
     */
    public function destroy(Group $group)
    {
        $group->delete();

        return response()->noContent();
    }

    /**
     * Asignar estudiantes al grupo (alta)
     * body: { "student_user_ids": ["uuid", ...] }
     */
    public function addStudents(Request $request, string $group)
    {
        $data = $request->validate([
            'student_user_ids' => ['required', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
        ]);

        $centro = $request->user()->institution_id;
        $ids = array_values(array_unique($data['student_user_ids']));
        $marcas = implode(', ', array_fill(0, count($ids), '?'));

        // UNA sentencia atómica (antes: binding, transacción, lectura de alumnos, upsert, recuento
        // y relectura del aula): activa la matrícula de los alumnos DEL CENTRO (los de otro no
        // aparecen en `act`), conserva `joined_at` al reactivar y deja `student_count` al día. El
        // recuento no puede leer lo que inserta esta misma sentencia, así que cuenta las matrículas
        // activas que no están en `act` y suma las de `act` (todas quedan activas).
        $fila = DB::selectOne(
            "WITH act AS (SELECT user_id FROM students WHERE institution_id = ? AND user_id IN ({$marcas})),
                  ins AS (INSERT INTO group_students (institution_id, group_id, student_user_id, joined_at, left_at)
                          SELECT ?, ?, a.user_id, ?, NULL FROM act a
                           WHERE EXISTS (SELECT 1 FROM groups g WHERE g.id = ? AND g.institution_id = ?)
                          ON CONFLICT (group_id, student_user_id) DO UPDATE SET left_at = NULL
                          RETURNING student_user_id)
             UPDATE groups SET updated_at = now(),
                    student_count = (SELECT COUNT(*) FROM group_students gs
                                      WHERE gs.group_id = ? AND gs.institution_id = ? AND gs.left_at IS NULL
                                        AND gs.student_user_id NOT IN (SELECT user_id FROM act))
                                    + (SELECT COUNT(*) FROM act)
              WHERE id = ? AND institution_id = ?
          RETURNING *",
            array_merge([$centro], $ids, [$centro, $group, now()->toDateTimeString(), $group, $centro, $group, $centro, $group, $centro])
        );

        return $this->respuestaDeAula($fila, $centro, 'Estudiantes asignados');
    }

    /**
     * Remover estudiantes del grupo (baja lógica)
     * body: { "student_user_ids": ["uuid", ...] }
     */
    public function removeStudents(Request $request, string $group)
    {
        $data = $request->validate([
            'student_user_ids' => ['required', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
        ]);

        $centro = $request->user()->institution_id;
        $ids = array_values(array_unique($data['student_user_ids']));
        $marcas = implode(', ', array_fill(0, count($ids), '?'));

        // UNA sentencia atómica: da de baja a los indicados y recalcula `student_count` (las
        // matrículas activas que no se están dando de baja).
        $fila = DB::selectOne(
            "WITH rem AS (UPDATE group_students SET left_at = ?
                           WHERE institution_id = ? AND group_id = ? AND student_user_id IN ({$marcas}) AND left_at IS NULL
                       RETURNING student_user_id)
             UPDATE groups SET updated_at = now(),
                    student_count = (SELECT COUNT(*) FROM group_students gs
                                      WHERE gs.group_id = ? AND gs.institution_id = ? AND gs.left_at IS NULL
                                        AND gs.student_user_id NOT IN (SELECT student_user_id FROM rem))
              WHERE id = ? AND institution_id = ?
          RETURNING *",
            array_merge([now()->toDateTimeString(), $centro, $group], $ids, [$group, $centro, $group, $centro])
        );

        return $this->respuestaDeAula($fila, $centro, 'Estudiantes removidos');
    }

    /** La respuesta de `addStudents`/`removeStudents` desde la fila que devolvió la sentencia. */
    private function respuestaDeAula(?object $fila, string $centro, string $mensaje)
    {
        if ($fila === null) {
            abort(404);
        }

        // `student_count` está en el listado de aulas cacheado y esto no dispara eventos de Eloquent.
        TenantCache::invalidar($centro, TenantCache::CATALOGO, TenantCache::MAPAS, TenantCache::AGENDA);

        return response()->json([
            'message' => $mensaje,
            'data' => [
                'group' => (new Group())->newFromBuilder((array) $fila),
            ],
        ]);
    }
}
