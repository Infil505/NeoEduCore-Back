<?php

namespace Tests\Feature\Db;

use Tests\TestCase;

/**
 * Los ficheros deben estar en la ruta que exige PSR-4 para su namespace.
 *
 * Este guardián existe porque el fallo es **invisible en Windows**: el sistema
 * de ficheros no distingue mayúsculas, así que `App\Services\...` encontraba
 * `app/services/...` sin protestar. En Linux —el contenedor de producción— no:
 * PSR-4 busca `app/Services/...`, no lo encuentra, y `composer dump-autoload`
 * además había **excluido esas 11 clases del classmap** por incumplir la norma.
 * Cualquier petición que usara un servicio habría fallado con «Class not found»:
 * entregar un examen, el tutor IA, los reportes, la carga masiva.
 *
 * Corregido el 07/08/2026 renombrando `app/services` → `app/Services` y moviendo
 * `PasswordPolicyTest` de `tests/Feature/Auth` a `tests/Unit/Auth`.
 *
 * La comparación es **carácter a carácter**, sin `realpath()`: este normaliza el
 * case en Windows y el fallo volvería a pasar desapercibido justo donde ya lo hizo.
 */
class Psr4ComplianceTest extends TestCase
{
    /** @return array<string,string> prefijo de namespace => directorio */
    private function reglas(): array
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        return array_merge(
            $composer['autoload']['psr-4'] ?? [],
            $composer['autoload-dev']['psr-4'] ?? [],
        );
    }

    private function normalizar(string $ruta): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', $ruta);
    }

    public function test_every_class_sits_where_psr4_expects_it(): void
    {
        $incumplen = [];

        foreach ($this->reglas() as $prefijo => $directorio) {
            $raiz = base_path(rtrim($directorio, '/'));

            if (! is_dir($raiz)) {
                continue;
            }

            $ficheros = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($ficheros as $fichero) {
                if ($fichero->getExtension() !== 'php') {
                    continue;
                }

                $codigo = file_get_contents($fichero->getPathname());

                // Scripts sueltos sin namespace: no los cubre PSR-4.
                if (! preg_match('/^namespace\s+([^;]+);/m', $codigo, $ns)) {
                    continue;
                }

                $clase = trim($ns[1]) . '\\' . $fichero->getBasename('.php');

                if (! str_starts_with($clase, $prefijo)) {
                    continue;   // la cubre otra regla del composer.json
                }

                // Ruta que PSR-4 deduce del namespace declarado.
                $relativa = str_replace('\\', '/', substr($clase, strlen($prefijo)));
                $esperada = $this->normalizar(base_path(rtrim($directorio, '/') . '/' . $relativa . '.php'));
                $real     = $this->normalizar($fichero->getPathname());

                if ($real !== $esperada) {
                    $incumplen[] = sprintf(
                        "  %s\n      está en: %s\n      debería: %s",
                        $clase,
                        $real,
                        $esperada
                    );
                }
            }
        }

        $this->assertSame([], $incumplen, sprintf(
            "%d clase(s) fuera de la ruta que exige PSR-4.\n\n"
            . "En Windows funcionan igual; en Linux (producción) Composer las excluye del\n"
            . "classmap y fallan con «Class not found».\n\n%s\n",
            count($incumplen),
            implode("\n\n", $incumplen)
        ));
    }
}
