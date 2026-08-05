<?php

namespace App\Http\Controllers\Academic;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Academic\Group;
use App\Models\Students\Student;
use App\Services\Academic\BulkReassignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reasignación masiva de estudiantes (grupo y materias).
 *
 * Pensado para la promoción de fin de año y para correcciones de matrícula
 * en bloque, donde hacerlo estudiante por estudiante es inviable.
 */
class BulkReassignmentController extends Controller
{
    public function __construct(private BulkReassignmentService $service)
    {
    }

    /**
     * POST /api/bulk/reassign-group
     *
     * body: {
     *   "student_user_ids": ["uuid", ...]   // uno u otro, no ambos
     *   "from_group_id": "uuid",
     *   "to_group_id": "uuid",
     *   "sync_student_fields": true          // opcional, default true
     * }
     */
    public function reassignGroup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyIfNotAdmin($user)) {
            return $denied;
        }

        $data = $request->validate([
            'student_user_ids'   => ['required_without:from_group_id', 'prohibits:from_group_id', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
            'from_group_id'      => ['required_without:student_user_ids', 'uuid'],
            'to_group_id'        => ['required', 'uuid'],
            'sync_student_fields' => ['nullable', 'boolean'],
        ]);

        // Group está TenantScoped: un id de otra institución no resuelve.
        $destino = Group::find($data['to_group_id']);

        if (!$destino) {
            return response()->json([
                'message' => 'El grupo destino no existe en esta institución.',
            ], 404);
        }

        $studentIds = $this->resolverEstudiantes($data, $destino);

        if ($studentIds === null) {
            return response()->json([
                'message' => 'El grupo origen no existe en esta institución.',
            ], 404);
        }

        if (empty($studentIds)) {
            return response()->json([
                'message' => 'No hay estudiantes que reasignar.',
                'data'    => [
                    'requested' => 0,
                    'moved'     => 0,
                ],
            ], 422);
        }

        $resumen = $this->service->reassignGroup(
            $user->institution_id,
            $studentIds,
            $destino,
            $data['sync_student_fields'] ?? true
        );

        return response()->json([
            'message' => 'Reasignación de grupo aplicada.',
            'data'    => $resumen,
        ]);
    }

    /**
     * POST /api/bulk/reassign-subjects
     *
     * body: {
     *   "student_user_ids": ["uuid", ...]   // uno u otro, no ambos
     *   "from_group_id": "uuid",
     *   "subject_ids": ["uuid", ...],
     *   "mode": "replace" | "add" | "remove"
     * }
     */
    public function reassignSubjects(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyIfNotAdmin($user)) {
            return $denied;
        }

        $data = $request->validate([
            'student_user_ids'   => ['required_without:from_group_id', 'prohibits:from_group_id', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
            'from_group_id'      => ['required_without:student_user_ids', 'uuid'],
            'subject_ids'        => ['present', 'array'],
            'subject_ids.*'      => ['uuid'],
            'mode'               => ['required', Rule::in(BulkReassignmentService::MODOS)],
        ]);

        // add/remove sin materias no tiene efecto; replace con lista vacía sí
        // (significa "dejarlo sin materias"), así que solo se exige en esos dos.
        if ($data['mode'] !== 'replace' && empty($data['subject_ids'])) {
            return response()->json([
                'message' => 'Indica al menos una materia para los modos add y remove.',
                'errors'  => ['subject_ids' => ['El campo subject_ids no puede estar vacío en este modo.']],
            ], 422);
        }

        $fuera = $this->service->subjectsFueraDelTenant($data['subject_ids']);

        if (!empty($fuera)) {
            return response()->json([
                'message' => 'Alguna materia no existe en esta institución.',
                'errors'  => ['subject_ids' => ['Materias no encontradas: ' . implode(', ', $fuera)]],
            ], 422);
        }

        $studentIds = $this->resolverEstudiantes($data);

        if ($studentIds === null) {
            return response()->json([
                'message' => 'El grupo origen no existe en esta institución.',
            ], 404);
        }

        if (empty($studentIds)) {
            return response()->json([
                'message' => 'No hay estudiantes que reasignar.',
                'data'    => ['requested' => 0],
            ], 422);
        }

        $resumen = $this->service->reassignSubjects(
            $user->institution_id,
            $studentIds,
            $data['subject_ids'],
            $data['mode']
        );

        return response()->json([
            'message' => 'Reasignación de materias aplicada.',
            'data'    => $resumen,
        ]);
    }

    /**
     * POST /api/bulk/reset-progress
     *
     * Reinicia el progreso de repitentes: pone el dominio a 0 y marca el corte
     * para que el recálculo desde intentos ignore el historial anterior.
     * Los intentos y respuestas NO se borran.
     *
     * body: {
     *   "from_group_id": "uuid",           // o "student_user_ids": ["uuid", ...]
     *   "subject_ids": ["uuid", ...]       // opcional; vacío = todas sus materias
     * }
     */
    public function resetProgress(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($denied = $this->denyIfNotAdmin($user)) {
            return $denied;
        }

        $data = $request->validate([
            'student_user_ids'   => ['required_without:from_group_id', 'prohibits:from_group_id', 'array', 'min:1'],
            'student_user_ids.*' => ['uuid'],
            'from_group_id'      => ['required_without:student_user_ids', 'uuid'],
            'subject_ids'        => ['nullable', 'array'],
            'subject_ids.*'      => ['uuid'],
        ]);

        $materias = $data['subject_ids'] ?? [];
        $fuera = $this->service->subjectsFueraDelTenant($materias);

        if (!empty($fuera)) {
            return response()->json([
                'message' => 'Alguna materia no existe en esta institución.',
                'errors'  => ['subject_ids' => ['Materias no encontradas: ' . implode(', ', $fuera)]],
            ], 422);
        }

        $studentIds = $this->resolverEstudiantes($data);

        if ($studentIds === null) {
            return response()->json([
                'message' => 'El grupo origen no existe en esta institución.',
            ], 404);
        }

        if (empty($studentIds)) {
            return response()->json([
                'message' => 'No hay estudiantes cuyo progreso resetear.',
                'data'    => ['requested' => 0],
            ], 422);
        }

        $resumen = $this->service->resetProgress($studentIds, $materias);

        return response()->json([
            'message' => 'Progreso reseteado.',
            'data'    => $resumen,
        ]);
    }

    /* =========================
     |  Helpers
     ========================= */

    /**
     * Resuelve el lote de estudiantes: lista explícita o los activos del grupo
     * origen. Devuelve null si el grupo origen no existe en el tenant.
     *
     * @return array<int,string>|null
     */
    private function resolverEstudiantes(array $data, ?Group $destino = null): ?array
    {
        if (!empty($data['student_user_ids'])) {
            return $data['student_user_ids'];
        }

        $origen = Group::find($data['from_group_id']);

        if (!$origen) {
            return null;
        }

        // Mover un grupo sobre sí mismo no tiene sentido: lote vacío.
        if ($destino && $origen->id === $destino->id) {
            return [];
        }

        return Student::query()
            ->whereIn('user_id', function ($q) use ($origen) {
                $q->select('student_user_id')
                    ->from('group_students')
                    ->where('group_id', $origen->id)
                    ->whereNull('left_at');
            })
            ->pluck('user_id')
            ->all();
    }

    /**
     * Operación masiva y difícil de deshacer (toca membresías, contadores y
     * campos denormalizados de cientos de filas): reservada al administrador.
     */
    private function denyIfNotAdmin(?object $user): ?JsonResponse
    {
        if (!$user || $user->user_type !== UserType::Admin) {
            return response()->json([
                'message' => 'Solo un administrador puede hacer reasignaciones masivas.',
            ], 403);
        }

        if (!$user->institution_id) {
            return response()->json([
                'message' => 'Usuario sin institución asignada.',
            ], 409);
        }

        return null;
    }
}
