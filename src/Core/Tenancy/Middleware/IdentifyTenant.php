<?php

namespace LaraSlice\Core\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaraSlice\Core\Tenancy\TenantManager;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    /**
     * Handle an incoming request and set tenant context.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (TenantManager::isEnabled()) {
            $tenantId = null;

            // 1. Route parameter (e.g. /{tenant}/...)
            if ($request->route('company_id')) {
                $tenantId = (int) $request->route('company_id');
            } elseif ($request->route('tenant_id')) {
                $tenantId = (int) $request->route('tenant_id');
            }

            // 2. Query string override (if authorized)
            if (! $tenantId && $request->filled('switch_company_id')) {
                $tenantId = (int) $request->input('switch_company_id');
            }

            // 3. Fallback to session / auth user via TenantManager
            if (! $tenantId) {
                $tenantId = TenantManager::getCurrentTenantId();
            }

            if ($tenantId !== null) {
                TenantManager::setTenant($tenantId);
            }
        }

        return $next($request);
    }
}
