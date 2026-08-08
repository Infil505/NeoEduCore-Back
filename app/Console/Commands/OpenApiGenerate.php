<?php

namespace App\Console\Commands;

use App\Support\ApiSpec;
use Illuminate\Console\Command;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\Route;

/**
 * Genera `storage/api-docs/api-docs.json` a partir de las rutas REALES.
 *
 * Por qué no se anota a mano: `l5-swagger:generate` solo recoge lo que esté
 * escrito en atributos `#[OA\...]`, y con 103 endpoints eso significaba más de
 * dos mil líneas de atributos dentro de los controladores que además se
 * desincronizan en cuanto alguien toca una ruta. Aquí se invierte el flujo: la
 * ruta es la fuente de verdad y el documento se deriva de ella, igual que la
 * colección de Postman. Los metadatos que no se pueden deducir de una ruta
 * —módulo, cuerpo de ejemplo, si es pública— viven en `ApiSpec`, compartidos
 * por ambos generadores.
 *
 * Lo que SÍ se deduce automáticamente de cada ruta:
 *   - método, path y parámetros de ruta
 *   - si exige autenticación (middleware `auth:sanctum`)
 *   - qué roles la pueden usar (middleware `RequireRole:...`)
 *   - los códigos de respuesta que se derivan de lo anterior
 *
 * Limitación conocida: no hay esquema detallado de la respuesta 200. Para el
 * cuerpo de las peticiones se usan los ejemplos de `ApiSpec::exampleBodies()`.
 */
class OpenApiGenerate extends Command
{
    protected $signature = 'openapi:generate {--path= : Ruta de salida alternativa}';

    protected $description = 'Genera el api-docs.json de OpenAPI desde las rutas reales de la API';

