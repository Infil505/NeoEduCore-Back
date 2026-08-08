<?php

namespace App\Http\Controllers\Exams;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AcotaAlDocente;
use App\Http\Controllers\Concerns\AcotaExamenAlEstudiante;
use App\Http\Controllers\Concerns\RevelaRespuestas;
use App\Enums\ExamStatus;
use App\Models\Exams\Exam;
use App\Models\Academic\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    use RevelaRespuestas, AcotaExamenAlEstudiante, AcotaAlDocente;

    /**
     * Resuelve los grupos destino comprobando que el docente los tenga
     * asignados **en la materia del examen**.
     *
     * Devuelve un array de ids válidos, o una respuesta 403 si alguno queda
     * fuera de su asignación. El fallo es explícito y nombra el grupo: antes
     * los ids no válidos se descartaban en silencio y el docente creía haber
     * publicado un examen que no llegaba a nadie.
     *
     * Para admin no hay restricción de asignación, solo la de tenant.
     *
     * @return array<int,string>|\Illuminate\Http\JsonResponse
     */
    private function resolverGruposDestino(array $groupIds, string $subjectId, object $user)
    {
        // TenantScoped en Group descarta los de otra institución.
        $grupos = Group::whereIn('id', $groupIds)->pluck('id')->all();

        $fuera = array_diff($groupIds, $grupos);

        if (!$this->esDocente($user)) {
            if (!empty($fuera)) {
                return response()->json([
                    'message' => 'Algún grupo no existe en esta institución.',
                    'grupos_invalidos' => array_values($fuera),
                ], 422);
            }

            return $grupos;
        }

        $noAsignados = [];

        foreach ($grupos as $grupoId) {
            if (!$this->docenteAlcanzaGrupoEnMateria($user, $grupoId, $subjectId)) {
                $noAsignados[] = $grupoId;
            }
        }

        $noAsignados = array_merge($noAsignados, array_values($fuera));

        if (!empty($noAsignados)) {
            $nombres = Group::whereIn('id', $noAsignados)->pluck('name')->all();

            return response()->json([
                'message' => 'No estás asignado a ' . (empty($nombres)
                    ? 'alguno de los grupos indicados'
                    : implode(', ', $nombres)) . ' en esta materia.',
                'grupos_no_asignados' => $noAsignados,
            ], 403);
        }

        return $grupos;
    }

    /**
     * Listar exámenes (filtrado por tenant vía TenantScoped)
     */
    public function index(Request $request)
    {
        $query = Exam::query()
            // Al estudiante solo los suyos: activos, vigentes y asignados a sus
            // grupos. Antes devolvía el catálogo entero con sus ids, que era el
            // primer paso para leer enunciados ajenos vía `show()`.
            ->visibleTo($request->user())
            ->with(['subject', 'teacher'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->string('subject_id')->toString());
        }

        if ($request->filled('grade')) {
            $query->where('grade', (int) $request->input('grade'));
        }

        $paginator = $query->paginate(20);

        $this->acotarExamenes($request->user(), $paginator->getCollection());

        return response()->json([
            'data' => $paginator,
        ]);
    }

    /**
     * Crear examen
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'subject_id' => ['required', 'uuid', Rule::exists('subjects', 'id')],
            'grade' => ['required', 'integer', 'between:7,12'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'between:1,300'],

            // Config avanzada RN-EXAM-034/035
            'max_attempts' => ['nullable', 'integer', 'between:1,10'],
            'show_results_immediately' => ['nullable', 'boolean'],
            'allow_review_after_submission' => ['nullable', 'boolean'],
            'randomize_questions' => ['nullable', 'boolean'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],

            // grupos objetivo (opcional)
            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['uuid'],
        ]);

        $user = $request->user();

        // Se valida ANTES de crear: si el docente apunta a un grupo que no
        // tiene asignado, la petición se rechaza entera y no queda un examen
        // huérfano en draft.
        $grupos = [];

        if (!empty($data['group_ids'])) {
            $grupos = $this->resolverGruposDestino($data['group_ids'], $data['subject_id'], $user);

            if ($grupos instanceof \Illuminate\Http\JsonResponse) {
                return $grupos;
            }
        }

        $exam = Exam::create([
            'created_by_teacher_id' => $user->id,

            'title' => trim($data['title']),
            'subject_id' => $data['subject_id'],
            'grade' => (int) $data['grade'],
            'instructions' => $data['instructions'] ?? null,
            'duration_minutes' => (int) $data['duration_minutes'],

            'status' => ExamStatus::Draft->value,

            'max_attempts' => $data['max_attempts'] ?? 3,
            'show_results_immediately' => $data['show_results_immediately'] ?? true,
            'allow_review_after_submission' => $data['allow_review_after_submission'] ?? true,
            'randomize_questions' => $data['randomize_questions'] ?? false,

            'available_from' => $data['available_from'] ?? null,
            'available_until' => $data['available_until'] ?? null,
        ]);

        if (!empty($grupos)) {
            $exam->syncGroups($grupos);
        }

        return response()->json([
            'data' => $exam->load(['subject', 'teacher', 'groups']),
        ], 201);
    }

    /**
     * Ver examen
     */
    public function show(Exam $exam, Request $request)
    {
        // El binding de ruta resuelve el examen sin mirar quién pregunta, así
        // que la visibilidad se comprueba aquí. 404 y no 403: confirmar que el
        // examen existe ya le diría al alumno que hay una prueba preparada.
        if (!Exam::query()->whereKey($exam->getKey())->visibleTo($request->user())->exists()) {
            return response()->json(['message' => 'No encontrado'], 404);
        }

        $exam->load(['subject', 'teacher', 'groups', 'questions.options']);

        // Esta ruta es de lectura compartida (admin, teacher y student): sin
        // esto, un alumno vería `is_correct` de cada opción antes de entregar.
        $this->revelarRespuestas($request->user(), $exam->questions);
        $this->acotarExamen($request->user(), $exam);

        return response()->json([
            'data' => $exam,
        ]);
    }

    /**
     * Actualizar examen (solo si está en draft o published)
     */
    public function update(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (!in_array($exam->status->value, [ExamStatus::Draft->value, ExamStatus::Published->value], true)) {
            return response()->json([
                'message' => 'No se puede editar un examen activo o completado',
            ], 409);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'subject_id' => ['sometimes', 'uuid', Rule::exists('subjects', 'id')],
            'grade' => ['sometimes', 'integer', 'between:7,12'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['sometimes', 'integer', 'between:1,300'],

            'max_attempts' => ['sometimes', 'integer', 'between:1,10'],
            'show_results_immediately' => ['sometimes', 'boolean'],
            'allow_review_after_submission' => ['sometimes', 'boolean'],
            'randomize_questions' => ['sometimes', 'boolean'],

            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', 'after_or_equal:available_from'],

            'group_ids' => ['nullable', 'array'],
            'group_ids.*' => ['uuid'],
        ]);

        // La materia puede venir cambiada en la misma petición; la asignación se
        // comprueba contra la que va a quedar, no contra la que había.
        $materiaFinal = $data['subject_id'] ?? $exam->subject_id;
        $grupos = null;

        if (array_key_exists('group_ids', $data)) {
            $grupos = empty($data['group_ids'])
                ? []
                : $this->resolverGruposDestino($data['group_ids'], $materiaFinal, $user);

            if ($grupos instanceof \Illuminate\Http\JsonResponse) {
                return $grupos;
            }
        }

        $exam->fill($data);
        $exam->save();

        if ($grupos !== null) {
            $exam->syncGroups($grupos);
        }

        return response()->json([
            'data' => $exam->load(['subject', 'teacher', 'groups']),
        ]);
    }

    /**
     * Cambiar estado (draft -> published -> active -> completed)
     */
    public function setStatus(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([
                ExamStatus::Draft->value,
                ExamStatus::Published->value,
                ExamStatus::Active->value,
                ExamStatus::Completed->value,
            ])],
        ]);

        // Reglas mínimas de transición (puedes endurecer si quieres)
        $current = $exam->status->value;
        $next = $data['status'];

        $allowed = [
            ExamStatus::Draft->value => [ExamStatus::Published->value],
            ExamStatus::Published->value => [ExamStatus::Active->value, ExamStatus::Draft->value],
            ExamStatus::Active->value => [ExamStatus::Completed->value],
            ExamStatus::Completed->value => [],
        ];

        if (!in_array($next, $allowed[$current], true)) {
            return response()->json([
                'message' => "Transición inválida: {$current} -> {$next}",
            ], 409);
        }

        // No publicar si no tiene preguntas
        if ($next === ExamStatus::Published->value && $exam->questions()->count() === 0) {
            return response()->json([
                'message' => 'No se puede publicar un examen sin preguntas',
            ], 409);
        }

        // No activar si la ventana ya expiró
        if ($next === ExamStatus::Active->value) {
            if ($exam->available_until && now()->gt($exam->available_until)) {
                return response()->json([
                    'message' => 'No se puede activar: la ventana de disponibilidad ya expiró',
                ], 409);
            }
        }

        $exam->status = $next;
        $exam->save();

        return response()->json([
            'data' => $exam,
        ]);
    }

    /**
     * Eliminar examen (solo draft)
     */
    public function destroy(Request $request, Exam $exam)
    {
        $user = $request->user();
        if ($user->user_type->value === 'teacher' && $exam->created_by_teacher_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($exam->status->value !== ExamStatus::Draft->value) {
            return response()->json([
                'message' => 'Solo se pueden eliminar exámenes en estado draft',
            ], 409);
        }

        $exam->delete();

        return response()->noContent();
    }
}
