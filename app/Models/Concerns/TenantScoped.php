<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait TenantScoped
{
    /**
     * `institution_id` no sale en el JSON de la API: quien consulta ya está dentro de
     * su centro (el token lo fija) y el valor era el mismo en TODAS las filas y en
     * cada modelo anidado —36 caracteres más la clave, repetidos cientos de veces por
     * listado—. Sigue disponible como atributo (`$modelo->institution_id`) para el
     * código; solo se oculta al serializar. El front nunca lo ha usado.
     */
    public function initializeTenantScoped(): void
    {
        $this->makeHidden('institution_id');
    }

    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {

            if (!app()->bound('tenant_id') || !app('tenant_id')) {
                /*
                | En CLI (artisan, migraciones, seeders, tests, worker de cola) es
                | esperado. En HTTP es un bug: el middleware SetTenantFromAuth no
                | corrió, y seguir adelante devolvería filas de TODAS las
                | instituciones.
                |
                | La condición mira la marca que pone `MarcaContextoHttp`, no
                | `app()->runningInConsole()`. Ese método mira `PHP_SAPI`, y
                | **Octane arranca desde consola**: con `--server=swoole` o
                | `roadrunner` el SAPI es `cli` en plena petición HTTP, así que
                | esta guarda se habría apagado sola y en silencio. Con FrankenPHP
                | —lo que fija hoy el Dockerfile— no pasa, pero era una palabra del
                | `CMD` de distancia, y el `CMD` se toca al desplegar.
                */
                if (app()->bound('contexto_http')) {
                    throw new \RuntimeException(
                        'Modelo ' . $builder->getModel()::class . ' consultado sin contexto ' .
                        'de tenant. Verifica que SetTenantFromAuth esté activo en la ruta.'
                    );
                }
                return;
            }

            $builder->where(
                $builder->getModel()->getTable() . '.institution_id',
                app('tenant_id')
            );
        });

        // Autoasignar institution_id al crear
        static::creating(function ($model) {
            if (
                app()->bound('tenant_id') &&
                empty($model->institution_id)
            ) {
                $model->institution_id = app('tenant_id');
            }
        });
    }
}