<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait TenantScoped
{
    protected static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {

            // Evita errores en CLI, seeds, jobs, migrations
            if (!app()->bound('tenant_id')) {
                return;
            }

            $tenantId = app('tenant_id');

            if ($tenantId) {
                $builder->where(
                    $builder->getModel()->getTable() . '.institution_id',
                    $tenantId
                );
            }
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