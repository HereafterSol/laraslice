<?php

namespace LaraSlice\Core\Security\Traits;

use Illuminate\Support\Facades\Route;
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

        // Feature-module switch: a deactivated module refuses its
        // pages too, not just its menu entries.
        if (method_exists($this, 'ownManifest')) {
            $featureModule = $this->ownManifest()['feature_module'] ?? null;
            if (is_string($featureModule) && \LaraSlice\Support\ModuleSettings::isHiddenFor($user, $featureModule)) {
                abort(403, "Access Denied: The '{$featureModule}' module is disabled for your account type.");
            }
        }
    }

    /**
     * @return array<int, string>
     */
    protected function slicePermissionCandidates(string $action): array
    {
        $base = $this->getPermissionBase();
        $plural = Str::plural($base);

        $candidates = [
            "{$base}.{$action}",
            "{$plural}.{$action}",
            "{$base}.*",
            "{$plural}.*",
        ];

        // Own-scope view (Body v2 access): holding ONLY the
        // view-own form authorizes the view, and the listing
        // narrows to the viewer's own records (see
        // sliceViewScope). Creating/editing are never implied.
        if ($action === 'view') {
            $candidates[] = "{$base}.view-own";
            $candidates[] = "{$plural}.view-own";
        }

        return array_values(array_unique($candidates));
    }

    /**
     * How the current user may view this slice, under the
     * scope layer: 'all' records, 'both' (their own + ones
     * they added), 'owned', 'added', or 'none'. The scope
     * comes from the grant rows themselves
     * (Access::scopeFor resolves the permission type's
     * name); a legacy grant without a type counts as 'all'.
     * The Body v2 view-own slug form still resolves to
     * 'owned'. The base controller enforces the narrowing
     * on lists and detail routes.
     */
    protected function sliceViewScope(): string
    {
        $user = auth()->user();
        if (! $user) {
            return 'none';
        }
        $base = $this->getPermissionBase();
        $plural = Str::plural($base);

        $scope = 'none';
        foreach (["{$base}.view", "{$plural}.view", "{$base}.*", "{$plural}.*"] as $slug) {
            $scope = Access::widerScope($scope, Access::scopeFor($user, $slug));
        }
        if ($scope === 'none'
            && Access::allows($user, ["{$base}.view-own", "{$plural}.view-own"])) {
            $scope = 'owned';
        }

        return $scope;
    }

    /**
     * What the signed-in user may do in this slice, for showing only the controls they can use.
     * The server still checks every action; this only hides buttons that would end in a 403.
     *
     * @return array{view: bool, create: bool, edit: bool, delete: bool, seed: bool}
     */
    protected function sliceAbilities(): array
    {
        $user = auth()->user();
        $can = fn (string $action) => $user !== null && Access::allows($user, $this->slicePermissionCandidates($action));

        return [
            'view' => $can('view'),
            'create' => $can('create'),
            'edit' => $can('edit'),
            'delete' => $can('delete'),
            'seed' => $user !== null
                && Route::has('laraslice.wizard.seed_slice')
                && Access::allows($user, 'studio.access')
                && Access::allows($user, ['system.slices.seed', 'slice.seed', $this->getPermissionBase().'.seed']),
        ];
    }
}
