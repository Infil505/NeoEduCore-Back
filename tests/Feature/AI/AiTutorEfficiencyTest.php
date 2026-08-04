<?php

namespace Tests\Feature\AI;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\AI\AiTutorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Eficiencia del tutor IA contra la base de datos.
 *
 * El tutor es el endpoint que más presión puede meter: cada llamada bloquea un
 * worker de Octane 1-15 s y, antes de estas optimizaciones, además releía el
 * perfil del estudiante y reescribía la conversación entera en cada turno.
 *
 * Ver ANALISIS_CONCURRENCIA.md §5.3.
 */
class AiTutorEfficiencyTest extends TestCase
{
    use ApiAuth;

    private function estudianteConProgreso(int $materias = 3): User
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->student()->create(['institution_id' => $institution->id]);
        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $institution->id,
        ]);

        for ($i = 0; $i < $materias; $i++) {
            $subject = Subject::factory()->create(['institution_id' => $institution->id]);
            StudentProgress::create([
                'institution_id'     => $institution->id,
                'student_user_id'    => $user->id,
                'subject_id'         => $subject->id,
                'mastery_percentage' => 65,
                'updated_at'         => now(),
            ]);
        }

        return $user;
    }

    private function contarQueries(callable $fn): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $fn();

        return $n;
    }

    /** Tamaño total (bytes) de los bindings enviados a la BD. */
    private function bytesEnviados(callable $fn): int
    {
        $bytes = 0;
        DB::listen(function ($q) use (&$bytes) {
            foreach ($q->bindings as $b) {
                $bytes += is_scalar($b) ? strlen((string) $b) : 0;
            }
        });
        $fn();

        return $bytes;
    }

    /* =========================
     |  Contexto cacheado
     ========================= */

    public function test_student_context_is_not_reloaded_on_every_turn(): void
    {
        $user = $this->estudianteConProgreso();
        $this->actingAs($user, 'sanctum');

        $primer = $this->contarQueries(function () {
            $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();
        });

        $segundo = $this->contarQueries(function () {
            $this->postJson('/api/ai/tutor/chat', ['message' => '¿Y esto otro?'])->assertOk();
        });

        // El segundo turno no vuelve a leer perfil + progreso + materias
        $this->assertLessThan(
            $primer,
            $segundo,
            "El contexto no se está cacheando: 1er turno={$primer}, 2º turno={$segundo} queries"
        );
    }

    public function test_context_cache_is_scoped_per_student(): void
    {
        $a = $this->estudianteConProgreso();
        $b = $this->estudianteConProgreso();

        $this->actingAs($a, 'sanctum');
        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        // El caché de A no debe servir a B: cada uno tiene su propio prompt
        $this->actingAs($b, 'sanctum');
        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        $sesionB = DB::table('ai_chat_sessions')
            ->where('student_user_id', $b->id)
            ->first();

        $this->assertNotNull($sesionB, 'B debe tener su propia sesión');
        $this->assertNotSame($res->json('data.session_id'), null);
    }

    public function test_context_can_be_invalidated(): void
    {
        $user = $this->estudianteConProgreso();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])->assertOk();

        AiTutorService::olvidarContexto($user->id);

        // Tras invalidar, el siguiente turno vuelve a leer el contexto
        $tras = $this->contarQueries(function () {
            $this->postJson('/api/ai/tutor/chat', ['message' => 'Otra vez'])->assertOk();
        });

        $conCache = $this->contarQueries(function () {
            $this->postJson('/api/ai/tutor/chat', ['message' => 'Y otra'])->assertOk();
        });

        $this->assertGreaterThan(
            $conCache,
            $tras,
            'Tras olvidarContexto() el turno siguiente debe releer el perfil'
        );
    }

    /* =========================
     |  Escritura incremental de la conversación
     ========================= */

    public function test_conversation_write_does_not_grow_with_history(): void
    {
        $user = $this->estudianteConProgreso();
        $this->actingAs($user, 'sanctum');

        $sessionId = $this->postJson('/api/ai/tutor/chat', ['message' => 'Turno inicial'])
            ->assertOk()->json('data.session_id');

        // Conversación corta
        $bytesCorta = $this->bytesEnviados(function () use ($sessionId) {
            $this->postJson('/api/ai/tutor/chat', [
                'message' => 'Mensaje de prueba',
                'session_id' => $sessionId,
            ])->assertOk();
        });

        // Alargamos la conversación
        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/ai/tutor/chat', [
                'message'    => "Relleno de conversación número {$i} con texto suficiente para pesar",
                'session_id' => $sessionId,
            ])->assertOk();
        }

        $bytesLarga = $this->bytesEnviados(function () use ($sessionId) {
            $this->postJson('/api/ai/tutor/chat', [
                'message' => 'Mensaje de prueba',
                'session_id' => $sessionId,
            ])->assertOk();
        });

        // Antes se reenviaba la conversación entera en cada turno, así que este
        // número crecía sin parar. Ahora solo viaja el delta (2 mensajes).
        $this->assertLessThan(
            $bytesCorta * 2,
            $bytesLarga,
            sprintf(
                'La escritura crece con el historial: conversación corta=%d bytes, '
                . 'larga=%d bytes. ¿Se volvió a reescribir el JSONB entero?',
                $bytesCorta,
                $bytesLarga
            )
        );
    }

    public function test_messages_are_appended_in_order_and_capped(): void
    {
        $user = $this->estudianteConProgreso();
        $this->actingAs($user, 'sanctum');

        $sessionId = $this->postJson('/api/ai/tutor/chat', ['message' => 'primero'])
            ->assertOk()->json('data.session_id');

        $this->postJson('/api/ai/tutor/chat', [
            'message' => 'segundo', 'session_id' => $sessionId,
        ])->assertOk();

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message' => 'tercero', 'session_id' => $sessionId,
        ])->assertOk();

        $mensajes = json_decode(
            DB::table('ai_chat_sessions')->where('id', $sessionId)->value('messages'),
            true
        );

        // 3 turnos × (user + assistant)
        $this->assertCount(6, $mensajes);
        $this->assertSame(6, $res->json('data.message_count'));

        // El orden se conserva y son alternos
        $this->assertSame('primero', $mensajes[0]['content']);
        $this->assertSame('user', $mensajes[0]['role']);
        $this->assertSame('assistant', $mensajes[1]['role']);
        $this->assertSame('segundo', $mensajes[2]['content']);
        $this->assertSame('tercero', $mensajes[4]['content']);
    }

    public function test_conversation_is_capped_at_the_stored_limit(): void
    {
        $user = $this->estudianteConProgreso();
        $this->actingAs($user, 'sanctum');

        // La primera llamada va por HTTP para dejar el tenant resuelto en el
        // contenedor; el resto va directa al servicio para no chocar con el
        // throttle por usuario (30/min), que aquí no es lo que se prueba.
        $sessionId = $this->postJson('/api/ai/tutor/chat', ['message' => 'arranque'])
            ->assertOk()->json('data.session_id');

        $tutor = app(AiTutorService::class);

        // 60 es el tope (MAX_STORED_MESSAGES) → 30 turnos. Nos pasamos.
        for ($i = 0; $i < 34; $i++) {
            $tutor->chat($user->id, "turno {$i}", $sessionId);
        }

        $mensajes = json_decode(
            DB::table('ai_chat_sessions')->where('id', $sessionId)->value('messages'),
            true
        );

        $this->assertCount(60, $mensajes, 'El JSONB debe recortarse al tope');

        // Se conservan los ÚLTIMOS, no los primeros
        $this->assertSame('turno 33', $mensajes[58]['content']);
        $this->assertNotSame('arranque', $mensajes[0]['content']);
    }

    /* =========================
     |  Presupuesto global de IA
     ========================= */

    public function test_global_ai_limiter_is_applied_to_ai_routes(): void
    {
        $rutas = collect(app('router')->getRoutes())->filter(
            fn ($r) => in_array($r->uri(), [
                'api/ai/tutor/chat',
                'api/ai/tutor/diagnosis',
                'api/ai/generate',
            ], true)
        );

        $this->assertCount(3, $rutas, 'Deben existir las 3 rutas que llaman a OpenAI');

        foreach ($rutas as $ruta) {
            $this->assertContains(
                'throttle:ai-global',
                $ruta->gatherMiddleware(),
                "La ruta {$ruta->uri()} llama a OpenAI pero no entra en el presupuesto global de IA"
            );
        }
    }
}
