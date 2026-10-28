<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\PageStructureDrift;
use LaraSlice\Support\SystemDrift;
use LaraSlice\Support\SystemMind;
use Symfony\Component\Yaml\Yaml;

class SliceDriftCommand extends Command
{
    protected $signature = 'slice:drift {--fix : Reconcile: grant the mapped roles what their clubs promise (creating view-own permission rows when missing)} {--structure : Also audit generated index blades against the Page Structure Contract (Body v4)}';

    protected $description = 'Mind ↔ Heart drift audit: compare the Mind\'s role clubs and the Bodies\' access declarations against the roles\' actual permission grants. Exits 1 on error-severity drift.';

    public function handle(): int
    {
        $path = SystemMind::defaultPath();
        $hasMind = is_file($path);
        if (! $hasMind && ! $this->option('structure')) {
            $this->warn('No Mind found at porting/system.yaml — nothing to audit against. Draft one with slice:plan:draft.');

            return self::SUCCESS;
        }
        if (! $hasMind) {
            $this->warn('No Mind found at porting/system.yaml — running the page-structure audit only.');
        }
        $mind = $hasMind ? (SystemMind::load($path) ?? []) : [];

        $roleGrants = [];
        $registry = [];
        if (Schema::hasTable('roles') && Schema::hasTable('permissions')) {
            $registry = DB::table('permissions')->pluck('slug')->map(fn ($s) => (string) $s)->all();
            $grants = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->select('permission_role.role_id', 'permissions.slug')
                ->get();
            $byRoleId = [];
            foreach ($grants as $row) {
                $byRoleId[(int) $row->role_id][] = (string) $row->slug;
            }
            foreach (DB::table('roles')->get() as $role) {
                $roleGrants[(string) ($role->display_name ?? $role->name)] = $byRoleId[(int) $role->id] ?? [];
            }
        }

        $bodies = [];
        $dir = base_path('porting/ui-spec');
        if (is_dir($dir)) {
            foreach (glob($dir.'/*.ui.yaml') ?: [] as $file) {
                try {
                    $parsed = Yaml::parseFile($file);
                    if (is_array($parsed) && isset($parsed['slice'])) {
                        $bodies[(string) $parsed['slice']] = $parsed;
                    }
                } catch (\Throwable) {
                    // an unparsable Body is itself drift, but the
                    // form audit reports it; skip here.
                }
            }
        }

        if ($this->option('fix') && $hasMind) {
            $this->reconcile($mind, $roleGrants, $registry);
            // Re-gather grants + registry after the changes.
            $registry = DB::table('permissions')->pluck('slug')->map(fn ($s) => (string) $s)->all();
            $grants = DB::table('permission_role')
                ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
                ->select('permission_role.role_id', 'permissions.slug')
                ->get();
            $byRoleId = [];
            foreach ($grants as $row) {
                $byRoleId[(int) $row->role_id][] = (string) $row->slug;
            }
            foreach (DB::table('roles')->get() as $role) {
                $roleGrants[(string) ($role->display_name ?? $role->name)] = $byRoleId[(int) $role->id] ?? [];
            }
        }

        $findings = $hasMind ? SystemDrift::audit($mind, $roleGrants, $registry, $bodies) : [];

        if ($this->option('structure')) {
            $findings = array_merge($findings, $this->auditPageStructure());
        }

        if ($findings === []) {
            $this->info('No drift: the Mind\'s promises, the Bodies\' access declarations, and the roles\' grants agree.');

            return self::SUCCESS;
        }

        $errors = 0;
        foreach ($findings as $f) {
            if ($f['severity'] === 'error') {
                $errors++;
                $this->error("[{$f['kind']}] {$f['message']}");
            } else {
                $this->warn("[{$f['kind']}] {$f['message']}");
            }
        }
        $this->line("Drift audit: {$errors} error(s), ".(count($findings) - $errors).' warning(s).');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Page Structure Contract (Body v4) audit: every generated
     * index blade under app/Slices against its slice's declared
     * ui config, plus the app's published data-table component.
     *
     * @return list<array{kind: string, severity: string, message: string}>
     */
    private function auditPageStructure(): array
    {
        $findings = [];
        $slicesRoot = base_path('app/Slices');
        if (is_dir($slicesRoot)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($slicesRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getFilename() !== 'index.blade.php') {
                    continue;
                }
                $bladePath = $file->getPathname();
                if (! str_contains($bladePath, '/Resources/views/')) {
                    continue;
                }
                $sliceDir = dirname($bladePath, 3);
                $ui = [];
                $yamlPath = $sliceDir.'/slice.yaml';
                if (is_file($yamlPath)) {
                    try {
                        $parsed = Yaml::parseFile($yamlPath);
                        $ui = is_array($parsed['ui'] ?? null) ? $parsed['ui'] : [];
                    } catch (\Throwable) {
                        // unparsable Heart: the blade checks still run
                    }
                }
                $label = str_replace(base_path().'/', '', $sliceDir);
                $findings = array_merge(
                    $findings,
                    PageStructureDrift::auditBlade((string) file_get_contents($bladePath), $ui, $label)
                );

                // Detail-page half of the contract: a Heart that
                // promises relation tabs needs a show blade that
                // can render them (structure.hollow-hub).
                $showPath = $sliceDir.'/Resources/views/show.blade.php';
                if (is_file($showPath) && is_file($yamlPath)) {
                    try {
                        $manifest = Yaml::parseFile($yamlPath);
                        if (is_array($manifest)) {
                            $findings = array_merge(
                                $findings,
                                PageStructureDrift::auditShowBlade((string) file_get_contents($showPath), $manifest, $label)
                            );
                        }
                    } catch (\Throwable) {
                        // unparsable Heart: the index checks already ran
                    }
                }
            }
        }

        $component = base_path('resources/views/components/ui/data-table.blade.php');
        if (is_file($component)) {
            $findings = array_merge(
                $findings,
                PageStructureDrift::auditComponent((string) file_get_contents($component))
            );
        }

        return $findings;
    }

