<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\Institution;
use App\Models\Admin\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * `$request->ip()` debe devolver la IP del CLIENTE, no la del proxy.
 *
 * En producción la aplicación va detrás de Traefik (Coolify), y puede llegar a ir
 * también detrás de Cloudflare. Sin proxies de confianza declarados, Laravel ve
 * siempre la IP del proxy y **todos los límites por IP agrupan a los usuarios en
 * un único cubo**: el tope de 60 accesos/minuto por IP, pensado para que un aula
 * tras el mismo NAT pueda entrar, se aplicaría a todos los usuarios del sistema a
 * la vez. Se autobloquearía sin que nadie lo atacara.
 *
 * En la suite las peticiones salen de 127.0.0.1, que está entre los proxies de
 * confianza por defecto, así que `X-Forwarded-For` se respeta.
 */
class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        // El valor es estático y sobrevive entre tests: hay que restaurarlo o
        // contamina a los demás.
        TrustProxies::at(config('trusted_proxies.proxies'));

        parent::tearDown();
    }

    private function rutaQueDevuelveLaIp(): void
    {
        Route::middleware('api')->get('/api/_test/ip', fn () => response()->json([
            'ip' => request()->ip(),
        ]));
    }

    public function test_the_client_ip_is_read_from_the_forwarded_header(): void
    {
        $this->rutaQueDevuelveLaIp();

        $this->getJson('/api/_test/ip', ['X-Forwarded-For' => '203.0.113.5'])
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.5']);
    }

    public function test_without_trusted_proxies_the_header_is_ignored(): void
    {
        // Así estaba el proyecto hasta el 07/08/2026: sin confiar en nadie, la
        // cabecera se descarta y todos los clientes comparten la IP del proxy.
        TrustProxies::at([]);

        $this->rutaQueDevuelveLaIp();

        $this->getJson('/api/_test/ip', ['X-Forwarded-For' => '203.0.113.5'])
            ->assertOk()
            ->assertJson(['ip' => '127.0.0.1']);
    }

    /**
     * Lo que de verdad importa: que el limitador por IP separe a los clientes
     * reales en vez de meterlos a todos en el cubo del proxy.
     */
    public function test_the_ip_rate_limit_buckets_by_real_client_not_by_proxy(): void
    {
        config(['rate_limits.login_ip' => 3]);

        $institution = Institution::factory()->create();

        $crear = fn (string $etiqueta) => User::factory()->create([
            'institution_id' => $institution->id,
            'email'          => $etiqueta . '-' . uniqid() . '@mail.test',
            'password_hash'  => Hash::make('Correcta123'),
            'status'         => 'active',
        ]);

        $intentar = fn (string $email, string $ipCliente) => $this->postJson(
            '/api/auth/login',
            ['email' => $email, 'password' => 'MalaClave1'],
            ['X-Forwarded-For' => $ipCliente]
        )->status();

        // Un cliente agota su cupo por IP
        for ($i = 0; $i < 3; $i++) {
            $intentar($crear("atacante{$i}")->email, '198.51.100.10');
        }
        $this->assertSame(429, $intentar($crear('atacante-extra')->email, '198.51.100.10'));

        // Otro cliente, desde OTRA IP, no se ve afectado: si Laravel viera la IP
        // del proxy, este también estaría bloqueado.
        $this->assertSame(
            401,
            $intentar($crear('inocente')->email, '198.51.100.99'),
            'Un cliente legítimo quedó bloqueado por el cupo de otro: se está agrupando por la IP del proxy'
        );
    }
}
