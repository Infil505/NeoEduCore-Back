<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

/*
 | Estos atributos solo los lee `l5-swagger:generate`, que YA NO es el
 | generador del proyecto: el documento lo produce `openapi:generate` desde
 | las rutas reales (ver App\Console\Commands\OpenApiGenerate). Se conservan
 | porque documentan la API a nivel de código, pero no alimentan nada.
 */
#[OA\Info(title: 'NeoEduCore API', version: '1.0.0', description: 'API REST del backend NeoEduCore para la plataforma educativa.')]
// `bearerFormat` describía el token como JWT y no lo es: Sanctum emite tokens
// **opacos** guardados en `personal_access_tokens`. El informe del TFG comete el
// mismo error (ver docs/ANALISIS_MODELO_DATOS_TFG.md §9.1 nº 4), así que la
// documentación generada lo estaba respaldando.
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    scheme: 'bearer',
    description: 'Token opaco de Laravel Sanctum. Se obtiene en POST /api/auth/login y se envía como `Authorization: Bearer <token>`.'
)]
#[OA\Server(url: L5_SWAGGER_CONST_HOST, description: 'Servidor local')]
abstract class Controller
{
    /**
     * Tamaño de página de un listado: `?per_page=` acotado a 1..`pagination.max`,
     * y `pagination.default` si no viene o no es un número.
     *
     * Se acota en vez de rechazar con 422: un valor raro no debería romper una
     * pantalla. El frontend pide páginas de 100 para recorrer un listado entero
     * en pocas peticiones —con la base remota cada una cuesta segundos—; antes el
     * backend ignoraba `per_page` y 243 alumnos eran 13 peticiones seguidas.
     */
    /**
     * `paginate()` sin la consulta `COUNT` cuando no hace falta.
     *
     * `paginate()` siempre cuenta primero y luego trae la página: dos consultas.
     * Pero si la página llega con MENOS filas que `per_page`, el total ya se sabe
     * (`(página − 1) × per_page + filas`) y el conteo sobra. Es lo normal en los
     * listados pequeños (materias, aulas, docentes…) y con la base remota cada
     * consulta cuesta ~0,5 s. Si la página sale llena, o vacía más allá de la
     * primera, hay que contar igual y se hace como siempre.
     *
     * Devuelve un paginador con el mismo JSON que `paginate()` salvo los enlaces
     * (`links`, `*_page_url`, `path`, `from`, `to`), que ningún cliente usa y que
     * pesan en cada listado: ver `PaginadorLigero`.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    protected function paginar($query, \Illuminate\Http\Request $request, ?int $tamano = null): \Illuminate\Pagination\LengthAwarePaginator
    {
        $porPagina = $tamano ?? $this->porPagina($request);
        $pagina = max(1, (int) \Illuminate\Pagination\Paginator::resolveCurrentPage());

        $filas = (clone $query)->forPage($pagina, $porPagina)->get();

        $sabeElTotal = $filas->count() < $porPagina && ($pagina === 1 || $filas->isNotEmpty());
        $total = $sabeElTotal
            ? ($pagina - 1) * $porPagina + $filas->count()
            : $query->toBase()->getCountForPagination();

        return new \App\Support\PaginadorLigero($filas, $total, $porPagina, $pagina, [
            'path'     => \Illuminate\Pagination\Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Huella estable de los parámetros de la consulta (página, filtros, orden…)
     * para usarla en la clave de `TenantCache`: dos peticiones con los mismos
     * parámetros, en cualquier orden, comparten entrada.
     */
    protected function huellaDeConsulta(\Illuminate\Http\Request $request): string
    {
        $parametros = $request->query();
        ksort($parametros);

        return md5((string) json_encode($parametros));
    }

    protected function porPagina(\Illuminate\Http\Request $request): int
    {
        $pedido = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);
        $maximo = max(1, (int) config('pagination.max'));

        return $pedido === false || $pedido < 1
            ? (int) config('pagination.default')
            : min($pedido, $maximo);
    }

    /**
     * Materias del centro por id, del catálogo en caché (ver `CatalogoMaterias`).
     *
     * @return \Illuminate\Support\Collection<string,\App\Models\Academic\Subject>
     */
    protected function materiasDelCentro(string $centro): \Illuminate\Support\Collection
    {
        return \App\Support\CatalogoMaterias::delCentro($centro);
    }
}
