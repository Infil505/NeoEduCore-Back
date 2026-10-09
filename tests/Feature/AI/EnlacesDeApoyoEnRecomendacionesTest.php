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
use App\Services\AI\AiRecommendationService;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Enlaces de apoyo del examen (08/10/2026): el docente deja vídeos y textos en
 * el examen y el tutor los usa como recurso al armar las recomendaciones.
 *
 * Lo que importa: la URL la pone una persona (nunca el modelo), se valida al
 * guardar, y manda sobre lo que proponga la IA y sobre el catálogo del centro.
 */
class EnlacesDeApoyoEnRecomendacionesTest extends TestCase
{
    use ApiAuth;

    private const VIDEO = 'https://www.youtube.com/watch?v=abc';
    private const TEXTO = 'https://es.wikipedia.org/wiki/Fracci%C3%B3n';

    /* =========================
     |  El docente los pone
     ========================= */

    private function base(): array
    {
        $institution = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $institution->id]);
        $materia = Subject::factory()->create(['institution_id' => $institution->id]);
        $grupo   = Group::factory()->create(['institution_id' => $institution->id]);
        $this->asignarDocente($docente, $grupo->id, $materia->id);

        return ['title' => 'Fracciones', 'subject_id' => $materia->id, 'grade' => 3, 'duration_minutes' => 30];
    }

    public function test_el_docente_guarda_enlaces_de_video_y_texto(): void
    {
        $base = $this->base();

        $res = $this->postJson('/api/exams', $base + ['support_resources' => [
            ['type' => 'video', 'url' => self::VIDEO, 'title' => 'Fracciones en 5 minutos'],
            ['type' => 'text',  'url' => self::TEXTO],
        ]])->assertCreated();

        $this->assertCount(2, $res->json('data.support_resources'));
        $this->assertSame('video', $res->json('data.support_resources.0.type'));
    }

    public function test_son_opcionales_y_se_pueden_quitar(): void
    {
        $base = $this->base();

        $sin = $this->postJson('/api/exams', $base)->assertCreated();
        $this->assertNull($sin->json('data.support_resources'));

        $id = $this->postJson('/api/exams', $base + ['support_resources' => [['type' => 'text', 'url' => self::TEXTO]]])
            ->json('data.id');

        $this->putJson("/api/exams/{$id}", ['title' => 'Otro título'])->assertOk();
        $this->assertCount(1, Exam::find($id)->support_resources, 'Omitirlos no los toca');

        $this->putJson("/api/exams/{$id}", ['support_resources' => []])->assertOk();
        $this->assertSame([], Exam::find($id)->support_resources);
    }

    public function test_rechaza_enlaces_peligrosos_o_mal_formados(): void
    {
        $base = $this->base();

        $malos = [
            'otro dominio'             => ['type' => 'text',  'url' => 'https://sitio-malo.net/x'],
            'sufijo engañoso'          => ['type' => 'text',  'url' => 'https://wikipedia.org.sitio-malo.net/x'],
            'esquema javascript'       => ['type' => 'text',  'url' => 'javascript:alert(1)'],
            'no es una url'            => ['type' => 'text',  'url' => 'hola'],
            'vídeo que no es de vídeo' => ['type' => 'video', 'url' => self::TEXTO],
            'tipo desconocido'         => ['type' => 'audio', 'url' => self::VIDEO],
            'sin url'                  => ['type' => 'text'],
        ];

        foreach ($malos as $caso => $item) {
            $this->postJson('/api/exams', $base + ['support_resources' => [$item]])
                ->assertStatus(422, $caso);
        }
    }

    public function test_hay_un_tope_de_enlaces(): void
    {
        $base = $this->base();
        $tope = (int) config('ai_resources.max_support_resources');

        $demasiados = array_fill(0, $tope + 1, ['type' => 'text', 'url' => self::TEXTO]);

        $this->postJson('/api/exams', $base + ['support_resources' => $demasiados])
            ->assertStatus(422)->assertJsonValidationErrors('support_resources');
    }

    /* =========================
     |  El tutor los usa
     ========================= */

    private function intento(?LearningStyle $estilo, ?array $enlaces, ?string $video = null, int $score = 2): array
    {
        $institution = Institution::factory()->create();
        $materia = Subject::factory()->create(['institution_id' => $institution->id]);
        $grupo   = Group::factory()->create(['institution_id' => $institution->id]);

        $alumno = User::factory()->student()->create(['institution_id' => $institution->id, 'status' => 'active']);
        Student::factory()->create([
            'user_id' => $alumno->id, 'institution_id' => $institution->id,
            'grade' => 3, 'learning_style' => $estilo?->value,
        ]);
        $this->matricularEnGrupo($alumno->id, $grupo->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id'    => $institution->id,
            'subject_id'        => $materia->id,
            'status'            => 'active',
            'title'             => 'Fracciones',
            'video_url'         => $video,
            'support_resources' => $enlaces,
        ]);
        $exam->syncGroups([$grupo->id]);

        $this->actingAs($alumno, 'sanctum');
        app()->instance('tenant_id', $institution->id);

        $intento = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $institution->id,
            'exam_id'         => $exam->id,
            'student_user_id' => $alumno->id,
            'score'           => $score,
            'max_score'       => 10,
        ]);

        return [$alumno, $intento];
    }

    private function recursoGenerado(ExamAttempt $intento, bool $conModelo = true, ?string $json = null): array
    {
        if ($conModelo) {
            $contenido = "strength: Bien.\nweakness: Repasa fracciones.\naction: Practica.\nresource: Mira esto. " . ($json ?? '');

            OpenAI::fake([CreateResponse::fake([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $contenido]]],
            ])]);
        }

        $creadas = $conModelo
            ? app(AiRecommendationService::class)->regenerateForAttempt($intento->fresh(), $intento->student_user_id)
            : app(AiRecommendationService::class)->generateFromAttempt($intento->fresh());

        $recurso = collect($creadas)->first(
            fn ($r) => ($r->recommendation_type?->value ?? $r->recommendation_type) === 'resource'
        );
        $this->assertNotNull($recurso, 'Tiene que haber una recomendación de recurso');

        return (array) ($recurso->resource_data ?? $recurso->resource ?? $recurso->metadata ?? []);
    }

    private function enlaces(): array
    {
        return [
            ['type' => 'text',  'url' => self::TEXTO, 'title' => 'Lectura sobre fracciones'],
            ['type' => 'video', 'url' => self::VIDEO, 'title' => 'Fracciones en 5 minutos'],
        ];
    }

    public function test_el_estilo_visual_recibe_el_video_y_el_lector_el_texto(): void
    {
        [, $visual] = $this->intento(LearningStyle::Visual, $this->enlaces());
        $this->assertSame(self::VIDEO, $this->recursoGenerado($visual)['url']);

        [, $lector] = $this->intento(LearningStyle::Lector, $this->enlaces());
        $r = $this->recursoGenerado($lector);
        $this->assertSame(self::TEXTO, $r['url']);
        $this->assertSame('Lectura sobre fracciones', $r['title']);
        $this->assertSame('article', $r['type']);
    }

    public function test_si_no_hay_del_tipo_preferido_se_da_el_que_haya(): void
    {
        [, $visual] = $this->intento(LearningStyle::Visual, [['type' => 'text', 'url' => self::TEXTO]]);

        $this->assertSame(self::TEXTO, $this->recursoGenerado($visual)['url']);
    }

    public function test_el_video_url_clasico_tambien_cuenta(): void
    {
        [, $auditivo] = $this->intento(LearningStyle::Auditivo, null, self::VIDEO);

        $this->assertSame(self::VIDEO, $this->recursoGenerado($auditivo)['url']);
    }

    public function test_lo_del_docente_manda_sobre_la_url_que_proponga_el_modelo(): void
    {
        [, $intento] = $this->intento(LearningStyle::Visual, $this->enlaces());

        $propuesta = '{"title":"Otro","type":"video","url":"https://www.khanacademy.org/otra"}';

        $this->assertSame(self::VIDEO, $this->recursoGenerado($intento, true, $propuesta)['url']);
    }

    public function test_la_reserva_sin_modelo_tambien_usa_los_enlaces_del_docente(): void
    {
        [, $intento] = $this->intento(LearningStyle::Visual, $this->enlaces(), null, 2);

        $this->assertSame(self::VIDEO, $this->recursoGenerado($intento, false)['url']);
    }

    public function test_sin_enlaces_del_docente_sigue_como_antes(): void
    {
        [, $intento] = $this->intento(LearningStyle::Visual, null);

        $r = $this->recursoGenerado($intento);

        $this->assertArrayNotHasKey('source', $r);
    }
}
