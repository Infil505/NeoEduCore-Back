<?php

namespace Tests\Feature\Exams;

use App\Enums\CalendarEventType;
use App\Models\Academic\CalendarEvent;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * Al activar un examen aparece en el calendario de cada aula destino, y lo ve
 * exactamente quien está matriculado en ella (regla de `CalendarEvent`).
 */
class ExamenEnElCalendarioTest extends TestCase
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
        $this->materia = Subject::factory()->create(['institution_id' => $this->institution->id, 'name' => 'Matemática']);
        $this->grupo   = Group::factory()->create(['institution_id' => $this->institution->id]);

        $this->asignarDocente($this->docente, $this->grupo->id, $this->materia->id);
    }

    private function examenPublicado(array $attrs = [], ?Group $otroGrupo = null): Exam
    {
        $exam = Exam::factory()->create($attrs + [
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $this->materia->id,
            'status'                => 'published',
            'title'                 => 'Fracciones',
            'duration_minutes'      => 45,
            'available_from'        => null,
            'available_until'       => null,
        ]);
        Question::factory()->create(['institution_id' => $this->institution->id, 'exam_id' => $exam->id]);
        $exam->syncGroups(array_filter([$this->grupo->id, $otroGrupo?->id]));

        return $exam;
    }

    private function alumnoEn(Group $grupo): User
    {
        $user = User::factory()->student()->create(['institution_id' => $this->institution->id, 'status' => 'active']);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $this->institution->id]);
        $this->matricularEnGrupo($user->id, $grupo->id, $this->institution->id);

        return $user;
    }

    private function activar(Exam $exam): void
    {
        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();
    }

    public function test_activar_crea_un_evento_por_aula_destino(): void
    {
        $otro = Group::factory()->create(['institution_id' => $this->institution->id]);
        $this->asignarDocente($this->docente, $otro->id, $this->materia->id);
        $exam = $this->examenPublicado([], $otro);

        $this->activar($exam);

        $eventos = CalendarEvent::where('exam_id', $exam->id)->get();
        $this->assertCount(2, $eventos);
        $this->assertEqualsCanonicalizing([$this->grupo->id, $otro->id], $eventos->pluck('group_id')->all());

        $e = $eventos->first();
        $this->assertSame(CalendarEventType::Exam, $e->event_type);
        $this->assertSame('Examen: Fracciones', $e->title);
        $this->assertSame('Matemática', $e->description);
        $this->assertSame($this->docente->id, $e->created_by);
        $this->assertSame($this->institution->id, $e->institution_id);
    }

    public function test_las_fechas_son_las_de_la_ventana_del_examen(): void
    {
        // Apertura ya pasada: «Abrir ahora» adelanta una apertura futura a este momento.
        $desde = now()->subHour()->startOfMinute();
        $hasta = now()->addDays(2)->startOfMinute();
        $exam = $this->examenPublicado(['available_from' => $desde, 'available_until' => $hasta]);

        $this->activar($exam);

        $e = CalendarEvent::where('exam_id', $exam->id)->firstOrFail();
        $this->assertTrue($e->start_at->equalTo($desde));
        $this->assertTrue($e->end_at->equalTo($hasta));
    }

    public function test_sin_ventana_dura_lo_que_dura_el_examen(): void
    {
        $exam = $this->examenPublicado();

        $this->activar($exam);

        $e = CalendarEvent::where('exam_id', $exam->id)->firstOrFail();
        $this->assertSame(45, (int) $e->start_at->diffInMinutes($e->end_at));
    }

    public function test_lo_ve_quien_esta_matriculado_en_el_aula_y_nadie_mas(): void
    {
        $exam = $this->examenPublicado();
        $enGrupo = $this->alumnoEn($this->grupo);
        $seFue = $this->alumnoEn($this->grupo);
        DB::table('group_students')->where('student_user_id', $seFue->id)->update(['left_at' => now()]);
        $otroAula = $this->alumnoEn(Group::factory()->create(['institution_id' => $this->institution->id]));

        $this->activar($exam);

        $ids = fn (User $u) => CalendarEvent::visibleTo($u)->where('exam_id', $exam->id)->count();
        $this->assertSame(1, $ids($enGrupo));
        $this->assertSame(0, $ids($seFue));
        $this->assertSame(0, $ids($otroAula));
    }

    public function test_no_duplica_el_evento_que_el_docente_ya_puso(): void
    {
        $exam = $this->examenPublicado();
        CalendarEvent::create([
            'institution_id' => $this->institution->id,
            'title'          => 'A mano',
            'start_at'       => now()->addDay(),
            'end_at'         => now()->addDays(2),
            'event_type'     => CalendarEventType::Exam,
            'exam_id'        => $exam->id,
            'group_id'       => $this->grupo->id,
            'created_by'     => $this->docente->id,
        ]);

        $this->activar($exam);

        $this->assertSame(1, CalendarEvent::where('exam_id', $exam->id)->count());
    }

    public function test_otras_transiciones_no_crean_eventos(): void
    {
        $exam = $this->examenPublicado();

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'draft'])->assertOk();

        $this->assertSame(0, CalendarEvent::where('exam_id', $exam->id)->count());
    }
}
