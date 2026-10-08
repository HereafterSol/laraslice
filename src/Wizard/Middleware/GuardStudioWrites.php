<?php

namespace LaraSlice\Wizard\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses studio actions that write code, run migrations or change data while the
 * application runs in production, unless explicitly allowed with
 * LARASLICE_WIZARD_ALLOW_IN_PRODUCTION=true.
 */
class GuardStudioWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! config('laraslice.wizard.allow_in_production', false)) {
            $message = 'Slice Studio cannot change code, migrations or data in production. '
                .'Make the change in development and deploy it, or set LARASLICE_WIZARD_ALLOW_IN_PRODUCTION=true.';

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $message], 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }
}
