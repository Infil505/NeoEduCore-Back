<?php

namespace App\Services\AI;

use App\Models\AI\AiChatSession;
use App\Models\Students\Student;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use App\Services\AI\AiOutputValidator;

class AiTutorService
{
    private const MAX_HISTORY_MESSAGES = 20;
    private const MAX_TOKENS = 600;
    // Máximo de mensajes almacenados en JSONB — evita crecimiento ilimitado del campo
    private const MAX_STORED_MESSAGES = 60;

    /**
     * TTL del contexto del estudiante (perfil + progreso) usado para armar el
     * system prompt. Ese contexto cambia solo al entregar un examen, pero se
     * leía en CADA turno de chat (4 queries: student, user, progress, subjects).
     * Cachearlo quita esas lecturas del camino caliente del tutor.
     *
     * 5 min es el retardo máximo con el que el tutor vería un progreso nuevo;
     * a cambio, una conversación de 20 turnos pasa de ~80 lecturas a ~4.
     */
    private const CONTEXT_TTL = 300;

    public function chat(
        string $studentUserId,
        string $message,
        ?string $sessionId = null,
        ?string $subjectId = null,
        string $mode = 'ask',
        ?string $topic = null
    ): array {
        // El controlador ya verificó que el usuario tiene perfil de estudiante,
        // así que aquí solo hace falta el contexto para el prompt (cacheable).
        $systemPrompt = $this->systemPromptCacheado($studentUserId);

        $session = $this->resolveSession($studentUserId, $sessionId, $subjectId);

        $history = collect($session->messages ?? [])
            ->take(-self::MAX_HISTORY_MESSAGES)
            ->values()
            ->all();

        $modePrefix = $this->buildModePrefix($mode, $topic);
        $userContent = $modePrefix !== '' ? "{$modePrefix}\n\n{$message}" : $message;

        $history[] = ['role' => 'user', 'content' => $userContent];

        $reply = $this->callOpenAi($systemPrompt, $history, $mode);

        $nuevos = [
            ['role' => 'user',      'content' => $message, 'mode' => $mode, 'created_at' => now()->toISOString()],
            ['role' => 'assistant', 'content' => $reply,   'created_at' => now()->toISOString()],
        ];

        $totalPrevio = count($session->messages ?? []);

        $this->anexarMensajes($session->id, $nuevos);

        return [
            'session_id'    => $session->id,
            'reply'         => $reply,
            // El append + truncado ocurre en SQL; el resultado es determinista,
            // así que se calcula aquí en vez de releer la fila.
            'message_count' => min($totalPrevio + count($nuevos), self::MAX_STORED_MESSAGES),
        ];
    }

    /**
     * Añade mensajes a la conversación SIN reescribirla entera.
     *
     * Antes se hacía `$session->update(['messages' => $todos])`, lo que enviaba
     * la conversación completa (hasta 60 mensajes de ~600 tokens ≈ cientos de
     * KB) por la red y reescribía todo el JSONB en cada turno. El coste crecía
     * con la longitud de la conversación.
     *
     * Ahora solo viaja el delta: PostgreSQL concatena con `||` y recorta a los
     * últimos MAX_STORED_MESSAGES en la misma sentencia. Coste constante.
     */
    private function anexarMensajes(string $sessionId, array $nuevos): void
    {
        $delta = json_encode($nuevos, JSON_UNESCAPED_UNICODE);

        // SQL explícito en vez del query builder: el builder no permite mezclar
        // bindings propios dentro de un DB::raw() manteniendo el orden.
        DB::update(
            "UPDATE ai_chat_sessions
                SET messages = (
                        SELECT COALESCE(jsonb_agg(e ORDER BY o), '[]'::jsonb)
                        FROM jsonb_array_elements(messages || ?::jsonb)
                             WITH ORDINALITY AS t(e, o)
                        WHERE o > GREATEST(
                            0,
                            jsonb_array_length(messages || ?::jsonb) - ?
                        )
                    ),
                    updated_at = ?
              WHERE id = ?",
            [$delta, $delta, self::MAX_STORED_MESSAGES, now(), $sessionId]
        );
    }

    /**
     * System prompt del estudiante, cacheado. Ver CONTEXT_TTL.
     */
    private function systemPromptCacheado(string $studentUserId): string
    {
        return Cache::remember(
            "ai:tutor:prompt:{$studentUserId}",
            self::CONTEXT_TTL,
            function () use ($studentUserId) {
                $student = Student::with(['user', 'progress.subject'])
                    ->where('user_id', $studentUserId)
                    ->firstOrFail();

                return $this->buildSystemPrompt($student);
            }
        );
    }

