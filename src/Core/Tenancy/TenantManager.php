<?php

namespace LaraSlice\Core\Tenancy;

use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;

class TenantManager
{
    protected static ?int $overrideTenantId = null;

    protected static bool $bypass = false;

    /**
     * Determine if multi-tenancy is active.
     */
    public static function isEnabled(): bool
    {
        if (static::$bypass) {
            return false;
        }

        return (bool) Config::get('laraslice.tenancy.enabled', false);
    }

    /**
     * Enable tenancy mode at runtime.
     */
    public static function enable(): void
    {
        Config::set('laraslice.tenancy.enabled', true);
        static::$bypass = false;
    }

    /**
     * Disable tenancy mode at runtime.
     */
    public static function disable(): void
    {
        Config::set('laraslice.tenancy.enabled', false);
    }

    /**
     * Resolve the active Tenant ID from runtime, session, auth user, or request headers.
     */
    public static function getCurrentTenantId(): ?int
    {
        if (static::$bypass) {
            return null;
        }

        if (static::$overrideTenantId !== null) {
            return static::$overrideTenantId;
        }

        $tenantKey = Config::get('laraslice.tenancy.tenant_key', 'company_id');

        // 1. Session active company / tenant
        if (Session::has('active_tenant_id')) {
            return (int) Session::get('active_tenant_id');
        }
        if (Session::has('active_company_id')) {
            return (int) Session::get('active_company_id');
        }

        // 2. Authenticated user attribute
        $user = auth()->user();
        if ($user) {
            if (isset($user->{$tenantKey}) && $user->{$tenantKey} !== null) {
                return (int) $user->{$tenantKey};
            }
            if (isset($user->company_id) && $user->company_id !== null) {
                return (int) $user->company_id;
            }
            if (isset($user->tenant_id) && $user->tenant_id !== null) {
                return (int) $user->tenant_id;
            }
        }

        // 3. Request Header (API / Webhooks)
        if (request()->hasHeader('X-Tenant-Id')) {
            return (int) request()->header('X-Tenant-Id');
        }
        if (request()->hasHeader('X-Company-Id')) {
            return (int) request()->header('X-Company-Id');
        }

        return null;
    }

    /**
     * Set the current tenant context.
     */
    public static function setTenant(?int $tenantId): void
    {
        static::$overrideTenantId = $tenantId;
        if ($tenantId !== null) {
            Session::put('active_tenant_id', $tenantId);
            Session::put('active_company_id', $tenantId);
        } else {
            Session::forget('active_tenant_id');
            Session::forget('active_company_id');
        }
    }

    /**
     * Execute a callback without any tenant scoping (e.g. system jobs, platform admin).
     */
    public static function withoutTenancy(Closure $callback): mixed
    {
        $previousBypass = static::$bypass;
        static::$bypass = true;

        try {
            return $callback();
        } finally {
            static::$bypass = $previousBypass;
        }
    }
}
