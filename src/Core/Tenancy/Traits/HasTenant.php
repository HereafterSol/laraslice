<?php

namespace LaraSlice\Core\Tenancy\Traits;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Core\Tenancy\Scopes\TenantScope;
use LaraSlice\Core\Tenancy\TenantManager;

trait HasTenant
{
    /**
     * Boot the tenant trait for an Eloquent model.
     */
    public static function bootHasTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            $tenantKey = static::getTenantKeyName();
            if (empty($model->{$tenantKey})) {
                $currentTenantId = TenantManager::getCurrentTenantId();
                if ($currentTenantId !== null) {
                    $model->{$tenantKey} = $currentTenantId;
                }
            }
        });
    }

    /**
     * Get the database column name used for tenant isolation.
     */
    public static function getTenantKeyName(): string
    {
        return property_exists(static::class, 'tenantKey') && ! empty(static::$tenantKey)
            ? static::$tenantKey
            : config('laraslice.tenancy.tenant_key', 'company_id');
    }
}
