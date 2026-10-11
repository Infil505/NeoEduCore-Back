<?php

namespace App\Http\Controllers\Academic;

use App\Support\TenantCache;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Academic\TeacherAssignment;
use App\Models\Admin\User;
use App\Support\RelacionesEnLinea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gestión de asignaciones docente → grupo → materia.
 *
 * **Solo admin.** Es la razón de ser de la tabla: si un docente pudiera crear
 * sus propias asignaciones, volvería el problema que esto viene a cerrar —darse
 * acceso a sí mismo a grupos que no le corresponden—. Las rutas ya van bajo
 * `role:admin`; la comprobación se repite aquí a propósito, porque un cambio
 * descuidado en `routes/api.php` no debería bastar para abrir la puerta.
 */
class TeacherAssignmentController extends Controller
{
    /**
     * GET /api/teacher-assignments
     * Filtros opcionales: teacher_user_id, group_id, subject_id
     */
    public function index(Request $request): JsonResponse
    {
        if ($denegado = $this->denyIfNotAdmin($request->user())) {
            return $denegado;
        }

        $data = $request->validate([
            'teacher_user_id' => ['nullable', 'uuid'],
            'group_id'        => ['nullable', 'uuid'],
            'subject_id'      => ['nullable', 'uuid'],
        ]);

        $query = TeacherAssignment::query()
            ->with(['teacher:id,full_name,email', 'group:id,name,grade,section', 'subject:id,name'])
            ->orderByDesc('assigned_at');

        foreach (['teacher_user_id', 'group_id', 'subject_id'] as $filtro) {
            if (!empty($data[$filtro])) {
                $query->where($filtro, $data[$filtro]);
            }
        }

        // Solo administrador (comprobado arriba): una entrada por centro y filtros.
        $datos = TenantCache::remember(
            $request->user()->institution_id,
            TenantCache::CATALOGO,
            'assignments:' . $this->huellaDeConsulta($request),
            300,
            fn () => $this->paginar($query, $request)->toArray()
        );

        return response()->json(['data' => $datos]);
    }

    /**
     * POST /api/teacher-assignments
     *
     * body: {
     *   "teacher_user_id": "uuid",
     *   "group_ids":   ["uuid", ...],
     *   "subject_ids": ["uuid", ...]
     * }
     *
     * Crea el producto grupo × materia: asignar un docente a dos secciones y
     * tres materias son seis filas. Es idempotente — repetir la llamada no
     * duplica ni pisa el `assigned_at` original.
     */
    public function store(Request $request): JsonResponse
    {
        if ($denegado = $this->denyIfNotAdmin($request->user())) {
            return $denegado;
        }

        $data = $request->validate([
            'teacher_user_id' => ['required', 'uuid'],
            'group_ids'       => ['required', 'array', 'min:1'],
            'group_ids.*'     => ['uuid'],
            'subject_ids'     => ['required', 'array', 'min:1'],
            'subject_ids.*'   => ['uuid'],
        ]);

        // El destinatario tiene que ser docente y de esta institución. User no
        // es TenantScoped, así que el institution_id va explícito.
        $docente = User::where('id', $data['teacher_user_id'])
            ->where('institution_id', app('tenant_id'))
            ->where('user_type', UserType::Teacher->value)
            ->first();

        if (!$docente) {
            return response()->json([
                'message' => 'El usuario indicado no existe en esta institución o no es docente.',
            ], 422);
        }

        // Group y Subject sí son TenantScoped: un id de otra institución no
        // resuelve y se queda fuera del pluck.
        // Se comprueban contra los catálogos en caché del centro (sin consultas a la base).
        $centro   = $request->user()->institution_id;
        $catGrupos   = \App\Support\CatalogoGrupos::delCentro($centro);
        $catMaterias = \App\Support\CatalogoMaterias::delCentro($centro);
        $grupos   = array_values(array_filter(array_unique($data['group_ids']), fn ($id) => $catGrupos->has($id)));
        $materias = array_values(array_filter(array_unique($data['subject_ids']), fn ($id) => $catMaterias->has($id)));

        if (empty($grupos) || empty($materias)) {
            return response()->json([
                'message' => 'Ningún grupo o materia válido en esta institución.',
            ], 422);
        }

        $ahora = now();
        $filas = [];

        foreach ($grupos as $grupoId) {
            foreach ($materias as $materiaId) {
                $filas[] = [
                    'id'              => (string) \Illuminate\Support\Str::uuid(),
                    'institution_id'  => app('tenant_id'),
                    'teacher_user_id' => $docente->id,
                    'group_id'        => $grupoId,
                    'subject_id'      => $materiaId,
                    'assigned_at'     => $ahora,
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ];
            }
        }

        // DO NOTHING en conflicto: conserva el assigned_at de la asignación
        // original en lugar de reiniciarlo en cada llamada.
        DB::table('teacher_assignments')->insertOrIgnore($filas);
        TenantCache::invalidar($request->user()->institution_id, TenantCache::CATALOGO); // sin eventos de Eloquent

        return response()->json([
            'message' => 'Asignaciones aplicadas.',
            'data'    => [
                'teacher_user_id' => $docente->id,
                'grupos'          => count($grupos),
                'materias'        => count($materias),
                'filas_enviadas'  => count($filas),
                // Aula y materia unidas en la misma consulta (`RelacionesEnLinea`), no dos más.
                'asignaciones'    => $this->asignacionesDe($docente->id),
            ],
        ], 201);
    }

