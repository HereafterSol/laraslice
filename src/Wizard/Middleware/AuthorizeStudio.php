<?php

namespace LaraSlice\Wizard\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaraSlice\Core\Security\Access;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeStudio
{
    /**
     * Require the given studio permission (or the `studio.*` wildcard) for this route.
     */
    public function handle(Request $request, Closure $next, string $permission = 'studio.access'): Response
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(\Illuminate\Support\Facades\Route::has('login') ? route('login') : '/');
        }

        if (Access::allows($user, [$permission, 'studio.*'])) {
            return $next($request);
        }

        $message = "Access Denied: You do not have the required permission [{$permission}] for Slice Studio.";

        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], 403);
        }

        abort(403, $message);
    }
}
