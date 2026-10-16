<?php

namespace LaraSlice\Core\Tenancy\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LaraSlice\Core\Tenancy\TenantManager;

class TenantScope implements Scope
{
    /**
     * Apply the tenant constraint to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! TenantManager::isEnabled()) {
            return;
        }

        $tenantId = TenantManager::getCurrentTenantId();
        if ($tenantId !== null) {
            $keyName = method_exists($model, 'getTenantKeyName')
                ? $model->getTenantKeyName()
                : config('laraslice.tenancy.tenant_key', 'company_id');

            $builder->where($model->getTable().'.'.$keyName, $tenantId);
        }
    }
}
