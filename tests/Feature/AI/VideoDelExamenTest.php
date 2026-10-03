<?php

namespace Tests\Feature\AI;

use App\Enums\LearningStyle;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use Illuminate\Support\Facades\Queue;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Vídeo de apoyo del examen (03/10/2026).
 *
 * Lo pone el docente, opcional, al crear o editar el examen; solo YouTube. El
 * tutor se lo entrega al alumnado `visual` o `auditivo` —en el chat sobre ese
 * examen y en sus recomendaciones— y a nadie más. El enlace nunca lo propone el
 * modelo: con menores de 6 a 12 años no se entrega contenido sin revisar.
 */
class VideoDelExamenTest extends TestCase
{
    use ApiAuth;

    private const VIDEO = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    /* =========================
     |  El docente lo pone
     ========================= */

    public function test_el_docente_puede_crear_el_examen_con_o_sin_video(): void
    {
        [$docente, $materia, $grupo] = $this->docenteConAsignacion();

        $base = [
            'title'            => 'Fracciones',
            'subject_id'       => $materia->id,
            'grade'            => 3,
            'duration_minutes' => 30,
        ];

        $sin = $this->postJson('/api/exams', $base)->assertCreated();
        $this->assertNull($sin->json('data.video_url'), 'El vídeo es opcional');

        $con = $this->postJson('/api/exams', $base + ['video_url' => self::VIDEO])->assertCreated();
        $this->assertSame(self::VIDEO, $con->json('data.video_url'));

        // youtu.be y m.youtube.com también valen.
        $this->postJson('/api/exams', $base + ['video_url' => 'https://youtu.be/dQw4w9WgXcQ'])->assertCreated();
        $this->postJson('/api/exams', $base + ['video_url' => 'https://m.youtube.com/watch?v=x'])->assertCreated();
    }

    public function test_solo_se_aceptan_videos_de_youtube(): void
    {
        [, $materia] = $this->docenteConAsignacion();

        $base = ['title' => 'Fracciones', 'subject_id' => $materia->id, 'grade' => 3, 'duration_minutes' => 30];

        foreach ([
            'https://vimeo.com/123',
            'https://youtube.com.sitio-malo.net/watch?v=x', // sufijo engañoso
            'javascript:alert(1)',
            'no es una url',
        ] as $malo) {
            $this->postJson('/api/exams', $base + ['video_url' => $malo])
                ->assertStatus(422)
                ->assertJsonValidationErrors('video_url');
        }
    }

    public function test_el_docente_puede_quitar_el_video_al_editar(): void
    {
        [$docente, $materia] = $this->docenteConAsignacion();

        $exam = Exam::factory()->create([
            'institution_id'        => $docente->institution_id,
            'created_by_teacher_id' => $docente->id,
            'subject_id'            => $materia->id,
            'status'                => 'draft',
            'video_url'             => self::VIDEO,
        ]);

        // Omitirlo no lo toca.
        $this->putJson("/api/exams/{$exam->id}", ['title' => 'Fracciones 2'])->assertOk();
        $this->assertSame(self::VIDEO, $exam->fresh()->video_url);

        $this->putJson("/api/exams/{$exam->id}", ['video_url' => null])->assertOk();
        $this->assertNull($exam->fresh()->video_url);
    }

    /* =========================
     |  El tutor lo entrega
     ========================= */

    public function test_el_chat_sobre_el_examen_da_el_video_al_estilo_visual_y_auditivo(): void
    {
        foreach ([LearningStyle::Visual, LearningStyle::Auditivo] as $estilo) {
            [$alumno, $exam] = $this->alumnoConExamen($estilo, self::VIDEO);
            $this->fingirModelo('Repasemos las fracciones.');

            $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'No entendí', 'exam_id' => $exam->id])
                ->assertOk();

            $this->assertSame(self::VIDEO, $res->json('data.video.url'), $estilo->value);
            $this->assertSame($exam->id, $res->json('data.video.exam_id'));

