<?php

namespace LaraSlice\Wizard\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeStudio
{
    /**
     * Handle an incoming request to Slice Studio & Architecture Wizard.
     */
    public function handle(Request $request, Closure $next, ?string $permission = 'studio.access'): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // 1. Super Administrator always has unrestricted access
        if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
            return $next($request);
        }

        // 2. Check candidate permissions for Slice Studio
        $candidates = array_unique(array_filter([
            $permission,
            'studio.access',
            'system.slices.view',
            'system.*',
            'studio.*',
        ]));

        $hasAccess = false;
        foreach ($candidates as $candidate) {
            if (method_exists($user, 'hasPermission') && $user->hasPermission($candidate)) {
                $hasAccess = true;
                break;
            } elseif (method_exists($user, 'can') && $user->can($candidate)) {
                $hasAccess = true;
                break;
            }
        }

        if (! $hasAccess) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access Denied: You do not have the required permission [' . $permission . '] to access Slice Studio.',
                ], 403);
            }

            abort(403, 'Access Denied: You do not have the required permission [' . $permission . '] to access Slice Studio.');
        }

        return $next($request);
    }
}