    public function handle(): int
    {
        $paths = [];
        $tags  = [];
        $total = 0;

        foreach ($this->apiRoutes() as $route) {
            $uri    = $route->uri();
            $method = strtolower(ApiSpec::pickMethod(implode('|', $route->methods())));
            $tag    = ApiSpec::folderFor($uri);

            $tags[$tag] ??= true;
            $paths['/' . $uri][$method] = $this->operation($route, $uri, $method, $tag);
            $total++;
        }

        ksort($paths);

        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title'       => 'NeoEduCore API',
                'version'     => '1.0.0',
                'description' => $this->descripcion(),
            ],
            'servers' => [
                ['url' => rtrim(config('app.url', 'http://localhost:8000'), '/'), 'description' => 'Servidor'],
            ],
            'tags' => array_map(
                fn (string $n) => ['name' => $n],
                $this->ordenados(array_keys($tags))
            ),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    // Sanctum emite tokens OPACOS contra `personal_access_tokens`,
                    // no JWT. Ver docs/ANALISIS_MODELO_DATOS_TFG.md §9.1 nº 4.
                    'sanctum' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'Token opaco de Laravel Sanctum. Se obtiene en `POST /api/auth/login` '
                            . 'y se envía como `Authorization: Bearer <token>`.',
                    ],
                ],
            ],
        ];

        $salida = $this->option('path') ?: storage_path('api-docs/api-docs.json');

        if (! is_dir(dirname($salida))) {
            mkdir(dirname($salida), 0755, true);
        }

        file_put_contents(
            $salida,
            json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        );

        $this->info("OK: {$total} endpoints documentados en " . count($tags) . ' módulos.');
        $this->line("Archivo: {$salida}");

        return self::SUCCESS;
    }

    /** @return array<int,RouteInstance> */
    private function apiRoutes(): array
    {
        $saltar = ApiSpec::skippedUris();

        $rutas = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RouteInstance $r) => str_starts_with($r->uri(), 'api/') && ! in_array($r->uri(), $saltar, true)
        );

        usort($rutas, fn (RouteInstance $a, RouteInstance $b) => strcmp($a->uri(), $b->uri()));

        return array_values($rutas);
    }

    /** @return array<string,mixed> */
    private function operation(RouteInstance $route, string $uri, string $method, string $tag): array
    {
        $roles     = $this->roles($route);
        $protegida = ! in_array($uri, ApiSpec::publicUris(), true) && $this->requiereAuth($route);

        $op = [
            'tags'        => [$tag],
            'summary'     => $this->resumen($route),
            'description' => $this->descripcionOperacion($route, $roles),
            'operationId' => $this->operationId($method, $uri),
        ];

        if ($params = $this->parametros($uri)) {
            $op['parameters'] = $params;
        }

        if ($body = $this->cuerpo($method, $uri)) {
            $op['requestBody'] = $body;
        }

        $op['responses'] = $this->respuestas($method, $uri, $protegida, $roles !== []);

        if ($protegida) {
            $op['security'] = [['sanctum' => []]];
        }

        return $op;
    }

    /**
     * Roles exigidos por el middleware de rol, si lo hay.
     *
     * `gatherMiddleware()` devuelve el **alias** tal como se registró
     * (`role:admin,teacher`), no la clase; `route:list --json` en cambio la
     * resuelve a `App\Http\Middleware\RequireRole:...`. Se aceptan las dos
     * formas para que esto no dependa de por dónde se lean las rutas.
     */
    private function roles(RouteInstance $route): array
    {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && preg_match('/(?:^role|RequireRole):(.+)$/', $m, $coincide)) {
                return array_map('trim', explode(',', $coincide[1]));
            }
        }

        return [];
    }

    private function requiereAuth(RouteInstance $route): bool
    {
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && (str_contains($m, 'Authenticate:sanctum') || str_starts_with($m, 'auth:'))) {
                return true;
            }
        }

        return false;
    }

    /** Resumen legible a partir de `Controlador@metodo`. */
    private function resumen(RouteInstance $route): string
    {
        $accion = $route->getActionName();

        if (! str_contains($accion, '@')) {
            return 'Closure';
        }

        [$clase, $metodo] = explode('@', $accion);
        $clase = class_basename($clase);

        // examSummary -> "Exam summary"
        $legible = ucfirst(strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $metodo))));

        return "{$legible} ({$clase})";
    }

    private function descripcionOperacion(RouteInstance $route, array $roles): string
    {
        $partes = ['Controlador: `' . $route->getActionName() . '`.'];

        if ($roles !== []) {
            $partes[] = '**Roles permitidos:** ' . implode(', ', $roles) . '.';
        }

        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'throttle:')) {
                $partes[] = 'Limitado por `' . $m . '`.';
            }
        }

        return implode(' ', $partes);
    }

    private function operationId(string $method, string $uri): string
    {
        return $method . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', trim($uri, '/'));
    }

    /** @return array<int,array<string,mixed>> */
    private function parametros(string $uri): array
    {
        preg_match_all('/\{(.+?)\??\}/', $uri, $coincidencias);

        $nombres = ApiSpec::paramNames();

        return array_map(fn (string $p) => [
            'name'        => $p,
            'in'          => 'path',
            'required'    => true,
            'description' => $nombres[$p] ?? $p,
            'schema'      => ['type' => 'string', 'format' => 'uuid'],
        ], $coincidencias[1]);
    }

    /** @return array<string,mixed>|null */
    private function cuerpo(string $method, string $uri): ?array
    {
        if (! in_array($method, ['post', 'put', 'patch'], true)) {
            return null;
        }

        $ejemplo = ApiSpec::exampleBodies()[strtoupper($method) . ' ' . $uri] ?? null;

        // La carga masiva sube un fichero, no JSON.
        if ($uri === 'api/students/bulk-upload') {
            return [
                'required' => true,
                'content'  => ['multipart/form-data' => ['schema' => [
                    'type'       => 'object',
                    'properties' => ['file' => [
                        'type'        => 'string',
                        'format'      => 'binary',
                        'description' => 'CSV o XLSX. Máximo 5 MB y 5.000 filas.',
                    ]],
                ]]],
            ];
        }

        if ($ejemplo === null) {
            return null;
        }

        unset($ejemplo['_comment']);

        return [
            'required' => true,
            'content'  => ['application/json' => [
                'schema'  => ['type' => 'object'],
                'example' => $this->resolverPlaceholders($ejemplo),
            ]],
        ];
    }

    /**
     * Los cuerpos de ejemplo vienen de la colección de Postman y llevan sus
     * variables `{{...}}`, que ahí las resuelve el runner. En Swagger no
     * significan nada y encima invitan a pegarlas tal cual, así que se
     * sustituyen por valores literales plausibles.
     *
     * @param  array<string,mixed>  $ejemplo
     * @return array<string,mixed>
     */
    private function resolverPlaceholders(array $ejemplo): array
    {
        static $valores = [
            'admin_email'      => 'admin@neoeducore.edu.co',
            'admin_password'   => 'password123',
            'teacher_email'    => 'profesor1@neoeducore.edu.co',
            'student_email'    => 'estudiante1@neoeducore.edu.co',
            'reset_token'      => '<token recibido por correo>',
            'institution_id'   => '00000000-0000-4000-8000-000000000001',
        ];

        foreach ($ejemplo as $clave => $valor) {
            if (is_array($valor)) {
                $ejemplo[$clave] = $this->resolverPlaceholders($valor);
                continue;
            }

            if (is_string($valor)) {
                $ejemplo[$clave] = preg_replace_callback(
                    '/\{\{(\w+)\}\}/',
                    fn (array $m) => $valores[$m[1]] ?? ('<' . $m[1] . '>'),
                    $valor
                );
            }
        }

        return $ejemplo;
    }

    /** @return array<string,mixed> */
    private function respuestas(string $method, string $uri, bool $protegida, bool $porRol): array
    {
        $r = [
            $method === 'post' && ! str_contains($uri, 'login') ? '201' : '200' => [
                'description' => 'Operación correcta',
            ],
        ];

        if ($protegida) {
            $r['401'] = ['description' => 'No autenticado: falta el token o ha caducado'];
        }

        if ($porRol) {
            $r['403'] = ['description' => 'El rol del usuario no tiene permiso, o el recurso es de otro docente'];
        }

        if (str_contains($uri, '{')) {
            $r['404'] = ['description' => 'No encontrado, o fuera de la institución del usuario'];
        }

        if (in_array($method, ['post', 'put', 'patch'], true)) {
            $r['422'] = ['description' => 'Error de validación'];
        }

        return $r;
    }

    /** @param array<int,string> $nombres */
    private function ordenados(array $nombres): array
    {
        sort($nombres);

        return $nombres;
    }

    private function descripcion(): string
    {
        return "API REST del backend NeoEduCore.\n\n"
            . "**Este documento se genera con `php artisan openapi:generate`**, leyendo las rutas reales "
            . "de la aplicación, así que no se desincroniza al añadir o quitar endpoints. No se edita a mano.\n\n"
            . "Autenticación: token opaco de Laravel Sanctum (no JWT). Multi-tenant: cada petición queda "
            . "acotada a la institución del usuario autenticado.\n\n"
            . "Los cuerpos de ejemplo salen de `App\\Support\\ApiSpec`, compartidos con la colección de Postman.";
    }
}
