<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait TenantScoped
{
    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {

            if (!app()->bound('tenant_id') || !app('tenant_id')) {
                // En CLI (artisan, migrations, seeds, tests, queue workers) es esperado.
                // En HTTP es un bug: el middleware SetTenantFromAuth no corrió.
                if (!app()->runningInConsole()) {
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