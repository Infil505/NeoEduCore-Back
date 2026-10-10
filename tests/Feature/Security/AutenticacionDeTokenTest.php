<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\ApiAuth;
use Tests\Traits\EscenarioDeAtaque;

/**
 * El token y su usuario se buscan en UNA consulta (`AccessToken::findToken`).
 * Que sea una sola consulta no puede abrir ninguna puerta: estos casos fijan lo
 * que debe seguir rechazándose.
 */
class AutenticacionDeTokenTest extends TestCase
{
    use ApiAuth, EscenarioDeAtaque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarEscenario();
    }

    private function conToken(string $token, string $url = '/api/auth/me')
    {
        // Sin esto el guard conserva al usuario de la petición anterior dentro del test.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->getJson($url);
    }

    public function test_un_token_valido_entra_y_trae_al_usuario_correcto(): void
    {
        $token = $this->docente->createToken('t')->plainTextToken;

        $this->conToken($token)->assertOk()->assertJsonFragment(['email' => $this->docente->email]);
    }

    public function test_autenticar_cuesta_una_sola_consulta(): void
    {
        $token = $this->docente->createToken('t')->plainTextToken;
        $this->conToken($token, '/api/subjects')->assertOk(); // calienta cachés

        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->conToken($token, '/api/subjects')->assertOk();
        $usuarios = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'personal_access_tokens'))->count();
        $sueltas = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'select * from "users"'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $usuarios, 'Token y usuario deben venir en una sola consulta.');
        $this->assertSame(0, $sueltas, 'No debe haber una consulta aparte para el usuario.');
    }

    public function test_un_token_con_secreto_equivocado_se_rechaza(): void
    {
        $id = explode('|', $this->docente->createToken('t')->plainTextToken)[0];

        $this->conToken("{$id}|secreto-que-no-es")->assertUnauthorized();
    }

    public function test_un_id_de_token_que_no_es_numerico_es_401_y_no_un_error_del_servidor(): void
    {
        $this->conToken('abc|xyz')->assertUnauthorized();
        $this->conToken("1 OR 1=1|xyz")->assertUnauthorized();
        $this->conToken('|')->assertUnauthorized();
    }

    public function test_un_token_borrado_ya_no_entra(): void
    {
        $nuevo = $this->docente->createToken('t');
        $this->conToken($nuevo->plainTextToken)->assertOk();

        $nuevo->accessToken->delete();

        $this->conToken($nuevo->plainTextToken)->assertUnauthorized();
    }

    public function test_el_token_de_una_cuenta_suspendida_sigue_sin_entrar(): void
    {
        $token = $this->docente->createToken('t')->plainTextToken;
        $this->conToken($token)->assertOk();

        DB::table('users')->where('id', $this->docente->id)->update(['status' => 'suspended']);

        $this->conToken($token)->assertStatus(403);
    }

    public function test_el_token_de_un_usuario_ya_no_existente_no_entra(): void
    {
        $nuevo = $this->colega->createToken('t');
        DB::table('users')->where('id', $this->colega->id)->delete();

        $this->conToken($nuevo->plainTextToken)->assertUnauthorized();
    }
}