    /**
     * Invalida el contexto cacheado. Llamar cuando cambie el progreso o el
     * perfil del estudiante si se quiere que el tutor lo vea al instante en
     * vez de esperar al TTL.
     */
    public static function olvidarContexto(string $studentUserId): void
    {
        Cache::forget("ai:tutor:prompt:{$studentUserId}");
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

    public function getDiagnosis(string $studentUserId): string
    {
        $student = Student::with(['user', 'progress.subject'])
            ->where('user_id', $studentUserId)
            ->firstOrFail();

        $name = $student->user?->full_name ?? 'el estudiante';

        $progressLines = $student->progress->map(function ($p) {
            $status = $p->mastery_percentage >= 70 ? 'Dominado' : ($p->mastery_percentage >= 40 ? 'En progreso' : 'Por reforzar');
            return "  - {$p->subject?->name}: {$p->mastery_percentage}% ({$status})";
        })->join("\n");

        if ($progressLines === '') {
            return "Hola {$name}. Aún no tienes exámenes registrados. ¡Realiza tu primer examen para ver tu diagnóstico personalizado!";
        }

        $prompt = "Genera un diagnóstico educativo breve y motivador para {$name}.\n\n"
            . "Progreso por materia:\n{$progressLines}\n\n"
            . "Incluye: resumen general, fortalezas, áreas por mejorar y 1-2 acciones concretas. "
            . "Máximo 4 párrafos. Usa español claro y alentador.";

        try {
            $response = OpenAI::chat()->create([
                'model'    => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un tutor educativo. Genera diagnósticos motivadores y accionables.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.6,
                'max_tokens'  => 500,
            ]);

            $text = trim((string) ($response->choices[0]->message->content ?? ''));

            if ($text === '') {
                return $this->fallbackDiagnosis($name, $progressLines);
            }

            // Coherencia con chat(): el diagnóstico también pasa por el filtro PII/longitud.
            $validator = new AiOutputValidator();
            if ($validator->validate($text) !== null) {
                Log::warning('AiTutorService: diagnóstico bloqueado por validación PII/longitud');
                return $this->fallbackDiagnosis($name, $progressLines);
            }

            return $validator->sanitize($text);
        } catch (\Throwable $e) {
            Log::warning('AiTutorService: diagnosis OpenAI error', ['error' => $e->getMessage()]);
            return $this->fallbackDiagnosis($name, $progressLines);
        }
    }

    private function buildModePrefix(string $mode, ?string $topic): string
    {
        return match ($mode) {
            'explain'  => $topic
                ? "[MODO: explicar '{$topic}'] El estudiante no entendió este tema. Explícalo de otra manera con un ejemplo diferente."
                : '[MODO: explicar] El estudiante no entendió. Reformula la explicación anterior con otro enfoque.',
            'practice' => $topic
                ? "[MODO: práctica '{$topic}'] Genera 3 ejercicios prácticos de dificultad progresiva sobre este tema. Incluye la respuesta al final."
                : '[MODO: práctica] Genera 3 ejercicios prácticos sobre el último tema tratado, de dificultad progresiva.',
            default    => '',
        };
    }

    private function callOpenAi(string $systemPrompt, array $history, string $mode = 'ask'): string
    {
        // El timeout sale de OPENAI_REQUEST_TIMEOUT (config/openai.php, default
        // 30 s). Acótalo: mientras dura la llamada el worker de Octane está
        // bloqueado y no puede atender a nadie más. Ver docs/ANALISIS_CONCURRENCIA.md.
        try {
            $response = OpenAI::chat()->create([
                'model'    => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => array_merge(
                    [['role' => 'system', 'content' => $systemPrompt]],
                    $history
                ),
                'temperature' => $mode === 'practice' ? 0.5 : 0.7,
                'max_tokens'  => $mode === 'practice' ? 800 : self::MAX_TOKENS,
            ]);

            $text = trim((string) ($response->choices[0]->message->content ?? ''));

            if ($text === '') {
                return $this->fallbackReply();
            }

            $validator = new AiOutputValidator();
            if ($validator->validate($text) !== null) {
                Log::warning('AiTutorService: output bloqueado por validación PII/longitud');
                return $this->fallbackReply();
            }

            return $validator->sanitize($text);
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

    private function fallbackDiagnosis(string $name, string $progressLines): string
    {
        return "Hola {$name}, aquí está tu diagnóstico actual:\n\n{$progressLines}\n\n"
            . "Continúa practicando en las áreas con menor porcentaje y consulta al tutor si tienes dudas.";
    }
}
