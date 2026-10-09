<?php

namespace Tests\Feature\Notifications;

use App\Events\ExamenActivado;
use App\Models\Academic\Group;
use App\Models\Academic\Subject;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Exams\Question;
use App\Models\Students\Student;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Traits\ApiAuth;

/**
 * El alumnado ve el examen activado sin recargar (WebSocket, Laravel Reverb).
 *
 * Lo que se prueba es lo que decide la seguridad: a qué canales se emite y
 * quién puede suscribirse a cada uno. El transporte (Reverb) no se levanta.
 */
class ExamenActivadoEnVivoTest extends TestCase
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

    private function alumno(?string $institutionId = null): User
    {
        $institutionId ??= $this->institution->id;
        $user = User::factory()->student()->create(['institution_id' => $institutionId, 'status' => 'active']);
        Student::factory()->create(['user_id' => $user->id, 'institution_id' => $institutionId]);

        return $user;
    }

    private function examenPublicado(): Exam
    {
        $exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $this->materia->id,
            'status'                => 'published',
            'title'                 => 'Fracciones',
            'available_from'        => null,
            'available_until'       => now()->addWeek(),
        ]);
        Question::factory()->create(['institution_id' => $this->institution->id, 'exam_id' => $exam->id]);
        $exam->syncGroups([$this->grupo->id]);

        return $exam;
    }

    private function canalesEmitidos(Exam $exam): array
    {
        Event::fake([ExamenActivado::class]);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();

        $canales = null;
        Event::assertDispatched(ExamenActivado::class, function (ExamenActivado $e) use (&$canales) {
            $canales = array_map(fn (PrivateChannel $c) => $c->name, $e->broadcastOn());

            return true;
        });

        return $canales;
    }

    private function matriculado(string $status = 'active'): User
    {
        $alumno = $this->alumno();
        $alumno->forceFill(['status' => $status])->save();
        $this->matricularEnGrupo($alumno->id, $this->grupo->id, $this->institution->id);

        return $alumno;
    }

    public function test_se_emite_exactamente_a_quienes_pueden_ver_el_examen(): void
    {
        $exam = $this->examenPublicado();

        $enGrupo = $this->matriculado();

        $seFue = $this->matriculado();
        DB::table('group_students')->where('student_user_id', $seFue->id)->update(['left_at' => now()]);

        $suspendido = $this->matriculado('suspended');

        $otroGrupo = $this->alumno();
        $this->matricularEnGrupo(
            $otroGrupo->id,
            Group::factory()->create(['institution_id' => $this->institution->id])->id,
            $this->institution->id
        );

        $this->assertSame(["private-alumno.{$enGrupo->id}"], $this->canalesEmitidos($exam));
    }

    public function test_el_payload_es_solo_lo_imprescindible(): void
    {
        $exam = $this->examenPublicado();
        $this->matriculado();
        Event::fake([ExamenActivado::class]);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();

        Event::assertDispatched(ExamenActivado::class, fn (ExamenActivado $e) =>
            array_keys($e->broadcastWith()) === ['exam_id', 'title', 'available_from', 'available_until']
            && $e->broadcastAs() === 'examen.activado');
    }

    public function test_con_la_ventana_aun_cerrada_se_avisa_igual_con_las_fechas(): void
    {
        $exam = $this->examenPublicado();
        $exam->update(['available_from' => now()->addDay()]);
        $alumno = $this->matriculado();

        $this->assertSame(["private-alumno.{$alumno->id}"], $this->canalesEmitidos($exam));

        $evento = ExamenActivado::de($exam->fresh());
        $this->assertNotNull($evento->broadcastWith()['available_from']);
        $this->assertNotNull($evento->broadcastWith()['available_until']);
    }

    public function test_otras_transiciones_no_emiten_el_evento(): void
    {
        Event::fake([ExamenActivado::class]);
        $exam = $this->examenPublicado();

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'draft'])->assertOk();

        Event::assertNotDispatched(ExamenActivado::class);
    }

    /* =========================
     |  Quién puede suscribirse
     ========================= */

    private function autenticarCanal(User $user, string $canalDe)
    {
        config([
            'broadcasting.default'                   => 'reverb',
            'broadcasting.connections.reverb.key'    => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        // Cambiar de driver descarta los canales registrados: se vuelven a cargar.
        app('Illuminate\Broadcasting\BroadcastManager')->forgetDrivers();
        require base_path('routes/channels.php');

        Sanctum::actingAs($user);

        return $this->postJson('/api/broadcasting/auth', [
            'socket_id'    => '1234.5678',
            'channel_name' => "private-alumno.{$canalDe}",
        ]);
    }

    public function test_el_alumno_puede_suscribirse_a_su_propio_canal(): void
    {
        $alumno = $this->matriculado();

        $this->autenticarCanal($alumno, $alumno->id)->assertOk()->assertJsonStructure(['auth']);
    }

    public function test_un_alumno_no_puede_escuchar_el_canal_de_otro(): void
    {
        $this->autenticarCanal($this->matriculado(), $this->matriculado()->id)->assertForbidden();
    }

    public function test_un_docente_no_puede_escuchar_el_canal_de_un_alumno(): void
    {
        $this->autenticarCanal($this->docente, $this->matriculado()->id)->assertForbidden();
    }

    public function test_una_cuenta_suspendida_no_puede_suscribirse_ni_a_su_canal(): void
    {
        $suspendido = $this->matriculado('suspended');

        $this->autenticarCanal($suspendido, $suspendido->id)->assertForbidden();
    }

    public function test_sin_token_no_hay_autenticacion_de_canal(): void
    {
        // setUp dejó autenticado al docente: se olvida para simular la ausencia de token.
        app('auth')->forgetGuards();
        $this->app['auth']->shouldUse('sanctum');
        $this->flushHeaders();

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1.2', 'channel_name' => "private-alumno.{$this->docente->id}",
        ])->assertUnauthorized();
    }
}
