<?php

namespace Tests\Feature\Perf;

use App\Models\AI\AiRecommendation;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\StudyResource;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Exams\QuestionOption;
use App\Models\Students\Student;
use App\Models\Students\StudentAnswer;
use App\Models\Students\StudentProgress;
use App\Support\PreguntasEnLinea;
use App\Support\RespuestasEnLinea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Objetivo: **ninguna respuesta de la API pasa de 2 s** (con la base remota cada consulta
 * cuesta ~0,4 s: token + como mucho 3 consultas de la aplicación).
 *
 * Tres tipos de prueba:
 *  1. **Presupuesto de consultas** por endpoint (el token no se cuenta: `actingAs` lo salta).
 *  2. **Equivalencia**: lo que se trae «en línea» (JOIN / `json_agg`) es idéntico, campo a
 *     campo, a lo que daría `with()`. Sin esto, ahorrar consultas podría cambiar el JSON.
 *  3. **Deriva de columnas**: las listas de columnas que se unen (`COLUMNAS`) son las reales
 *     de la tabla. Si alguien añade una columna y no la lista aquí, el JOIN la perdería.
 */
class ConsultasEnLineaTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    /** Número de consultas que dispara la petición. */
    private function consultas(callable $peticion): int
    {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        $peticion();

        return $n;
    }

    /** Pasa por JSON para comparar lo que de verdad ve el cliente (fechas, ocultos, enums). */
    private function comoJson(mixed $valor): array
    {
        return json_decode(json_encode($valor), true);
    }

    private function conIntentoRespondido(): array
    {
        $intento = $this->intentoEntregado($this->alumnoA);

        $respuesta = StudentAnswer::create([
            'institution_id' => $this->centro->id,
            'attempt_id'     => $intento->id,
            'question_id'    => $this->preguntaMc->id,
            'is_correct'     => true,
            'points_awarded' => 2,
            'answered_at'    => now(),
            'review_status'  => 'auto_graded',
        ]);
        DB::table('student_answer_options')->insert([
            'institution_id'    => $this->centro->id,
            'student_answer_id' => $respuesta->id,
            'option_id'         => $this->opcionCorrecta,
        ]);
        StudentAnswer::create([
            'institution_id' => $this->centro->id,
            'attempt_id'     => $intento->id,
            'question_id'    => $this->preguntaCorta->id,
            'answer_text'    => 'algo',
            'is_correct'     => false,
            'points_awarded' => 0,
            'answered_at'    => now(),
            'review_status'  => 'auto_graded',
        ]);

        return [$intento, $respuesta];
    }

    /* =========================
     |  2. Equivalencia con with()
     ========================= */

    public function test_preguntas_en_linea_son_identicas_a_las_de_with(): void
    {
        $this->montarEscenario();

        $enLinea = PreguntasEnLinea::obtener($this->examen->questions());
        $conWith = $this->examen->questions()->with('options')->get();

        $this->assertCount(2, $enLinea);
        $this->assertEqualsCanonicalizing(
            $this->comoJson($conWith->sortBy('id')->values()),
            $this->comoJson($enLinea->sortBy('id')->values()),
        );
    }

    public function test_el_alumno_no_recibe_respuestas_correctas_por_las_preguntas_en_linea(): void
    {
        $this->montarEscenario();

        $preguntas = $this->comoJson(PreguntasEnLinea::obtener($this->examen->questions()));

        foreach ($preguntas as $pregunta) {
            $this->assertArrayNotHasKey('correct_answer_text', $pregunta);
            foreach ($pregunta['options'] as $opcion) {
                $this->assertArrayNotHasKey('is_correct', $opcion);
            }
        }
    }

    public function test_las_respuestas_en_linea_son_identicas_a_las_de_with(): void
    {
        $this->montarEscenario();
        [$intento] = $this->conIntentoRespondido();

        $enLinea = RespuestasEnLinea::delIntento($intento->id);
        $conWith = StudentAnswer::query()
            ->where('attempt_id', $intento->id)
            ->with(['question.options', 'selectedOptions'])
            ->get();

        $this->assertCount(2, $enLinea);
        $this->assertEqualsCanonicalizing(
            $this->comoJson($conWith->sortBy('id')->values()),
            $this->comoJson($enLinea->sortBy('id')->values()),
        );
    }

    public function test_alumno_con_usuario_en_una_consulta_es_identico_a_with_user(): void
    {
        $this->montarEscenario();

        $enLinea = Student::conUsuario($this->alumnoA->id);
        $conWith = Student::with('user')->where('user_id', $this->alumnoA->id)->first();

        $this->assertSame($this->comoJson($conWith), $this->comoJson($enLinea));
        $this->assertNull(Student::conUsuario('00000000-0000-4000-8000-000000000000'));
    }

    /* =========================
     |  3. Deriva de columnas
     ========================= */

    public function test_las_listas_de_columnas_unidas_son_las_de_la_tabla(): void
    {
        $this->assertEqualsCanonicalizing(Schema::getColumnListing('exams'), Exam::COLUMNAS);
        $this->assertEqualsCanonicalizing(Schema::getColumnListing('questions'), Question::COLUMNAS);
        $this->assertEqualsCanonicalizing(Schema::getColumnListing('students'), Student::COLUMNAS);

        // De `users` no se une lo que el modelo oculta (hash de la clave y token de sesión).
        $esperadas = array_diff(Schema::getColumnListing('users'), ['password_hash', 'remember_token']);
        $this->assertEqualsCanonicalizing($esperadas, Student::COLUMNAS_USUARIO);
    }

    /* =========================
     |  1. Presupuesto de consultas (≤ 3 de la aplicación + token ⇒ ≤ ~2 s)
     ========================= */

    public function test_detalle_de_aviso_y_de_recurso_son_una_consulta(): void
    {
        $this->montarEscenario();
        $aviso = CalendarEvent::create([
            'institution_id' => $this->centro->id, 'title' => 'Aviso', 'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(), 'event_type' => 'activity',
            'group_id' => $this->aulaA->id, 'created_by' => $this->docente->id, 'exam_id' => $this->examen->id,
        ]);
        $recurso = StudyResource::create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id, 'title' => 'rec',
            'resource_type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abc', 'created_by' => $this->docente->id,
        ]);
        $recurso->syncGroups([$this->aulaA->id]);

        $this->actuarComo($this->alumnoA);
        $this->getJson("/api/calendar-events/{$aviso->id}")->assertOk(); // calienta cachés

        $this->assertSame(1, $this->consultas(fn () => $this->getJson("/api/calendar-events/{$aviso->id}")->assertOk()));
        $respuesta = $this->getJson("/api/calendar-events/{$aviso->id}")->json('data');
        $this->assertSame($this->examen->title, $respuesta['exam']['title']);
        $this->assertSame($this->docente->full_name, $respuesta['creator']['full_name']);
        $this->assertArrayNotHasKey('email', $respuesta['creator']);

        $this->assertSame(1, $this->consultas(fn () => $this->getJson("/api/study-resources/{$recurso->id}")->assertOk()));
        $this->assertArrayNotHasKey('groups', $this->getJson("/api/study-resources/{$recurso->id}")->json('data'));

        // El personal sí recibe las aulas del recurso: una consulta más.
        $this->actuarComo($this->docente);
        $this->assertLessThanOrEqual(2, $this->consultas(fn () => $this->getJson("/api/study-resources/{$recurso->id}")->assertOk()));
        $this->assertNotEmpty($this->getJson("/api/study-resources/{$recurso->id}")->json('data.groups'));

        // Lo no visible sigue siendo 404.
        $this->actuarComo($this->alumnoB);
        $this->getJson("/api/calendar-events/{$aviso->id}")->assertNotFound();
        $this->getJson("/api/study-resources/{$recurso->id}")->assertNotFound();
    }

    public function test_detalle_del_examen_y_sus_preguntas_son_pocas_consultas(): void
    {
        $this->montarEscenario();

        foreach ([$this->admin, $this->docente, $this->alumnoA] as $usuario) {
            $this->actuarComo($usuario);
            $this->getJson("/api/exams/{$this->examen->id}")->assertOk(); // calienta cachés

            // examen + aulas + preguntas con opciones.
            $this->assertLessThanOrEqual(
                3,
                $this->consultas(fn () => $this->getJson("/api/exams/{$this->examen->id}")->assertOk()),
                "detalle del examen como {$usuario->user_type->value}"
            );
            $this->assertLessThanOrEqual(
                2,
                $this->consultas(fn () => $this->getJson("/api/exams/{$this->examen->id}/questions")->assertOk()),
                "preguntas del examen como {$usuario->user_type->value}"
            );
        }

        $json = $this->getJson("/api/exams/{$this->examen->id}")->json('data');
        $this->assertSame($this->docente->full_name, $json['teacher']['full_name']);
        $this->assertCount(2, $json['questions']);
        $this->assertArrayNotHasKey('is_correct', $json['questions'][0]['options'][0] ?? []);

        // Ajeno / oculto: 404 como antes.
        $this->actuarComo($this->alumnoB);
        $this->getJson("/api/exams/{$this->examen->id}")->assertNotFound();
        $this->getJson("/api/exams/{$this->examen->id}/questions")->assertNotFound();
        $this->actuarComo($this->colega);
        $this->getJson("/api/exams/{$this->examen->id}")->assertNotFound();
    }

    public function test_el_intento_del_alumno_son_dos_consultas(): void
    {
        $this->montarEscenario();
        [$intento] = $this->conIntentoRespondido();

        $this->actuarComo($this->alumnoA);
        $url = "/api/exams/{$this->examen->id}/attempts/{$intento->id}";
        $this->getJson($url)->assertOk();

        $this->assertLessThanOrEqual(2, $this->consultas(fn () => $this->getJson($url)->assertOk()));

        $json = $this->getJson($url)->json();
        $this->assertCount(2, $json['data']['answers']);
        $this->assertArrayNotHasKey('exam', $json['data'], 'el examen no se devuelve dentro del intento');
        $this->assertFalse($json['meta']['review_shown']);

        // El intento de otro alumno o con otro examen sigue siendo 404.
        $this->actuarComo($this->alumnoB);
        $this->getJson($url)->assertNotFound();
        $this->actuarComo($this->alumnoA);
        $this->getJson("/api/exams/00000000-0000-4000-8000-000000000000/attempts/{$intento->id}")->assertNotFound();
    }

    public function test_respuestas_de_un_intento_para_el_personal_son_dos_consultas(): void
    {
        $this->montarEscenario();
        [$intento] = $this->conIntentoRespondido();

        $this->actuarComo($this->admin);
        $this->getJson("/api/exam-attempts/{$intento->id}/answers")->assertOk();

        $this->assertLessThanOrEqual(2, $this->consultas(fn () => $this->getJson("/api/exam-attempts/{$intento->id}/answers")->assertOk()));
        $ids = collect($this->getJson("/api/exam-attempts/{$intento->id}/answers")->json('data'))->pluck('question.id');
        $this->assertEqualsCanonicalizing([$this->preguntaMc->id, $this->preguntaCorta->id], $ids->all());
    }

    public function test_reportes_y_analitica_del_alumno_son_pocas_consultas(): void
    {
        $this->montarEscenario();
        $this->conIntentoRespondido();
        StudentProgress::create([
            'institution_id' => $this->centro->id, 'student_user_id' => $this->alumnoA->id,
            'subject_id' => $this->materia->id, 'mastery_percentage' => 80, 'updated_at' => now(),
        ]);

        $this->actuarComo($this->admin);
        $id = $this->alumnoA->id;

        foreach ([
            "/api/reports/students/{$id}/history"   => 2,   // alumno+usuario, intentos+examen
            "/api/reports/students/{$id}/summary"   => 3,   // alumno+usuario, intentos+totales, progreso
            "/api/analytics/students/{$id}"         => 3,   // alumno+usuario, progreso, intentos+totales
            '/api/analytics/institution'            => 2,   // alumnado, intentos
        ] as $url => $maximo) {
            $this->getJson($url)->assertOk(); // calienta cachés
            $this->assertLessThanOrEqual($maximo, $this->consultas(fn () => $this->getJson($url)->assertOk()), $url);
        }

        $resumen = $this->getJson("/api/reports/students/{$id}/summary")->json('data');
        $this->assertSame($this->alumnoA->full_name, $resumen['student']['full_name']);
        $this->assertSame(1, $resumen['totals']['attempts']);
        $this->assertCount(1, $resumen['score_trend']);

        $analitica = $this->getJson("/api/analytics/students/{$id}")->json('data');
        $this->assertSame(1, $analitica['attempts_count']);
        $this->assertSame($this->examen->id, $analitica['recent_attempts'][0]['exam']['id']);
        $this->assertSame($this->alumnoA->full_name, $analitica['student']['user']['full_name']);
    }

    public function test_resultados_y_resumen_de_un_examen_son_pocas_consultas(): void
    {
        $this->montarEscenario();
        $this->conIntentoRespondido();
        $this->actuarComo($this->admin);

        foreach ([
            "/api/reports/exams/{$this->examen->id}/results" => 2,   // examen (binding) + intentos con nombre
            "/api/reports/exams/{$this->examen->id}/summary" => 2,   // examen (binding) + agregados con docente
        ] as $url => $maximo) {
            $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual($maximo, $this->consultas(fn () => $this->getJson($url)->assertOk()), $url);
        }

        $this->assertSame($this->alumnoA->full_name, $this->getJson("/api/reports/exams/{$this->examen->id}/results")->json('data.results.data.0.student_name'));
        $resumen = $this->getJson("/api/reports/exams/{$this->examen->id}/summary")->json('data');
        $this->assertSame($this->docente->full_name, $resumen['exam']['teacher']);
        $this->assertSame($this->materia->name, $resumen['exam']['subject']);
    }

    public function test_recomendaciones_y_progreso_del_personal_son_una_consulta(): void
    {
        $this->montarEscenario();
        $recomendacion = AiRecommendation::factory()->create([
            'institution_id' => $this->centro->id, 'student_user_id' => $this->alumnoA->id,
            'subject_id' => $this->materia->id, 'exam_id' => $this->examen->id,
        ]);
        StudentProgress::create([
            'institution_id' => $this->centro->id, 'student_user_id' => $this->alumnoA->id,
            'subject_id' => $this->materia->id, 'mastery_percentage' => 80, 'updated_at' => now(),
        ]);

        $this->actuarComo($this->admin);
        foreach (['/api/ai-recommendations', "/api/ai-recommendations/{$recomendacion->id}", '/api/student-progress'] as $url) {
            $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual(1, $this->consultas(fn () => $this->getJson($url)->assertOk()), $url);
        }

        $fila = $this->getJson('/api/ai-recommendations')->json('data.data.0');
        $this->assertSame($this->alumnoA->full_name, $fila['student']['user']['full_name']);
        $this->assertSame($this->materia->name, $fila['subject']['name']);
        $this->assertSame($this->examen->title, $fila['exam']['title']);

        $progreso = $this->getJson('/api/student-progress')->json('data.data.0');
        $this->assertSame($this->alumnoA->full_name, $progreso['student']['user']['full_name']);
        $this->assertSame($this->materia->name, $progreso['subject']['name']);
    }

    public function test_cada_seccion_del_panel_del_personal_son_tres_consultas_o_menos(): void
    {
        $this->montarEscenario();
        $this->conIntentoRespondido();
        CalendarEvent::create([
            'institution_id' => $this->centro->id, 'title' => 'Aviso', 'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(), 'event_type' => 'activity',
            'group_id' => $this->aulaA->id, 'created_by' => $this->docente->id, 'exam_id' => $this->examen->id,
        ]);
        $recurso = StudyResource::create([
            'institution_id' => $this->centro->id, 'subject_id' => $this->materia->id, 'title' => 'rec',
            'resource_type' => 'video', 'url' => 'https://www.youtube.com/watch?v=abc', 'created_by' => $this->docente->id,
        ]);
        $recurso->syncGroups([$this->aulaA->id]);
        StudentProgress::create([
            'institution_id' => $this->centro->id, 'student_user_id' => $this->alumnoA->id,
            'subject_id' => $this->materia->id, 'mastery_percentage' => 80, 'updated_at' => now(),
        ]);

        foreach ([$this->admin, $this->docente] as $usuario) {
            $this->actuarComo($usuario);

            foreach (['summary', 'users', 'students', 'subjects', 'groups', 'exams', 'calendar', 'resources', 'analytics', 'teachers', 'assignments'] as $seccion) {
                $url = "/api/dashboard/staff-overview?include={$seccion}";
                $this->getJson($url)->assertOk(); // calienta cachés
                // `exams` suma la columna de seguimiento (`ExamMonitorService::resumen`: destinatarios,
                // intentos y fichas, 3 consultas propias): 4 en total. `analytics` del docente calcula
                // sus cifras acotadas (antes veía las del centro entero): también 4. El resto, 3.
                $this->assertLessThanOrEqual(
                    ($seccion === 'exams' || ($seccion === 'analytics' && $usuario->user_type->value === 'teacher')) ? 4 : 3,
                    $this->consultas(fn () => $this->getJson($url)->assertOk()),
                    "include={$seccion} como {$usuario->user_type->value}"
                );
            }
        }

        // La forma de lo unido no cambia.
        $datos = $this->getJson('/api/dashboard/staff-overview?include=exams,calendar,students,resources,analytics')->json('data');
        $this->assertSame($this->docente->full_name, $datos['exams'][0]['teacher']['full_name']);
        $this->assertSame($this->materia->name, $datos['exams'][0]['subject']['name']);
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($datos['exams'][0]['subject']));
        $this->assertSame($this->examen->title, $datos['calendar'][0]['exam']['title']);
        $this->assertSame($this->aulaA->id, $datos['calendar'][0]['group']['id']);
        $this->assertSame($this->docente->full_name, $datos['calendar'][0]['creator']['full_name']);
        $this->assertSame($this->alumnoA->full_name, $datos['students'][0]['user']['full_name']);
        $this->assertSame($this->docente->full_name, $datos['resources'][0]['creator']['full_name']);
        $this->assertNotEmpty($datos['resources'][0]['groups']);
        $this->assertSame(1, $datos['analyticsSubjects'][0]['enrolled_students']);
        $this->assertSame(1, $datos['analyticsSubjects'][0]['exams_count']);
        $this->assertEquals(80.0, $datos['analyticsSubjects'][0]['average_mastery']);
    }

    public function test_el_alcance_del_docente_se_comprueba_en_la_misma_consulta_del_alumno(): void
    {
        $this->montarEscenario();
        $this->conIntentoRespondido();
        $id = $this->alumnoA->id;
        $sinAsignar = \App\Models\Admin\User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);

        // Docente con acceso: la comprobación no añade ninguna consulta.
        $this->actuarComo($this->docente);
        foreach (["/api/students/{$id}" => 1, "/api/reports/students/{$id}/history" => 2, "/api/analytics/students/{$id}" => 3] as $url => $maximo) {
            $this->getJson($url)->assertOk();
            $this->assertLessThanOrEqual($maximo, $this->consultas(fn () => $this->getJson($url)->assertOk()), $url);
        }
        $this->assertArrayNotHasKey('alcanzado_por_docente', $this->getJson("/api/students/{$id}")->json('data'));

        // Docente sin asignación a ese alumno: 403 igual que antes, y sin datos del alumno.
        $this->actuarComo($sinAsignar);
        foreach (["/api/students/{$id}", "/api/reports/students/{$id}/history", "/api/reports/students/{$id}/summary",
                  "/api/reports/students/{$id}/strategies", "/api/analytics/students/{$id}"] as $url) {
            $this->getJson($url)->assertForbidden();
        }

        // Un alumno que no existe sigue siendo 404 (no 403) para el docente.
        $this->actuarComo($this->docente);
        $this->getJson('/api/students/00000000-0000-4000-8000-000000000000')->assertNotFound();
    }

    public function test_un_id_sin_forma_de_uuid_es_404_y_no_llega_a_la_base(): void
    {
        $this->montarEscenario();
        $this->actuarComo($this->alumnoA);
        $uuid = '00000000-0000-4000-8000-000000000000';

        // Estas rutas reciben el id como texto (hacen ellas la consulta única): solo el patrón
        // de ruta las protege de un error de PostgreSQL con `no-es-un-uuid`.
        foreach ([
            "/api/exams/{$uuid}/attempts/no-es-un-uuid",
            "/api/exams/no-es-un-uuid/attempts/{$uuid}",
            '/api/exam-attempts/no-es-un-uuid/recommendations',
            '/api/exams/no-es-un-uuid',
            '/api/calendar-events/no-es-un-uuid',
            '/api/study-resources/no-es-un-uuid',
        ] as $url) {
            $this->getJson($url)->assertNotFound();
        }

        $this->actuarComo($this->admin);
        $this->getJson('/api/ai-recommendations/no-es-un-uuid')->assertNotFound();
    }

    public function test_metricas_de_plataforma_son_tres_consultas(): void
    {
        $superadmin = \App\Models\Admin\User::factory()->create(['user_type' => 'superadmin', 'institution_id' => null, 'status' => 'active']);
        $this->actuarComo($superadmin);
        $this->getJson('/api/platform/ai-tutor-metrics')->assertOk();

        $this->assertLessThanOrEqual(3, $this->consultas(fn () => $this->getJson('/api/platform/ai-tutor-metrics')->assertOk()));
    }
}
