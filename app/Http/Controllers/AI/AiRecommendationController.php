<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AI\AiRecommendation;
use App\Models\Students\Student;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiRecommendationController extends Controller
{
    /**
     * Listar recomendaciones
     * - Admin/Teacher: puede filtrar por student_user_id
     * - Student: solo ve las propias
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'student_user_id'   => ['nullable', 'uuid'],
            'subject_id'        => ['nullable', 'uuid'],
            'exam_id'           => ['nullable', 'uuid'],
            'recommendation_type' => ['nullable', Rule::in(['strength', 'weakness', 'resource', 'action'])],
        ]);

        $query = AiRecommendation::query()
            ->with(['student.user', 'subject', 'exam'])
            ->orderByDesc('created_at');

        // 👩‍🎓 Estudiante: solo sus recomendaciones
        if ($user->user_type->value === 'student') {
            $query->where('student_user_id', $user->id);
        }

        // 👨‍🏫 Admin / Teacher
        if (!empty($data['student_user_id'])) {
            $query->where('student_user_id', $data['student_user_id']);
        }

        if (!empty($data['subject_id'])) {
            $query->where('subject_id', $data['subject_id']);
        }

        if (!empty($data['exam_id'])) {
            $query->where('exam_id', $data['exam_id']);
        }

        if (!empty($data['recommendation_type'])) {
            $query->where('recommendation_type', $data['recommendation_type']);
        }

        return response()->json([
            'data' => $query->paginate(20),
        ]);
    }

    /**
     * Ver una recomendación específica
     */
    public function show(AiRecommendation $aiRecommendation, Request $request)
    {
        $user = $request->user();

        // Estudiante: solo puede ver las suyas
        if ($user->user_type->value === 'student' && $aiRecommendation->student_user_id !== $user->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        // Teacher: solo puede ver recomendaciones vinculadas a sus propios exámenes
        if ($user->user_type->value === 'teacher') {
            $aiRecommendation->loadMissing('exam');
            if ($aiRecommendation->exam === null || $aiRecommendation->exam->created_by_teacher_id !== $user->id) {
                return response()->json(['message' => 'No autorizado'], 403);
            }
        }

        return response()->json([
            'data' => $aiRecommendation->load(['student.user', 'subject', 'exam']),
        ]);
    }

    /**
     * Recomendaciones del estudiante autenticado (atajo)
     */
    public function myRecommendations(Request $request)
    {
        $user = $request->user();

        // Confirmar que tenga perfil de estudiante
        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json([
                'message' => 'Este usuario no tiene perfil de estudiante',
            ], 404);
        }

        $data = $request->validate([
            'subject_id'          => ['nullable', 'uuid'],
            'recommendation_type' => ['nullable', Rule::in(['strength', 'weakness', 'resource', 'action'])],
        ]);

        $query = AiRecommendation::query()
            ->with(['subject', 'exam'])
            ->where('student_user_id', $user->id)
            ->orderByDesc('created_at');

        if (!empty($data['subject_id'])) {
            $query->where('subject_id', $data['subject_id']);
        }

        if (!empty($data['recommendation_type'])) {
            $query->where('recommendation_type', $data['recommendation_type']);
        }

        return response()->json([
            'data' => $query->paginate(15),
        ]);
    }
}
