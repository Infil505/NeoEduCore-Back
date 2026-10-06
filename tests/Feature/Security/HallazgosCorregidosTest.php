<?php

namespace Tests\Feature\Security;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\ExamAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * Comportamiento nuevo que introdujeron las correcciones de los hallazgos de los
 * tests de ataque (05/10/2026). Los `Ataques*Test` comprueban que el agujero ya no
 * está; estos comprueban que lo que se puso en su lugar **hace lo que debe**, y que
 * no rompe lo legítimo (reactivar una cuenta, reanudar un intento, buscar un `%`).
 */
class HallazgosCorregidosTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    private const CLAVE = 'Abcdefg1x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function url(string $ruta, ?ExamAttempt $intento = null): string
    {
        return "/api/exams/{$this->examen->id}/attempts" . ($intento ? "/{$intento->id}" : '') . $ruta;
    }

    /* ============================================================
     | Cuenta y centro activos
     ============================================================ */

    public function test_una_cuenta_reactivada_vuelve_a_entrar_con_un_login_nuevo(): void
    {
        $this->docente->update(['password_hash' => Hash::make(self::CLAVE)]);

        $this->actuarComo($this->admin);
        $this->patchJson("/api/users/{$this->docente->id}/status", ['status' => 'suspended'])->assertOk();
        $this->assertSame(0, $this->docente->tokens()->count(), 'Al suspender deben cerrarse las sesiones abiertas.');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $this->docente->email, 'password' => self::CLAVE])->assertForbidden();

        $this->actuarComo($this->admin);
        $this->patchJson("/api/users/{$this->docente->id}/status", ['status' => 'active'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $this->docente->email, 'password' => self::CLAVE])->assertOk();
    }

    public function test_dar_de_baja_y_reactivar_un_centro_surte_efecto_al_instante(): void
    {
        $token = $this->admin->createToken('t')->plainTextToken;
        $superadmin = User::factory()->superAdmin()->create(['institution_id' => null, 'status' => 'active']);

        // Calienta la caché del estado del centro: la baja tiene que invalidarla.
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->assertTrue(Cache::has("institution.activa.{$this->centro->id}"));

        $this->actuarComo($superadmin);
        $this->patchJson("/api/institutions/{$this->centro->id}/toggle")->assertOk();   // baja

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();

        $this->actuarComo($superadmin);
        $this->patchJson("/api/institutions/{$this->centro->id}/toggle")->assertOk();   // alta

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
    }

    public function test_el_superadmin_no_depende_de_ningun_centro(): void
    {
        $this->centro->update(['is_active' => false]);
        $this->actuarComo(User::factory()->superAdmin()->create(['institution_id' => null, 'status' => 'active']));

        $this->getJson('/api/institutions')->assertOk();
    }

    public function test_una_institucion_inexistente_cuenta_como_inactiva(): void
    {
        $this->assertFalse(Institution::estaActiva('00000000-0000-4000-8000-000000000000'));
    }

    /* ============================================================
     | Intentos de examen
     ============================================================ */

    public function test_un_segundo_start_devuelve_el_intento_en_curso_en_vez_de_crear_otro(): void
    {
        $this->actuarComo($this->alumnoA);
        $this->examen->update(['max_attempts' => 3]);

        $primero = $this->postJson($this->url('/start'))->assertCreated()->json('data.id');
        $segundo = $this->postJson($this->url('/start'))->assertStatus(409);

        $this->assertSame($primero, $segundo->json('data.id'), 'El 409 debe decir cuál es el intento que hay que retomar.');
        $this->assertSame(1, ExamAttempt::where('exam_id', $this->examen->id)->where('student_user_id', $this->alumnoA->id)->count());
    }

    public function test_tras_entregar_se_puede_abrir_el_siguiente_si_quedan_intentos(): void
    {
        $this->actuarComo($this->alumnoA);
        $this->examen->update(['max_attempts' => 2]);

        $primero = ExamAttempt::findOrFail($this->postJson($this->url('/start'))->assertCreated()->json('data.id'));
        $this->postJson($this->url('/submit', $primero), ['answers' => $this->respuestasValidas()])->assertOk();

        $segundo = $this->postJson($this->url('/start'))->assertCreated();
        $this->assertSame(2, $segundo->json('data.attempt_number'));

        $this->postJson($this->url('/submit', ExamAttempt::findOrFail($segundo->json('data.id'))), ['answers' => $this->respuestasValidas()])->assertOk();
        $this->postJson($this->url('/start'))->assertStatus(409);   // ya gastó los dos
    }

    public function test_un_intento_abandonado_y_vencido_gasta_turno(): void
    {
        // Abrió el examen, miró las preguntas y lo dejó pasar el tiempo sin entregar.
        $abandonado = $this->intentoAbierto($this->alumnoA);
        $abandonado->update(['started_at' => now()->subHours(3)]);

        $this->actuarComo($this->alumnoA);   // max_attempts = 1
        $this->postJson($this->url('/start'))->assertStatus(409);

        $this->assertSame(1, ExamAttempt::where('exam_id', $this->examen->id)->where('student_user_id', $this->alumnoA->id)->count());
    }

    public function test_un_abandonado_vencido_deja_abrir_otro_si_aun_hay_intentos(): void
    {
        $this->examen->update(['max_attempts' => 2]);
        $this->intentoAbierto($this->alumnoA)->update(['started_at' => now()->subHours(3)]);

        $this->actuarComo($this->alumnoA);
        $nuevo = $this->postJson($this->url('/start'))->assertCreated();

        $this->assertSame(2, $nuevo->json('data.attempt_number'));
    }

    public function test_dos_alumnos_pueden_tener_cada_uno_su_intento_abierto(): void
    {
        $this->examen->syncGroups([$this->aulaA->id, $this->aulaB->id]);

        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/start'))->assertCreated();
        $this->actuarComo($this->alumnoB);
        $this->postJson($this->url('/start'))->assertCreated();
    }

    /* ============================================================
     | Tope de pausa
     ============================================================ */

    public function test_la_pausa_se_acredita_hasta_el_tope_y_no_mas(): void
    {
        config(['academic.exam.max_pause_seconds' => 600]);
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['paused_at' => now()->subHour()]);

        $this->actuarComo($this->alumnoA);
        $this->patchJson($this->url('/resume', $intento))->assertOk();

        $this->assertSame(600, (int) $intento->fresh()->total_paused_seconds);
    }

    public function test_una_pausa_corta_se_acredita_entera(): void
    {
        config(['academic.exam.max_pause_seconds' => 600]);
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['paused_at' => now()->subSeconds(120)]);

        $this->actuarComo($this->alumnoA);
        $this->patchJson($this->url('/resume', $intento))->assertOk();

        $this->assertEqualsWithDelta(120, (int) $intento->fresh()->total_paused_seconds, 3);
    }

    public function test_con_el_cupo_de_pausa_agotado_no_se_puede_volver_a_pausar(): void
    {
        config(['academic.exam.max_pause_seconds' => 600]);
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['total_paused_seconds' => 600]);

        $this->actuarComo($this->alumnoA);
        $this->patchJson($this->url('/pause', $intento))->assertStatus(409);
        $this->assertNull($intento->fresh()->paused_at);
    }

    public function test_con_el_tope_en_cero_las_pausas_quedan_desactivadas(): void
    {
        config(['academic.exam.max_pause_seconds' => 0]);
        $intento = $this->intentoAbierto($this->alumnoA);

        $this->actuarComo($this->alumnoA);
        $this->patchJson($this->url('/pause', $intento))->assertStatus(409);
    }

    public function test_la_pausa_en_curso_tampoco_estira_el_plazo_mas_alla_del_tope(): void
    {
        config(['academic.exam.max_pause_seconds' => 600]);

        // 60 min de examen; empezó hace 90 y lleva 80 pausado. Con el tope, solo se
        // acreditan 10 min de pausa: el plazo es 60 + 10 = 70 min y ya pasaron 90.
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['started_at' => now()->subMinutes(90), 'paused_at' => now()->subMinutes(80)]);

        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas()])->assertStatus(409);
    }

    public function test_una_pausa_dentro_del_tope_si_alarga_el_plazo(): void
    {
        config(['academic.exam.max_pause_seconds' => 600]);

        // 60 min; empezó hace 65 y estuvo 8 pausado (dentro del tope): plazo 68 min. Entra.
        $intento = $this->intentoAbierto($this->alumnoA);
        $intento->update(['started_at' => now()->subMinutes(65), 'total_paused_seconds' => 480]);

        $this->actuarComo($this->alumnoA);
        $this->postJson($this->url('/submit', $intento), ['answers' => $this->respuestasValidas()])->assertOk();
    }

    /* ============================================================
     | Validación de entrada
     ============================================================ */

    public function test_un_id_que_no_es_uuid_es_un_404_y_los_literales_siguen_funcionando(): void
    {
        $this->actuarComo($this->alumnoA);
        $this->getJson('/api/students/no-es-un-uuid')->assertNotFound();
        $this->getJson('/api/students/me')->assertOk();
        $this->getJson('/api/students/me/subjects')->assertOk();

        $this->actuarComo($this->admin);
        $this->getJson('/api/students/no-es-un-uuid/subjects')->assertNotFound();
        $this->getJson("/api/students/{$this->alumnoA->id}")->assertOk();
        $this->getJson("/api/students/{$this->alumnoA->id}/subjects")->assertOk();
        $this->getJson("/api/analytics/students/{$this->alumnoA->id}")->assertOk();
    }

    public function test_un_filtro_con_un_valor_invalido_es_un_422_que_nombra_el_campo(): void
    {
        $this->actuarComo($this->admin);

        $this->getJson('/api/exams?status=zzz')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->getJson('/api/exams?subject_id=zzz')->assertStatus(422)->assertJsonValidationErrors('subject_id');
        $this->getJson('/api/study-resources?resource_type=zzz')->assertStatus(422)->assertJsonValidationErrors('resource_type');
        $this->getJson('/api/students?status=zzz')->assertStatus(422)->assertJsonValidationErrors('status');

        // Y los valores buenos siguen filtrando.
        $this->getJson('/api/exams?status=active&subject_id=' . $this->materia->id)->assertOk();
        $this->getJson('/api/students?status=active')->assertOk();
    }

    public function test_las_fechas_imposibles_o_fuera_de_rango_se_rechazan_y_las_normales_pasan(): void
    {
        $this->actuarComo($this->docente);
        $base = ['title' => 'Evento', 'event_type' => 'activity', 'group_id' => $this->aulaA->id];

        foreach (['2026-02-30 10:00:00', '@99999999999999', '9999-12-31', '1500-01-01', 'ayer por la tarde'] as $mala) {
            $this->postJson('/api/calendar-events', $base + ['start_at' => $mala, 'end_at' => $mala])
                ->assertStatus(422)->assertJsonValidationErrors('start_at');
        }

        // Formatos habituales de un cliente.
        foreach (['2026-11-05 10:00:00', '2026-11-05T10:00:00', '2026-11-05T10:00:00Z', '2026-11-05T10:00:00-06:00'] as $buena) {
            $this->postJson('/api/calendar-events', $base + ['start_at' => $buena, 'end_at' => '2026-11-06 10:00:00'])->assertCreated();
        }
    }

    public function test_el_fin_no_puede_ser_anterior_al_inicio_pero_si_igual(): void
    {
        $this->actuarComo($this->docente);
        $base = ['title' => 'Evento', 'event_type' => 'activity', 'group_id' => $this->aulaA->id];

        $this->postJson('/api/calendar-events', $base + ['start_at' => '2026-11-05 10:00:00', 'end_at' => '2026-11-04 10:00:00'])
            ->assertStatus(422)->assertJsonValidationErrors('end_at');
        $this->postJson('/api/calendar-events', $base + ['start_at' => '2026-11-05 10:00:00', 'end_at' => '2026-11-05 10:00:00'])
            ->assertCreated();
    }

    public function test_la_disponibilidad_de_un_examen_valida_el_orden_de_las_fechas(): void
    {
        $this->actuarComo($this->docente);
        $base = ['title' => 'Examen', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30];

        $this->postJson('/api/exams', $base + ['available_from' => '2026-11-05', 'available_until' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('available_until');
        $this->postJson('/api/exams', $base + ['available_from' => '2026-11-01', 'available_until' => '2026-11-05'])
            ->assertCreated();
    }

    public function test_el_caracter_nulo_se_elimina_y_no_trunca_el_texto(): void
    {
        $this->actuarComo($this->docente);

        $this->postJson('/api/exams', [
            'title' => "Examen\u{0000} Final", 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30,
        ])->assertCreated();

        // Antes el controlador de la base lo truncaba en silencio: «Examen».
        $this->assertDatabaseHas('exams', ['title' => 'Examen Final']);
    }

    public function test_un_examen_con_materia_de_otro_centro_es_un_422_sobre_ese_campo(): void
    {
        $ajena = \App\Models\Academic\Subject::factory()->create(['institution_id' => Institution::factory()->create()->id]);
        $this->actuarComo($this->docente);

        $this->postJson('/api/exams', ['title' => 'Examen', 'subject_id' => $ajena->id, 'grade' => 4, 'duration_minutes' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('subject_id');

        // Un examen activo no se edita (409): para probar la validación, un borrador.
        $this->examen->update(['status' => 'draft']);
        $propio = $this->examen->fresh();
        $this->putJson("/api/exams/{$propio->id}", ['subject_id' => $ajena->id])->assertStatus(422)->assertJsonValidationErrors('subject_id');
    }

    /* ============================================================
     | Búsqueda
     ============================================================ */

    public function test_buscar_un_porcentaje_encuentra_solo_a_quien_lo_lleva_en_el_nombre(): void
    {
        User::factory()->student()->create(['institution_id' => $this->centro->id, 'full_name' => 'Ana 100% Real', 'email' => 'ana.real@centro.test']);
        User::factory()->student()->create(['institution_id' => $this->centro->id, 'full_name' => 'Beto Guion_Bajo', 'email' => 'beto@centro.test']);
        $this->actuarComo($this->admin);

        $porcentaje = collect($this->getJson('/api/users?q=' . urlencode('%'))->assertOk()->json('data.data'))->pluck('full_name')->all();
        $this->assertSame(['Ana 100% Real'], $porcentaje);

        $guion = collect($this->getJson('/api/users?q=' . urlencode('_'))->assertOk()->json('data.data'))->pluck('full_name')->all();
        $this->assertSame(['Beto Guion_Bajo'], $guion);

        // Una búsqueda normal sigue funcionando, sin distinguir mayúsculas.
        $this->assertCount(1, $this->getJson('/api/users?q=ANA')->assertOk()->json('data.data'));
    }

    public function test_el_login_con_un_correo_que_no_es_texto_responde_422_no_500(): void
    {
        foreach ([['email' => []], ['email' => ['x']], ['email' => ['$ne' => null], 'password' => ['$ne' => null]]] as $cuerpo) {
            $this->postJson('/api/auth/login', $cuerpo)->assertStatus(422);
        }
    }
}
