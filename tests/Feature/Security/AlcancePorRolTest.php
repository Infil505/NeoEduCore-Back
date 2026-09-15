<?php

namespace Tests\Feature\Security;

use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Revisión de alcance por rol, endpoint por endpoint (tarea S1).
 *
 * La pregunta que responde no es «¿llega?» sino **«¿qué ve cuando llega?»**. Los
 * cinco hallazgos anteriores de este proyecto aparecieron por casualidad y
 * ninguno lo cazaba un test: todos eran rutas que respondían 200 con datos que
 * no le correspondían a quien preguntaba.
 *
 * La regla que se comprueba, y que es la del sistema entero: **el docente
 * alcanza a un estudiante solo a través de `teacher_assignments`**. No por haber
 * creado un examen, no por compartir institución. Si una ruta devuelve alumnado,
 * tiene que respetar esa frontera, y da igual por qué puerta se entre.
 *
 * Este test nació de encontrar justamente una puerta paralela: `/students`
 * estaba acotado pero `/users?user_type=student` devolvía a los mismos menores
 * con nombre y correo, sin pasar por la asignación.
 */
class AlcancePorRolTest extends TestCase
{
    use ApiAuth;

    /* =========================================================
     | 1. Docente SIN asignación: no alcanza a ningún estudiante
     ========================================================= */

    /**
     * El listado propio del docente ya estaba bien acotado; se comprueba para
     * que el contraste con el resto de puertas sea explícito.
     */
    public function test_docente_sin_asignacion_no_ve_alumnado_en_students(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);
        $this->alumno($centro);

        $res = $this->getJson('/api/students')->assertOk();

