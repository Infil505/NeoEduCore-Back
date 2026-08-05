<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AI\AiChatSession;
use App\Models\Academic\Subject;
use App\Models\Students\Student;
use App\Services\AI\AiTutorService;
use Illuminate\Http\Request;

class AiTutorController extends Controller
{
    public function chat(Request $request, AiTutorService $tutorService)
    {
        $user = $request->user();

        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json(['message' => 'Solo estudiantes pueden usar el tutor IA'], 403);
        }

        $data = $request->validate([
            'message'    => ['required', 'string', 'min:1', 'max:2000'],
            'session_id' => ['nullable', 'uuid'],
            'subject_id' => ['nullable', 'uuid'],
            'mode'       => ['nullable', 'string', 'in:ask,explain,practice'],
            'topic'      => ['nullable', 'string', 'max:200'],
        ]);

        // La materia debe existir y pertenecer a la institución del estudiante.
        // Subject es TenantScoped, así que exists() ya filtra por tenant y evita
        // guardar en la sesión una referencia cruzada a otra institución.
        if (!empty($data['subject_id']) && !Subject::whereKey($data['subject_id'])->exists()) {
            return response()->json(['message' => 'Materia no encontrada'], 422);
        }

        $result = $tutorService->chat(
            studentUserId: $user->id,
            message:       $data['message'],
            sessionId:     $data['session_id'] ?? null,
            subjectId:     $data['subject_id'] ?? null,
            mode:          $data['mode'] ?? 'ask',
            topic:         $data['topic'] ?? null
        );

        return response()->json(['data' => $result]);
    }

    public function endSession(Request $request, string $sessionId, AiTutorService $tutorService)
    {
        $user = $request->user();

        $ended = $tutorService->endSession($user->id, $sessionId);

        if (!$ended) {
            return response()->json(['message' => 'Sesión no encontrada o ya finalizada'], 404);
        }

        return response()->json(['message' => 'Sesión finalizada']);
    }

    public function diagnosis(Request $request, AiTutorService $tutorService)
    {
        $user = $request->user();

        $student = Student::where('user_id', $user->id)->first();
        if (!$student) {
            return response()->json(['message' => 'Solo estudiantes pueden ver el diagnóstico IA'], 403);
        }

        $text = $tutorService->getDiagnosis($user->id);

        return response()->json(['data' => ['diagnosis' => $text]]);
    }

    public function sessions(Request $request)
    {
        $user = $request->user();

        // Excluye 'messages' (JSONB potencialmente grande) del listado de sesiones.
        $sessions = AiChatSession::select('id', 'student_user_id', 'subject_id', 'exam_id', 'ended_at', 'created_at', 'updated_at')
            ->where('student_user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->paginate(10);

        return response()->json(['data' => $sessions]);
    }
}
