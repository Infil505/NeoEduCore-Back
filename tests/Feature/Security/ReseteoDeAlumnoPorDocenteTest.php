<?php

namespace Tests\Feature\Security;

use App\Jobs\EnviarEnlaceDeAlta;
use App\Mail\ContrasenaTemporalMail;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * El docente restablece la contraseña de UN ALUMNO SUYO y el alumno recibe una
 * temporal por correo; el docente nunca la ve. Solo de sus alumnos
 * (`teacher_assignments`), igual que el resto del alcance docente.
 */
class ReseteoDeAlumnoPorDocenteTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function url(User $alumno): string
    {
        return "/api/students/{$alumno->id}/reset-password";
    }

    public function test_el_docente_restablece_a_su_alumno_y_el_alumno_recibe_la_temporal(): void
    {
        Mail::fake();
        $this->actingAs($this->docente, 'sanctum');

        $respuesta = $this->postJson($this->url($this->alumnoA))->assertOk();

        // El job corre en la cola sync: el correo sale al correo del ALUMNO.
        Mail::assertSent(ContrasenaTemporalMail::class, fn ($m) => $m->hasTo($this->alumnoA->email));

        $alumno = $this->alumnoA->fresh();
        $this->assertTrue($alumno->must_change_password);
        $enviada = Mail::sent(ContrasenaTemporalMail::class)->first()->temporal;
        $this->assertTrue(Hash::check($enviada, $alumno->password_hash));

        // El docente no recibe la clave ni en la respuesta.
        $this->assertStringNotContainsString($enviada, $respuesta->getContent());
    }

    public function test_el_docente_no_puede_restablecer_a_un_alumno_ajeno(): void
    {
        Queue::fake();
        $this->actingAs($this->docente, 'sanctum');

        $this->postJson($this->url($this->alumnoB))->assertForbidden();

        Queue::assertNotPushed(EnviarEnlaceDeAlta::class);
        $this->assertFalse((bool) $this->alumnoB->fresh()->must_change_password);
    }

    public function test_el_administrador_tambien_puede(): void
    {
        Queue::fake();
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson($this->url($this->alumnoB))->assertOk();

        Queue::assertPushed(EnviarEnlaceDeAlta::class, fn ($job) => $job->userId === $this->alumnoB->id);
    }

    public function test_un_alumno_no_puede_restablecer_a_nadie(): void
    {
        Queue::fake();
        $this->actingAs($this->alumnoA, 'sanctum');

        $this->postJson($this->url($this->alumnoA))->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_no_funciona_con_un_docente_ni_con_un_id_inexistente(): void
    {
        Queue::fake();
        $this->actingAs($this->admin, 'sanctum');

        // Un docente no tiene ficha de estudiante: 404, no se le restablece.
        $this->postJson($this->url($this->colega))->assertNotFound();
        $this->postJson('/api/students/' . \Illuminate\Support\Str::uuid() . '/reset-password')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_un_alumno_de_otro_centro_no_se_encuentra(): void
    {
        Queue::fake();
        $otro = Institution::factory()->create();
        $ajeno = User::factory()->student()->create(['institution_id' => $otro->id, 'status' => 'active']);
        \App\Models\Students\Student::withoutGlobalScopes()->create([
            'institution_id' => $otro->id, 'user_id' => $ajeno->id, 'student_code' => 'AJENO-1', 'status' => 'active',
        ]);
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson($this->url($ajeno))->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_una_cuenta_suspendida_no_recibe_acceso(): void
    {
        Queue::fake();
        $this->alumnoA->update(['status' => 'suspended']);
        $this->actingAs($this->docente, 'sanctum');

        $this->postJson($this->url($this->alumnoA))->assertStatus(422);
        Queue::assertNothingPushed();
    }
}