        $this->assertCount(0, $res->json('data.data'));
    }

    /**
     * **La puerta paralela.** `/users` filtra por institución pero no por
     * asignación, así que devolvía el mismo alumnado con `full_name` y `email`.
     */
    public function test_docente_sin_asignacion_no_ve_alumnado_en_users(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);
        $alumno = $this->alumno($centro, 'Alumna Ajena');

        $res = $this->getJson('/api/users?user_type=student')->assertOk();

        $cuerpo = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($alumno->id, $cuerpo, 'Alumnado ajeno filtrado por /users.');
        $this->assertStringNotContainsString('Alumna Ajena', $cuerpo);
        $this->assertStringNotContainsString($alumno->email, $cuerpo);
    }

    public function test_docente_sin_asignacion_no_abre_la_ficha_de_usuario_de_un_alumno(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);
        $alumno = $this->alumno($centro);

        // 404 y no 403: distinguirlos revelaría que ese estudiante existe.
        $this->getJson("/api/users/{$alumno->id}")->assertStatus(404);
    }

    /** Las demás puertas a datos de un estudiante concreto. */
    public function test_docente_sin_asignacion_queda_fuera_de_todo_lo_del_estudiante(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);
        $alumno = $this->alumno($centro);

        foreach ([
            "/api/students/{$alumno->id}",
            "/api/students/{$alumno->id}/subjects",
            "/api/analytics/students/{$alumno->id}",
            "/api/reports/students/{$alumno->id}/history",
            "/api/reports/students/{$alumno->id}/summary",
            "/api/reports/students/{$alumno->id}/strategies",
            "/api/reports/students/{$alumno->id}/history.csv",
            "/api/reports/students/{$alumno->id}/history.xlsx",
        ] as $ruta) {
            $this->getJson($ruta)->assertStatus(403, "Sin asignación no debería entrar a {$ruta}");
        }
    }

    public function test_docente_sin_asignacion_no_ve_progreso_ni_recomendaciones(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);
        $alumno = $this->alumno($centro);

        $materia = Subject::factory()->create(['institution_id' => $centro->id]);
        StudentProgress::factory()->create([
            'institution_id'  => $centro->id,
            'student_user_id' => $alumno->id,
            'subject_id'      => $materia->id,
        ]);

        $this->assertCount(0, $this->getJson('/api/student-progress')->assertOk()->json('data.data'));

        $recomendaciones = $this->getJson('/api/ai-recommendations')->assertOk()->json('data.data');
        $this->assertSame([], $recomendaciones ?? []);
    }

    public function test_docente_sin_asignacion_no_ve_grupos(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);
        $grupo = \App\Models\Academic\Group::factory()->create(['institution_id' => $centro->id]);

        $this->assertCount(0, $this->getJson('/api/groups')->assertOk()->json('data.data'));

        // La ficha del grupo es la lista nominal del aula: la más expuesta.
        $this->getJson("/api/groups/{$grupo->id}")->assertStatus(403);
    }

    /* =========================================================
     | 2. Docente CON asignación: alcanza al suyo, no al de al lado
     ========================================================= */

    public function test_docente_asignado_ve_al_suyo_y_no_al_ajeno(): void
    {
        $centro  = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $centro->id]);

        $mio   = $this->alumno($centro, 'Alumno Propio');
        $ajeno = $this->alumno($centro, 'Alumno Ajeno');

        $this->darAccesoDocenteA($docente, $mio->id, $centro->id);

        $porStudents = json_encode($this->getJson('/api/students')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($mio->id, $porStudents);
        $this->assertStringNotContainsString($ajeno->id, $porStudents);

        $porUsers = json_encode($this->getJson('/api/users?user_type=student')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($mio->id, $porUsers);
        $this->assertStringNotContainsString($ajeno->id, $porUsers);

        $this->getJson("/api/users/{$mio->id}")->assertOk();
        $this->getJson("/api/users/{$ajeno->id}")->assertStatus(404);

        $this->getJson("/api/students/{$mio->id}")->assertOk();
        $this->getJson("/api/students/{$ajeno->id}")->assertStatus(403);
    }

    /**
     * El personal del centro no es alumnado: un docente puede seguir viendo a
     * sus colegas en `/users`. La frontera que se cierra es la de los menores.
     */
    public function test_el_docente_sigue_viendo_al_personal_del_centro(): void
    {
        $centro = Institution::factory()->create();
        $this->signInTeacher(['institution_id' => $centro->id]);

        $colega = User::factory()->teacher()->create(['institution_id' => $centro->id]);

        $cuerpo = json_encode($this->getJson('/api/users')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($colega->id, $cuerpo);
    }

    /* =========================================================
     | 2b. Analíticas por materia: el docente ve lo que imparte (S5)
     ========================================================= */

    public function test_el_docente_solo_ve_las_analiticas_de_sus_materias(): void
    {
        $centro  = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $centro->id]);

        $ajena = Subject::factory()->create(['institution_id' => $centro->id, 'name' => 'Materia Ajena']);
        $alumno = $this->alumno($centro);
        $suya = $this->darAccesoDocenteA($docente, $alumno->id, $centro->id)['subject'];

        $materias = collect($this->getJson('/api/analytics/subjects')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($materias->contains($suya->id), 'Debe ver la materia que imparte.');
        $this->assertFalse($materias->contains($ajena->id), 'No debe ver la de sus colegas.');
    }

    public function test_el_admin_ve_las_analiticas_de_todas_las_materias(): void
    {
        $centro = Institution::factory()->create();
        $this->signInAdmin(['institution_id' => $centro->id]);

        $una = Subject::factory()->create(['institution_id' => $centro->id]);
        $otra = Subject::factory()->create(['institution_id' => $centro->id]);

        $materias = collect($this->getJson('/api/analytics/subjects')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertTrue($materias->contains($una->id));
        $this->assertTrue($materias->contains($otra->id));
    }

    /* =========================================================
     | 2c. Autoría: nadie toca el trabajo de otro docente (S6)
     ========================================================= */

    public function test_un_docente_no_edita_ni_borra_el_evento_de_otro(): void
    {
        $centro = Institution::factory()->create();
        $autor  = User::factory()->teacher()->create(['institution_id' => $centro->id]);

        $evento = \App\Models\Academic\CalendarEvent::factory()->create([
            'institution_id' => $centro->id,
            'created_by'     => $autor->id,
        ]);

        $this->signInTeacher(['institution_id' => $centro->id]);

        $this->putJson("/api/calendar-events/{$evento->id}", ['title' => 'Secuestrado'])
            ->assertStatus(403);
        $this->deleteJson("/api/calendar-events/{$evento->id}")->assertStatus(403);

        $this->assertDatabaseHas('calendar_events', ['id' => $evento->id]);
    }

    public function test_el_docente_si_edita_y_borra_lo_suyo(): void
    {
        $centro  = Institution::factory()->create();
        $docente = $this->signInTeacher(['institution_id' => $centro->id]);

        $evento = \App\Models\Academic\CalendarEvent::factory()->create([
            'institution_id' => $centro->id,
            'created_by'     => $docente->id,
        ]);

        $this->putJson("/api/calendar-events/{$evento->id}", ['title' => 'Repaso de fracciones'])
            ->assertOk();
        $this->deleteJson("/api/calendar-events/{$evento->id}")->assertNoContent();
    }

    /** El admin responde por el centro: ordena el calendario aunque no sea suyo. */
    public function test_el_admin_si_puede_con_el_evento_de_un_docente(): void
    {
        $centro = Institution::factory()->create();
        $autor  = User::factory()->teacher()->create(['institution_id' => $centro->id]);

        $evento = \App\Models\Academic\CalendarEvent::factory()->create([
            'institution_id' => $centro->id,
            'created_by'     => $autor->id,
        ]);

        $this->signInAdmin(['institution_id' => $centro->id]);

        $this->deleteJson("/api/calendar-events/{$evento->id}")->assertNoContent();
    }

    public function test_un_docente_no_edita_ni_borra_el_recurso_de_otro(): void
    {
        $centro = Institution::factory()->create();
        $autor  = User::factory()->teacher()->create(['institution_id' => $centro->id]);

        $recurso = \App\Models\Academic\StudyResource::factory()->create([
            'institution_id' => $centro->id,
            'created_by'     => $autor->id,
        ]);

        $this->signInTeacher(['institution_id' => $centro->id]);

        $this->putJson("/api/study-resources/{$recurso->id}", ['title' => 'Secuestrado'])
            ->assertStatus(403);
        $this->deleteJson("/api/study-resources/{$recurso->id}")->assertStatus(403);

        $this->assertDatabaseHas('study_resources', ['id' => $recurso->id]);
    }

    /**
     * `created_by` es nullable y queda en NULL al borrarse la cuenta que lo
     * creó. Esas entradas huérfanas solo las toca el administrador: dejarlas
     * abiertas a cualquier docente reabriría el agujero por la puerta de atrás.
     */
    public function test_un_recurso_sin_autor_solo_lo_toca_el_admin(): void
    {
        $centro = Institution::factory()->create();

        $recurso = \App\Models\Academic\StudyResource::factory()->create([
            'institution_id' => $centro->id,
            'created_by'     => null,
        ]);

        $this->signInTeacher(['institution_id' => $centro->id]);
        $this->deleteJson("/api/study-resources/{$recurso->id}")->assertStatus(403);

        $this->signInAdmin(['institution_id' => $centro->id]);
        $this->deleteJson("/api/study-resources/{$recurso->id}")->assertNoContent();
    }

    /* =========================================================
     | 3. Estudiante: solo lo suyo
     ========================================================= */

    public function test_el_estudiante_no_abre_el_intento_de_otro(): void
    {
        $centro = Institution::factory()->create();
        $otro   = $this->alumno($centro);

        $examen  = $this->examen($centro);
        $intento = ExamAttempt::factory()->submitted()->create([
            'institution_id'  => $centro->id,
            'exam_id'         => $examen->id,
            'student_user_id' => $otro->id,
        ]);

        $this->signInStudent(['institution_id' => $centro->id]);

        $this->getJson("/api/exams/{$examen->id}/attempts/{$intento->id}")->assertStatus(404);
        $this->getJson("/api/exam-attempts/{$intento->id}/recommendations")->assertStatus(403);
        $this->postJson("/api/exam-attempts/{$intento->id}/recommendations/regenerate")->assertStatus(403);
    }

    public function test_el_estudiante_no_entra_a_las_rutas_de_gestion(): void
    {
        $centro = Institution::factory()->create();
        $alumno = $this->alumno($centro);
        $this->signInStudent(['institution_id' => $centro->id]);

        foreach ([
            '/api/students',
            '/api/users',
            '/api/groups',
            '/api/student-progress',
            '/api/ai-recommendations',
            '/api/analytics/institution',
            '/api/reports/topics',
            "/api/reports/students/{$alumno->id}/history",
        ] as $ruta) {
            $this->getJson($ruta)->assertStatus(403, "El estudiante no debería entrar a {$ruta}");
        }
    }

    /* =========================================================
     | 4. Institución: nadie cruza a otro centro
     ========================================================= */

    public function test_el_admin_no_alcanza_a_nadie_de_otro_centro(): void
    {
        $ajeno  = Institution::factory()->create();
        $suyo   = Institution::factory()->create();

        $alumnoAjeno = $this->alumno($ajeno);
        $grupoAjeno  = \App\Models\Academic\Group::factory()->create(['institution_id' => $ajeno->id]);

        $this->signInAdmin(['institution_id' => $suyo->id]);

        $this->getJson("/api/users/{$alumnoAjeno->id}")->assertStatus(404);
        $this->getJson("/api/students/{$alumnoAjeno->id}")->assertStatus(404);
        $this->getJson("/api/groups/{$grupoAjeno->id}")->assertStatus(404);

        $cuerpo = json_encode($this->getJson('/api/users')->assertOk()->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($alumnoAjeno->id, $cuerpo);
    }

    /* =========================================================
     | 5. Superadmin: externo, y por eso no entra al aula
     ========================================================= */

    public function test_el_superadmin_no_entra_a_los_datos_de_una_institucion(): void
    {
        $centro = Institution::factory()->create();
        $alumno = $this->alumno($centro);

        $this->signInSuperAdmin();

        foreach ([
            '/api/students',
            '/api/users',
            '/api/groups',
            '/api/student-progress',
            '/api/reports/topics',
            "/api/students/{$alumno->id}",
        ] as $ruta) {
            $this->getJson($ruta)->assertStatus(403, "El superadmin no debería entrar a {$ruta}");
        }
    }

    public function test_el_admin_no_entra_a_las_rutas_de_plataforma(): void
    {
        $this->signInAdmin();

        foreach ([
            '/api/institutions',
            '/api/institution-admins',
            '/api/platform/ai-tutor-metrics',
        ] as $ruta) {
            $this->getJson($ruta)->assertStatus(403, "El admin no debería entrar a {$ruta}");
        }
    }

    /* =========================
     | Apoyo
     ========================= */

    private function alumno(Institution $centro, ?string $nombre = null): User
    {
        $user = User::factory()->student()->create(array_filter([
            'institution_id' => $centro->id,
            'full_name'      => $nombre,
        ]));

        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $centro->id,
        ]);

        return $user;
    }

    private function examen(Institution $centro): Exam
    {
        return Exam::factory()->create([
            'institution_id' => $centro->id,
            'subject_id'     => Subject::factory()->create(['institution_id' => $centro->id])->id,
        ]);
    }
}
