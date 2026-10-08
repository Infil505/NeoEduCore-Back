<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AI\AiChatSession;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Services\AI\AiTutorService;
use App\Services\AI\FormatoPorEstilo;
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
            'exam_id'    => ['nullable', 'uuid'],
            'mode'       => ['nullable', 'string', 'in:ask,explain,practice'],
            'topic'      => ['nullable', 'string', 'max:200'],
            // O7: true → 202 y la respuesta llega por GET /ai/tutor/sessions/{id}.
            'async'      => ['nullable', 'boolean'],
        ]);

        // La materia debe existir y pertenecer a la institución del estudiante.
        // Subject es TenantScoped, así que exists() ya filtra por tenant y evita
        // guardar en la sesión una referencia cruzada a otra institución.
        if (!empty($data['subject_id']) && !Subject::whereKey($data['subject_id'])->exists()) {
            return response()->json(['message' => 'Materia no encontrada'], 422);
        }

        if (!empty($data['exam_id']) && !$this->examenDelEstudiante($data['exam_id'], $user)) {
            return response()->json(['message' => 'Examen no encontrado'], 422);
        }

        $argumentos = [
            'studentUserId' => $user->id,
            'message'       => $data['message'],
            'sessionId'     => $data['session_id'] ?? null,
            'subjectId'     => $data['subject_id'] ?? null,
            'mode'          => $data['mode'] ?? 'ask',
            'topic'         => $data['topic'] ?? null,
            'examId'        => $data['exam_id'] ?? null,
        ];

        $asincrono = (bool) ($data['async'] ?? false);

        $result = $asincrono
            ? $tutorService->chatAsincrono(...$argumentos)
            : $tutorService->chat(...$argumentos);

        if ($result === null) {
            return response()->json([
                'message' => 'Todavía estoy respondiendo tu mensaje anterior. Espera un momento.',
            ], 409);
        }

        // O2: el estilo del alumno, para que el frontend sepa cómo presentar la
        // respuesta (p. ej. leerla en voz alta con `auditivo`). Sale del perfil
        // ya cargado arriba: no cuesta una consulta.
        $formato = app(FormatoPorEstilo::class);
        $result['presentation'] = $formato->presentacion($student->learning_style);

        // El vídeo que el docente puso en el examen del que se está hablando:
        // el de esta petición o, si no vino, el que la sesión ya tenía fijado.
        // Solo se busca si el estilo lo recibe, para no gastar consultas.
        $result['video'] = null;
        if ($formato->recibeVideo($student->learning_style)) {
            $examId = $data['exam_id']
                ?? AiChatSession::whereKey($result['session_id'])->value('exam_id');

            $result['video'] = $formato->videoPara(
                $student->learning_style,
                $examId ? Exam::find($examId) : null,
                $student
            );
        }

        // 202 solo si de verdad quedó en la cola; un intento de inyección se
        // contesta al momento aunque se pidiera async.
        $codigo = ($result['status'] ?? null) === 'pending' ? 202 : 200;

        return response()->json(['data' => $result], $codigo);
    }

    /**
     * GET /api/ai/tutor/sessions/{id} — una sesión con sus mensajes (O7).
     *
     * Es donde el modo asíncrono recoge la respuesta: `awaiting_reply` dice si
     * sigue en camino. Solo las sesiones propias; una ajena da 404, igual que
     * una inexistente.
     */
    public function showSession(Request $request, string $sessionId)
    {
        $session = AiChatSession::where('id', $sessionId)
            ->where('student_user_id', $request->user()->id)
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Sesión no encontrada'], 404);
        }

        $stale = now()->subSeconds((int) config('openai.tutor.async_stale_seconds'));

        return response()->json(['data' => [
            'id'                   => $session->id,
            'subject_id'           => $session->subject_id,
            'exam_id'              => $session->exam_id,
            'ended_at'             => $session->ended_at,
            'messages'             => $session->messages ?? [],
            'message_count'        => count($session->messages ?? []),
            // Una marca más vieja que el umbral es un job perdido: no se le
            // dice al frontend que siga esperando para siempre.
            'awaiting_reply'       => $session->awaiting_reply_since !== null
                && $session->awaiting_reply_since->gt($stale),
            'awaiting_reply_since' => $session->awaiting_reply_since,
            'ai_notice'            => (string) config('openai.tutor.notice'),
        ]]);
    }

    /**
     * ¿Puede este alumno abrir una conversación sobre este examen?
     *
     * No sirve `scopeVisibleTo` a secas: acota a exámenes **activos y dentro de
     * ventana**, que es justo lo que un examen ya presentado deja de ser — y
     * consultar al tutor sobre la prueba que uno acaba de entregar es el caso de
     * uso principal. Vale, por tanto, si lo ha rendido **o** si hoy lo tiene
     * disponible. Las dos consultas son `TenantScoped`, así que un examen de otra
     * institución no entra por ninguna de las dos vías.
     */
    private function examenDelEstudiante(string $examId, object $user): bool
    {
        return Exam::whereKey($examId)->visibleTo($user)->exists()
            || ExamAttempt::where('exam_id', $examId)
                ->where('student_user_id', $user->id)
                ->exists();
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

        return response()->json(['data' => [
            'diagnosis' => $text,
            // El diagnóstico también lo redacta el modelo, así que lleva el
            // mismo aviso que el chat ([397], D4).
            'ai_notice' => (string) config('openai.tutor.notice'),
            'presentation' => app(FormatoPorEstilo::class)->presentacion($student->learning_style),
        ]]);
    }

    public function sessions(Request $request)
    {
        $user = $request->user();

        // Excluye 'messages' (JSONB potencialmente grande) del listado de sesiones.
        $sessions = AiChatSession::select('id', 'student_user_id', 'subject_id', 'exam_id', 'ended_at', 'created_at', 'updated_at')
            ->where('student_user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->paginate(config('pagination.default'));

        return response()->json(['data' => $sessions]);
    }
}
