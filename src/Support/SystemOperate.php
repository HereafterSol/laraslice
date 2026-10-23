<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;

/**
 * Operate mode: the running system's care surface — health,
 * backups (as declared in the Mind's operations), audit, and
 * access review (R5), plus on-demand manual generation (R4).
 * Everything reads the Mind's declarations and reports reality
 * against them; nothing here changes runtime behavior.
 */
class SystemOperate
{
    /** @return list<array{name: string, ok: bool, detail: string}> */
    public static function health(): array
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks[] = ['name' => 'Database', 'ok' => true, 'detail' => 'Connected ('.DB::connection()->getDriverName().')'];
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'Database', 'ok' => false, 'detail' => 'Unreachable'];
        }

        $manager = app(SliceManager::class);
        $manager->discover();
        $count = count($manager->discoveredSlices());
        $checks[] = ['name' => 'Slices', 'ok' => $count > 0, 'detail' => $count.' discovered'];

        $writable = is_writable(storage_path('app'));
        $checks[] = ['name' => 'Storage', 'ok' => $writable, 'detail' => $writable ? 'storage/app writable' : 'storage/app NOT writable'];

        $mind = SystemMind::load();
        $checks[] = ['name' => 'Mind', 'ok' => $mind !== null, 'detail' => $mind !== null ? 'porting/system.yaml present' : 'No Mind — draft one with slice:plan:draft'];

        $audit = Schema::hasTable('laraslice_audit_logs');
        $checks[] = ['name' => 'Audit trail', 'ok' => $audit, 'detail' => $audit ? DB::table('laraslice_audit_logs')->count().' entries' : 'Audit table missing'];

        $checks[] = ['name' => 'Runtime', 'ok' => true, 'detail' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version()];

        return $checks;
    }

    public static function backupDir(): string
    {
        return storage_path('app/laraslice/backups');
    }

    /** @return list<array{name: string, size: string, at: string}> */
    public static function backups(): array
    {
        $dir = self::backupDir();
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                $out[] = [
                    'name' => basename($file),
                    'size' => number_format(filesize($file) / 1024, 1).' KB',
                    'at' => date('Y-m-d H:i', filemtime($file)),
                ];
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['at'], $a['at']));

        return $out;
    }

    /**
     * Run a backup now, honoring the Mind's declared includes
     * (db / uploads). Returns the created file's name.
     */
    public static function runBackup(?string $dir = null): string
    {
        $dir ??= self::backupDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $mind = SystemMind::load();
        $includes = $mind['operations']['backups']['includes'] ?? ['db'];
        $stamp = date('Ymd-His');
        $created = [];

        if (in_array('db', (array) $includes, true)) {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                $source = DB::connection()->getDatabaseName();
                $target = $dir."/backup-{$stamp}.sqlite";
                copy($source, $target);
                $created[] = basename($target);
            } else {
                $target = $dir."/backup-{$stamp}.json";
                file_put_contents($target, json_encode(['note' => 'Logical backup placeholder for driver '.$driver.' — configure a dump tool in operations.', 'at' => $stamp]));
                $created[] = basename($target);
            }
        }
        if (in_array('uploads', (array) $includes, true) && is_dir(storage_path('app/public'))) {
            $target = $dir."/uploads-{$stamp}.txt";
            $files = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(storage_path('app/public'), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files[] = $f->getPathname().' ('.$f->getSize().' bytes)';
                }
            }
            file_put_contents($target, "Uploads manifest (contents stay in place; this lists what a full backup must copy):\n".implode("\n", $files));
            $created[] = basename($target);
        }

        return implode(', ', $created);
    }

    /** @return list<array<string, mixed>> */
    public static function recentAudit(int $limit = 12): array
    {
        if (! Schema::hasTable('laraslice_audit_logs')) {
            return [];
        }

        return DB::table('laraslice_audit_logs')->latest('id')->limit($limit)
            ->get(['id', 'slice', 'action', 'actor_email', 'entity_type', 'entity_id', 'created_at'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /**
     * R5 access review: every user's roles, flagging the grants
     * that deserve a second look (system-wide roles, and users
     * holding more than one role).
     *
     * @return list<array{user: string, email: string, roles: list<string>, flags: list<string>}>
     */
    public static function accessReview(): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('role_user') || ! Schema::hasTable('roles')) {
            return [];
        }
        $rows = DB::table('users')
            ->leftJoin('role_user', 'role_user.user_id', '=', 'users.id')
            ->leftJoin('roles', 'roles.id', '=', 'role_user.role_id')
            ->select('users.name as user', 'users.email', 'roles.name as role')
            ->orderBy('users.name')->get();

        $byUser = [];
        foreach ($rows as $row) {
            $key = $row->email ?? $row->user;
            $byUser[$key] ??= ['user' => (string) $row->user, 'email' => (string) $row->email, 'roles' => [], 'flags' => []];
            if ($row->role) {
                $byUser[$key]['roles'][] = (string) $row->role;
            }
        }
        foreach ($byUser as &$entry) {
            foreach ($entry['roles'] as $role) {
                if (preg_match('/super|developer|admin/i', $role)) {
                    $entry['flags'][] = 'Holds a system-wide role: '.$role;
                }
            }
            if (count($entry['roles']) > 1) {
                $entry['flags'][] = 'Multiple roles ('.count($entry['roles']).')';
            }
            if ($entry['roles'] === []) {
                $entry['flags'][] = 'No role assigned';
            }
        }

        return array_values($byUser);
    }

    /** R4: an operator/user manual assembled from the Mind + slices. */
    public static function manualMarkdown(?array $mind = null): string
    {
        $mind ??= SystemMind::load() ?? [];
        $name = (string) ($mind['system']['name'] ?? config('app.name', 'System'));
        $out = ["# {$name} — Operator Manual", '', (string) ($mind['system']['purpose'] ?? ''), ''];

        $out[] = '## Who it serves';
        foreach ($mind['actors'] ?? [] as $actor) {
            $out[] = '- **'.($actor['name'] ?? '').'** ('.($actor['kind'] ?? '').') — '.($actor['description'] ?? '');
        }
        $out[] = '';
        $out[] = '## Roles';
        foreach ($mind['roles']['clubs'] ?? [] as $club) {
            $out[] = '- **'.($club['name'] ?? '').'**: '.implode(', ', (array) ($club['permissions'] ?? []));
        }
        if (! empty($mind['roles']['overrides'])) {
            $out[] = '';
            $out[] = (string) $mind['roles']['overrides'];
        }
        $out[] = '';
        $out[] = '## Journeys';
        foreach ($mind['journeys'] ?? [] as $journey) {
            $out[] = '### '.($journey['name'] ?? '');
            $out[] = 'Actor: '.($journey['actor'] ?? '').' · Steps: '.implode(' → ', (array) ($journey['steps'] ?? [])).' · Outcome: '.($journey['outcome'] ?? '');
            $out[] = '';
        }
        $out[] = '## Modules';
        try {
            $manager = app(SliceManager::class);
            $manager->discover();
            foreach ($manager->discoveredSlices() as $slice) {
                $data = $slice->toArray();
                $out[] = '- '.($data['title'] ?? $data['name'] ?? 'Slice');
            }
        } catch (\Throwable) {
            $out[] = '- (slice inventory unavailable outside a booted application)';
        }
        $out[] = '';
        $out[] = '## Operations';
        foreach ((array) ($mind['operations'] ?? []) as $key => $value) {
            $out[] = '- **'.$key.'**: '.(is_array($value) ? (string) json_encode($value) : (string) $value);
        }
        $out[] = '';
        $out[] = '_Generated from the system Mind by Operate mode. The Mind is intent; slices are the runtime truth._';

        return implode("\n", $out)."\n";
    }
}
