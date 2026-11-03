<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Derive slice navigation (label / group / order / visibility) from
 * a live menu probe of the source application.
 *
 * The runtime has always honoured the navigation contract in
 * slice.yaml; ports simply never wrote source-derived values into
 * it, so a fresh port's sidebar listed every generated slice under
 * its raw name. The menu probe (captured from the source's own
 * sidebar: ordered groups of {label, href}) is the evidence: each
 * item is matched to the ported slice that owns the target table,
 * and matched slices inherit the source's label, section and
 * position. With --hide-children, slices whose root table holds a
 * foreign key to another ported slice's root table — detail /
 * line-item slices the source never lists — are hidden from the
 * navigation (they stay routable; visibility is a YAML flag).
 */
class SliceDeriveNavigationCommand extends Command
{
    protected $signature = 'slice:derive-navigation
                            {--probe= : Path to the source menu probe JSON}
                            {--hide-children : Hide non-menu child slices (FK-holding detail tables)}
                            {--apply : Write navigation declarations to slice.yaml files}';

    protected $description = 'Derive slice navigation labels, groups, order and visibility from a source menu probe';

    public function handle(SliceManager $manager): int
    {
        $probePath = $this->option('probe');
        if (! $probePath || ! is_file($probePath)) {
            $this->error('Menu probe not found. Pass --probe=<menu.json>.');

            return self::FAILURE;
        }
        $menu = json_decode((string) file_get_contents($probePath), true);
        $groups = $menu['groups'] ?? [];
        if ($groups === []) {
            $this->error('Menu probe carries no groups.');

            return self::FAILURE;
        }

        $manager->discover(true);

        // table => slice (first table of a slice is its root)
        $byTable = [];
        $slices = [];
        foreach ($manager->getSlices() as $slice) {
            $slices[$slice->name] = $slice;
            foreach (($slice->raw['tables'] ?? []) as $i => $table) {
                if (is_string($table) && $table !== '' && ! isset($byTable[$table])) {
                    $byTable[$table] = ['slice' => $slice->name, 'root' => $i === 0];
                }
            }
        }

        $matched = [];
        $matchedScore = [];
        $unmatched = [];
        $order = 0;
        foreach ($groups as $group) {
            $groupName = $group['group'] ?? null;
            foreach (($group['items'] ?? []) as $item) {
                $order += 10;
                $label = trim((string) ($item['label'] ?? ''));
                $href = (string) ($item['href'] ?? '');
                $slug = Str::slug(basename((string) parse_url($href, PHP_URL_PATH)));
                // App-level pages (dashboards, the settings area)
                // are shell concerns, never slice matches.
                if (in_array($slug, ['dashboard', 'dashboard-advanced', 'company-settings'], true)) {
                    continue;
                }
                [$sliceName, $score] = $this->matchSlice($slug, $byTable);
                if ($sliceName === null) {
                    $unmatched[] = $label !== '' ? $label : $slug;
                    continue;
                }
                // A slice keeps its strongest menu claim: module
                // entries (exact table matches) outrank report /
                // segment matches regardless of menu order; at equal
                // strength the earlier item wins.
                if (isset($matchedScore[$sliceName]) && $matchedScore[$sliceName] >= $score) {
                    continue;
                }
                $matchedScore[$sliceName] = $score;
                $matched[$sliceName] = [
                    'label' => $label,
                    // A standalone item becomes a single-item section
                    // named after itself (the starter layout renders
                    // every group as a section).
                    'group' => $groupName ?: $label,
                    'order' => $order,
                ];
            }
        }

        $hidden = [];
        if ($this->option('hide-children')) {
            $childTables = $this->childTables(array_keys($byTable));
            $rootTables = [];
            $matchedRoots = [];
            foreach ($byTable as $table => $info) {
                if ($info['root']) {
                    $rootTables[$table] = $info['slice'];
                    if (isset($matched[$info['slice']])) {
                        $matchedRoots[$table] = true;
                    }
                }
            }
            foreach ($byTable as $table => $info) {
                if (! $info['root'] || isset($matched[$info['slice']])) {
                    continue;
                }
                if (isset($childTables[$table]) || $this->isFamilyChild($table, $rootTables, $matchedRoots)) {
                    $hidden[$info['slice']] = $table;
                }
            }
        }

        // Unmatched, non-hidden slices keep their porter order,
        // which interleaves their sections through the derived
        // menu. Defer them behind the menu's order range so the
        // source's structure reads first.
        $apply = (bool) $this->option('apply');
        $deferred = [];
        if ($apply) {
            foreach ($slices as $sliceName => $slice) {
                if (isset($matched[$sliceName]) || isset($hidden[$sliceName])) {
                    continue;
                }
                $current = (int) (($slice->raw['navigation']['order'] ?? 50));
                if ($current < 1000) {
                    $deferred[$sliceName] = 1000 + $current;
                }
            }
        }

        foreach ($matched as $sliceName => $nav) {
            $slice = $slices[$sliceName];
            $this->line(sprintf('  <fg=green>%-24s</> ← "%s" [%s] order %d', $sliceName, $nav['label'], $nav['group'], $nav['order']));
            if ($apply) {
                $this->writeNavigation($slice->path, $nav, null);
            }
        }
        foreach ($hidden as $sliceName => $table) {
            $this->line("  <fg=yellow>hide</> {$sliceName} (child table {$table})");
            if ($apply) {
                $slice = $slices[$sliceName];
                $this->writeNavigation($slice->path, null, false);
            }
        }
        foreach ($deferred as $sliceName => $newOrder) {
            $slice = $slices[$sliceName];
            $manifestPath = $slice->path.DIRECTORY_SEPARATOR.'slice.yaml';
            if (is_file($manifestPath)) {
                $manifest = ManifestRepository::read($manifestPath);
                $manifest['navigation'] = (array) ($manifest['navigation'] ?? []);
                $manifest['navigation']['order'] = $newOrder;
                ManifestRepository::writeBoth($manifestPath, $manifest);
            }
        }
        if ($deferred !== []) {
            $this->line('  <fg=gray>deferred '.count($deferred).' unmatched slices behind the menu order range</>');
        }
        if ($unmatched !== []) {
            $this->warn('Menu items with no ported slice: '.implode(', ', $unmatched));
        }
        $this->info(sprintf('Matched %d slices, hid %d child slices, %d menu items unmatched.', count($matched), count($hidden), count($unmatched)));
        if (! $apply) {
            $this->comment('Dry-run complete. Run with --apply to write to slice.yaml.');
        }

        return self::SUCCESS;
    }

