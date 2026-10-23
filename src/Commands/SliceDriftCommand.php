<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\SystemDrift;
use LaraSlice\Support\SystemMind;
use Symfony\Component\Yaml\Yaml;

class SliceDriftCommand extends Command
{
    protected $signature = 'slice:drift {--fix : Reconcile: grant the mapped roles what their clubs promise (creating view-own permission rows when missing)}';

    protected $description = 'Mind ↔ Heart drift audit: compare the Mind\'s role clubs and the Bodies\' access declarations against the roles\' actual permission grants. Exits 1 on error-severity drift.';

    public function handle(): int
    {
        $path = SystemMind::defaultPath();
        if (! is_file($path)) {
            $this->warn('No Mind found at porting/system.yaml — nothing to audit against. Draft one with slice:plan:draft.');
            return self::SUCCESS;
        }
        $mind = SystemMind::load($path) ?? [];

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

        if ($this->option('fix')) {
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

        $findings = SystemDrift::audit($mind, $roleGrants, $registry, $bodies);
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
     * Execute the reconciliation plan: create framework-construct
     * permissions the registry lacks (view-own forms) and grant
     * each mapped role the slugs its club promises.
     *
     * @param array<string, mixed> $mind
     * @param array<string, list<string>> $roleGrants
     * @param list<string> $registry
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
        foreach ($plan as $action) {
            if ($action['action'] === 'unresolvable') {
                $this->warn("Unresolvable: club {$action['club']} pattern '{$action['pattern']}' names nothing in the registry — fix the Mind's vocabulary.");
                continue;
            }
            if ($action['action'] === 'unmapped-club' || $action['role'] === null) {
                $this->warn("Unmapped club {$action['club']}: declare maps_to in the Mind to reconcile '{$action['pattern']}'.");
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
            $roleId = $roleIds[$action['role']] ?? null;
            if ($roleId !== null) {
                DB::table('permission_role')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
                $granted++;
            }
        }
        $this->info("Reconciled: {$granted} grant(s) applied, {$created} permission(s) created.");
    }
}
