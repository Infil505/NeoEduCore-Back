<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use App\Models\AI\AiChatSession;
use App\Models\Academic\Subject;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Services\AI\AiTutorService;
use App\Services\AI\CuotaDelTutor;
use App\Services\AI\FormatoPorEstilo;
use Illuminate\Http\Request;

class AiTutorController extends Controller
{
    /** @return \Illuminate\Support\Collection<int,string> ids de las materias que lleva el estudiante */
    private function materiasDelEstudiante(string $studentUserId, string $institutionId): \Illuminate\Support\Collection
    {
        // Corta: se pregunta en cada mensaje del chat y la lista casi no cambia.
        $ids = \Illuminate\Support\Facades\Cache::remember(
            "ai:tutor:materias:{$studentUserId}",
            60,
            fn () => app(\App\Services\Students\StudentSubjectsService::class)
                ->materias($studentUserId, $institutionId)->pluck('subject_id')->all()
        );

        return collect($ids);
    }

    /**
     * GET /api/ai/tutor/quota — cuántas consultas le quedan hoy al estudiante. Solo lee la caché.
     */
    public function quota(Request $request, CuotaDelTutor $cuota)
    {
        return response()->json(['data' => $cuota->estado($request->user()->id)]);
    }

    public function chat(Request $request, AiTutorService $tutorService, CuotaDelTutor $cuota)
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

        // La materia debe ser una de las QUE LLEVA el estudiante (su sección + las inscritas a mano):
        // es lo que ofrece el selector. Eso ya implica que existe y es de su institución.
        if (!empty($data['subject_id'])
            && !$this->materiasDelEstudiante($user->id, $user->institution_id)->contains($data['subject_id'])) {
            return response()->json(['message' => 'Materia no encontrada'], 422);
        }

        if (!empty($data['exam_id']) && !$this->examenDelEstudiante($data['exam_id'], $user)) {
            return response()->json(['message' => 'Examen no encontrado'], 422);
        }

        // El tope diario. Va DESPUÉS de las validaciones (un 422 no gasta consulta) y ANTES de tocar el modelo.
        // Se apunta de antemano para que dos mensajes a la vez no se cuelen; si no llega al modelo, se devuelve.
        if (!$cuota->reservar($user->id)) {
            $estado = $cuota->estado($user->id);

            return response()->json([
                'message' => "Ya usaste tus {$estado['limit']} consultas de hoy con el tutor. Se renuevan mañana; mientras tanto puedes repasar tus recursos y tus resultados.",
                'quota'   => $estado,
            ], 429);
        }

        $argumentos = [
            'studentUserId' => $user->id,
            'message'       => $data['message'],
            'sessionId'     => $data['session_id'] ?? null,
            // null = no vino (se deja la de la sesión); «» = vino vacía: «cualquier materia».
            'subjectId'     => $request->has('subject_id') ? ($data['subject_id'] ?? '') : null,
            'mode'          => $data['mode'] ?? 'ask',
            'topic'         => $data['topic'] ?? null,
            'examId'        => $data['exam_id'] ?? null,
        ];

        $asincrono = (bool) ($data['async'] ?? false);

        try {
            $result = $asincrono
                ? $tutorService->chatAsincrono(...$argumentos)
                : $tutorService->chat(...$argumentos);
        } catch (\Throwable $e) {
            $cuota->devolver($user->id);

            throw $e;
        }

        // Lo que no llegó al modelo (otro mensaje aún en curso, intento de inyección, respuesta de reserva)
        // no gasta consulta.
        if ($result === null || ($result['counted'] ?? true) === false) {
            $cuota->devolver($user->id);
        }

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

        // Lo que le queda hoy, para que la pantalla lo muestre sin otra petición.
        unset($result['counted']);
        $result['quota'] = $cuota->estado($user->id);

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

        $student = Student::conUsuario($user->id);
        if (!$student) {
            return response()->json(['message' => 'Solo estudiantes pueden ver el diagnóstico IA'], 403);
        }

        $text = $tutorService->getDiagnosis($user->id, $student);

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
            ->orderBy('updated_at', 'desc');

        $sessions = $this->paginar($sessions, $request);

        return response()->json(['data' => $sessions]);
    }
}
