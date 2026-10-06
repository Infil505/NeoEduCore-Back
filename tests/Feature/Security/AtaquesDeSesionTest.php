<?php

namespace Tests\Feature\Security;

use App\Models\Academic\Group;
use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;
use OpenAI\Responses\Chat\CreateResponse;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * **Sesiones y autenticación.** Tokens que deberían haber muerto, cuentas
 * desactivadas que siguen dentro, centros dados de baja, fuerza bruta,
 * filtración del hash de contraseña, un cliente que intenta elegir su centro.
 *
 * Aquí se usan **tokens reales** (`Authorization: Bearer ...`), no
 * `Sanctum::actingAs`, que se salta justo lo que se está atacando.
 *
 * Verde = el sistema se defendió; rojo = hallazgo.
 */
class AtaquesDeSesionTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    private const CLAVE = 'Abcdefg1x';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    /** Petición con un token real; reinicia la guardia para que no recuerde al usuario anterior. */
    private function conToken(string $token)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token);
    }

    private function sinToken()
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this;
    }

    private function tokenDe(User $u): string
    {
        return $u->createToken('ataque')->plainTextToken;
    }

    private function conClave(User $u): User
    {
        $u->update(['password_hash' => Hash::make(self::CLAVE), 'status' => 'active']);

        return $u->fresh();
    }

    /* ============================================================
     | Tokens que deberían haber muerto
     ============================================================ */

    /**
     * El admin suspende una cuenta: debe quedar fuera **ya**, no solo cuando
     * intente volver a iniciar sesión. Un alumno expulsado no puede seguir
     * dentro del examen con el token que ya tenía.
     */
    public function test_el_token_de_una_cuenta_suspendida_o_desactivada_deja_de_valer(): void
    {
        foreach (['suspended', 'inactive'] as $estado) {
            $docente = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
            $token = $this->tokenDe($docente);

            $this->conToken($token)->getJson('/api/auth/me')->assertOk();   // antes de suspenderla, entra

            $this->actuarComo($this->admin);
            $this->patchJson("/api/users/{$docente->id}/status", ['status' => $estado])->assertOk();

            $res = $this->conToken($token)->getJson('/api/auth/me');
            $this->assertContains(
                $res->getStatusCode(), [401, 403],
                "Una cuenta «{$estado}» sigue operando con su token ({$res->getStatusCode()})."
            );
        }
    }

    public function test_el_token_de_un_alumno_suspendido_no_inicia_ni_entrega_examenes(): void
    {
        $token = $this->tokenDe($this->alumnoA);

        $this->actuarComo($this->admin);
        $this->patchJson("/api/users/{$this->alumnoA->id}/status", ['status' => 'suspended'])->assertOk();

        $res = $this->conToken($token)->postJson("/api/exams/{$this->examen->id}/attempts/start");

        $this->assertNotSame(201, $res->getStatusCode(), 'Un alumno suspendido pudo empezar un examen con su token anterior.');
        $this->assertDatabaseMissing('exam_attempts', ['student_user_id' => $this->alumnoA->id]);
    }

    public function test_un_centro_dado_de_baja_no_deja_entrar_ni_seguir_a_sus_usuarios(): void
    {
        $admin = $this->conClave($this->admin);
        $token = $this->tokenDe($admin);

        $this->conToken($token)->getJson('/api/auth/me')->assertOk();

        // El superadmin da de baja el centro.
        $this->actuarComo(User::factory()->superAdmin()->create(['institution_id' => null, 'status' => 'active']));
        $this->patchJson("/api/institutions/{$this->centro->id}/toggle")->assertOk();
        $this->assertFalse((bool) $this->centro->fresh()->is_active, 'El toggle no desactivó el centro: el test sería vacío.');

        $login = $this->sinToken()->postJson('/api/auth/login', ['email' => $admin->email, 'password' => self::CLAVE]);
        $this->assertNotSame(200, $login->getStatusCode(), 'Un usuario de un centro dado de baja inició sesión.');

        $res = $this->conToken($token)->getJson('/api/auth/me');
        $this->assertContains($res->getStatusCode(), [401, 403], 'Un centro dado de baja sigue operando con tokens anteriores.');
    }

    public function test_el_token_de_un_usuario_borrado_no_vale(): void
    {
        $colega = User::factory()->teacher()->create(['institution_id' => $this->centro->id, 'status' => 'active']);
        $token = $this->tokenDe($colega);

        $this->actuarComo($this->admin);
        $this->deleteJson("/api/users/{$colega->id}")->assertSuccessful();

        $this->conToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_tras_cerrar_sesion_el_token_no_vale(): void
    {
        $docente = $this->conClave($this->docente);

        $login = $this->sinToken()->postJson('/api/auth/login', ['email' => $docente->email, 'password' => self::CLAVE])->assertOk();
        $token = $login->json('token');

        $this->conToken($token)->getJson('/api/auth/me')->assertOk();
        $this->conToken($token)->postJson('/api/auth/logout')->assertOk();

        $this->conToken($token)->getJson('/api/auth/me')->assertUnauthorized();
        $this->conToken($token)->getJson('/api/exams')->assertUnauthorized();
    }

    public function test_cambiar_la_contrasena_cierra_las_otras_sesiones_pero_no_la_actual(): void
    {
        $docente = $this->conClave($this->docente);
        $actual = $this->tokenDe($docente);
        $robado = $this->tokenDe($docente);

        $this->conToken($actual)->postJson('/api/password/change', [
            'current_password' => self::CLAVE, 'password' => 'Nueva1234x', 'password_confirmation' => 'Nueva1234x',
        ])->assertOk();

        $this->conToken($robado)->getJson('/api/auth/me')->assertUnauthorized();
        $this->conToken($actual)->getJson('/api/auth/me')->assertOk();
    }

    public function test_cambiar_la_contrasena_exige_conocer_la_actual(): void
    {
        $docente = $this->conClave($this->docente);
        $token = $this->tokenDe($docente);
        $hash = $docente->fresh()->password_hash;

        foreach (['', 'incorrecta1A', 'Nueva1234x'] as $intento) {
            $this->conToken($token)->postJson('/api/password/change', [
                'current_password' => $intento, 'password' => 'Hack12345a', 'password_confirmation' => 'Hack12345a',
            ])->assertStatus($intento === '' ? 422 : 400);
        }

        $this->assertSame($hash, $docente->fresh()->password_hash, 'La contraseña cambió sin conocer la actual.');
    }

    public function test_al_resetear_el_admin_la_clave_se_cierran_las_sesiones_del_usuario(): void
    {
        $robado = $this->tokenDe($this->docente);

        $this->actuarComo($this->admin);
        $this->patchJson("/api/users/{$this->docente->id}/reset-password", [
            'password' => 'Nueva1234x', 'password_confirmation' => 'Nueva1234x',
        ])->assertOk();

        $this->conToken($robado)->getJson('/api/auth/me')->assertUnauthorized();
    }

    /* ============================================================
     | Tokens falsos
     ============================================================ */

    public function test_tokens_falsos_o_manipulados_no_entran(): void
    {
        $real = $this->docente->createToken('t');
        $id = $real->accessToken->id;

        foreach ([
            'abc', '1|abc', "{$id}|secreto-inventado", "{$id}|", '|', '0|' . str_repeat('a', 40),
            str_repeat('A', 5000), "{$id}|{$id}", "' OR '1'='1", '../../', "\u{0000}",
        ] as $falso) {
            $this->conToken($falso)->getJson('/api/auth/me')->assertUnauthorized();
        }

        // Y el real, sí.
        $this->conToken($real->plainTextToken)->getJson('/api/auth/me')->assertOk();
    }

    public function test_sin_cabecera_o_con_un_esquema_distinto_no_entra_nadie(): void
    {
        $token = $this->tokenDe($this->docente);

        $this->sinToken()->getJson('/api/auth/me')->assertUnauthorized();

        foreach (["Basic {$token}", "Token {$token}", $token, 'Bearer', 'Bearer  '] as $cabecera) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $this->withHeader('Authorization', $cabecera)->getJson('/api/auth/me')->assertUnauthorized();
        }
    }

    /* ============================================================
     | Elegir el centro desde el cliente
     ============================================================ */

    public function test_el_cliente_no_elige_el_centro_con_cabeceras_ni_parametros(): void
    {
        $otro = Institution::factory()->create();
        $aulaAjena = Group::factory()->create(['institution_id' => $otro->id, 'name' => 'AULA-DEL-OTRO-CENTRO']);
        $this->actuarComo($this->admin);

        $cabeceras = ['X-Tenant-ID', 'X-Tenant', 'X-Institution-Id', 'X-Institution-ID', 'Institution-Id', 'X-Forwarded-Tenant'];
        $parametros = ['institution_id', 'tenant_id', 'tenant', 'institution'];

        foreach ($cabeceras as $c) {
            $cuerpo = $this->withHeader($c, $otro->id)->getJson('/api/groups')->getContent();
            $this->assertStringNotContainsString($aulaAjena->id, $cuerpo, "La cabecera {$c} cambió de centro.");
        }
        foreach ($parametros as $p) {
            $cuerpo = $this->getJson("/api/groups?{$p}={$otro->id}")->getContent();
            $this->assertStringNotContainsString($aulaAjena->id, $cuerpo, "El parámetro {$p} cambió de centro.");
        }
    }

    /* ============================================================
     | Filtración de credenciales
     ============================================================ */

    public function test_ninguna_respuesta_filtra_el_hash_de_la_contrasena_ni_tokens(): void
    {
        $this->actuarComo($this->admin);

        $rutas = [
            '/api/auth/me', '/api/users', "/api/users/{$this->docente->id}", "/api/users/{$this->alumnoA->id}",
            '/api/students', "/api/students/{$this->alumnoA->id}", "/api/groups/{$this->aulaA->id}",
            '/api/exams', "/api/exams/{$this->examen->id}", '/api/study-resources', '/api/calendar-events',
            '/api/teacher-assignments', '/api/ai-recommendations',
        ];

        foreach ($rutas as $ruta) {
            $cuerpo = $this->getJson($ruta)->getContent();

            $this->assertStringNotContainsString('password_hash', $cuerpo, "{$ruta} expone password_hash.");
            $this->assertStringNotContainsString('remember_token', $cuerpo, "{$ruta} expone remember_token.");
            $this->assertStringNotContainsString('$2y$', $cuerpo, "{$ruta} expone un hash bcrypt.");
        }

        // El inicio de sesión devuelve el token, pero ningún dato de credencial.
        $docente = $this->conClave($this->docente);
        $login = $this->sinToken()->postJson('/api/auth/login', ['email' => $docente->email, 'password' => self::CLAVE])->assertOk();
        $this->assertStringNotContainsString('password', json_encode($login->json('user')));
    }

    public function test_un_alumno_y_un_docente_tampoco_ven_hashes_en_lo_que_consultan(): void
    {
        foreach ([[$this->alumnoA, ['/api/auth/me', '/api/exams', "/api/exams/{$this->examen->id}", '/api/students/me']],
                  [$this->docente, ['/api/auth/me', '/api/students', "/api/groups/{$this->aulaA->id}", '/api/exams', '/api/users']]] as [$quien, $rutas]) {
            $this->actuarComo($quien);
            foreach ($rutas as $ruta) {
                $cuerpo = $this->getJson($ruta)->getContent();
                $this->assertStringNotContainsString('password_hash', $cuerpo, "{$ruta} expone password_hash a un {$quien->user_type->value}.");
                $this->assertStringNotContainsString('$2y$', $cuerpo, "{$ruta} expone un hash a un {$quien->user_type->value}.");
            }
        }
    }

    /* ============================================================
     | Inicio de sesión
     ============================================================ */

    public function test_el_login_no_distingue_correo_desconocido_de_clave_incorrecta(): void
    {
        $docente = $this->conClave($this->docente);

        $desconocido = $this->sinToken()->postJson('/api/auth/login', ['email' => 'nadie@no-existe.test', 'password' => self::CLAVE]);
        $incorrecta  = $this->sinToken()->postJson('/api/auth/login', ['email' => $docente->email, 'password' => 'Equivocada1x']);

        $this->assertSame($desconocido->getStatusCode(), $incorrecta->getStatusCode(), 'El código delata si el correo existe.');
        $this->assertSame($desconocido->json('message'), $incorrecta->json('message'), 'El mensaje delata si el correo existe.');
    }

    public function test_el_login_rechaza_cuentas_no_activas_y_cuerpos_raros_sin_500(): void
    {
        foreach (['inactive', 'suspended'] as $estado) {
            $u = User::factory()->teacher()->create([
                'institution_id' => $this->centro->id, 'status' => $estado, 'password_hash' => Hash::make(self::CLAVE),
            ]);
            $this->sinToken()->postJson('/api/auth/login', ['email' => $u->email, 'password' => self::CLAVE])->assertForbidden();
        }

        $fallos = [];
        foreach ([
            [], ['email' => []], ['email' => 'a@b.c', 'password' => []], ['email' => str_repeat('a', 300) . '@x.test', 'password' => 'x'],
            ['email' => "a@b.c\u{0000}", 'password' => 'x'], ['email' => 'a@b.c', 'password' => str_repeat('x', 100000)],
            ['email' => ['$ne' => null], 'password' => ['$ne' => null]],
        ] as $cuerpo) {
            $res = $this->sinToken()->postJson('/api/auth/login', $cuerpo);
            $resumen = mb_substr(json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 80);
            if ($res->getStatusCode() >= 500 || $res->getStatusCode() === 200) {
                $fallos[] = "login con {$resumen} → {$res->getStatusCode()}";
            }
        }

        $this->assertSame([], $fallos, "Login (sin autenticar) con cuerpos hostiles:
" . implode("
", $fallos));
    }

    public function test_el_correo_se_compara_sin_distinguir_mayusculas_pero_sin_colarse_en_otra_cuenta(): void
    {
        $docente = $this->conClave($this->docente);

        $this->sinToken()->postJson('/api/auth/login', ['email' => strtoupper($docente->email), 'password' => self::CLAVE])->assertOk();
        // Los espacios alrededor los recorta el middleware TrimStrings: es el mismo correo, entra.
        $this->sinToken()->postJson('/api/auth/login', ['email' => " {$docente->email} ", 'password' => self::CLAVE])->assertOk();
        $this->sinToken()->postJson('/api/auth/login', ['email' => $docente->email . '.otro', 'password' => self::CLAVE])->assertStatus(401);
    }

    /* ============================================================
     | Recuperación de contraseña
     ============================================================ */

    public function test_el_token_de_recuperacion_de_una_cuenta_no_sirve_para_otra(): void
    {
        $a = $this->conClave($this->docente);
        $b = $this->conClave($this->colega);
        $hashB = $b->fresh()->password_hash;

        $token = Str::random(64);
        DB::table('password_reset_tokens')->insert(['email' => $a->email, 'token' => Hash::make($token), 'created_at' => now()]);

        $res = $this->sinToken()->postJson('/api/password/reset', [
            'email' => $b->email, 'token' => $token, 'password' => 'Hack12345a', 'password_confirmation' => 'Hack12345a',
        ]);
        $this->assertContains($res->getStatusCode(), [400, 401, 403, 404, 422], "El reset con token ajeno respondió {$res->getStatusCode()}.");

        $this->assertSame($hashB, $b->fresh()->password_hash, 'El token de A cambió la clave de B.');
    }

    public function test_adivinar_el_token_de_recuperacion_a_fuerza_bruta_se_frena(): void
    {
        $docente = $this->conClave($this->docente);
        DB::table('password_reset_tokens')->insert([
            'email' => $docente->email, 'token' => Hash::make(Str::random(64)), 'created_at' => now(),
        ]);

        $frenado = false;
        for ($i = 0; $i < 40 && !$frenado; $i++) {
            $res = $this->sinToken()->postJson('/api/password/verify', ['email' => $docente->email, 'token' => Str::random(64)]);
            $frenado = $res->getStatusCode() === 429;
        }

        $this->assertTrue($frenado, 'Se pudieron probar 40 tokens de recuperación seguidos sin que nada frenara.');
    }

    /* ============================================================
     | Abuso de costes
     ============================================================ */

    public function test_el_chat_del_tutor_se_frena_al_abusar(): void
    {
        $tope = (int) config('rate_limits.ai_chat');
        OpenAI::fake(array_fill(0, $tope + 10, CreateResponse::fake([
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hola']]],
        ])));
        $this->actuarComo($this->alumnoA);

        $frenado = false;
        for ($i = 0; $i < $tope + 8 && !$frenado; $i++) {
            $frenado = $this->postJson('/api/ai/tutor/chat', ['message' => "pregunta {$i}"])->getStatusCode() === 429;
        }

        $this->assertTrue($frenado, "Más de {$tope} mensajes por minuto al tutor de pago sin que nada los frenara.");
    }
}
