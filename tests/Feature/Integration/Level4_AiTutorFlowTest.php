<?php

namespace Tests\Feature\Integration;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 4 — Flujo del tutor IA conversacional.
 *
 * Las llamadas a OpenAI usan el fallback local (sin API key real en tests).
 * Se verifican: creación de sesión, persistencia de mensajes, modos, diagnóstico.
 */
class Level4_AiTutorFlowTest extends TestCase
{
    use ApiAuth;

    private function buildStudent(): array
    {
        $institution = Institution::factory()->create();
        $studentUser = User::factory()->student()->create(['institution_id' => $institution->id]);
        $student     = Student::factory()->create([
            'user_id'        => $studentUser->id,
            'institution_id' => $institution->id,
        ]);

        return compact('institution', 'studentUser', 'student');
    }

    // -------------------------------------------------------------------------
    // Sesión y persistencia
    // -------------------------------------------------------------------------

    public function test_chat_creates_session_and_returns_reply(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message' => 'Hola, necesito ayuda con matemáticas',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['session_id', 'reply', 'message_count']]);
        $this->assertNotEmpty($res->json('data.reply'));
        $this->assertNotEmpty($res->json('data.session_id'));
    }

    public function test_second_message_continues_same_session(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();

        $this->actingAs($studentUser, 'sanctum');

        $first = $this->postJson('/api/ai/tutor/chat', ['message' => 'Primera pregunta']);
        $first->assertOk();
        $sessionId = $first->json('data.session_id');

        $second = $this->postJson('/api/ai/tutor/chat', [
            'message'    => 'Segunda pregunta',
            'session_id' => $sessionId,
        ]);

        $second->assertOk();
        $this->assertEquals($sessionId, $second->json('data.session_id'));
        // 2 user + 2 assistant = 4 mensajes
        $this->assertEquals(4, $second->json('data.message_count'));
    }

    public function test_chat_messages_persisted_in_session(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Mensaje de prueba']);
        $res->assertOk();
        $sessionId = $res->json('data.session_id');

        $this->assertDatabaseHas('ai_chat_sessions', ['id' => $sessionId]);

        $session = \App\Models\AI\AiChatSession::find($sessionId);
        $this->assertCount(2, $session->messages); // user + assistant
    }

    public function test_end_session(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();

        $this->actingAs($studentUser, 'sanctum');

        $res     = $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola']);
        $sessionId = $res->json('data.session_id');

        $end = $this->patchJson("/api/ai/tutor/sessions/{$sessionId}/end");
        $end->assertOk();

        $this->assertDatabaseHas('ai_chat_sessions', [
            'id'       => $sessionId,
        ]);

        $session = \App\Models\AI\AiChatSession::find($sessionId);
        $this->assertNotNull($session->ended_at);
    }

    public function test_sessions_list_returns_without_messages_column(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();

        $this->actingAs($studentUser, 'sanctum');

        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola']);

        $res = $this->getJson('/api/ai/tutor/sessions');
        $res->assertOk();

        $firstItem = $res->json('data.data.0');
        // La columna messages JSONB no debe exponerse en el listado
        $this->assertArrayNotHasKey('messages', $firstItem ?? []);
    }

    // -------------------------------------------------------------------------
    // Modos interactivos
    // -------------------------------------------------------------------------

    public function test_mode_ask_default_works(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message' => '¿Qué es una función lineal?',
            'mode'    => 'ask',
        ]);

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['reply']]);
    }

    public function test_mode_explain_accepted(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message' => 'No entendí lo de las derivadas',
            'mode'    => 'explain',
            'topic'   => 'derivadas',
        ]);

        $res->assertOk();
    }

    public function test_mode_practice_accepted(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message' => 'Dame ejercicios',
            'mode'    => 'practice',
            'topic'   => 'ecuaciones',
        ]);

        $res->assertOk();
    }

    public function test_invalid_mode_rejected(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message' => 'Hola',
            'mode'    => 'hack',
        ]);

        $res->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Diagnóstico automático
    // -------------------------------------------------------------------------

    public function test_diagnosis_returns_text_for_student_with_no_progress(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();
        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/ai/tutor/diagnosis');

        $res->assertOk();
        $res->assertJsonStructure(['data' => ['diagnosis']]);
        $this->assertNotEmpty($res->json('data.diagnosis'));
    }

    public function test_diagnosis_returns_text_for_student_with_progress(): void
    {
        ['institution' => $institution, 'studentUser' => $studentUser] = $this->buildStudent();

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        StudentProgress::factory()->create([
            'institution_id'   => $institution->id,
            'student_user_id'  => $studentUser->id,
            'subject_id'       => $subject->id,
            'mastery_percentage' => 65,
        ]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->getJson('/api/ai/tutor/diagnosis');

        $res->assertOk();
        $this->assertNotEmpty($res->json('data.diagnosis'));
    }

    public function test_non_student_cannot_use_tutor(): void
    {
        $institution = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $institution->id]);

        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola']);
        $res->assertStatus(403);
    }

    // -------------------------------------------------------------------------
    // Acceso y aislamiento de datos
    // -------------------------------------------------------------------------

    public function test_chat_requires_authentication(): void
    {
        // Sin token el tutor no debe ser accesible.
        $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola']);
        $res->assertStatus(401);
    }

    public function test_chat_rejects_subject_from_another_tenant(): void
    {
        ['studentUser' => $studentUser] = $this->buildStudent();

        // Materia que pertenece a OTRA institución.
        $otherInstitution = Institution::factory()->create();
        $otherSubject     = Subject::factory()->create(['institution_id' => $otherInstitution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message'    => 'Hola',
            'subject_id' => $otherSubject->id,
        ]);

        $res->assertStatus(422);
    }

    public function test_chat_accepts_subject_from_same_tenant(): void
    {
        ['institution' => $institution, 'studentUser' => $studentUser] = $this->buildStudent();

        $subject = Subject::factory()->create(['institution_id' => $institution->id]);

        $this->actingAs($studentUser, 'sanctum');

        $res = $this->postJson('/api/ai/tutor/chat', [
            'message'    => 'Hola',
            'subject_id' => $subject->id,
        ]);

        $res->assertOk();
        $this->assertDatabaseHas('ai_chat_sessions', [
            'id'         => $res->json('data.session_id'),
            'subject_id' => $subject->id,
        ]);
    }
}
