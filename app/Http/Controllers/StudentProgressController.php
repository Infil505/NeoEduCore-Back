<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\StudentProgress;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentProgressController extends Controller
{
    /**
     * Listar progreso
     * - Admin/Teacher: puede filtrar por student_user_id y subject_id
     * - Student: solo ve su propio progreso
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'student_user_id' => ['nullable', 'uuid'],
            'subject_id'      => ['nullable', 'uuid'],
        ]);

        $query = StudentProgress::query()
            ->with(['student.user', 'subject'])
            ->orderByDesc('updated_at');

        // Estudiante: solo lo propio
        if ($user->user_type->value === 'student') {
            $query->where('student_user_id', $user->id);
        } else {
            if (!empty($data['student_user_id'])) {
                $query->where('student_user_id', $data['student_user_id']);
            }
        }

        if (!empty($data['subject_id'])) {
            $query->where('subject_id', $data['subject_id']);
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Ver progreso del estudiante autenticado (atajo)
     */
    public function me(Request $request)
    {
        $user = $request->user();

        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        $progress = StudentProgress::query()
            ->with('subject')
            ->where('student_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $progress,
        ]);
    }

    /**
     * Recalcular progreso (manual o por acción del docente)
     * body:
     * {
     *   "student_user_id": "uuid",
     *   "subject_id": "uuid",
     *   "mastery_percentage": 0-100
     * }
     *
     * Nota: en un sistema real, esto se calcula desde intentos y resultados.
     * Aquí dejamos endpoint para actualizar/recalcular según tu lógica.
     */
    public function upsert(Request $request)
    {
        $data = $request->validate([
            'student_user_id' => ['required', 'uuid'],
            'subject_id'      => ['required', 'uuid'],
            'mastery_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        // Verificar que existan (y pertenezcan al tenant) vía scopes
        Student::where('user_id', $data['student_user_id'])->firstOrFail();
        Subject::where('id', $data['subject_id'])->firstOrFail();

        $progress = StudentProgress::updateOrCreate(
            [
                'student_user_id' => $data['student_user_id'],
                'subject_id' => $data['subject_id'],
            ],
            [
                'mastery_percentage' => round((float)$data['mastery_percentage'], 2),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'data' => $progress->load(['student.user', 'subject']),
        ], 201);
    }

    /**
     * Recalcular progreso automáticamente desde intentos (opcional)
     * - Si luego querés, aquí se calcula usando ExamAttempt y StudentAnswer.
     */
    public function recalcFromAttempts(Request $request)
    {
        $data = $request->validate([
            'student_user_id' => ['required', 'uuid'],
            'subject_id'      => ['required', 'uuid'],
        ]);

        // Placeholder: aquí va tu lógica real con promedios por materia
        // Por ahora dejamos un ejemplo simple que mantiene el valor actual
        $progress = StudentProgress::where('student_user_id', $data['student_user_id'])
            ->where('subject_id', $data['subject_id'])
            ->first();

        if (!$progress) {
            return response()->json([
                'message' => 'No existe progreso para recalcular',
            ], 404);
        }

        $progress->updated_at = now();
        $progress->save();

        return response()->json([
            'data' => $progress->fresh()->load('subject'),
        ]);
    }
}