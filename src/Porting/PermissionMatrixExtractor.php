<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

use Illuminate\Support\Str;
use PDO;

/**
 * Source permission-matrix extraction (backlog C3).
 *
 * The porter historically ported role NAMES, not the
 * matrix behind them — personas were rebuilt by hand
 * afterwards (the demo's Mind clubs are hand-repaired).
 * This extractor reads the source's declared matrix —
 * roles × permissions with a per-pair SCOPE (the
 * Worksuite shape: permission_types added / owned /
 * both / all / none over a permission_role pivot) — and
 * normalizes it into LaraSlice vocabulary:
 *
 *  - verbs map to actions (add/create → create,
 *    view → view, edit/update/change → update,
 *    delete → delete; any other verb is kept as a
 *    custom action and flagged);
 *  - the noun is singularized per segment into a
 *    proposed permission base (project_tasks →
 *    project_task) — proposals, resolved against the
 *    real registry by slice:drift exactly like Mind
 *    patterns are;
 *  - scope 'all' proposes the plain slug; a scoped
 *    view (owned/added/both) proposes the view-own
 *    construct; scoped writes keep the action slug and
 *    carry their scope in the artifact, where own_scope
 *    declarations can honour it. 'none' rows are
 *    counted, never emitted as grants.
 *
 * Sources without this table shape yield an empty
 * matrix with a note — never an exception.
 */
final class PermissionMatrixExtractor
{
    private const VERB_ACTIONS = [
        'add' => 'create',
        'create' => 'create',
        'view' => 'view',
        'edit' => 'update',
        'update' => 'update',
        'change' => 'update',
        'delete' => 'delete',
    ];

    private const KNOWN_VERBS = [
        'add', 'create', 'view', 'edit', 'update', 'change', 'delete',
        'approve', 'link', 'unlink', 'manage', 'send', 'renew',
        'reschedule', 'recruit', 'task',
    ];

    /**
     * @return array{roles: array<int, array<string, mixed>>, notes: array<int, string>, totals: array<string, int>}
     */
    public static function extract(PDO $source): array
    {
        $tables = $source->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? $source->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN)
            : $source->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['roles', 'permissions', 'permission_role', 'permission_types'] as $required) {
            if (! in_array($required, $tables, true)) {
                return ['roles' => [], 'notes' => ["source has no '{$required}' table — no permission matrix to extract"], 'totals' => []];
            }
        }

        $types = [];
        foreach ($source->query('SELECT id, name FROM permission_types')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[(int) $row['id']] = (string) $row['name'];
        }

        $modules = [];
        if (in_array('modules', $tables, true)) {
            foreach ($source->query('SELECT id, module_name FROM modules')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $modules[(int) $row['id']] = (string) $row['module_name'];
            }
        }

        $permissions = [];
        foreach ($source->query('SELECT id, name, module_id FROM permissions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $permissions[(int) $row['id']] = [
                'name' => (string) $row['name'],
                'module' => $modules[(int) $row['module_id']] ?? null,
                'proposal' => self::propose((string) $row['name']),
            ];
        }

        $roles = [];
        $roleRows = $source->query('SELECT id, name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $grantStmt = $source->prepare('SELECT permission_id, permission_type_id FROM permission_role WHERE role_id = ?');
        $totals = ['roles' => 0, 'permissions' => count($permissions), 'effective_grants' => 0, 'unparsed' => 0];

        foreach ($roleRows as $role) {
            $grantStmt->execute([(int) $role['id']]);
            $grants = [];
            $byScope = [];
            foreach ($grantStmt->fetchAll(PDO::FETCH_ASSOC) as $pair) {
                $scope = $types[(int) $pair['permission_type_id']] ?? 'none';
                $byScope[$scope] = ($byScope[$scope] ?? 0) + 1;
                if ($scope === 'none') {
                    continue;
                }
                $perm = $permissions[(int) $pair['permission_id']] ?? null;
                if ($perm === null) {
                    continue;
                }
                if ($perm['proposal'] === null) {
                    $totals['unparsed']++;
                }
                $slug = $perm['proposal'] === null ? null : self::scopedSlug($perm['proposal'], $scope);
                // Compound nouns (project_file, project_task)
                // are hub CHILDREN in a port — authorized
                // through the parent slice. Offer the
                // parent-collapsed slug as an alternative;
                // resolution stats show which form the
                // target registry actually speaks.
                $alt = null;
                if ($perm['proposal'] !== null && str_contains($perm['proposal']['base'], '_')) {
                    $parent = $perm['proposal'];
                    $parent['base'] = explode('_', $parent['base'])[0];
                    $alt = self::scopedSlug($parent, $scope);
                }
                $grants[] = [
                    'source' => $perm['name'],
                    'module' => $perm['module'],
                    'scope' => $scope,
                    'slug' => $slug,
                    'alt_slug' => $alt,
                ];
                $totals['effective_grants']++;
            }
            $isSuper = ($byScope['all'] ?? 0) === count($permissions) && count($permissions) > 0;
            $roles[] = [
                'role' => (string) $role['name'],
                'super' => $isSuper,
                'by_scope' => $byScope,
                'grants' => $grants,
                'club' => [
                    'maps_to' => Str::studly((string) $role['name']),
                    'permissions' => $isSuper ? ['*'] : array_values(array_unique(array_filter(array_column($grants, 'slug')))),
                ],
            ];
            $totals['roles']++;
        }

        return ['roles' => $roles, 'notes' => [], 'totals' => $totals];
    }

    /**
     * Propose the LaraSlice slug for a source permission
     * name, or null when it has no recognizable verb.
     *
     * @return array{base: string, action: string, custom: bool}|null
     */
    public static function propose(string $sourceName): ?array
    {
        if (! preg_match('/^([a-z]+)_(.+)$/', $sourceName, $m)) {
            return null;
        }
        [, $verb, $noun] = $m;
        if (! in_array($verb, self::KNOWN_VERBS, true)) {
            return null;
        }
        $segments = array_map(static fn (string $s) => Str::singular($s), explode('_', $noun));
        $base = implode('_', $segments);

        return [
            'base' => $base,
            'action' => self::VERB_ACTIONS[$verb] ?? $verb,
            'custom' => ! isset(self::VERB_ACTIONS[$verb]),
        ];
    }

    /**
     * @param  array{base: string, action: string, custom: bool}  $proposal
     */
    private static function scopedSlug(array $proposal, string $scope): string
    {
        if ($proposal['action'] === 'view' && $scope !== 'all') {
            return $proposal['base'].'.view-own';
        }

        return $proposal['base'].'.'.$proposal['action'];
    }
}
