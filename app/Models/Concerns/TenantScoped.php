<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

trait TenantScoped
{
    public static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($tenantId = app()->bound('tenant_id') ? app('tenant_id') : null) {
                $builder->where($builder->getModel()->getTable() . '.institution_id', $tenantId);
            }
        });
    }
}