    /**
     * A slice is a navigation child when its root table extends a
     * menu-matched family: either a strict name child of another
     * root table (invoice_items of invoices) or a sibling in the
     * same first-segment family as a matched root (employee_docs
     * beside employee_details) that the menu itself does not list.
     *
     * @param  array<string, string>  $rootTables  table => slice
     * @param  array<string, true>  $matchedRoots
     */
    protected function isFamilyChild(string $table, array $rootTables, array $matchedRoots): bool
    {
        $segment = explode('_', $table)[0];
        foreach ($rootTables as $other => $slice) {
            if ($other === $table) {
                continue;
            }
            // Child tables are built on the parent's stem
            // (invoice_items of invoices, leave_files of leaves —
            // the naive stem, since Str::singular('leaves') is
            // 'leaf' while the table family uses 'leave').
            $stems = array_unique(array_filter([
                Str::singular($other),
                str_ends_with($other, 's') ? substr($other, 0, -1) : null,
                $other,
            ]));
            foreach ($stems as $stem) {
                if (str_starts_with($table, $stem.'_')) {
                    return true; // strict child of any ported root
                }
            }
        }
        foreach ($matchedRoots as $matchedTable => $_) {
            if (explode('_', $matchedTable)[0] === $segment) {
                return true; // unmatched sibling of a matched family
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{slice: string, root: bool}>  $byTable
     * @return array{0: string|null, 1: int}
     */
    protected function matchSlice(string $slug, array $byTable): array
    {
        if ($slug === '') {
            return [null, 0];
        }
        $norm = static fn (string $s): string => str_replace(['_', '-'], '', strtolower($s));
        $slugNorm = $norm($slug);
        $slugSingular = $norm(Str::singular($slug));
        $segments = explode('-', $slug);

        $scores = [];
        $tables = [];
        foreach ($byTable as $table => $info) {
            if (! $info['root']) {
                continue;
            }
            // The slice's own name is strong evidence too: a slice
            // named after the menu item (Messages, whose table is
            // users_chat) binds even when its table does not.
            if (Str::slug($info['slice']) === $slug) {
                $scores[$info['slice']] = max($scores[$info['slice']] ?? 0, 85);
                $tables[$info['slice']] = $table;
            }
            $tableNorm = $norm($table);
            $score = null;
            if ($tableNorm === $slugNorm) {
                $score = 100;
            } elseif ($tableNorm === $slugSingular || $tableNorm === $norm(Str::plural(Str::singular($slug)))) {
                $score = 90;
            } elseif (str_starts_with($tableNorm, $slugSingular) || str_starts_with($tableNorm, $slugNorm)) {
                $score = 70; // employees -> employee_details
                // The profile-table convention: <stem>_details is
                // the family's directory owner (employee_details,
                // client_details), outranking sibling extensions.
                if ($tableNorm === $slugSingular.'details' || $tableNorm === $slugNorm.'details') {
                    $score = 75;
                }
            } elseif (count($segments) > 1 && $tableNorm === $norm(Str::plural($segments[0]))) {
                $score = 60; // lead-contact -> leads
            } elseif (str_contains($tableNorm, $slugSingular) || str_contains($tableNorm, $slugNorm)) {
                $score = 40; // shifts -> employee_shifts, timelogs -> project_time_logs
            }
            if ($score !== null) {
                $slice = $info['slice'];
                if (($scores[$slice] ?? 0) <= $score) {
                    $scores[$slice] = $score;
                    $tables[$slice] = $table;
                }
            }
        }
        if ($scores === []) {
            return [null, 0];
        }
        $best = max($scores);
        $winners = array_keys(array_filter($scores, static fn ($s) => $s === $best));
        if (count($winners) > 1) {
            // Deterministic tie-break: the shortest root table is
            // the family's canonical owner (employee_shifts over
            // employee_shift_schedules).
            usort($winners, static fn ($a, $b) => strlen($tables[$a]) <=> strlen($tables[$b]));
            $shortest = strlen($tables[$winners[0]]);
            $winners = array_values(array_filter($winners, static fn ($w) => strlen($tables[$w]) === $shortest));
        }

        return count($winners) === 1 ? [$winners[0], $best] : [null, 0];
    }

    /**
     * Tables (among the given set) holding a foreign key to another
     * table in the set.
     *
     * @param  list<string>  $tables
     * @return array<string, true>
     */
    protected function childTables(array $tables): array
    {
        $children = [];
        try {
            $rows = DB::select(
                'SELECT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
            );
        } catch (\Throwable) {
            return $children;
        }
        $set = array_flip($tables);
        foreach ($rows as $row) {
            if (isset($set[$row->child]) && isset($set[$row->parent]) && $row->child !== $row->parent) {
                $children[$row->child] = true;
            }
        }
        // SQLite fallback path is unnecessary for ported apps on
        // MySQL/MariaDB; Schema-based FK listing covers the rest.
        if ($children === [] && Schema::getConnection()->getDriverName() === 'sqlite') {
            foreach ($tables as $table) {
                foreach (Schema::getForeignKeys($table) as $fk) {
                    $parent = $fk['foreign_table'] ?? null;
                    if ($parent && isset($set[$parent]) && $parent !== $table) {
                        $children[$table] = true;
                    }
                }
            }
        }

        return $children;
    }

    /**
     * @param  array{label: string, group: string, order: int}|null  $nav
     */
    protected function writeNavigation(string $slicePath, ?array $nav, ?bool $visible): void
    {
        $manifestPath = $slicePath.DIRECTORY_SEPARATOR.'slice.yaml';
        if (! is_file($manifestPath)) {
            return;
        }
        $manifest = ManifestRepository::read($manifestPath);
        $existing = (array) ($manifest['navigation'] ?? []);
        if ($nav !== null) {
            $existing['label'] = $nav['label'];
            $existing['group'] = $nav['group'];
            $existing['order'] = $nav['order'];
        }
        if ($visible === false) {
            $existing['visible'] = false;
        }
        $manifest['navigation'] = $existing;
        ManifestRepository::writeBoth($manifestPath, $manifest);
    }
}
