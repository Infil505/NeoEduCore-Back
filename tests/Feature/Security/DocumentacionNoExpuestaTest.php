<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La documentación de la API no se sirve por HTTP: ni Swagger UI ni el
 * `api-docs.json`. El OpenAPI se genera con `php artisan openapi:generate` y se
 * lee como fichero. L5-Swagger está en `dont-discover` (composer.json) para que
 * su proveedor no registre rutas; si alguien lo quita, este test avisa.
 */
class DocumentacionNoExpuestaTest extends TestCase
{
    #[DataProvider('rutasDeDocumentacion')]
    public function test_la_documentacion_no_es_accesible(string $uri): void
    {
        $this->getJson($uri)->assertNotFound();
    }

    public static function rutasDeDocumentacion(): array
    {
        return [
            'swagger ui' => ['/api/documentation'],
            'callback oauth2' => ['/api/oauth2-callback'],
            'ui antigua' => ['/docs'],
            'spec yaml' => ['/docs/openapi.yaml'],
            'spec json' => ['/docs/api-docs.json'],
            'assets' => ['/docs/asset/swagger-ui.css'],
        ];
    }

    public function test_ninguna_ruta_registrada_sirve_documentacion(): void
    {
        $sospechosas = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($u) => preg_match('#(^|/)(docs|documentation|swagger|openapi)(/|$)|oauth2-callback#i', $u))
            ->values()->all();

        $this->assertSame([], $sospechosas);
    }

    /** Lo que hay en `public/` lo sirve el servidor web sin pasar por Laravel: no lo ve ningún test HTTP. */
    public function test_public_no_contiene_documentacion_estatica(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('docs'));
        $this->assertSame([], glob(public_path('*{openapi,swagger,api-docs}*'), GLOB_BRACE) ?: []);
    }

    /** Sin el proveedor de L5-Swagger la documentación se sigue generando, pero ya no se sirve ni se puede pisar. */
    public function test_el_generador_propio_sigue_disponible_y_el_del_paquete_no(): void
    {
        $comandos = array_keys(\Illuminate\Support\Facades\Artisan::all());

        $this->assertContains('openapi:generate', $comandos);
        $this->assertNotContains('l5-swagger:generate', $comandos);
    }
}
