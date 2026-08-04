<?php

namespace Tests\Feature\Integration;

use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\ExamAttempt;
use App\Models\Students\Student;
use App\Models\Students\StudentProgress;
use App\Services\Students\StudentProgressService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Nivel 7 — Ciclo académico de fin de año, end-to-end.
 *
 * Ejercita todo lo añadido el 31/07/2026 en un único recorrido realista:
 * catálogo de materias admin-only con nombre único, membresía de grupo,
 * reasignación masiva (promovidos + repitentes + alumnos nuevos), replan de
 * materias y reseteo de progreso con su marca de corte.
 *
 * Escenario: institución con 7º A del año 2026. De 5 estudiantes, 3 promueven
 * a 8º A 2027 y 2 repiten en 7º A 2027. Llegan 2 alumnos nuevos a 7º A 2027.
 */
class Level7_AcademicCycleTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->admin = $this->signInAdmin(['institution_id' => $this->institution->id]);
    }

    private function nuevoEstudiante(): string
    {
        $user = User::factory()->student()->create(['institution_id' => $this->institution->id]);
        Student::factory()->create([
            'user_id'        => $user->id,
            'institution_id' => $this->institution->id,
            'grade'          => 7,
            'section'        => 'A',
            'year'           => 2026,
        ]);

        return $user->id;
    }

    private function membresiaActiva(string $groupId, string $studentId): bool
    {
        return DB::table('group_students')
            ->where('group_id', $groupId)
            ->where('student_user_id', $studentId)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * El recorrido completo en un solo test: cada paso depende del estado que
     * dejó el anterior, que es justamente lo que un test de integración debe
     * verificar y lo que se perdería troceándolo.
     */
    public function test_full_year_end_promotion_cycle(): void
    {
        /* ---------------------------------------------------------------
         | 1. Catálogo de materias — admin-only y nombre único
         --------------------------------------------------------------- */

        $mate7 = $this->postJson('/api/subjects', ['name' => 'Matemática 7º'])
            ->assertCreated()->json('data.id');
        $mate8 = $this->postJson('/api/subjects', ['name' => 'Matemática 8º'])
            ->assertCreated()->json('data.id');
        $lengua7 = $this->postJson('/api/subjects', ['name' => 'Lengua 7º'])
            ->assertCreated()->json('data.id');

        // Mismo grado distinto nivel convive; un duplicado real no
        $this->postJson('/api/subjects', ['name' => '  MATEMÁTICA 7º '])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        // Un docente no puede tocar el catálogo
        $teacher = User::factory()->teacher()->create(['institution_id' => $this->institution->id]);
        $this->actingAs($teacher, 'sanctum');
        $this->postJson('/api/subjects', ['name' => 'Física 7º'])->assertForbidden();
        $this->deleteJson("/api/subjects/{$lengua7}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum');

        /* ---------------------------------------------------------------
         | 2. Grupos del año viejo y del nuevo
         --------------------------------------------------------------- */

        $g7_2026 = Group::factory()->create([
            'institution_id' => $this->institution->id,
            'name' => '7-A 2026', 'grade' => 7, 'section' => 'A',
            'year' => 2026, 'group_code' => '7A-2026',
        ]);
        $g7_2027 = Group::factory()->create([
            'institution_id' => $this->institution->id,
            'name' => '7-A 2027', 'grade' => 7, 'section' => 'A',
            'year' => 2027, 'group_code' => '7A-2027',
        ]);
        $g8_2027 = Group::factory()->create([
            'institution_id' => $this->institution->id,
            'name' => '8-A 2027', 'grade' => 8, 'section' => 'A',
            'year' => 2027, 'group_code' => '8A-2027',
        ]);

        /* ---------------------------------------------------------------
         | 3. Población del año 2026 y su historial
         --------------------------------------------------------------- */

        $promovidos = [$this->nuevoEstudiante(), $this->nuevoEstudiante(), $this->nuevoEstudiante()];
        $repitentes = [$this->nuevoEstudiante(), $this->nuevoEstudiante()];
        $todos2026  = [...$promovidos, ...$repitentes];

        $this->postJson("/api/groups/{$g7_2026->id}/students", [
            'student_user_ids' => $todos2026,
        ])->assertOk();

        $this->assertSame(5, (int) $g7_2026->fresh()->student_count);

        // Plan de materias de 7º para todos
        $this->postJson('/api/bulk/reassign-subjects', [
            'from_group_id' => $g7_2026->id,
            'subject_ids'   => [$mate7, $lengua7],
            'mode'          => 'replace',
        ])->assertOk();

        $this->assertSame(10, DB::table('student_subjects')
            ->whereIn('student_user_id', $todos2026)->count());

        // Historial del año: examen rendido con nota alta
        $examen2026 = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'subject_id'            => $mate7,
            'created_by_teacher_id' => $teacher->id,
        ]);

        foreach ($todos2026 as $id) {
            ExamAttempt::factory()->create([
                'institution_id'  => $this->institution->id,
                'exam_id'         => $examen2026->id,
                'student_user_id' => $id,
                'submitted_at'    => now()->subMonths(2),
                'score'           => 88,
                'max_score'       => 100,
            ]);
            StudentProgress::create([
                'institution_id'     => $this->institution->id,
                'student_user_id'    => $id,
                'subject_id'         => $mate7,
                'mastery_percentage' => 88,
                'updated_at'         => now()->subMonths(2),
            ]);
        }

        /* ---------------------------------------------------------------
         | 4. Promoción — el ORDEN es lo que evita necesitar un "excluir"
         --------------------------------------------------------------- */

        // 4a. Primero los repitentes, por lista explícita → 7º A 2027
        $resRep = $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => $repitentes,
            'to_group_id'      => $g7_2027->id,
        ]);
        $resRep->assertOk();
        $this->assertSame(2, $resRep->json('data.moved'));

        // 4b. El resto por grupo origen: los repitentes YA salieron de 7A-2026,
        //     así que from_group_id devuelve solo a los promovidos.
        $resProm = $this->postJson('/api/bulk/reassign-group', [
            'from_group_id' => $g7_2026->id,
            'to_group_id'   => $g8_2027->id,
        ]);
        $resProm->assertOk();
        $this->assertSame(3, $resProm->json('data.moved'), 'Solo los 3 promovidos, no los 5');

        // Ningún repitente acabó en 8º
        foreach ($repitentes as $id) {
            $this->assertFalse($this->membresiaActiva($g8_2027->id, $id));
            $this->assertTrue($this->membresiaActiva($g7_2027->id, $id));
        }
        foreach ($promovidos as $id) {
            $this->assertTrue($this->membresiaActiva($g8_2027->id, $id));
        }

        // El grupo viejo quedó vacío y los contadores cuadran
        $this->assertSame(0, (int) $g7_2026->fresh()->student_count);
        $this->assertSame(2, (int) $g7_2027->fresh()->student_count);
        $this->assertSame(3, (int) $g8_2027->fresh()->student_count);

        // Campos denormalizados sincronizados, incluido `year`
        $this->assertDatabaseHas('students', [
            'user_id' => $repitentes[0], 'grade' => 7, 'section' => 'A',
            'year' => 2027, 'group_code' => '7A-2027',
        ]);
        $this->assertDatabaseHas('students', [
            'user_id' => $promovidos[0], 'grade' => 8, 'section' => 'A',
            'year' => 2027, 'group_code' => '8A-2027',
        ]);

        // El historial de membresía del año viejo se conserva
        $this->assertDatabaseHas('group_students', [
            'group_id' => $g7_2026->id, 'student_user_id' => $promovidos[0],
        ]);

        /* ---------------------------------------------------------------
         | 5. Alumnos nuevos de matrícula
         --------------------------------------------------------------- */

        $nuevos = [$this->nuevoEstudiante(), $this->nuevoEstudiante()];

        $this->postJson("/api/groups/{$g7_2027->id}/students", [
            'student_user_ids' => $nuevos,
        ])->assertOk();

        $this->assertSame(4, (int) $g7_2027->fresh()->student_count, '2 repitentes + 2 nuevos');

        /* ---------------------------------------------------------------
         | 6. Replan de materias del año nuevo
         --------------------------------------------------------------- */

        // 8º: plan nuevo, se va Matemática 7º
        $this->postJson('/api/bulk/reassign-subjects', [
            'from_group_id' => $g8_2027->id,
            'subject_ids'   => [$mate8],
            'mode'          => 'replace',
        ])->assertOk();

        foreach ($promovidos as $id) {
            $materias = DB::table('student_subjects')->where('student_user_id', $id)
                ->pluck('subject_id')->all();
            $this->assertSame([$mate8], $materias);
        }

        // 7º 2027: repitentes conservan su plan, los nuevos lo estrenan
        $inscripcionPrevia = DB::table('student_subjects')
            ->where('student_user_id', $repitentes[0])->where('subject_id', $mate7)
            ->value('enrolled_at');

        $this->postJson('/api/bulk/reassign-subjects', [
            'from_group_id' => $g7_2027->id,
            'subject_ids'   => [$mate7, $lengua7],
            'mode'          => 'replace',
        ])->assertOk();

        // replace NO reinicia lo que ya existía: el enrolled_at original sigue
        $this->assertSame($inscripcionPrevia, DB::table('student_subjects')
            ->where('student_user_id', $repitentes[0])->where('subject_id', $mate7)
            ->value('enrolled_at'));

        foreach ($nuevos as $id) {
            $this->assertSame(2, DB::table('student_subjects')->where('student_user_id', $id)->count());
        }

        /* ---------------------------------------------------------------
         | 7. Reseteo de progreso — solo repitentes
         --------------------------------------------------------------- */

        $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => $repitentes,
        ])->assertOk();

        foreach ($repitentes as $id) {
            $fila = DB::table('student_progress')
                ->where('student_user_id', $id)->where('subject_id', $mate7)->first();

            $this->assertSame('0.00', (string) $fila->mastery_percentage);
            $this->assertNotNull($fila->reset_at);
            $this->assertSame('0.00', (string) Student::query()->where('user_id', $id)->value('overall_average'));
        }

        // Los promovidos conservan su progreso: el reseteo no los tocó
        $this->assertSame('88.00', (string) DB::table('student_progress')
            ->where('student_user_id', $promovidos[0])->where('subject_id', $mate7)
            ->value('mastery_percentage'));

        // El historial de intentos del repitente sigue intacto
        $this->assertSame(1, DB::table('exam_attempts')
            ->where('student_user_id', $repitentes[0])->count());

        /* ---------------------------------------------------------------
         | 8. El corte aguanta el ciclo siguiente
         --------------------------------------------------------------- */

        $servicio = app(StudentProgressService::class);

        // Un recálculo inmediato NO debe resucitar el 88 del año pasado
        $servicio->recalcFromAttempts($repitentes[0], $mate7);
        $this->assertSame('0.00', (string) DB::table('student_progress')
            ->where('student_user_id', $repitentes[0])->where('subject_id', $mate7)
            ->value('mastery_percentage'));

        // Pero un examen del año nuevo sí cuenta, y solo él
        $examen2027 = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'subject_id'            => $mate7,
            'created_by_teacher_id' => $teacher->id,
        ]);
        ExamAttempt::factory()->create([
            'institution_id'  => $this->institution->id,
            'exam_id'         => $examen2027->id,
            'student_user_id' => $repitentes[0],
            'submitted_at'    => now()->addMinute(),
            'score'           => 60,
            'max_score'       => 100,
        ]);

        $servicio->recalcFromAttempts($repitentes[0], $mate7);

        // 60, no 74 (que sería el promedio con el 88 del año anterior)
        $this->assertSame('60.00', (string) DB::table('student_progress')
            ->where('student_user_id', $repitentes[0])->where('subject_id', $mate7)
            ->value('mastery_percentage'));

        // Y el promovido, que nunca se reseteó, sigue promediando su historial
        $servicio->recalcFromAttempts($promovidos[0], $mate7);
        $this->assertSame('88.00', (string) DB::table('student_progress')
            ->where('student_user_id', $promovidos[0])->where('subject_id', $mate7)
            ->value('mastery_percentage'));
    }

    /**
     * Aislamiento multi-tenant del ciclo: ningún endpoint masivo puede alcanzar
     * datos de otra institución, ni siquiera pasando ids válidos de la otra.
     */
    public function test_bulk_cycle_cannot_cross_tenant_boundaries(): void
    {
        $otra = Institution::factory()->create();

        $grupoAjeno = Group::factory()->create(['institution_id' => $otra->id]);
        $materiaAjena = Subject::factory()->create(['institution_id' => $otra->id]);

        $userAjeno = User::factory()->student()->create(['institution_id' => $otra->id]);
        Student::factory()->create(['user_id' => $userAjeno->id, 'institution_id' => $otra->id]);

        $grupoPropio = Group::factory()->create(['institution_id' => $this->institution->id]);
        $propio = $this->nuevoEstudiante();

        // Grupo destino ajeno → 404
        $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => [$propio],
            'to_group_id'      => $grupoAjeno->id,
        ])->assertNotFound();

        // Grupo origen ajeno → 404
        $this->postJson('/api/bulk/reassign-group', [
            'from_group_id' => $grupoAjeno->id,
            'to_group_id'   => $grupoPropio->id,
        ])->assertNotFound();

        // Materia ajena → 422
        $this->postJson('/api/bulk/reassign-subjects', [
            'student_user_ids' => [$propio],
            'subject_ids'      => [$materiaAjena->id],
            'mode'             => 'add',
        ])->assertStatus(422);

        // Estudiante ajeno con id válido → se ignora, no se mueve
        $res = $this->postJson('/api/bulk/reassign-group', [
            'student_user_ids' => [$userAjeno->id],
            'to_group_id'      => $grupoPropio->id,
        ]);
        $res->assertOk();
        $this->assertSame(0, $res->json('data.moved'));
        $this->assertSame([$userAjeno->id], $res->json('data.skipped'));

        // Y su progreso tampoco es reseteable desde esta institución
        StudentProgress::withoutGlobalScope('tenant')->create([
            'institution_id'     => $otra->id,
            'student_user_id'    => $userAjeno->id,
            'subject_id'         => $materiaAjena->id,
            'mastery_percentage' => 77,
            'updated_at'         => now(),
        ]);

        $this->postJson('/api/bulk/reset-progress', [
            'student_user_ids' => [$userAjeno->id],
        ])->assertOk();

        $this->assertSame('77.00', (string) DB::table('student_progress')
            ->where('student_user_id', $userAjeno->id)->value('mastery_percentage'));
    }
}
