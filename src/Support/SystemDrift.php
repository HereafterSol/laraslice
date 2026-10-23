<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * Mind ↔ Heart drift audit.
 *
 * The trilogy's rule 3: the Mind and the Bodies are checked
 * against the Heart and the runtime, and drift is reported.
 * This audit compares what the Mind PROMISES (role clubs and
 * their permission patterns, journeys' implied access) and
 * what the Bodies DECLARE (per-club access + data scope)
 * against what the runtime actually GRANTS (role → permission
 * rows). Pure logic over plain arrays so it is unit-testable;
 * the slice:drift command gathers the live inputs.
 */
class SystemDrift
{
    /**
     * @param array<string, mixed> $mind parsed Mind (system.yaml)
     * @param array<string, list<string>> $roleGrants role name => granted permission slugs
     * @param list<string> $registry every declared permission slug
     * @param array<string, array<string, mixed>> $bodies slice => parsed Body (v2) — access sections are read when present
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function audit(array $mind, array $roleGrants, array $registry, array $bodies = []): array
    {
        $findings = [];
        $clubs = $mind['roles']['clubs'] ?? [];

        // Map clubs to roles by normalized name; a club may also
        // declare maps_to in the Mind.
        $roleByNorm = [];
        foreach (array_keys($roleGrants) as $role) {
            $roleByNorm[self::norm($role)] = $role;
        }

        foreach ($clubs as $club) {
            $clubName = (string) ($club['name'] ?? '');
            $patterns = $club['permissions'] ?? [];
            if ($patterns === ['*'] || in_array('*', $patterns, true)) {
                continue; // the super club is definitionally complete
            }
            $mappedRole = $club['maps_to'] ?? ($roleByNorm[self::norm($clubName)] ?? null);

            foreach ($patterns as $pattern) {
                $pattern = (string) $pattern;
                [$required, $scopeNote] = self::requiredPermissions($pattern, $registry);
                if ($required === []) {
                    $findings[] = self::finding('club-pattern-unmatched', 'warning',
                        "Club {$clubName} declares '{$pattern}' but no declared permission matches it — the pattern names nothing the Hearts offer.");
                    continue;
                }
                if ($scopeNote !== null) {
                    $findings[] = self::finding('scope-declared', 'warning',
                        "Club {$clubName} declares '{$pattern}' ({$scopeNote}); data-scope enforcement is a runtime duty — verify it before release.");
                }
                if ($mappedRole !== null) {
                    $missing = array_values(array_filter($required, fn ($slug) => ! self::grantsSatisfy($roleGrants[$mappedRole] ?? [], $slug)));
                    if ($missing !== []) {
                        $findings[] = self::finding('club-grant-missing', 'error',
                            "Club {$clubName} (role {$mappedRole}) promises '{$pattern}' but the role does not grant: ".implode(', ', $missing).'.');
                    }
                } else {
                    $grantors = [];
                    foreach ($roleGrants as $role => $grants) {
                        $covered = true;
                        foreach ($required as $slug) {
                            if (! self::grantsSatisfy($grants, $slug)) {
                                $covered = false;
                                break;
                            }
                        }
                        if ($covered) {
                            $grantors[] = $role;
                        }
                    }
                    $findings[] = $grantors === []
                        ? self::finding('club-ungranted', 'error',
                            "Club {$clubName} promises '{$pattern}' (".implode(', ', $required).") but NO role grants it — the Mind promises access nobody has.")
                        : self::finding('club-unmapped', 'warning',
                            "Club {$clubName} has no role with a matching name; its '{$pattern}' promise is currently kept by: ".implode(', ', $grantors).'. Map the club (maps_to) or rename for clarity.');
                }
            }
        }

        // Roles that grant nothing at all.
        foreach ($roleGrants as $role => $grants) {
            if ($grants === [] && ! preg_match('/super/i', $role)) {
                $findings[] = self::finding('role-empty', 'error',
                    "Role {$role} grants zero permissions — every page it can navigate to will refuse it (403). Grant its club bundle or remove the role.");
            }
        }

        // Bodies: per-club access promises vs grants.
        foreach ($bodies as $slice => $body) {
            foreach (($body['access'] ?? []) as $clubName => $access) {
                $actions = $access['actions'] ?? [];
                $scope = $access['scope'] ?? null;
                $mappedRole = $roleByNorm[self::norm((string) $clubName)] ?? null;
                foreach ($actions as $action) {
                    $slug = self::actionSlug((string) $slice, (string) $action);
                    if ($slug === null) {
                        continue;
                    }
                    if (! self::slugDeclared($slug, $registry)) {
                        $findings[] = self::finding('body-permission-undeclared', 'warning',
                            "Body {$slice}: club {$clubName} action '{$action}' implies {$slug}, which no Heart declares.");
                        continue;
                    }
                    $granted = $mappedRole !== null
                        ? self::grantsSatisfy($roleGrants[$mappedRole] ?? [], $slug)
                        : self::anyRoleGrants($roleGrants, $slug);
                    if (! $granted) {
                        $findings[] = self::finding('body-access-ungranted', 'error',
                            "Body {$slice}: club {$clubName} is declared '{$action}' access but ".($mappedRole ? "role {$mappedRole} does not grant {$slug}" : "no role grants {$slug}").'.');
                    }
                }
                if ($scope === 'own') {
                    $findings[] = self::finding('scope-declared', 'warning',
                        "Body {$slice}: club {$clubName} is scoped 'own' — the runtime must filter lists and guard detail routes to the viewer's own records; verify before release.");
                }
            }
        }

        return $findings;
    }

    /** @param array<string, list<string>> $roleGrants */
    private static function anyRoleGrants(array $roleGrants, string $slug): bool
    {
        foreach ($roleGrants as $grants) {
            if (self::grantsSatisfy($grants, $slug)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The runtime's authorization accepts the base slug and its
     * plural ({base}.{action} or {bases}.{action}, plus
     * wildcards) — a grant satisfies a requirement when any
     * candidate form is granted. Mirrors
     * AuthorizesSliceActions::slicePermissionCandidates.
     *
     * @param list<string> $grants
     */
    private static function grantsSatisfy(array $grants, string $slug): bool
    {
        foreach (self::slugCandidates($slug) as $candidate) {
            if (in_array($candidate, $grants, true)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $registry */
    private static function slugDeclared(string $slug, array $registry): bool
    {
        return self::resolveDeclaredSlug($slug, $registry) !== null;
    }

    /**
     * The registry's own spelling of a requirement: the first
     * candidate form actually declared (leaf.create for a
     * leaves.create requirement). Wildcard candidates do not
     * resolve — a requirement is a concrete permission.
     *
     * @param list<string> $registry
     */
    private static function resolveDeclaredSlug(string $slug, array $registry): ?string
    {
        foreach (self::slugCandidates($slug) as $candidate) {
            if (str_ends_with($candidate, '.*')) {
                continue;
            }
            if (in_array($candidate, $registry, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function slugCandidates(string $slug): array
    {
        $pos = strrpos($slug, '.');
        if ($pos === false) {
            return [$slug];
        }
        $base = substr($slug, 0, $pos);
        $action = substr($slug, $pos + 1);
        $forms = [$base, \Illuminate\Support\Str::singular($base), \Illuminate\Support\Str::plural($base)];
        $out = [];
        foreach (array_unique($forms) as $form) {
            $out[] = "{$form}.{$action}";
            $out[] = "{$form}.*";
        }
        // A full-view grant satisfies a view-own REQUIREMENT
        // (it is strictly broader) — the reverse is not true,
        // which is why this widening lives only here, on the
        // requirement side.
        if ($action === 'view-own') {
            foreach (array_unique($forms) as $form) {
                $out[] = "{$form}.view";
            }
        }
        return $out;
    }

    private static function actionSlug(string $slice, string $action): ?string
    {
        return match ($action) {
            'view' => "{$slice}.view",
            'create' => "{$slice}.create",
            'edit' => "{$slice}.edit",
            'delete' => "{$slice}.delete",
            'approve' => null, // workflow transitions are gated by the workflow engine, not a .approve slug
            default => null,
        };
    }

    /**
     * Expand a club pattern to the concrete permissions it
     * requires: globs (hr.*, *) match the registry; `X.apply`
     * requires X.create (applying IS creating a record);
     * `X.view-own` requires X.view plus a scope note.
     *
     * @param list<string> $registry
     * @return array{0: list<string>, 1: ?string}
     */
    private static function requiredPermissions(string $pattern, array $registry): array
    {
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1);
            return [array_values(array_filter($registry, fn ($slug) => str_starts_with($slug, $prefix))), null];
        }
        if (str_ends_with($pattern, '.view-own')) {
            // The requirement is the construct slug itself; a
            // broader full-view grant also satisfies it via the
            // candidate widening in slugCandidates().
            return [[$pattern], "scoped to the viewer's own records"];
        }
        if (str_ends_with($pattern, '.apply')) {
            $base = substr($pattern, 0, -strlen('.apply'));
            return [[$base.'.create'], null];
        }
        return [[$pattern], null];
    }

    private static function norm(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $name) ?? '');
    }

    /** @return array{kind: string, severity: string, message: string} */
    private static function finding(string $kind, string $severity, string $message): array
    {
        return ['kind' => $kind, 'severity' => $severity, 'message' => $message];
    }

    /**
     * Plan the reconciliation between the Mind's club promises
     * and the roles' grants: one action per (role, slug) —
     * 'grant' when the permission exists, 'create-and-grant'
     * for framework-construct slugs (view-own forms) that the
     * registry does not carry yet, and 'unresolvable' for
     * patterns naming nothing. Pure: the command executes.
     *
     * @param array<string, mixed> $mind
     * @param array<string, list<string>> $roleGrants
     * @param list<string> $registry
     * @return list<array{club: string, role: ?string, slug: string, action: string, pattern: string}>
     */
    public static function planReconciliation(array $mind, array $roleGrants, array $registry): array
    {
        $actions = [];
        $roleByNorm = [];
        foreach (array_keys($roleGrants) as $role) {
            $roleByNorm[self::norm($role)] = $role;
        }
        foreach (($mind['roles']['clubs'] ?? []) as $club) {
            $clubName = (string) ($club['name'] ?? '');
            $patterns = $club['permissions'] ?? [];
            if (in_array('*', $patterns, true)) {
                continue;
            }
            $role = $club['maps_to'] ?? ($roleByNorm[self::norm($clubName)] ?? null);
            foreach ($patterns as $pattern) {
                $pattern = (string) $pattern;
                // A view-own promise reconciles to the view-own
                // CONSTRUCT slug itself (task.view-own), never to
                // the full view — granting the full view would
                // silently widen own-scope to all records.
                if (str_ends_with($pattern, '.view-own')) {
                    if ($role === null) {
                        $actions[] = ['club' => $clubName, 'role' => null, 'slug' => $pattern, 'action' => 'unmapped-club', 'pattern' => $pattern];
                        continue;
                    }
                    if (self::grantsSatisfy($roleGrants[$role] ?? [], $pattern)) {
                        continue;
                    }
                    $actions[] = ['club' => $clubName, 'role' => $role, 'slug' => $pattern,
                        'action' => in_array($pattern, $registry, true) ? 'grant' : 'create-and-grant', 'pattern' => $pattern];
                    continue;
                }
                [$required] = self::requiredPermissions($pattern, $registry);
                if ($required === []) {
                    $actions[] = ['club' => $clubName, 'role' => $role, 'slug' => $pattern, 'action' => 'unresolvable', 'pattern' => $pattern];
                    continue;
                }
                foreach ($required as $slug) {
                    if ($role === null) {
                        $actions[] = ['club' => $clubName, 'role' => null, 'slug' => $slug, 'action' => 'unmapped-club', 'pattern' => $pattern];
                        continue;
                    }
                    if (self::grantsSatisfy($roleGrants[$role] ?? [], $slug)) {
                        continue; // already kept
                    }
                    // Resolve to the DECLARED slug form (leaf.create,
                    // not the leaves.create requirement spelling) so
                    // the executor grants the real permission row.
                    $declared = self::resolveDeclaredSlug($slug, $registry);
                    $actions[] = ['club' => $clubName, 'role' => $role, 'slug' => $declared ?? $slug,
                        'action' => $declared !== null ? 'grant' : 'create-and-grant', 'pattern' => $pattern];
                }
            }
        }
        return $actions;
    }
}
