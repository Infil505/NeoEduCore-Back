<?php

namespace Tests\Feature\Notifications;

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
 * O1 — el alumnado recibe un aviso en la app cuando un examen pasa a `active`.
 *
 * El destinatario tiene que ser exactamente quien verá el examen en
 * `available-exams`: miembros vigentes de un grupo destino, con la cuenta
 * activa. Lo que más importa probar es a quién NO le llega.
 */
class ExamAvailableNotificationTest extends TestCase
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

    private function alumno(string $status = 'active', ?string $institutionId = null): User
    {
        $institutionId ??= $this->institution->id;

        $user = User::factory()->student()->create([
            'institution_id' => $institutionId,
            'status'         => $status,
        ]);
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

    private function avisosDe(User $user): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('notifiable_id', $user->id)->get();
    }

    /* =========================
     |  Quién recibe el aviso
     ========================= */

    public function test_activating_an_exam_notifies_exactly_the_students_who_can_take_it(): void
    {
        $exam = $this->examenPublicado();

        $enGrupo = $this->alumno();
        $this->matricularEnGrupo($enGrupo->id, $this->grupo->id, $this->institution->id);

        $seFue = $this->alumno();
        $this->matricularEnGrupo($seFue->id, $this->grupo->id, $this->institution->id);
        DB::table('group_students')->where('student_user_id', $seFue->id)->update(['left_at' => now()]);

        $otroGrupo = $this->alumno();
        $grupoAjeno = Group::factory()->create(['institution_id' => $this->institution->id]);
        $this->matricularEnGrupo($otroGrupo->id, $grupoAjeno->id, $this->institution->id);

        $suspendido = $this->alumno('suspended');
        $this->matricularEnGrupo($suspendido->id, $this->grupo->id, $this->institution->id);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();

        $this->assertCount(1, $this->avisosDe($enGrupo));
        $this->assertCount(0, $this->avisosDe($seFue), 'Quien dejó el grupo no lo verá en available-exams');
        $this->assertCount(0, $this->avisosDe($otroGrupo));
        $this->assertCount(0, $this->avisosDe($suspendido));
        $this->assertCount(0, $this->avisosDe($this->docente));

        $aviso = $this->avisosDe($enGrupo)->first();
        $this->assertSame('exam_available', $aviso->type);

        $data = json_decode($aviso->data, true);
        $this->assertSame($exam->id, $data['exam_id']);
        $this->assertSame('Fracciones', $data['exam_title']);
        $this->assertSame('Matemática', $data['subject_name']);
        $this->assertNotNull($data['available_until']);
    }

    public function test_publishing_alone_does_not_notify(): void
    {
        $exam = Exam::factory()->create([
            'institution_id'        => $this->institution->id,
            'created_by_teacher_id' => $this->docente->id,
            'subject_id'            => $this->materia->id,
            'status'                => 'draft',
            // «Listo» exige una ventana programada en el futuro.
            'available_from'        => now()->addDay(),
            'available_until'       => now()->addDays(2),
        ]);
        Question::factory()->create(['institution_id' => $this->institution->id, 'exam_id' => $exam->id]);
        $exam->syncGroups([$this->grupo->id]);

        $alumno = $this->alumno();
        $this->matricularEnGrupo($alumno->id, $this->grupo->id, $this->institution->id);

        // `published` aún no está delante del alumnado: scopeVisibleTo exige `active`.
        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'published'])->assertOk();

        $this->assertCount(0, $this->avisosDe($alumno));
    }

    /* =========================
     |  Endpoints
     ========================= */

    public function test_a_student_lists_only_their_own_notifications(): void
    {
        $exam = $this->examenPublicado();
        $uno  = $this->alumno();
        $otro = $this->alumno();
        $this->matricularEnGrupo($uno->id, $this->grupo->id, $this->institution->id);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();

        Sanctum::actingAs($uno);
        $res = $this->getJson('/api/notifications')->assertOk();
        $this->assertCount(1, $res->json('data.data'));
        $this->assertSame('exam_available', $res->json('data.data.0.type'));
        $this->assertSame($exam->id, $res->json('data.data.0.data.exam_id'));
        $this->assertSame(1, $res->json('meta.unread_count'));

        Sanctum::actingAs($otro);
        $res = $this->getJson('/api/notifications')->assertOk();
        $this->assertCount(0, $res->json('data.data'));
        $this->assertSame(0, $res->json('meta.unread_count'));
    }

    public function test_the_unread_count_is_cheap_and_only_counts_your_own(): void
    {
        $exam = $this->examenPublicado();
        $uno  = $this->alumno();
        $otro = $this->alumno();
        $this->matricularEnGrupo($uno->id, $this->grupo->id, $this->institution->id);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();

        Sanctum::actingAs($uno);
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $res = $this->getJson('/api/notifications/unread-count')->assertOk();
        $consultasAvisos = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"notifications"'))->count();
        \DB::disableQueryLog();

        $this->assertSame(1, $res->json('data.unread_count'));
        $this->assertSame(1, $consultasAvisos, 'El contador es UNA consulta, no la lista paginada.');

        Sanctum::actingAs($otro);
        $this->assertSame(0, $this->getJson('/api/notifications/unread-count')->json('data.unread_count'));
    }

    public function test_marking_as_read_only_works_on_your_own_notification(): void
    {
        $exam = $this->examenPublicado();
        $uno  = $this->alumno();
        $otro = $this->alumno();
        $this->matricularEnGrupo($uno->id, $this->grupo->id, $this->institution->id);

        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();
        $id = $this->avisosDe($uno)->first()->id;

        // Ajena: 404, igual que una inexistente — no confirma que exista.
        Sanctum::actingAs($otro);
        $this->patchJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));

        Sanctum::actingAs($uno);
        $this->patchJson("/api/notifications/{$id}/read")->assertOk();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));

        $this->assertSame(0, $this->getJson('/api/notifications')->json('meta.unread_count'));
        $this->assertCount(0, $this->getJson('/api/notifications?unread=1')->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/notifications')->json('data.data'));
    }

    public function test_read_all_marks_every_unread_notification(): void
    {
        $alumno = $this->alumno();
        $this->matricularEnGrupo($alumno->id, $this->grupo->id, $this->institution->id);

        foreach ([$this->examenPublicado(), $this->examenPublicado()] as $exam) {
            $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();
        }

        Sanctum::actingAs($alumno);
        $this->assertSame(2, $this->getJson('/api/notifications')->json('meta.unread_count'));

        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.marked', 2);
        $this->assertSame(0, $this->getJson('/api/notifications')->json('meta.unread_count'));
    }

    public function test_deleting_the_user_removes_their_notifications(): void
    {
        $exam   = $this->examenPublicado();
        $alumno = $this->alumno();
        $this->matricularEnGrupo($alumno->id, $this->grupo->id, $this->institution->id);
        $this->patchJson("/api/exams/{$exam->id}/status", ['status' => 'active'])->assertOk();

        $this->assertCount(1, $this->avisosDe($alumno));

        // La FK notifiable_id → users(id) ON DELETE CASCADE: sin ella, un
        // aviso polimórfico quedaría huérfano.
        DB::table('users')->where('id', $alumno->id)->delete();

        $this->assertCount(0, $this->avisosDe($alumno));
    }
}
