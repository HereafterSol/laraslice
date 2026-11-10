<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * F4: link/route integrity audit for slices.
 *
 * For every active slice that declares a navigation URL, verify that:
 *  - an index route exists for the URL,
 *  - when the slice's primary table holds records, a detail (show) route
 *    exists under the same URL — list rows must never be dead ends,
 *  - the detail route is owned by a controller inside the slice's own
 *    namespace — a detail route resolving into another slice's tree is
 *    the cross-slice collision that shipped silently in ported apps.
 *  - the registry holds no name collisions: duplicate raw names
 *    (the later registration wins the key) or names that differ only
 *    by spacing/case (normalized lookups can only pick one) are
 *    reported from SliceManager::nameCollisions().
 *  - no table is claimed as the PRIMARY table by two
 *    distinctly-named active slices (cross-port table
 *    ownership — see Check 4). Tables merely shared as
 *    non-primary models (parent/child composition) are
 *    reported as information, not problems.
 *
 * Exits non-zero when problems are found (CI-friendly). Writes nothing.
 */
final class SliceLinksCommand extends Command
{
    protected $signature = 'slice:links';

    protected $description = 'Audit slice navigation links: index/show route presence and route ownership. Writes nothing.';

    public function handle(SliceManager $manager): int
    {
        $manager->discover(true); // entry point: operate on the slices as they are on disk now, not a boot-time registry
        $routes = Route::getRoutes();
        $problems = [];
        $checked = 0;

        // Check 1: duplicate page-route names across slices. Generated
        // blades build row links with route('<name>.show'), and Laravel
        // resolves a duplicated name to a single winner — so when two
        // slices claim the same name, every list's View/Edit links jump
        // to the winner's tree (the Tasks list opening a Demo slice's
        // detail page is this bug in the wild).
        $pageSuffixes = ['index', 'show', 'create', 'store', 'edit', 'update', 'destroy'];
        $claims = [];
        foreach ($routes as $route) {
            $name = $route->getName();
            if (! $name) {
                continue;
            }
            $suffix = str_contains($name, '.') ? substr(strrchr($name, '.'), 1) : '';
            if (! in_array($suffix, $pageSuffixes, true)) {
                continue;
            }
            $action = (string) ($route->getActionName() ?? '');
            $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
            $ns = explode('\\Controllers\\', $controller)[0] ?? $controller;
            $claims[$name][$ns] = trim($route->uri(), '/');
        }
        foreach ($claims as $name => $owners) {
            if (count($owners) > 1) {
                $list = collect($owners)->map(fn ($uri, $ns) => "{$ns} ({$uri})")->implode('; ');
                $problems[] = ['route:'.$name, 'claimed by '.count($owners).' slices — route() links resolve to only one: '.$list];
            }
        }

        // Check 2: per-slice nav integrity (index presence, dead-end
        // details, cross-namespace detail routes under the nav base).
        foreach ($manager->getActiveSlices() as $slice) {
            $navUrl = $slice->raw['navigation']['url'] ?? null;
            if (! is_string($navUrl) || $navUrl === '' || str_contains($navUrl, '://')) {
                continue;
            }
            $base = trim($navUrl, '/');
            if ($base === '') {
                continue;
            }
            $checked++;
            $namespace = (string) ($slice->raw['namespace'] ?? '');

            $hasIndex = false;
            $showRoute = null;
            foreach ($routes as $route) {
                if (! in_array('GET', $route->methods(), true)) {
                    continue;
                }
                $uri = trim($route->uri(), '/');
                if ($uri === $base) {
                    $hasIndex = true;
                }
                if (preg_match('#^'.preg_quote($base, '#').'/\{[^}]+\}$#', $uri)) {
                    $showRoute = $route;
                }
            }

            if (! $hasIndex) {
                $problems[] = [$slice->name, "no index route for {$navUrl}"];

                continue;
            }

            $primary = $slice->primaryTable();
            $hasRecords = false;
            if ($primary && Schema::hasTable($primary)) {
                try {
                    $hasRecords = DB::table($primary)->limit(1)->exists();
                } catch (\Throwable $e) {
                    $hasRecords = false;
                }
            }

            if ($showRoute === null) {
                if ($hasRecords) {
                    $problems[] = [$slice->name, "records exist in {$primary} but no detail route under {$navUrl} — list rows are dead ends"];
                }

                continue;
            }

            if ($namespace !== '') {
                $action = (string) ($showRoute->getActionName() ?? '');
                $controller = str_contains($action, '@') ? strstr($action, '@', true) : $action;
                if ($controller !== '' && $controller !== 'Closure' && ! str_starts_with($controller, rtrim($namespace, '\\'))) {
                    $problems[] = [$slice->name, "detail route owned by {$controller} — outside the slice namespace {$namespace}"];
                }
            }
        }

        // Check 3: registry name collisions. Two slices whose names
        // differ only by spaces/underscores/dashes ('Email Templates'
        // vs 'EmailTemplates') both register, but normalized lookups
        // (getSlice with a studly/singular query, commands taking a
        // slice name) can only resolve deterministically to one —
        // the other is shadowed for name-based tooling. Identical raw
        // names are worse: the later registration silently wins the
        // registry key. Either way the operator must know.
        foreach ($manager->nameCollisions() as $collision) {
            $list = collect($collision['slices'])
                ->map(fn ($s) => "'{$s['name']}' ({$s['path']})")
                ->implode('; ');
            $problems[] = [
                'registry:'.$collision['key'],
                $collision['type'] === 'duplicate-name'
                    ? "duplicate slice name — the later registration wins the registry key: {$list}"
                    : "names collide when normalized — name-based lookups resolve deterministically to one of: {$list}",
            ];
        }

        // Check 4: table ownership — with precision about WHAT
        // is claimed (precision pass, Cursor EVAL-B 10 Nov
        // 2026). The collision that corrupts data is two slices
        // each owning the same table as their PRIMARY table
        // (the classic cross-port collision: two source apps
        // ported into one install, both owning `invoices`) —
        // their models write through each other and the richer
        // slice is shadowed. A table that appears as a
        // NON-primary model in other slices is the standard
        // parent/child composition of this architecture (an
        // Orders slice modelling order_items beside the
        // OrderItems slice that owns them; a Roles slice
        // reading permission_types beside the ReferenceData
        // slice that owns them) — reporting those as failures
        // buried the real collisions in noise, so they are
        // printed as information only.
        // Scope: distinctly-named claimants that survived
        // registration — same-name overwrites never reach this
        // loop and are reported by Check 3 instead.
        $classified = self::classifyTableClaims($manager->getActiveSlices(), $manager);
        $problems = array_merge($problems, $classified['problems']);
        $shared = $classified['shared'];

        $this->info("Checked {$checked} navigable slices and ".count($claims).' page-route names.');
        foreach ($shared as [$name, $note]) {
            $this->line("  [{$name}] shared (composition, not a collision) — {$note}");
        }
        if ($problems === []) {
            $this->info('✓ All slice links resolve inside their own slice.');

            return self::SUCCESS;
        }

        $this->warn(count($problems).' link problem(s):');
        foreach ($problems as [$name, $issue]) {
            $this->warn("  [{$name}] {$issue}");
        }

        return self::FAILURE;
    }

