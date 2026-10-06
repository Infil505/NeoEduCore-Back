<?php

namespace Tests\Feature\Security;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Segunda pasada de la revisión de roles (05/10/2026).
 *
 * `AlcancePorRolTest` comprueba que el docente solo alcanza a SUS alumnos. Aquí
 * se cierran dos cosas que se le escapaban:
 *
 *  - Las escrituras ligadas a una materia (matrícula, progreso, recomendación
 *    de IA) comprobaban el alumno pero no la materia: un docente de Matemáticas
 *    podía tocarle Lengua a su alumno, o —en `/ai/generate`— escribirle a
 *    cualquiera de la institución.
 *  - `/analytics/institution` devolvía las cifras del centro entero al docente
 *    mientras `/analytics/subjects` ya estaba acotado.
 */
class AlcanceDocentePorMateriaTest extends TestCase
{
    use ApiAuth;

    private Institution $centro;
    private User $docente;
    private User $alumno;
    private Subject $mia;    // la que imparte
    private Subject $ajena;  // la que NO imparte

    protected function setUp(): void
    {
        parent::setUp();

        $this->centro  = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->centro->id]);
        $this->alumno  = $this->alumno();

        $acceso     = $this->darAccesoDocenteA($this->docente, $this->alumno->id, $this->centro->id);
        $this->mia  = $acceso['subject'];
        $this->ajena = Subject::factory()->create(['institution_id' => $this->centro->id]);
    }

    /* ---------------- matrícula ---------------- */

    public function test_docente_matricula_en_su_materia_pero_no_en_una_ajena(): void
    {
        $this->postJson("/api/students/{$this->alumno->id}/subjects", ['subject_id' => $this->ajena->id])
            ->assertForbidden();
        $this->assertDatabaseMissing('student_subjects', [
            'student_user_id' => $this->alumno->id, 'subject_id' => $this->ajena->id,
        ]);

        $this->postJson("/api/students/{$this->alumno->id}/subjects", ['subject_id' => $this->mia->id])
            ->assertCreated();
    }

    public function test_docente_no_desmatricula_de_una_materia_ajena(): void
    {
        DB::table('student_subjects')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'institution_id' => $this->centro->id,
            'student_user_id' => $this->alumno->id,
            'subject_id' => $this->ajena->id,
            'enrolled_at' => now(),
        ]);

        $this->deleteJson("/api/students/{$this->alumno->id}/subjects/{$this->ajena->id}")
            ->assertForbidden();
        $this->assertDatabaseHas('student_subjects', [
            'student_user_id' => $this->alumno->id, 'subject_id' => $this->ajena->id,
        ]);
    }

    public function test_el_admin_sigue_matriculando_en_cualquier_materia(): void
    {
        $this->signInAdmin(['institution_id' => $this->centro->id]);

        $this->postJson("/api/students/{$this->alumno->id}/subjects", ['subject_id' => $this->ajena->id])
            ->assertCreated();
    }

    /* ---------------- progreso ---------------- */

    public function test_docente_fija_progreso_solo_en_su_materia(): void
    {
        $this->postJson('/api/student-progress', [
            'student_user_id' => $this->alumno->id,
            'subject_id' => $this->ajena->id,
            'mastery_percentage' => 90,
        ])->assertForbidden();

        $this->postJson('/api/student-progress', [
            'student_user_id' => $this->alumno->id,
            'subject_id' => $this->mia->id,
            'mastery_percentage' => 90,
        ])->assertCreated();
    }

    public function test_recalc_del_docente_no_toca_progreso_de_materias_ajenas(): void
    {
        $enMia = StudentProgress::factory()->create([
            'institution_id' => $this->centro->id,
            'student_user_id' => $this->alumno->id,
            'subject_id' => $this->mia->id,
            'updated_at' => now()->subDay(),
        ]);
        $enAjena = StudentProgress::factory()->create([
            'institution_id' => $this->centro->id,
            'student_user_id' => $this->alumno->id,
            'subject_id' => $this->ajena->id,
            'updated_at' => now()->subDay(),
        ]);

        $this->postJson('/api/student-progress/recalc')->assertOk()->assertJsonPath('recalculated', 1);

        $this->assertTrue($enMia->fresh()->updated_at->isToday());
        $this->assertFalse($enAjena->fresh()->updated_at->isToday());
    }

    /* ---------------- IA manual ---------------- */

    public function test_generate_rechaza_alumno_o_materia_fuera_de_alcance_sin_llamar_a_openai(): void
    {
        $ajeno = $this->alumno();   // del centro, pero sin asignación

        foreach ([
            [$ajeno->id, $this->mia->id],              // alumno que no es suyo
            [$this->alumno->id, $this->ajena->id],     // materia que no imparte
        ] as [$alumnoId, $materiaId]) {
            // Sin OpenAI::fake(): si la comprobación llegara tarde, esto
            // intentaría salir a la red en lugar de dar 403.
            $this->postJson('/api/ai/generate', [
                'student_user_id' => $alumnoId,
                'subject_id' => $materiaId,
                'type' => 'action',
                'prompt' => 'Refuerza fracciones',
            ])->assertForbidden();
        }
    }

    /* ---------------- analíticas ---------------- */

    public function test_analytics_institution_cuenta_solo_los_alumnos_del_docente(): void
    {
        $ajeno = $this->alumno();
        $this->intentoEntregado($ajeno);
        $this->intentoEntregado($this->alumno);

        $res = $this->getJson('/api/analytics/institution')->assertOk();
        $this->assertSame(1, $res->json('data.total_students'));
        $this->assertSame(1, $res->json('data.exams_completed'));

        $this->signInAdmin(['institution_id' => $this->centro->id]);
        $res = $this->getJson('/api/analytics/institution')->assertOk();
        $this->assertSame(2, $res->json('data.total_students'));
        $this->assertSame(2, $res->json('data.exams_completed'));
    }

    /* ---------------- helpers ---------------- */

    private function alumno(): User
    {
        $user = User::factory()->student()->create(['institution_id' => $this->centro->id]);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $this->centro->id]);

        return $user;
    }

    private function intentoEntregado(User $alumno): void
    {
        ExamAttempt::factory()->create([
            'institution_id' => $this->centro->id,
            'student_user_id' => $alumno->id,
            'submitted_at' => now(),
            'score' => 5,
            'max_score' => 10,
        ]);
    }
}
