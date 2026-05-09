<?php

namespace App\Services\AI;

use App\Models\AI\AiChatSession;
use App\Models\Students\Student;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class AiTutorService
{
    private const MAX_HISTORY_MESSAGES = 20;
    private const MAX_TOKENS = 600;
    // Máximo de mensajes almacenados en JSONB — evita crecimiento ilimitado del campo
    private const MAX_STORED_MESSAGES = 60;

    public function chat(
        string $studentUserId,
        string $message,
        ?string $sessionId = null,
        ?string $subjectId = null
    ): array {
        $student = Student::with(['user', 'progress.subject'])
            ->where('user_id', $studentUserId)
            ->firstOrFail();

        $session = $this->resolveSession($studentUserId, $sessionId, $subjectId);

        $history = collect($session->messages ?? [])
            ->takeLast(self::MAX_HISTORY_MESSAGES)
            ->values()
            ->all();

        $history[] = ['role' => 'user', 'content' => $message];

        $reply = $this->callOpenAi($student, $history);

        $now = now()->toISOString();
        $allMessages   = $session->messages ?? [];
        $allMessages[] = ['role' => 'user',      'content' => $message, 'created_at' => $now];
        $allMessages[] = ['role' => 'assistant', 'content' => $reply,   'created_at' => now()->toISOString()];

        // Truncar mensajes antiguos para que el JSONB no crezca indefinidamente
        if (count($allMessages) > self::MAX_STORED_MESSAGES) {
            $allMessages = array_slice($allMessages, -self::MAX_STORED_MESSAGES);
        }

        $session->update(['messages' => $allMessages]);

        return [
            'session_id'    => $session->id,
            'reply'         => $reply,
            'message_count' => count($allMessages),
        ];
    }

    public function endSession(string $studentUserId, string $sessionId): bool
    {
        $session = AiChatSession::where('id', $sessionId)
            ->where('student_user_id', $studentUserId)
            ->whereNull('ended_at')
            ->first();

        if (!$session) {
            return false;
        }

        $session->update(['ended_at' => now()]);
        return true;
    }

    private function resolveSession(string $studentUserId, ?string $sessionId, ?string $subjectId): AiChatSession
    {
        if ($sessionId) {
            $session = AiChatSession::where('id', $sessionId)
                ->where('student_user_id', $studentUserId)
                ->whereNull('ended_at')
                ->first();

            if ($session) {
                return $session;
            }
        }

        return AiChatSession::create([
            'student_user_id' => $studentUserId,
            'subject_id'      => $subjectId,
            'messages'        => [],
        ]);
    }

    private function callOpenAi(Student $student, array $history): string
    {
        // El timeout se configura vía OPENAI_REQUEST_TIMEOUT=15 en .env
        // para liberar workers PHP rápido bajo carga concurrente.
        try {
            $response = OpenAI::chat()->create([
                'model'    => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $this->buildSystemPrompt($student)]],
                    $history
                ),
                'temperature' => 0.7,
                'max_tokens'  => self::MAX_TOKENS,
            ]);

            $text = trim((string) ($response->choices[0]->message->content ?? ''));
            return $text !== '' ? $text : $this->fallbackReply();
        } catch (\Throwable $e) {
            Log::warning('AiTutorService: OpenAI error', ['error' => $e->getMessage()]);
            return $this->fallbackReply();
        }
    }

    private function buildSystemPrompt(Student $student): string
    {
        $name  = $student->user?->full_name ?? 'el estudiante';
        $grade = $student->grade ? "grado {$student->grade}" : null;
        $style = $student->learning_style?->value;

        $styleDesc = match ($style) {
            'visual'   => 'Usa descripciones visuales, esquemas y analogías gráficas.',
            'auditivo' => 'Usa analogías sonoras y ritmo narrativo para explicar.',
            'lector'   => 'Sé estructurado y detallado; usa listas y definiciones claras.',
            default    => null,
        };

        $progressLines = $student->progress->map(function ($p) {
            $subjectName = $p->subject?->name ?? 'Materia';
            return "  - {$subjectName}: {$p->mastery_percentage}% de dominio";
        })->join("\n");

        $parts = ["Eres un tutor educativo personalizado para {$name}."];

        if ($grade || $style) {
            $profile = collect([$grade, $style ? "estilo de aprendizaje: {$style}" : null])
                ->filter()
                ->join(', ');
            $parts[] = "Perfil: {$profile}.";
        }

        if ($styleDesc) {
            $parts[] = $styleDesc;
        }

        if ($progressLines) {
            $parts[] = "Progreso actual del estudiante:\n{$progressLines}";
        }

        $parts[] = "Responde siempre en español, de forma clara y motivadora. "
            . "Adapta el nivel de detalle al perfil. "
            . "Sé conciso (máximo 4 párrafos). "
            . "No inventes datos ni resultados que no se te hayan dado.";

        return implode("\n", $parts);
    }

    private function fallbackReply(): string
    {
        return 'Lo siento, no puedo responder en este momento. Por favor intenta de nuevo más tarde.';
    }
}
