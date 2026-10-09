<?php

namespace Tests\Feature\Security;

use App\Models\Academic\CalendarEvent;
use App\Models\Academic\StudyResource;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use App\Models\Exams\Exam;
use App\Models\Students\Student;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * **Asignación masiva y escalada de privilegios.**
 *
 * El atacante añade a la petición campos que el formulario nunca ofrece
 * (`user_type`, `institution_id`, `created_by`, `status`, `password_hash`...) y
 * se comprueba lo único que importa: **el dato guardado no cambió**. Da igual si
 * la respuesta fue 200 (campo ignorado) o 422 (campo rechazado); lo que no puede
 * pasar es que se aplique.
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesDeAsignacionMasivaTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    private Institution $otroCentro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
        $this->otroCentro = Institution::factory()->create();
    }

    private function sinError500($res): void
    {
        $this->assertLessThan(500, $res->getStatusCode(), 'Error del servidor ante una petición hostil: ' . $res->getContent());
    }

    /* ============================================================
     | Crear: el autor y el centro salen de la sesión, no del cuerpo
     ============================================================ */

    public function test_un_examen_nuevo_no_acepta_autor_centro_ni_estado_impuestos(): void
    {
        $this->actuarComo($this->docente);

        $res = $this->postJson('/api/exams', [
            'title' => 'Examen con extras', 'subject_id' => $this->materia->id, 'grade' => 4, 'duration_minutes' => 30,
            'created_by_teacher_id' => $this->colega->id, 'institution_id' => $this->otroCentro->id,
            'status' => 'active', 'id' => '00000000-0000-4000-8000-000000000001',
        ]);
        // Los campos extra se IGNORAN: la petición válida sí crea el examen.
        $res->assertCreated();

        $examen = Exam::withoutGlobalScopes()->where('title', 'Examen con extras')->firstOrFail();
        $this->assertSame($this->docente->id, $examen->created_by_teacher_id, 'El autor lo eligió el cliente.');
        $this->assertSame($this->centro->id, $examen->institution_id, 'El centro lo eligió el cliente.');
        $this->assertSame('draft', $examen->status->value, 'El examen nació con el estado que mandó el cliente.');
        $this->assertNotSame('00000000-0000-4000-8000-000000000001', $examen->id);
    }

    public function test_un_recurso_y_un_evento_nuevos_no_aceptan_autor_ni_centro_impuestos(): void
    {
        $this->actuarComo($this->docente);

        $r = $this->postJson('/api/study-resources', [
            'title' => 'Recurso con extras', 'resource_type' => 'article', 'url' => 'https://es.khanacademy.org/math/x',
            'group_ids' => [$this->aulaA->id], 'created_by' => $this->colega->id, 'institution_id' => $this->otroCentro->id,
        ]);
        $r->assertCreated();

        $e = $this->postJson('/api/calendar-events', [
            'title' => 'Evento con extras', 'start_at' => now()->addDay()->toDateTimeString(),
            'end_at' => now()->addDays(2)->toDateTimeString(), 'event_type' => 'activity', 'group_id' => $this->aulaA->id,
            'created_by' => $this->colega->id, 'institution_id' => $this->otroCentro->id,
        ]);
        $e->assertCreated();

        $recurso = StudyResource::withoutGlobalScopes()->where('title', 'Recurso con extras')->firstOrFail();
        $evento  = CalendarEvent::withoutGlobalScopes()->where('title', 'Evento con extras')->firstOrFail();

        foreach ([$recurso, $evento] as $fila) {
            $this->assertSame($this->docente->id, $fila->created_by, 'El autor lo eligió el cliente.');
            $this->assertSame($this->centro->id, $fila->institution_id, 'El centro lo eligió el cliente.');
        }
    }

    /* ============================================================
     | Editar: lo que no está en la lista blanca no cambia
     ============================================================ */

    public function test_editar_un_examen_no_permite_cambiar_autor_centro_ni_estado(): void
    {
        $borrador = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id,
            'subject_id' => $this->materia->id, 'status' => 'draft',
        ]);
        $this->actuarComo($this->docente);

        $this->putJson("/api/exams/{$borrador->id}", [
            'title' => 'Titulo nuevo', 'created_by_teacher_id' => $this->colega->id,
            'institution_id' => $this->otroCentro->id, 'status' => 'active', 'subject_id' => $this->materia->id,
        ])->assertOk();
        $this->assertSame('Titulo nuevo', $borrador->fresh()->title, 'La edición legítima no se aplicó: el test sería vacío.');

        $fresco = $borrador->fresh();
        $this->assertSame($this->docente->id, $fresco->created_by_teacher_id, 'Se traspasó la autoría del examen.');
        $this->assertSame($this->centro->id, $fresco->institution_id);
        $this->assertSame('draft', $fresco->status->value, 'Se saltó el flujo de estados por PUT.');
    }

    public function test_editar_una_pregunta_no_la_mueve_a_otro_examen_ni_a_otro_centro(): void
    {
        $otro = Exam::factory()->create([
            'institution_id' => $this->centro->id, 'created_by_teacher_id' => $this->docente->id,
            'subject_id' => $this->materia->id, 'status' => 'draft',
        ]);
        $this->actuarComo($this->docente);

        $this->putJson("/api/questions/{$this->preguntaMc->id}", [
            'points' => 5, 'exam_id' => $otro->id, 'institution_id' => $this->otroCentro->id,
        ])->assertOk();
        $this->assertEquals(5, $this->preguntaMc->fresh()->points, 'La edición legítima no se aplicó: el test sería vacío.');

        $fresca = $this->preguntaMc->fresh();
        $this->assertSame($this->examen->id, $fresca->exam_id, 'La pregunta cambió de examen.');
        $this->assertSame($this->centro->id, $fresca->institution_id);
    }

    public function test_un_docente_no_cambia_la_identidad_de_un_alumno_por_la_ficha(): void
    {
        $this->actuarComo($this->docente);
        $fichaAntes = Student::where('user_id', $this->alumnoA->id)->first()->only(['user_id', 'institution_id', 'status', 'grade']);
        $correo = $this->alumnoA->email;

        $this->putJson("/api/students/{$this->alumnoA->id}", [
            'parent_name' => 'Tutor Nuevo',
            'user_id' => $this->alumnoB->id, 'institution_id' => $this->otroCentro->id, 'status' => 'suspended',
            'full_name' => 'Otro Nombre', 'email' => 'otro@hack.test', 'user_type' => 'superadmin', 'password_hash' => 'x',
        ])->assertOk();
        $this->assertSame('Tutor Nuevo', Student::where('user_id', $this->alumnoA->id)->first()->parent_name, 'La edición legítima no se aplicó: el test sería vacío.');

        $ficha = Student::where('user_id', $this->alumnoA->id)->first();
        $this->assertSame($fichaAntes, $ficha->only(['user_id', 'institution_id', 'status', 'grade']));

        $usuario = $this->alumnoA->fresh();
        $this->assertSame($correo, $usuario->email);
        $this->assertSame('student', $usuario->user_type->value);
        $this->assertNotSame('Otro Nombre', $usuario->full_name);
        $this->assertSame($this->centro->id, $usuario->institution_id);
    }

    public function test_editar_un_grupo_no_deja_fijar_el_contador_ni_el_centro(): void
    {
        $this->actuarComo($this->admin);

        $antes = $this->aulaA->fresh()->only(['institution_id', 'student_count']);
        $this->putJson("/api/groups/{$this->aulaA->id}", [
            'name' => 'Aula renombrada', 'student_count' => 9999, 'institution_id' => $this->otroCentro->id,
        ])->assertOk();
        $this->assertSame('Aula renombrada', $this->aulaA->fresh()->name, 'La edición legítima no se aplicó: el test sería vacío.');

        $fresca = $this->aulaA->fresh();
        $this->assertSame($antes['institution_id'], $fresca->institution_id, 'El aula cambió de centro.');
        $this->assertEquals($antes['student_count'], $fresca->student_count, 'El cliente fijó el contador denormalizado.');
    }

    /* ============================================================
     | Cuentas: rol y centro
     ============================================================ */

    public function test_un_admin_no_se_asciende_ni_asciende_a_otro_ni_cambia_de_centro_por_put(): void
    {
        $this->actuarComo($this->admin);

        foreach ([$this->docente, $this->alumnoA, $this->admin] as $objetivo) {
            $hash = DB::table('users')->where('id', $objetivo->id)->value('password_hash');

            $this->sinError500($this->putJson("/api/users/{$objetivo->id}", [
                'full_name' => 'Nombre nuevo',
                'user_type' => 'superadmin', 'institution_id' => $this->otroCentro->id,
                'password_hash' => 'texto-plano', 'password' => 'Hack12345a', 'status' => 'active',
            ]));

            $fresco = $objetivo->fresh();
            $this->assertSame($objetivo->user_type->value, $fresco->user_type->value, "Cambió el rol de {$objetivo->user_type->value}.");
            $this->assertSame($this->centro->id, $fresco->institution_id, 'Se movió la cuenta a otro centro.');
            $this->assertSame($hash, DB::table('users')->where('id', $objetivo->id)->value('password_hash'), 'Cambió la contraseña por PUT.');
        }

        $this->assertSame(0, User::withoutGlobalScopes()->where('user_type', 'superadmin')->where('institution_id', $this->centro->id)->count());
    }

    public function test_el_registro_no_crea_superadmins_ni_cuentas_en_otro_centro(): void
    {
        $this->actuarComo($this->admin);

        foreach (['superadmin', 'admin'] as $rol) {
            $this->sinError500($this->postJson('/api/register', [
                'full_name' => "Intruso {$rol}", 'email' => "intruso.{$rol}@hack.test",
                'password' => 'Hack12345a', 'password_confirmation' => 'Hack12345a',
                'user_type' => $rol, 'institution_id' => $this->otroCentro->id,
            ]));
        }

        $this->assertDatabaseMissing('users', ['email' => 'intruso.superadmin@hack.test']);

        // Si el alta de admin se permite dentro del centro, nunca en el ajeno.
        $this->assertDatabaseMissing('users', ['email' => 'intruso.admin@hack.test', 'institution_id' => $this->otroCentro->id]);
    }

    public function test_el_superadmin_solo_crea_administradores_aunque_pida_otro_rol(): void
    {
        $this->actuarComo(User::factory()->superAdmin()->create(['institution_id' => null, 'status' => 'active']));

        $this->sinError500($this->postJson("/api/institutions/{$this->centro->id}/admins", [
            'full_name' => 'Falso Admin', 'email' => 'falso.admin@hack.test', 'user_type' => 'superadmin',
            'institution_id' => $this->otroCentro->id, 'status' => 'active', 'password' => 'Hack12345a',
        ]));

        $creado = User::withoutGlobalScopes()->where('email', 'falso.admin@hack.test')->first();
        if ($creado) {
            $this->assertSame('admin', $creado->user_type->value, 'Se creó una cuenta con un rol impuesto por el cliente.');
            $this->assertSame($this->centro->id, $creado->institution_id, 'Se creó en un centro distinto del de la ruta.');
            // Activa, pero con la marca de contraseña temporal y SIN que valga la
            // clave que el cliente intentó imponer: la temporal la genera el servidor.
            $this->assertTrue($creado->must_change_password, 'La cuenta nueva no quedó obligada a cambiar la contraseña.');
            $this->assertFalse(\Illuminate\Support\Facades\Hash::check('Hack12345a', $creado->password_hash), 'Se aceptó una contraseña impuesta por el cliente.');
        }
    }

    /* ============================================================
     | Configuración
     ============================================================ */

    public function test_la_configuracion_ignora_claves_desconocidas_y_no_cambia_de_centro(): void
    {
        $this->actuarComo($this->admin);

        $this->putJson('/api/system/config', [
            'language' => 'en', 'rol_admin' => true, 'is_superadmin' => true,
            'institution_id' => $this->otroCentro->id, 'settings' => ['max_exam_duration' => 9999],
        ])->assertOk();
        $this->assertSame('en', $this->centro->fresh()->settings['language'], 'La edición legítima no se aplicó: el test sería vacío.');

        $ajustes = $this->centro->fresh()->settings ?? [];
        $this->assertArrayNotHasKey('rol_admin', $ajustes);
        $this->assertArrayNotHasKey('is_superadmin', $ajustes);
        $this->assertArrayNotHasKey('settings', $ajustes);
        $this->assertNotSame(9999, $ajustes['max_exam_duration'] ?? null);
        $this->assertEmpty(Institution::find($this->otroCentro->id)->settings ?? [], 'La configuración cayó en otro centro.');
    }
}