            // En el turno siguiente, sin repetir exam_id, la sesión lo recuerda.
            $this->fingirModelo('Sigamos.');
            $siguiente = $this->postJson('/api/ai/tutor/chat', [
                'message'    => '¿Y ahora?',
                'session_id' => $res->json('data.session_id'),
            ])->assertOk();

            $this->assertSame(self::VIDEO, $siguiente->json('data.video.url'));
        }
    }

    public function test_el_estilo_lector_y_sin_estilo_no_reciben_video(): void
    {
        foreach ([LearningStyle::Lector, null] as $estilo) {
            [, $exam] = $this->alumnoConExamen($estilo, self::VIDEO);
            $this->fingirModelo('Repasemos.');

            $res = $this->postJson('/api/ai/tutor/chat', ['message' => 'No entendí', 'exam_id' => $exam->id])
                ->assertOk();

            $this->assertArrayHasKey('video', $res->json('data'));
            $this->assertNull($res->json('data.video'));
        }
    }

    public function test_sin_video_en_el_examen_o_sin_examen_no_hay_video(): void
    {
        [, $exam] = $this->alumnoConExamen(LearningStyle::Visual, null);

        $this->fingirModelo('Repasemos.');
        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola', 'exam_id' => $exam->id])
            ->assertOk()->assertJsonPath('data.video', null);

        $this->fingirModelo('Hola.');
        $this->postJson('/api/ai/tutor/chat', ['message' => 'Hola'])
            ->assertOk()->assertJsonPath('data.video', null);
    }

    public function test_las_recomendaciones_del_examen_traen_el_video_segun_el_estilo(): void
    {
        Queue::fake();

        [$alumno, $exam] = $this->alumnoConExamen(LearningStyle::Auditivo, self::VIDEO);
        $intento = $this->intentoEntregado($alumno, $exam);

        $this->getJson("/api/exam-attempts/{$intento->id}/recommendations")
            ->assertOk()
            ->assertJsonPath('data.video.url', self::VIDEO);

        [$lector, $examLector] = $this->alumnoConExamen(LearningStyle::Lector, self::VIDEO);
        $intentoLector = $this->intentoEntregado($lector, $examLector);

        $this->getJson("/api/exam-attempts/{$intentoLector->id}/recommendations")
            ->assertOk()
            ->assertJsonPath('data.video', null);
    }

    /* =========================
     |  Escenario
     ========================= */

    private function docenteConAsignacion(): array
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);
        $materia = Subject::factory()->create(['institution_id' => $institution->id]);
        $grupo   = Group::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $grupo->id, $materia->id);

        return [$docente, $materia, $grupo];
    }

    /** Alumno con estilo, matriculado en un grupo con un examen activo dirigido a él. */
    private function alumnoConExamen(?LearningStyle $estilo, ?string $video): array
    {
        $institution = Institution::factory()->create();
        $materia = Subject::factory()->create(['institution_id' => $institution->id]);
        $grupo   = Group::factory()->create(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id, 'status' => 'active']);
        Student::factory()->create([
            'user_id'        => $alumno->id,
            'institution_id' => $institution->id,
            'grade'          => 3,
            'learning_style' => $estilo?->value,
        ]);
        $this->matricularEnGrupo($alumno->id, $grupo->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id'  => $institution->id,
            'subject_id'      => $materia->id,
            'status'          => 'active',
            'available_from'  => null,
            'available_until' => null,
            'video_url'       => $video,
        ]);
        $exam->syncGroups([$grupo->id]);

        $this->actingAs($alumno, 'sanctum');
        app()->instance('tenant_id', $institution->id);

        return [$alumno, $exam];
    }

    private function intentoEntregado(User $alumno, Exam $exam): ExamAttempt
    {
        return ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $exam->institution_id,
            'exam_id'         => $exam->id,
            'student_user_id' => $alumno->id,
        ]);
    }

    private function fingirModelo(string $contenido): void
    {
        OpenAI::fake([
            CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $contenido]]],
            ]),
        ]);
    }
}