    /**
     * Check 4's classification, extracted for direct testing:
     * a table claimed as PRIMARY by two or more slices is a
     * problem; a table claimed by several slices with at most
     * one primary owner is shared composition (information).
     *
     * @param  array<int, \LaraSlice\Core\Discovery\SliceManifest>  $slices
     * @return array{problems: array<int, array{0: string, 1: string}>, shared: array<int, array{0: string, 1: string}>}
     */
    public static function classifyTableClaims(array $slices, SliceManager $manager): array
    {
        $owners = [];
        $rootOwners = [];
        foreach ($slices as $slice) {
            $primary = $slice->primaryTable();
            foreach ($manager->getSliceTables($slice) as $table) {
                $owners[$table][$slice->name.' @ '.$slice->path] = true;
                if ($primary !== null && $primary === $table) {
                    $rootOwners[$table][$slice->name.' @ '.$slice->path] = true;
                }
            }
        }
        $problems = [];
        $shared = [];
        foreach ($owners as $table => $claimants) {
            if (count($claimants) < 2) {
                continue;
            }
            $roots = array_keys($rootOwners[$table] ?? []);
            if (count($roots) > 1) {
                $problems[] = ['table:'.$table, 'owned as primary table by '.count($roots).' active slices — models write through each other: '.implode('; ', $roots)];
            } else {
                $shared[] = ['table:'.$table, ($roots !== [] ? 'primary owner '.$roots[0] : 'no primary owner').'; also modelled by '.implode('; ', array_diff(array_keys($claimants), $roots))];
            }
        }

        return ['problems' => $problems, 'shared' => $shared];
    }
}
