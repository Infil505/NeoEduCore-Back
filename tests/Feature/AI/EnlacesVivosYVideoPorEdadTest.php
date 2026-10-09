<?php

namespace Tests\Feature\AI;

use App\Enums\LearningStyle;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use App\Services\AI\EnlaceDisponible;
use App\Services\AI\FormatoPorEstilo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * El tutor no entrega enlaces rotos, y cuando necesita un vídeo que el docente
 * no puso, lo busca en el catálogo acorde al grado del alumno.
 */
class EnlacesVivosYVideoPorEdadTest extends TestCase
{
    use ApiAuth;

    private const VIDEO_DOCENTE = 'https://www.youtube.com/watch?v=docente';

    protected function setUp(): void
    {
        parent::setUp();

        // En la suite el sondeo está apagado (phpunit.xml): aquí se prueba con Http simulado.
        config(['ai_resources.link_check.enabled' => true]);
        Cache::flush();
    }

    /* =========================
     |  EnlaceDisponible
     ========================= */

    public function test_un_video_de_youtube_que_existe_esta_disponible(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response(['title' => 'x'], 200)]);

        $this->assertTrue(app(EnlaceDisponible::class)->disponible('https://www.youtube.com/watch?v=vivo'));
    }

    public function test_un_video_borrado_o_privado_esta_roto(): void
    {
        foreach ([404, 401] as $status) {
            Cache::flush();
            Http::fake(['www.youtube.com/oembed*' => Http::response('', $status)]);

            $this->assertFalse(app(EnlaceDisponible::class)->disponible('https://youtu.be/borrado'), "status $status");
        }
    }

    public function test_una_pagina_404_o_410_esta_rota_y_una_200_no(): void
    {
        Http::fake([
            'es.wikipedia.org/wiki/Viva'  => Http::response('', 200),
            'es.wikipedia.org/wiki/Rota'  => Http::response('', 404),
            'es.wikipedia.org/wiki/Vieja' => Http::response('', 410),
        ]);
        $servicio = app(EnlaceDisponible::class);

        $this->assertTrue($servicio->disponible('https://es.wikipedia.org/wiki/Viva'));
        $this->assertFalse($servicio->disponible('https://es.wikipedia.org/wiki/Rota'));
        $this->assertFalse($servicio->disponible('https://es.wikipedia.org/wiki/Vieja'));
    }

    public function test_sin_veredicto_se_da_por_vivo_pero_no_se_cachea_mucho(): void
    {
        Http::fake(['es.wikipedia.org/*' => Http::response('', 503)]);

        $this->assertTrue(app(EnlaceDisponible::class)->disponible('https://es.wikipedia.org/wiki/Caida'));
    }

    public function test_un_dominio_fuera_de_la_lista_blanca_nunca_esta_disponible_ni_se_consulta(): void
    {
        Http::fake();
        $servicio = app(EnlaceDisponible::class);

        foreach ([
            'https://sitio-malo.net/x',
            'https://wikipedia.org.sitio-malo.net/x',
            'http://169.254.169.254/latest/meta-data',
            'http://localhost/admin',
            'javascript:alert(1)',
            '',
        ] as $url) {
            $this->assertFalse($servicio->disponible($url), $url);
        }

        Http::assertNothingSent();
    }

    public function test_el_veredicto_se_cachea(): void
    {
        Http::fake(['es.wikipedia.org/*' => Http::response('', 200)]);
        $servicio = app(EnlaceDisponible::class);

        $servicio->disponible('https://es.wikipedia.org/wiki/Cache');
        $servicio->disponible('https://es.wikipedia.org/wiki/Cache');

        Http::assertSentCount(1);
    }

    /* =========================
     |  Selección del vídeo
     ========================= */

    private function escenario(int $gradoAlumno, ?LearningStyle $estilo, ?string $videoDocente = null): array
    {
        $institution = Institution::factory()->create();
        $materia = Subject::factory()->create(['institution_id' => $institution->id]);
        $grupo   = Group::factory()->create(['institution_id' => $institution->id]);

        $user = User::factory()->student()->create(['institution_id' => $institution->id, 'status' => 'active']);
        $alumno = Student::factory()->create([
            'user_id' => $user->id, 'institution_id' => $institution->id,
            'grade' => $gradoAlumno, 'learning_style' => $estilo?->value,
        ]);
        $this->matricularEnGrupo($user->id, $grupo->id, $institution->id);

        $exam = Exam::factory()->create([
            'institution_id' => $institution->id,
            'subject_id'     => $materia->id,
            'grade'          => $gradoAlumno,
            'status'         => 'active',
            'video_url'      => $videoDocente,
        ]);
        $exam->syncGroups([$grupo->id]);

        app()->instance('tenant_id', $institution->id);

        return [$alumno, $exam, $materia, $grupo, $institution];
    }

    private function videoCatalogo(Institution $i, Group $g, ?Subject $materia, array $attrs): StudyResource
    {
        $r = StudyResource::factory()->create($attrs + [
            'institution_id' => $i->id,
            'subject_id'     => $materia?->id,
            'resource_type'  => 'video',
        ]);
        $r->syncGroups([$g->id]);

        return $r;
    }

    public function test_el_video_del_docente_vivo_se_entrega(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('{}', 200)]);
        [$alumno, $exam] = $this->escenario(3, LearningStyle::Visual, self::VIDEO_DOCENTE);

        $v = app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno);

        $this->assertSame(self::VIDEO_DOCENTE, $v['url']);
        $this->assertSame('teacher', $v['source']);
    }

    public function test_si_el_video_del_docente_esta_roto_se_usa_uno_del_catalogo_de_su_grado(): void
    {
        Http::fake(function ($request) {
            // Roto el del docente; vivo el del catálogo.
            return str_contains($request->url(), 'docente')
                ? Http::response('', 404)
                : Http::response('{}', 200);
        });
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(3, LearningStyle::Auditivo, self::VIDEO_DOCENTE);
        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://www.youtube.com/watch?v=catalogo', 'grade_min' => 2, 'grade_max' => 4,
        ]);

        $v = app(FormatoPorEstilo::class)->videoPara(LearningStyle::Auditivo, $exam, $alumno);

        $this->assertSame('https://www.youtube.com/watch?v=catalogo', $v['url']);
        $this->assertSame('catalog', $v['source']);
    }

    public function test_sin_video_del_docente_el_del_catalogo_tiene_que_ser_de_su_edad(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('{}', 200)]);
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(2, LearningStyle::Visual);

        // Pensado para 5.º-6.º: no es para un niño de 2.º.
        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://www.youtube.com/watch?v=mayores', 'grade_min' => 5, 'grade_max' => 6,
        ]);
        $this->assertNull(
            app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno),
            'Mejor ningún vídeo que uno de otra edad'
        );

        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://www.youtube.com/watch?v=suyo', 'grade_min' => 1, 'grade_max' => 3,
        ]);
        $this->assertSame(
            'https://www.youtube.com/watch?v=suyo',
            app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno)['url']
        );
    }

    public function test_un_rango_declarado_gana_a_uno_sin_rango_y_la_materia_a_lo_generico(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('{}', 200)]);
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(3, LearningStyle::Visual);

        // La fábrica da rango y dificultad al azar: se fijan para que el orden dependa solo de lo que se prueba.
        $sinRango = ['grade_min' => null, 'grade_max' => null, 'difficulty' => 'basic'];

        $this->videoCatalogo($inst, $grupo, null, $sinRango + ['url' => 'https://www.youtube.com/watch?v=generico']);
        $this->videoCatalogo($inst, $grupo, $materia, $sinRango + ['url' => 'https://www.youtube.com/watch?v=sinrango']);
        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://www.youtube.com/watch?v=conrango', 'grade_min' => 3, 'grade_max' => 3,
            'difficulty' => 'advanced',
        ]);

        $this->assertSame(
            'https://www.youtube.com/watch?v=conrango',
            app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno)['url']
        );
    }

    public function test_se_salta_el_roto_y_se_entrega_el_siguiente_vivo(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), 'roto') ? Http::response('', 404) : Http::response('{}', 200);
        });
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(3, LearningStyle::Visual);

        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://www.youtube.com/watch?v=roto', 'grade_min' => 3, 'grade_max' => 3, 'difficulty' => 'basic',
        ]);
        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://www.youtube.com/watch?v=vivo', 'grade_min' => 3, 'grade_max' => 3, 'difficulty' => 'advanced',
        ]);

        $this->assertSame(
            'https://www.youtube.com/watch?v=vivo',
            app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno)['url']
        );
    }

    public function test_si_todos_estan_rotos_no_se_entrega_ninguno(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('', 404)]);
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(3, LearningStyle::Visual, self::VIDEO_DOCENTE);
        $this->videoCatalogo($inst, $grupo, $materia, ['url' => 'https://www.youtube.com/watch?v=otro']);

        $this->assertNull(app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno));
    }

    public function test_solo_los_videos_que_el_alumno_puede_abrir(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('{}', 200)]);
        [$alumno, $exam, $materia, , $inst] = $this->escenario(3, LearningStyle::Visual);

        // Enviado a un aula donde el alumno NO está.
        $otraAula = Group::factory()->create(['institution_id' => $inst->id]);
        $this->videoCatalogo($inst, $otraAula, $materia, ['url' => 'https://www.youtube.com/watch?v=ajeno']);

        $this->assertNull(app(FormatoPorEstilo::class)->videoPara(LearningStyle::Visual, $exam, $alumno));
    }

    public function test_la_url_inventada_por_el_modelo_se_descarta_si_esta_rota(): void
    {
        Http::fake(function ($request) {
            return str_contains(strtolower($request->url()), 'inventado') ? Http::response('', 404) : Http::response('{}', 200);
        });
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(3, LearningStyle::Lector);
        $this->videoCatalogo($inst, $grupo, $materia, [
            'url' => 'https://es.wikipedia.org/wiki/Real', 'resource_type' => 'article',
            'grade_min' => 3, 'grade_max' => 3,
        ]);

        $intento = \App\Models\Exams\ExamAttempt::factory()->submitted()->create([
            'institution_id' => $inst->id, 'exam_id' => $exam->id,
            'student_user_id' => $alumno->user_id, 'score' => 2, 'max_score' => 10,
        ]);

        \OpenAI\Laravel\Facades\OpenAI::fake([\OpenAI\Responses\Chat\CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' =>
                "strength: Bien.\nweakness: Repasa.\naction: Practica.\n"
                . 'resource: Lee esto. {"title":"Inventado","type":"article","url":"https://es.wikipedia.org/wiki/Inventado"}',
            ]]],
        ])]);

        $creadas = app(\App\Services\AI\AiRecommendationService::class)
            ->regenerateForAttempt($intento->fresh(), $alumno->user_id);

        $recurso = collect($creadas)->first(
            fn ($r) => ($r->recommendation_type?->value ?? $r->recommendation_type) === 'resource'
        );

        $this->assertSame('https://es.wikipedia.org/wiki/Real', $recurso->resource['url']);
    }

    public function test_el_estilo_lector_no_recibe_video_del_catalogo(): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response('{}', 200)]);
        [$alumno, $exam, $materia, $grupo, $inst] = $this->escenario(3, LearningStyle::Lector);
        $this->videoCatalogo($inst, $grupo, $materia, ['url' => 'https://www.youtube.com/watch?v=algo']);

        $this->assertNull(app(FormatoPorEstilo::class)->videoPara(LearningStyle::Lector, $exam, $alumno));
    }
}
