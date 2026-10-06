<?php

namespace Tests\Feature\Security;

use App\Models\Admin\User;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * **Entrada malformada y hostil.** Ids que no son ids, tipos cambiados, bytes
 * nulos, arrays gigantes, paginación abusiva, comodines de búsqueda.
 *
 * La regla: el servidor **nunca** responde 5xx ante una petición mal formada.
 * Un 500 es un fallo de validación que se coló hasta la base de datos: en
 * producción (`APP_DEBUG=false`) no filtra nada, pero es una puerta abierta a
 * llenar los logs, a tumbar un worker con una petición barata y a esconder
 * ataques reales entre el ruido.
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesDeEntradaMalformadaTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    /**
     * @param  array<int,array{0:string,1:string,2?:array}>  $peticiones
     */
    /**
     * @param  array<int,array{0:string,1:string,2?:array}>  $peticiones
     * @param  array<int,string>  $valoresHostiles  como aparecen en la URL; se sustituyen por `{x}`
     *                                              para que el informe liste cada ENDPOINT una sola vez
     */
    private function exigirSin500(array $peticiones, array $valoresHostiles = [], bool $conCuerpo = false): void
    {
        $fallos = [];

        foreach ($peticiones as $p) {
            $res = $this->json($p[0], $p[1], $p[2] ?? []);

            if ($res->getStatusCode() >= 500) {
                $url = $p[1];
                foreach ($valoresHostiles as $v) {
                    $url = str_replace($v, '{x}', $url);
                }
                $cuerpo = '';
                if ($conCuerpo) {
                    // Solo el campo que se manipuló: el resto de claves del cuerpo es el cuerpo válido de base.
                    $cuerpo = ' ' . Str::limit(json_encode($p[3] ?? $p[2] ?? [], JSON_UNESCAPED_UNICODE), 70, '…');
                }
                $fallos[] = Str::limit("{$p[0]} {$url}", 110, '…') . $cuerpo . " → {$res->getStatusCode()}";
            }
        }

        $fallos = array_values(array_unique($fallos));

        $this->assertSame([], $fallos, count($fallos) . " endpoint(s) con 5xx ante entrada malformada:\n" . implode("\n", $fallos));
    }

    /* ============================================================
     | Ids que no son ids
     ============================================================ */

    public function test_un_id_mal_formado_en_la_ruta_nunca_da_500_para_el_admin(): void
    {
        $this->actuarComo($this->admin);

        $malos = ['no-es-un-uuid', '123', '%00', "x'--", '../../etc/passwd', str_repeat('a', 300), '00000000-0000-0000-0000-00000000000g'];
        $peticiones = [];
        $codificados = array_map('rawurlencode', $malos);

        foreach ($malos as $x) {
            $x = rawurlencode($x);
            foreach ([
                ['GET', "/api/exams/{$x}"], ['GET', "/api/exams/{$x}/questions"], ['PUT', "/api/exams/{$x}", ['title' => 'x']],
                ['PATCH', "/api/exams/{$x}/status", ['status' => 'active']], ['DELETE', "/api/exams/{$x}"],
                ['PUT', "/api/questions/{$x}", ['points' => 1]], ['DELETE', "/api/questions/{$x}"],
                ['GET', "/api/groups/{$x}"], ['PUT', "/api/groups/{$x}", ['name' => 'xx']], ['DELETE', "/api/groups/{$x}"],
                ['POST', "/api/groups/{$x}/students", ['student_user_ids' => [$this->alumnoA->id]]],
                ['GET', "/api/subjects/{$x}"], ['DELETE', "/api/subjects/{$x}"],
                ['GET', "/api/study-resources/{$x}"], ['DELETE', "/api/study-resources/{$x}"],
                ['GET', "/api/calendar-events/{$x}"], ['DELETE', "/api/calendar-events/{$x}"],
                ['GET', "/api/users/{$x}"], ['PUT', "/api/users/{$x}", ['full_name' => 'xx']],
                ['PATCH', "/api/users/{$x}/status", ['status' => 'active']], ['DELETE', "/api/users/{$x}"],
                ['GET', "/api/students/{$x}"], ['PUT', "/api/students/{$x}", ['parent_name' => 'xx']],
                ['PATCH', "/api/students/{$x}/status", ['status' => 'active']], ['GET', "/api/students/{$x}/subjects"],
                ['POST', "/api/students/{$x}/subjects", ['subject_id' => $this->materia->id]],
                ['DELETE', "/api/teacher-assignments/{$x}"],
                ['GET', "/api/exam-attempts/{$x}/answers"], ['PATCH', "/api/student-answers/{$x}/review", ['is_correct' => true, 'points_awarded' => 1]],
                ['GET', "/api/reports/exams/{$x}/results"], ['GET', "/api/reports/exams/{$x}/summary"],
                ['GET', "/api/reports/students/{$x}/history"], ['GET', "/api/reports/students/{$x}/summary"],
                ['GET', "/api/reports/students/{$x}/strategies"], ['GET', "/api/analytics/students/{$x}"],
                ['GET', "/api/ai-recommendations/{$x}"],
            ] as $p) {
                $peticiones[] = $p;
            }
        }

        $this->exigirSin500($peticiones, $codificados);
    }

    public function test_un_id_mal_formado_en_la_ruta_nunca_da_500_para_el_alumno(): void
    {
        $this->actuarComo($this->alumnoA);

        $peticiones = [];
        foreach (['no-es-un-uuid', '123', "x'--", '%00'] as $x) {
            $x = rawurlencode($x);
            foreach ([
                ['POST', "/api/exams/{$x}/attempts/start"], ['GET', "/api/exams/{$x}/attempts/{$x}"],
                ['POST', "/api/exams/{$this->examen->id}/attempts/{$x}/submit", ['answers' => []]],
                ['PATCH', "/api/exams/{$this->examen->id}/attempts/{$x}/pause"], ['PATCH', "/api/exams/{$this->examen->id}/attempts/{$x}/resume"],
                ['GET', "/api/exam-attempts/{$x}/recommendations"], ['POST', "/api/exam-attempts/{$x}/recommendations/regenerate"],
                ['GET', "/api/ai/tutor/sessions/{$x}"], ['PATCH', "/api/ai/tutor/sessions/{$x}/end"],
                ['PATCH', "/api/notifications/{$x}/read"],
            ] as $p) {
                $peticiones[] = $p;
            }
        }

        $this->exigirSin500($peticiones, array_map("rawurlencode", ["no-es-un-uuid", "123", "x'--", "%00"]));
    }

    public function test_un_filtro_de_listado_con_basura_nunca_da_500(): void
    {
        $this->actuarComo($this->admin);

        $basura = ["x'; DROP TABLE users;--", 'no-es-un-uuid', '%', '_', '\\', '[]', '1e999', "\u{202E}rtl", str_repeat('z', 5000)];
        $peticiones = [];

        foreach ($basura as $b) {
            $q = urlencode($b);
            foreach ([
                "/api/exams?subject_id={$q}", "/api/exams?status={$q}", "/api/exams?grade={$q}", "/api/exams?teacher_id={$q}",
                "/api/study-resources?subject_id={$q}", "/api/study-resources?grade={$q}", "/api/study-resources?difficulty={$q}",
                "/api/study-resources?resource_type={$q}",
                "/api/calendar-events?group_id={$q}", "/api/calendar-events?exam_id={$q}", "/api/calendar-events?event_type={$q}",
                "/api/calendar-events?from={$q}&to={$q}",
                "/api/users?q={$q}", "/api/users?status={$q}", "/api/users?user_type={$q}",
                "/api/subjects?search={$q}", "/api/students?search={$q}", "/api/students?grade={$q}", "/api/students?status={$q}",
                "/api/students?group_id={$q}", "/api/student-progress?subject_id={$q}", "/api/ai-recommendations?subject_id={$q}",
                "/api/teacher-assignments?group_id={$q}", "/api/teacher-assignments?teacher_user_id={$q}",
                "/api/reports/topics?subject_id={$q}", "/api/reports/topics?group_id={$q}", "/api/notifications?unread={$q}",
                "/api/reports/exams/{$this->examen->id}/results?page={$q}",
            ] as $url) {
                $peticiones[] = ['GET', $url];
            }
        }

        $this->exigirSin500($peticiones, array_map("urlencode", $basura));
    }

    /* ============================================================
     | Paginación abusiva
     ============================================================ */

    public function test_la_paginacion_abusiva_no_da_500_ni_devuelve_una_pagina_gigante(): void
    {
        $this->actuarComo($this->admin);

        $peticiones = [];
        foreach (['0', '-1', 'abc', '1e30', '99999999999999999999', '[]', '2147483648'] as $v) {
            foreach (['page', 'per_page'] as $param) {
                foreach (['/api/exams', '/api/groups', '/api/subjects', '/api/users', '/api/students', '/api/study-resources', '/api/calendar-events', '/api/notifications'] as $ruta) {
                    $peticiones[] = ['GET', "{$ruta}?{$param}=" . urlencode($v)];
                }
            }
        }
        $this->exigirSin500($peticiones);

        // Y un `per_page` enorme no abre la mano: con él, /subjects o se rechaza o se acota a 100.
        $res = $this->getJson('/api/subjects?per_page=100000');
        $this->assertContains($res->getStatusCode(), [200, 422]);
        if ($res->getStatusCode() === 200) {
            $this->assertLessThanOrEqual(100, count($res->json('data.data')));
        }
    }

    /* ============================================================
     | Tipos cambiados
     ============================================================ */

    public function test_tipos_cambiados_en_el_cuerpo_nunca_dan_500(): void
    {
        $locuras = [[], [[]], ['a' => ['b' => ['c' => 1]]], true, false, 0, -1, 1.5, 1e308, '', ' ', null, '{}'];
        $peticiones = [];

        $this->actuarComo($this->docente);
        foreach ($locuras as $v) {
            foreach (['title', 'subject_id', 'grade', 'duration_minutes', 'max_attempts', 'group_ids', 'instructions', 'video_url',
                      'available_from', 'available_until', 'randomize_questions'] as $campo) {
                $peticiones[] = ['POST', '/api/exams', [
                    'title' => 'Examen', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30, $campo => $v,
                ], [$campo => $v]];
            }
            foreach (['question_text', 'question_type', 'points', 'options', 'correct_answer_text', 'difficulty', 'topic'] as $campo) {
                $peticiones[] = ['POST', "/api/exams/{$this->examen->id}/questions", [
                    'question_text' => 'Pregunta', 'question_type' => 'essay', 'points' => 1, $campo => $v,
                ], [$campo => $v]];
            }
            foreach (['title', 'resource_type', 'url', 'subject_id', 'group_ids', 'grade_min', 'grade_max', 'estimated_duration'] as $campo) {
                $peticiones[] = ['POST', '/api/study-resources', [
                    'title' => 'Recurso', 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/x',
                    'group_ids' => [$this->aulaA->id], $campo => $v,
                ], [$campo => $v]];
            }
            foreach (['title', 'start_at', 'end_at', 'event_type', 'group_id', 'group_ids', 'exam_id'] as $campo) {
                $peticiones[] = ['POST', '/api/calendar-events', [
                    'title' => 'Evento', 'start_at' => now()->addDay()->toDateTimeString(),
                    'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'activity',
                    'group_id' => $this->aulaA->id, $campo => $v,
                ], [$campo => $v]];
            }
            $peticiones[] = ['PATCH', "/api/student-answers/00000000-0000-4000-8000-000000000000/review", ['is_correct' => $v, 'points_awarded' => $v]];
        }
        $this->exigirSin500($peticiones, [], true);

        // El alumno entregando respuestas con la forma equivocada.
        $this->actuarComo($this->alumnoA);
        $intento = $this->intentoAbierto($this->alumnoA);
        $entregas = [];
        foreach ($locuras as $v) {
            $entregas[] = ['POST', "/api/exams/{$this->examen->id}/attempts/{$intento->id}/submit", ['answers' => $v]];
            $entregas[] = ['POST', "/api/exams/{$this->examen->id}/attempts/{$intento->id}/submit", ['answers' => [$v]]];
            $entregas[] = ['POST', "/api/exams/{$this->examen->id}/attempts/{$intento->id}/submit", ['answers' => [
                ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => $v, 'answer_text' => $v],
            ]]];
            $entregas[] = ['POST', "/api/exams/{$this->examen->id}/attempts/{$intento->id}/submit", ['answers' => [
                ['question_id' => $this->preguntaMc->id, 'selected_option_ids' => [$v]],
            ]]];
        }
        $this->exigirSin500($entregas);
    }

    public function test_un_cuerpo_que_no_es_json_valido_no_da_500(): void
    {
        $this->actuarComo($this->docente);

        foreach (['{"roto', '[[[[[[[[[[[[[[[[[[[[[[[[[[[[', '{"a":' . str_repeat('[', 600) . str_repeat(']', 600) . '}', 'not json at all', "\xff\xfe\x00"] as $cuerpo) {
            foreach (['/api/exams', '/api/study-resources', '/api/calendar-events'] as $ruta) {
                $res = $this->call('POST', $ruta, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $cuerpo);
                $this->assertLessThan(500, $res->getStatusCode(), "POST {$ruta} con cuerpo roto respondió {$res->getStatusCode()}");
            }
        }
    }

    /* ============================================================
     | Bytes nulos y textos extremos
     ============================================================ */

    /**
     * El carácter NUL no cabe en un `text` de PostgreSQL: si la validación lo
     * deja pasar, la base lo rechaza y el usuario recibe un 500.
     */
    public function test_el_caracter_nulo_en_un_texto_se_rechaza_o_se_limpia_sin_500(): void
    {
        $nul = "ab\u{0000}cd";
        $fallos = [];

        $this->actuarComo($this->docente);
        foreach ([
            ['POST', '/api/exams', ['title' => $nul, 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30]],
            ['POST', '/api/exams', ['title' => 'Examen', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30, 'instructions' => $nul]],
            ['POST', "/api/exams/{$this->examen->id}/questions", ['question_text' => $nul, 'question_type' => 'essay', 'points' => 1]],
            ['POST', '/api/study-resources', ['title' => $nul, 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/x', 'group_ids' => [$this->aulaA->id]]],
            ['POST', '/api/study-resources', ['title' => 'Recurso', 'description' => $nul, 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/x', 'group_ids' => [$this->aulaA->id]]],
            ['POST', '/api/calendar-events', ['title' => $nul, 'start_at' => now()->addDay()->toDateTimeString(), 'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'activity', 'group_id' => $this->aulaA->id]],
            ['POST', '/api/calendar-events', ['title' => 'Evento', 'description' => $nul, 'start_at' => now()->addDay()->toDateTimeString(), 'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'activity', 'group_id' => $this->aulaA->id]],
            ['PUT', "/api/students/{$this->alumnoA->id}", ['parent_name' => $nul]],
        ] as [$m, $r, $c]) {
            $e = $this->json($m, $r, $c)->getStatusCode();
            if ($e >= 500) {
                $fallos[] = "{$m} {$r} → {$e}";
            }
        }

        $this->actuarComo($this->admin);
        foreach ([
            ['POST', '/api/groups', ['name' => $nul, 'grade' => 4, 'section' => 'A']],
            ['POST', '/api/subjects', ['name' => $nul]],
            ['PUT', "/api/users/{$this->docente->id}", ['full_name' => $nul]],
            ['POST', '/api/register', ['full_name' => $nul, 'email' => 'nul@hack.test', 'user_type' => 'teacher', 'password' => 'Hack12345a', 'password_confirmation' => 'Hack12345a']],
        ] as [$m, $r, $c]) {
            $e = $this->json($m, $r, $c)->getStatusCode();
            if ($e >= 500) {
                $fallos[] = "{$m} {$r} → {$e}";
            }
        }

        $this->actuarComo($this->alumnoA);
        OpenAI::fake([CreateResponse::fake(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Hola']]]])]);
        $e = $this->postJson('/api/ai/tutor/chat', ['message' => "hola{$nul}mundo"])->getStatusCode();
        if ($e >= 500) {
            $fallos[] = "POST /api/ai/tutor/chat → {$e}";
        }

        $this->assertSame([], $fallos, "El carácter nulo llegó hasta la base de datos:\n" . implode("\n", $fallos));
    }

    public function test_textos_gigantes_se_rechazan_sin_500(): void
    {
        $enorme = str_repeat('á', 200_000);
        $peticiones = [];

        $this->actuarComo($this->docente);
        $peticiones[] = ['POST', '/api/exams', ['title' => $enorme, 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30]];
        $peticiones[] = ['POST', '/api/exams', ['title' => 'Examen', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30, 'instructions' => $enorme]];
        $peticiones[] = ['POST', "/api/exams/{$this->examen->id}/questions", ['question_text' => $enorme, 'question_type' => 'essay', 'points' => 1]];
        $peticiones[] = ['POST', '/api/study-resources', ['title' => $enorme, 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/' . $enorme, 'group_ids' => [$this->aulaA->id]]];
        $peticiones[] = ['GET', '/api/users?q=' . urlencode($enorme)];

        $this->exigirSin500($peticiones);

        // Y se rechazan de verdad: ninguno de esos textos entró.
        $this->assertDatabaseMissing('exams', ['title' => $enorme]);

        $this->actuarComo($this->alumnoA);
        $res = $this->postJson('/api/ai/tutor/chat', ['message' => $enorme]);
        $this->assertSame(422, $res->getStatusCode(), 'El tutor aceptó un mensaje de 200 000 caracteres (iría entero al modelo de pago).');
    }

    public function test_arrays_enormes_no_tumban_el_servidor(): void
    {
        $uuids = array_map(fn () => (string) Str::uuid(), range(1, 3000));
        $peticiones = [];

        $this->actuarComo($this->docente);
        $peticiones[] = ['POST', '/api/exams', ['title' => 'Examen', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30, 'group_ids' => $uuids]];
        $peticiones[] = ['POST', '/api/study-resources', ['title' => 'Recurso', 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/x', 'group_ids' => $uuids]];
        $peticiones[] = ['POST', '/api/calendar-events', ['title' => 'Evento', 'start_at' => now()->addDay()->toDateTimeString(), 'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'activity', 'group_ids' => $uuids]];

        $this->actuarComo($this->admin);
        $peticiones[] = ['POST', "/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => $uuids]];
        $peticiones[] = ['DELETE', "/api/groups/{$this->aulaA->id}/students", ['student_user_ids' => $uuids]];
        $peticiones[] = ['POST', '/api/bulk/reassign-group', ['student_user_ids' => $uuids, 'target_group_id' => $this->aulaB->id]];
        $peticiones[] = ['POST', '/api/bulk/reset-progress', ['student_user_ids' => $uuids]];

        $this->exigirSin500($peticiones);

        $this->actuarComo($this->alumnoA);
        $intento = $this->intentoAbierto($this->alumnoA);
        $respuestas = array_map(fn ($u) => ['question_id' => $u, 'answer_text' => 'x'], $uuids);
        $this->exigirSin500([['POST', "/api/exams/{$this->examen->id}/attempts/{$intento->id}/submit", ['answers' => $respuestas]]]);
    }

    public function test_fechas_extremas_no_dan_500(): void
    {
        $fechas = ['0000-00-00', '2026-02-30', '9999-12-31 23:59:59', '1900-01-01', '-0001-01-01', '2026-13-45', 'ayer', '@99999999999999', 'now +1000000 years'];
        $peticiones = [];

        $this->actuarComo($this->docente);
        foreach ($fechas as $f) {
            $peticiones[] = ['POST', '/api/calendar-events', ['title' => 'Evento', 'start_at' => $f, 'end_at' => $f, 'event_type' => 'activity', 'group_id' => $this->aulaA->id]];
            $peticiones[] = ['POST', '/api/exams', ['title' => 'Examen', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30, 'available_from' => $f, 'available_until' => $f]];
            $peticiones[] = ['GET', '/api/calendar-events?from=' . urlencode($f) . '&to=' . urlencode($f)];
            $peticiones[] = ['PUT', "/api/students/{$this->alumnoA->id}", ['birth_date' => $f]];
        }

        $this->exigirSin500($peticiones);
    }

    /* ============================================================
     | Comodines de búsqueda
     ============================================================ */

    /**
     * En un `LIKE`, `%` y `_` son comodines. Si la búsqueda no los escapa, buscar
     * `%` devuelve todo el listado y `_` cualquier nombre de un carácter: no es
     * una fuga de datos que no se pudiera ver igualmente, pero es un filtro que
     * no filtra.
     */
    public function test_los_comodines_de_busqueda_se_tratan_como_texto_literal(): void
    {
        $this->actuarComo($this->admin);

        User::factory()->student()->create(['institution_id' => $this->centro->id, 'full_name' => 'Zacarias Sin Comodin', 'email' => 'zac@centro.test']);

        $total = count($this->getJson('/api/users')->json('data.data'));
        $this->assertGreaterThan(1, $total);

        foreach (['%', '_', '%%', '\\'] as $comodin) {
            $res = $this->getJson('/api/users?q=' . urlencode($comodin))->assertOk();
            $this->assertCount(
                0, $res->json('data.data'),
                "Buscar «{$comodin}» devolvió " . count($res->json('data.data')) . ' de ' . $total . ' cuentas: se usó como comodín.'
            );
        }

        // Las demás búsquedas del sistema, para contraste.
        $this->assertCount(0, $this->getJson('/api/subjects?search=%25')->assertOk()->json('data.data'));
    }
}