    /**
     * Execute the reconciliation plan: create framework-construct
     * permissions the registry lacks (view-own forms) and grant
     * each mapped role the slugs its club promises.
     *
     * @param  array<string, mixed>  $mind
     * @param  array<string, list<string>>  $roleGrants
     * @param  list<string>  $registry
     */
    private function reconcile(array $mind, array $roleGrants, array $registry): void
    {
        $plan = SystemDrift::planReconciliation($mind, $roleGrants, $registry);
        $roleIds = [];
        foreach (DB::table('roles')->get() as $role) {
            $roleIds[(string) ($role->display_name ?? $role->name)] = (int) $role->id;
        }
        $granted = 0;
        $created = 0;
        $missing = [];
        foreach ($plan as $action) {
            if ($action['action'] === 'unresolvable') {
                $this->warn("Unresolvable: club {$action['club']} pattern '{$action['pattern']}' names nothing in the registry — fix the Mind's vocabulary.");

                continue;
            }
            if ($action['action'] === 'unmapped-club' || $action['role'] === null) {
                $this->warn("Unmapped club {$action['club']}: declare maps_to in the Mind to reconcile '{$action['pattern']}'.");

                continue;
            }
            $roleId = $roleIds[$action['role']] ?? null;
            if ($roleId === null) {
                // A mapped-but-missing role must never fail
                // silently: warn once per club→role pair and
                // create nothing for it.
                $key = $action['club'].' -> '.$action['role'];
                if (! isset($missing[$key])) {
                    $missing[$key] = true;
                    $this->warn("Mapped role missing: club {$action['club']} maps_to '{$action['role']}', but no role with that name exists — nothing was granted for this club. maps_to must name a role in THIS system.");
                }

                continue;
            }
            $slug = $action['slug'];
            $permissionId = DB::table('permissions')->where('slug', $slug)->value('id');
            if ($permissionId === null) {
                $permissionId = DB::table('permissions')->insertGetId([
                    'name' => str($slug)->replace(['.', '-'], ' ')->title()->toString(),
                    'slug' => $slug,
                    'group' => 'Reconciled',
                    'description' => 'Created by slice:drift --fix from the Mind club promises.',
                    'source' => 'laraslice',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $created++;
            }
            DB::table('permission_role')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
            $granted++;
        }
        $this->info("Reconciled: {$granted} grant(s) applied, {$created} permission(s) created.");
    }
}
