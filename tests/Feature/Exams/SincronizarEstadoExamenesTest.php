<?php

namespace Tests\Feature\Exams;

use App\Events\ExamenActivado;
use App\Jobs\NotificarExamenDisponible;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * `exams:sincronizar-estados` abre y cierra solo los exámenes por fecha.
 *
 * Lo que importa: abrir por fecha hace LO MISMO que abrir a mano (estado, aviso, calendario
 * del aula y WebSocket), y no toca lo que no le corresponde.
 */
class SincronizarEstadoExamenesTest extends TestCase
{
    use ApiAuth;

    private Institution $institution;
    private User $docente;
    private Subject $materia;
    private Group $grupo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();
        $this->docente = $this->signInTeacher(['institution_id' => $this->institution->id]);
        $this->materia = Subject::factory()->create(['institution_id' => $this->institution->id]);
        $this->grupo   = Group::factory()->create(['institution_id' => $this->institution->id]);

        $this->asignarDocente($this->docente, $this->grupo->id, $this->materia->id);
    }

    private function examen(string $status, $desde, $hasta): Exam
    {
        $exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $this->materia->id,
            'status'                => $status,
            'available_from'        => $desde,
            'available_until'       => $hasta,
        ]);
        Question::factory()->create(['institution_id' => $this->institution->id, 'exam_id' => $exam->id]);
        $exam->syncGroups([$this->grupo->id]);

        return $exam;
    }

    public function test_abre_por_fecha_y_hace_lo_mismo_que_abrirlo_a_mano(): void
    {
        Queue::fake();
        Event::fake([ExamenActivado::class]);

        $alumno = User::factory()->student()->create(['institution_id' => $this->institution->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $alumno->id, 'institution_id' => $this->institution->id]);
        $this->matricularEnGrupo($alumno->id, $this->grupo->id, $this->institution->id);

        $exam = $this->examen('published', now()->subMinute(), now()->addDay());

        $this->artisan('exams:sincronizar-estados')->assertSuccessful();

        $this->assertSame('active', $exam->fresh()->status->value);
        Queue::assertPushed(NotificarExamenDisponible::class, fn ($j) => true);
        Event::assertDispatched(ExamenActivado::class, fn (ExamenActivado $e) => $e->examId === $exam->id
            && in_array((string) $alumno->id, $e->studentIds, true));
        $this->assertSame(1, DB::table('calendar_events')->where('exam_id', $exam->id)->where('group_id', $this->grupo->id)->count());
    }

    public function test_no_abre_antes_de_su_fecha_ni_duplica_el_calendario_al_repetirse(): void
    {
        Queue::fake();
        Event::fake([ExamenActivado::class]);

        $futuro = $this->examen('published', now()->addHour(), now()->addDay());
        $listo  = $this->examen('published', now()->subMinute(), now()->addDay());

        $this->artisan('exams:sincronizar-estados')->assertSuccessful();
        $this->artisan('exams:sincronizar-estados')->assertSuccessful();

        $this->assertSame('published', $futuro->fresh()->status->value);
        $this->assertSame('active', $listo->fresh()->status->value);
        $this->assertSame(1, DB::table('calendar_events')->where('exam_id', $listo->id)->count());
        $this->assertSame(0, DB::table('calendar_events')->where('exam_id', $futuro->id)->count());
    }

    public function test_cierra_al_pasar_la_fecha_de_cierre_y_no_toca_borradores(): void
    {
        Queue::fake();
        Event::fake([ExamenActivado::class]);

        $vencido  = $this->examen('active', now()->subDay(), now()->subMinute());
        $vigente  = $this->examen('active', now()->subHour(), now()->addHour());
        $borrador = $this->examen('draft', now()->subHour(), now()->addHour());

        $this->artisan('exams:sincronizar-estados')->assertSuccessful();

        $this->assertSame('completed', $vencido->fresh()->status->value);
        $this->assertSame('active', $vigente->fresh()->status->value);
        $this->assertSame('draft', $borrador->fresh()->status->value);
    }
}
