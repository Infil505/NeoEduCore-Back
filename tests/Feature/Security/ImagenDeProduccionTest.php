<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Lo que debe cumplir la imagen de producción (`Dockerfile`) y el PHP que la ejecuta.
 *
 * PHP2Shell (CVE-2026-109618 y CVE-2026-109619, octubre de 2026) es una ejecución remota de
 * código sin autenticación en el núcleo de PHP. Se corrige en 8.2.35, 8.3.36, 8.4.27 y 8.5.12.
 * Estos tests no sustituyen a `php -v` dentro del contenedor desplegado, pero evitan dos
 * regresiones: fijar la imagen base a una versión vulnerable y volver a correr como root.
 */
class ImagenDeProduccionTest extends TestCase
{
    /** Primera versión parcheada de cada rama de PHP. */
    private const PARCHEADAS = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    private function dockerfile(): string
    {
        $ruta = base_path('Dockerfile');
        $this->assertFileExists($ruta);

        return file_get_contents($ruta);
    }

    public function test_la_imagen_base_no_esta_fijada_a_un_php_vulnerable(): void
    {
        $this->assertSame(1, preg_match('/^FROM\s+(\S+)/mi', $this->dockerfile(), $m), 'El Dockerfile no tiene FROM.');
        $imagen = $m[1];

        $this->assertSame(1, preg_match('/php(\d+\.\d+)(?:\.(\d+))?/', $imagen, $v), "No se reconoce la versión de PHP en «{$imagen}».");
        $rama = $v[1];
        $this->assertArrayHasKey($rama, self::PARCHEADAS, "PHP {$rama} no recibe el parche de PHP2Shell: sube de rama.");

        // Tag flotante («1-php8.4»): coge el último parche al reconstruir sin caché.
        if (! isset($v[2])) {
            $this->addToAssertionCount(1);

            return;
        }

        $fijada = "{$rama}.{$v[2]}";
        $this->assertTrue(
            version_compare($fijada, self::PARCHEADAS[$rama], '>='),
            "La imagen fija PHP {$fijada}, vulnerable a PHP2Shell; mínimo ".self::PARCHEADAS[$rama].'.'
        );
    }

    public function test_el_contenedor_no_corre_como_root(): void
    {
        $lineas = preg_split('/\R/', $this->dockerfile());
        $usuario = null;
        foreach ($lineas as $linea) {
            if (preg_match('/^\s*USER\s+(\S+)/i', $linea, $m)) {
                $usuario = $m[1];
            }
        }

        $this->assertNotNull($usuario, 'El Dockerfile no declara USER: el contenedor correría como root.');
        $this->assertNotContains(strtolower(explode(':', $usuario)[0]), ['root', '0'], 'El último USER del Dockerfile es root.');
    }

    public function test_los_puertos_expuestos_no_necesitan_privilegios(): void
    {
        $this->assertSame(1, preg_match('/^EXPOSE\s+(.+)$/mi', $this->dockerfile(), $m));
        foreach (preg_split('/\s+/', trim($m[1])) as $puerto) {
            $this->assertGreaterThan(1024, (int) $puerto, "El puerto {$puerto} exige root para enlazarse.");
        }
    }

    /** El PHP que ejecuta la suite (local o CI). Falla mientras la máquina siga sin parchear. */
    public function test_el_php_en_ejecucion_tiene_el_parche_de_php2shell(): void
    {
        $rama = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        if (! isset(self::PARCHEADAS[$rama])) {
            $this->markTestSkipped("PHP {$rama} no está en la tabla de versiones parcheadas.");
        }

        $this->assertTrue(
            version_compare(PHP_VERSION, self::PARCHEADAS[$rama], '>='),
            'PHP '.PHP_VERSION.' es vulnerable a PHP2Shell (CVE-2026-109618/109619); actualiza a '.self::PARCHEADAS[$rama].' o superior.'
        );
    }
}
