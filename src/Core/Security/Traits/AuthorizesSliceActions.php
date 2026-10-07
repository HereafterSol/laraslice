<?php

namespace LaraSlice\Core\Security\Traits;

use Illuminate\Support\Str;
use LaraSlice\Core\Security\Access;

/**
 * Deny-by-default permission checks for slice controllers.
 *
 * An action is allowed only for super-admins or users holding one of
 * `{base}.{action}`, `{bases}.{action}`, `{base}.*` or `{bases}.*`.
 */
trait AuthorizesSliceActions
{
    /**
     * Permission base slug, e.g. "user" for users.view / user.view.
     */
    abstract protected function getPermissionBase(): string;

    protected function authorizeSlice(string $action): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        $candidates = $this->slicePermissionCandidates($action);

        if (! Access::allows($user, $candidates)) {
            abort(403, "Access Denied: You do not have the required permission [{$candidates[1]}] to perform this action.");
        }
    }

    /**
     * @return array<int, string>
     */
    protected function slicePermissionCandidates(string $action): array
    {
        $base = $this->getPermissionBase();
        $plural = Str::plural($base);

        return array_values(array_unique([
            "{$base}.{$action}",
            "{$plural}.{$action}",
            "{$base}.*",
            "{$plural}.*",
        ]));
    }
}