    /**
     * Las asignaciones de un docente con su aula (`id`, `name`, `grade`, `section`) y su materia
     * (`id`, `name`), en UNA consulta.
     */
    private function asignacionesDe(string $docenteId)
    {
        $filas = RelacionesEnLinea::unir(TeacherAssignment::query()->where('teacher_assignments.teacher_user_id', $docenteId), [
            'group'   => ['groups', 'group_id', ['id', 'name', 'grade', 'section']],
            'subject' => ['subjects', 'subject_id', ['id', 'name']],
        ])->get();
        RelacionesEnLinea::hidratar($filas, ['group' => Group::class, 'subject' => Subject::class]);

        return $filas;
    }

    /**
     * DELETE /api/teacher-assignments/{teacherAssignment}
     *
     * Borrado físico: la asignación no es un histórico, es un permiso vigente.
     * Retirarla corta el acceso del docente a ese grupo de inmediato.
     */
    public function destroy(Request $request, TeacherAssignment $teacherAssignment): JsonResponse
    {
        if ($denegado = $this->denyIfNotAdmin($request->user())) {
            return $denegado;
        }

        $teacherAssignment->delete();

        return response()->json(['message' => 'Asignación retirada.']);
    }

    /**
     * DELETE /api/teacher-assignments
     * body: { "teacher_user_id": "uuid", "group_id": "uuid" (opcional) }
     *
     * Retira de golpe todas las materias de un docente en un grupo, o todas sus
     * asignaciones si no se indica grupo. Es lo que hace falta cuando alguien
     * deja el centro.
     */
    public function destroyBulk(Request $request): JsonResponse
    {
        if ($denegado = $this->denyIfNotAdmin($request->user())) {
            return $denegado;
        }

        $data = $request->validate([
            'teacher_user_id' => ['required', 'uuid'],
            'group_id'        => ['nullable', 'uuid'],
        ]);

        $query = TeacherAssignment::where('teacher_user_id', $data['teacher_user_id']);

        if (!empty($data['group_id'])) {
            $query->where('group_id', $data['group_id']);
        }

        $retiradas = $query->delete();
        TenantCache::invalidar($request->user()->institution_id, TenantCache::CATALOGO); // el delete del builder no dispara eventos

        return response()->json([
            'message' => 'Asignaciones retiradas.',
            'data'    => ['retiradas' => $retiradas],
        ]);
    }

    private function denyIfNotAdmin(?object $user): ?JsonResponse
    {
        if (!$user || $user->user_type !== UserType::Admin) {
            return response()->json([
                'message' => 'Solo un administrador puede gestionar asignaciones de docentes.',
            ], 403);
        }

        return null;
    }
}
