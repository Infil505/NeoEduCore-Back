<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\StudyResource;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Ataques **entre instituciones** (multi-tenant).
 *
 * Quien ataca es el administrador —el rol con más poder dentro de su centro—
 * del centro A. Va contra los datos del centro B con todos los verbos. Después
 * de cada ataque se comprueba que **los datos de B quedaron idénticos, fila por
 * fila**, no solo que la respuesta fuera un error.
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesEntreCentrosTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    private Institution $centroB;
    private User $docenteB;
    private User $alumnoDeB;
    private Group $aulaDeB;
    private Subject $materiaDeB;
    private Exam $examenDeB;
    private Question $preguntaDeB;
    private ExamAttempt $intentoDeB;
    private StudyResource $recursoDeB;
    private CalendarEvent $eventoDeB;
    private string $asignacionDeB;

    /** Tablas con datos del centro, para comparar antes y después. */
    private const TABLAS = [
        'groups', 'subjects', 'exams', 'questions', 'study_resources', 'calendar_events', 'users', 'students',
        'teacher_assignments', 'group_students', 'student_subjects', 'student_progress', 'exam_attempts',
        'exam_targets', 'ai_recommendations',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();   // el centro A de siempre

        $this->centroB    = Institution::factory()->create();
        $this->docenteB   = User::factory()->teacher()->create(['institution_id' => $this->centroB->id, 'status' => 'active']);
        $this->aulaDeB    = Group::factory()->create(['institution_id' => $this->centroB->id]);
        $this->materiaDeB = Subject::factory()->create(['institution_id' => $this->centroB->id]);
        $this->asignarDocente($this->docenteB, $this->aulaDeB->id, $this->materiaDeB->id);
        $this->asignacionDeB = DB::table('teacher_assignments')->where('teacher_user_id', $this->docenteB->id)->value('id');

        $this->alumnoDeB = User::factory()->student()->create(['institution_id' => $this->centroB->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $this->alumnoDeB->id, 'institution_id' => $this->centroB->id]);
        $this->matricularEnGrupo($this->alumnoDeB->id, $this->aulaDeB->id, $this->centroB->id);

        $this->examenDeB = Exam::factory()->create([
            'institution_id' => $this->centroB->id, 'created_by_teacher_id' => $this->docenteB->id,
            'subject_id' => $this->materiaDeB->id, 'status' => 'active',
        ]);
        $this->examenDeB->syncGroups([$this->aulaDeB->id]);
        $this->preguntaDeB = Question::factory()->create(['institution_id' => $this->centroB->id, 'exam_id' => $this->examenDeB->id]);
        Question::factory()->create(['institution_id' => $this->centroB->id, 'exam_id' => $this->examenDeB->id, 'order_index' => 2]);
        $this->intentoDeB = ExamAttempt::create([
            'institution_id' => $this->centroB->id, 'exam_id' => $this->examenDeB->id,
            'student_user_id' => $this->alumnoDeB->id, 'attempt_number' => 1, 'started_at' => now(),
            'score' => 0, 'max_score' => 5, 'grade_status' => 'pending',
        ]);

        // Progreso del alumno de B: si un ataque lo borra o lo cambia, la huella lo delata.
        StudentProgress::factory()->create([
            'institution_id' => $this->centroB->id, 'student_user_id' => $this->alumnoDeB->id,
            'subject_id' => $this->materiaDeB->id, 'mastery_percentage' => 64.5,
        ]);

        $this->recursoDeB = StudyResource::factory()->create(['institution_id' => $this->centroB->id, 'created_by' => $this->docenteB->id]);
        $this->eventoDeB  = CalendarEvent::factory()->create([
            'institution_id' => $this->centroB->id, 'created_by' => $this->docenteB->id, 'group_id' => $this->aulaDeB->id,
        ]);
    }

    /** Huella de todo lo que es del centro B. */
    private function huellaDeB(): string
    {
        $huella = [];
        foreach (self::TABLAS as $tabla) {
            $huella[$tabla] = DB::table($tabla)->where('institution_id', $this->centroB->id)->orderBy(
                DB::table($tabla)->getConnection()->getSchemaBuilder()->hasColumn($tabla, 'id') ? 'id' : 'institution_id'
            )->get()->toJson();
        }

        return md5(json_encode($huella));
    }

    /**
     * Lanza el ataque y exige un rechazo: ni 2xx, ni 5xx.
     *
     * @return string  mensaje de fallo, o '' si se defendió
     */
    private function ataque(string $metodo, string $ruta, array $cuerpo = []): string
    {
        $estado = $this->json($metodo, $ruta, $cuerpo)->getStatusCode();

        return in_array($estado, [403, 404, 409, 422], true) ? '' : "{$metodo} {$ruta} → {$estado}";
    }

    public function test_el_admin_de_un_centro_no_lee_ni_toca_nada_del_otro(): void
    {
        $this->actuarComo($this->admin);

        $g = $this->aulaDeB->id; $s = $this->materiaDeB->id; $e = $this->examenDeB->id;
        $q = $this->preguntaDeB->id; $r = $this->recursoDeB->id; $v = $this->eventoDeB->id;
        $u = $this->docenteB->id; $a = $this->alumnoDeB->id; $i = $this->intentoDeB->id;
        $pass = ['password' => 'Nueva1234x', 'password_confirmation' => 'Nueva1234x'];

        $antes = $this->huellaDeB();
        $fallos = [];

        foreach ([
            // Aulas
            ['GET', "/api/groups/{$g}"], ['PUT', "/api/groups/{$g}", ['name' => 'Tomada']], ['DELETE', "/api/groups/{$g}"],
            ['POST', "/api/groups/{$g}/students", ['student_user_ids' => [$a]]],
            ['DELETE', "/api/groups/{$g}/students", ['student_user_ids' => [$a]]],
            // Materias
            ['GET', "/api/subjects/{$s}"], ['PUT', "/api/subjects/{$s}", ['name' => 'Tomada']],
            ['PATCH', "/api/subjects/{$s}", ['name' => 'Tomada']], ['DELETE', "/api/subjects/{$s}"],
            // Exámenes y preguntas
            ['GET', "/api/exams/{$e}"], ['GET', "/api/exams/{$e}/questions"],
            ['PUT', "/api/exams/{$e}", ['title' => 'Tomado']], ['PATCH', "/api/exams/{$e}/status", ['status' => 'completed']],
            ['DELETE', "/api/exams/{$e}"],
            ['POST', "/api/exams/{$e}/questions", ['question_text' => '¿Colada?', 'question_type' => 'essay', 'points' => 1]],
            ['PUT', "/api/questions/{$q}", ['points' => 9]], ['DELETE', "/api/questions/{$q}"],
            // Recursos y eventos
            ['GET', "/api/study-resources/{$r}"], ['PUT', "/api/study-resources/{$r}", ['title' => 'Tomado']],
            ['DELETE', "/api/study-resources/{$r}"],
            ['GET', "/api/calendar-events/{$v}"], ['PUT', "/api/calendar-events/{$v}", ['title' => 'Tomado']],
            ['DELETE', "/api/calendar-events/{$v}"],
            // Cuentas
            ['GET', "/api/users/{$u}"], ['PUT', "/api/users/{$u}", ['full_name' => 'Tomado']],
            ['PATCH', "/api/users/{$u}/status", ['status' => 'suspended']],
            ['PATCH', "/api/users/{$u}/reset-password", $pass], ['DELETE', "/api/users/{$u}"],
            ['GET', "/api/users/{$a}"], ['PATCH', "/api/users/{$a}/reset-password", $pass],
            // Alumnado
            ['GET', "/api/students/{$a}"], ['PUT', "/api/students/{$a}", ['parent_name' => 'Tomado']],
            ['PATCH', "/api/students/{$a}/status", ['status' => 'inactive']], ['GET', "/api/students/{$a}/subjects"],
            ['POST', "/api/students/{$a}/subjects", ['subject_id' => $s]],
            ['DELETE', "/api/students/{$a}/subjects/{$s}"],
            // Asignaciones: los tres ingredientes ajenos, de uno en uno y juntos
            ['POST', '/api/teacher-assignments', ['teacher_user_id' => $u, 'group_id' => $g, 'subject_id' => $s]],
            ['POST', '/api/teacher-assignments', ['teacher_user_id' => $this->docente->id, 'group_id' => $g, 'subject_id' => $this->materia->id]],
            ['POST', '/api/teacher-assignments', ['teacher_user_id' => $this->docente->id, 'group_id' => $this->aulaB->id, 'subject_id' => $s]],
            ['POST', '/api/teacher-assignments', ['teacher_user_id' => $u, 'group_id' => $this->aulaB->id, 'subject_id' => $this->materia->id]],
            ['DELETE', "/api/teacher-assignments/{$this->asignacionDeB}"],
            // Entregas, informes, progreso, IA
            ['GET', "/api/exam-attempts/{$i}/answers"], ['GET', "/api/reports/exams/{$e}/results"],
            ['GET', "/api/reports/exams/{$e}/summary"], ['GET', "/api/reports/students/{$a}/history"],
            ['GET', "/api/analytics/students/{$a}"],
            ['POST', '/api/student-progress', ['student_user_id' => $a, 'subject_id' => $s, 'mastery_percentage' => 1]],
            ['POST', '/api/student-progress', ['student_user_id' => $this->alumnoA->id, 'subject_id' => $s, 'mastery_percentage' => 1]],
            ['POST', '/api/ai/generate', ['student_user_id' => $a, 'subject_id' => $s, 'type' => 'action', 'prompt' => 'texto de prueba']],
            // Operaciones masivas
            ['POST', '/api/bulk/reassign-group', ['student_user_ids' => [$a], 'target_group_id' => $this->aulaA->id]],
            ['POST', '/api/bulk/reassign-group', ['student_user_ids' => [$this->alumnoA->id], 'target_group_id' => $g]],
        ] as $paso) {
            $fallo = $this->ataque($paso[0], $paso[1], $paso[2] ?? []);
            if ($fallo !== '') {
                $fallos[] = $fallo;
            }
        }

        // Primero los datos —lo que de verdad importa—, luego los códigos de respuesta.
        $this->assertSame($antes, $this->huellaDeB(), 'Los datos del centro B cambiaron tras los ataques.');
        $this->assertSame([], $fallos, "Respuestas que no fueron un rechazo:\n" . implode("\n", $fallos));
    }

    /**
     * Resetear el progreso de alumnos de OTRO centro: los datos no se tocan
     * (el servicio filtra por tenant). Las operaciones masivas devuelven un
     * resumen en vez de abortar, así que vale un rechazo **o** un 200 que
     * declare honestamente que no afectó a nadie.
     */
    public function test_resetear_el_progreso_de_alumnos_de_otro_centro_no_afecta_a_nadie(): void
    {
        $this->actuarComo($this->admin);
        $antes = $this->huellaDeB();

        $res = $this->postJson('/api/bulk/reset-progress', ['student_user_ids' => [$this->alumnoDeB->id]]);

        $this->assertSame($antes, $this->huellaDeB(), 'El progreso del centro B cambió.');

        if ($res->getStatusCode() === 200) {
            $this->assertSame(0, $res->json('data.affected_students'), 'Dice haber afectado a alumnos de otro centro.');
            $this->assertSame(0, $res->json('data.progress_reset'));
        } else {
            $this->assertContains($res->getStatusCode(), [403, 404, 422]);
        }
    }

    /** Los listados del centro A no mezclan ni una fila del B. */
    public function test_ningun_listado_del_admin_trae_filas_del_otro_centro(): void
    {
        $this->actuarComo($this->admin);

        $ajenos = [
            $this->aulaDeB->id, $this->materiaDeB->id, $this->examenDeB->id, $this->recursoDeB->id,
            $this->eventoDeB->id, $this->docenteB->id, $this->alumnoDeB->id, $this->intentoDeB->id,
        ];

        foreach ([
            '/api/groups', '/api/subjects', '/api/exams', '/api/study-resources', '/api/calendar-events',
            '/api/users', '/api/students', '/api/student-progress', '/api/ai-recommendations',
            '/api/teacher-assignments', '/api/analytics/institution', '/api/analytics/subjects',
            '/api/reports/topics', '/api/reports/ai/tutor-usage', '/api/system/config', '/api/notifications',
        ] as $ruta) {
            $cuerpo = $this->getJson($ruta)->getContent();

            foreach ($ajenos as $id) {
                $this->assertStringNotContainsString($id, $cuerpo, "{$ruta} trae datos del otro centro.");
            }
        }
    }

    /** Un docente cuela ids de otro centro dentro de sus propias creaciones. */
    public function test_un_docente_no_cuela_referencias_a_otro_centro_al_crear(): void
    {
        $this->actuarComo($this->docente);

        $antes = $this->huellaDeB();
        $fallos = [];

        $base = ['title' => 'Colado', 'grade' => 4, 'duration_minutes' => 30];
        $resultados = [
            // Materia, aula o examen del centro B dentro de un examen / recurso / evento del centro A
            'examen con materia de B'      => $this->postJson('/api/exams', $base + ['subject_id' => $this->materiaDeB->id]),
            'examen con aula de B'         => $this->postJson('/api/exams', $base + ['subject_id' => $this->materia->id, 'group_ids' => [$this->aulaDeB->id]]),
            'recurso con materia de B'     => $this->postJson('/api/study-resources', [
                'title' => 'Colado', 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/math/x',
                'subject_id' => $this->materiaDeB->id, 'group_ids' => [$this->aulaA->id],
            ]),
            'recurso con aula de B'        => $this->postJson('/api/study-resources', [
                'title' => 'Colado', 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/math/x',
                'group_ids' => [$this->aulaDeB->id],
            ]),
            'evento con aula de B'         => $this->postJson('/api/calendar-events', [
                'title' => 'Colado', 'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'activity', 'group_id' => $this->aulaDeB->id,
            ]),
            'evento con examen de B'       => $this->postJson('/api/calendar-events', [
                'title' => 'Colado', 'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'exam',
                'group_id' => $this->aulaA->id, 'exam_id' => $this->examenDeB->id,
            ]),
        ];

        foreach ($resultados as $que => $res) {
            if (!in_array($res->getStatusCode(), [403, 404, 422], true)) {
                $fallos[] = "{$que} → {$res->getStatusCode()}";
            }
        }

        $this->assertSame([], $fallos, "Referencias a otro centro aceptadas:\n" . implode("\n", $fallos));
        $this->assertDatabaseMissing('exams', ['title' => 'Colado']);
        $this->assertDatabaseMissing('study_resources', ['title' => 'Colado']);
        $this->assertDatabaseMissing('calendar_events', ['title' => 'Colado']);
        $this->assertSame($antes, $this->huellaDeB());
    }

    public function test_un_alumno_no_alcanza_nada_del_otro_centro(): void
    {
        $this->actuarComo($this->alumnoA);

        $this->getJson("/api/exams/{$this->examenDeB->id}")->assertNotFound();
        $this->postJson("/api/exams/{$this->examenDeB->id}/attempts/start")->assertNotFound();
        $this->getJson("/api/exams/{$this->examenDeB->id}/attempts/{$this->intentoDeB->id}")->assertNotFound();
        $this->getJson("/api/study-resources/{$this->recursoDeB->id}")->assertNotFound();
        $this->getJson("/api/calendar-events/{$this->eventoDeB->id}")->assertNotFound();
        // 404 (no se encuentra, por el aislamiento de centro) o 403: ambos son una defensa.
        $this->assertContains(
            $this->getJson("/api/exam-attempts/{$this->intentoDeB->id}/recommendations")->getStatusCode(), [403, 404]
        );

        // Sin OpenAI::fake(): debe cortar antes de llamar al modelo.
        $this->postJson('/api/ai/tutor/chat', ['message' => 'hola', 'subject_id' => $this->materiaDeB->id])->assertStatus(422);
        $this->postJson('/api/ai/tutor/chat', ['message' => 'hola', 'exam_id' => $this->examenDeB->id])->assertStatus(422);
    }
}
