<?php

namespace App\Http\Controllers;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ExamController extends Controller
{
    /**
     * Listar exámenes (filtrado por tenant vía TenantScoped)
     */
    public function index(Request $request)
    {
        $query = Exam::query()
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

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Crear examen
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:150'],
            'subject_id' => ['required', 'uuid'],
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

        // Asignar grupos objetivo (validando que pertenecen al tenant por TenantScoped en Group)
        if (!empty($data['group_ids'])) {
            $groups = Group::whereIn('id', $data['group_ids'])->pluck('id')->all();
            $exam->groups()->sync($groups);
        }

        return response()->json([
            'data' => $exam->load(['subject', 'teacher', 'groups']),
        ], 201);
    }

    /**
     * Ver examen
     */
    public function show(Exam $exam)
    {
        return response()->json([
            'data' => $exam->load(['subject', 'teacher', 'groups', 'questions.options']),
        ]);
    }

    /**
     * Actualizar examen (solo si está en draft o published)
     */
    public function update(Request $request, Exam $exam)
    {
        if (!in_array($exam->status->value, [ExamStatus::Draft->value, ExamStatus::Published->value], true)) {
            return response()->json([
                'message' => 'No se puede editar un examen activo o completado',
            ], 409);
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:3', 'max:150'],
            'subject_id' => ['sometimes', 'uuid'],
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

        $exam->fill($data);
        $exam->save();

        if (array_key_exists('group_ids', $data)) {
            $groups = empty($data['group_ids'])
                ? []
                : Group::whereIn('id', $data['group_ids'])->pluck('id')->all();

            $exam->groups()->sync($groups);
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

        $exam->status = $next;
        $exam->save();

        return response()->json([
            'data' => $exam,
        ]);
    }

    /**
     * Eliminar examen (solo draft)
     */
    public function destroy(Exam $exam)
    {
        if ($exam->status->value !== ExamStatus::Draft->value) {
            return response()->json([
                'message' => 'Solo se pueden eliminar exámenes en estado draft',
            ], 409);
        }

        $exam->delete();

        return response()->json([
            'message' => 'Examen eliminado',
        ]);
    }
}