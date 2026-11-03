<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Support\SystemOperate;

/**
 * Operate mode, on the CLI. The Studio's Operate surface
 * (health, backups, run-state) is backed by SystemOperate;
 * this command gives the same facts to a terminal, a cron
 * job, or an AI agent — nothing about running the system
 * should require opening the UI. Read-only except `backup`,
 * which writes one backup file where the Mind declares.
 *
 * Exits non-zero when any health check fails (CI-friendly).
 */
final class SliceOperateCommand extends Command
{
    protected $signature = 'slice:operate {action=health : health | backups | backup | manual | git} {--output= : For manual: write the markdown to this file instead of printing it}';

    protected $description = 'Operate the running system from the CLI: health checks, backup list, run a backup now, or generate the operator manual (the Studio Operate mode, without the UI).';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'health' => $this->health(),
            'backups' => $this->backups(),
            'backup' => $this->backup(),
            'manual' => $this->manual(),
            'git' => $this->git(),
            default => $this->unknown($action),
        };
    }

    private function git(): int
    {
        // The Studio Operate card's facts, for the terminal
        // and for AI agents: read-only, declarations only.
        $git = SystemOperate::git();
        if (! ($git['available'] ?? false)) {
            $this->warn($git['reason'] ?? 'Git state is not available.');

            return self::SUCCESS;
        }
        $this->info('Branch: '.($git['branch'] !== '' ? $git['branch'] : '(unknown)'));
        if (($git['dirty_count'] ?? 0) === 0) {
            $this->info('Declarations: all committed — Git matches the files on disk.');
        } else {
            $this->warn("Declarations: {$git['dirty_count']} file(s) with uncommitted changes:");
            foreach ($git['dirty'] as $d) {
                $this->line('  '.($d['status'] === '??' ? 'untracked' : $d['status']).' '.$d['path']);
            }
        }
        if (! empty($git['last_commit'])) {
            $this->line("Last declaration commit: {$git['last_commit']['hash']} {$git['last_commit']['subject']} ({$git['last_commit']['when']})");
        }

        return self::SUCCESS;
    }

    private function manual(): int
    {
        // The Studio's "Generate manual" button, for the
        // terminal and for AI agents: same SystemOperate
        // composition, markdown out.
        $markdown = SystemOperate::manualMarkdown();
        $output = (string) ($this->option('output') ?? '');
        if ($output !== '') {
            file_put_contents($output, $markdown);
            $this->info('Operator manual written to '.$output.' ('.strlen($markdown).' bytes).');
        } else {
            $this->line($markdown);
        }

        return self::SUCCESS;
    }

    private function health(): int
    {
        $rows = [];
        $failed = 0;
        foreach (SystemOperate::health() as $check) {
            $rows[] = [$check['name'], $check['ok'] ? 'OK' : 'FAIL', $check['detail']];
            if (! $check['ok']) {
                $failed++;
            }
        }
        $this->table(['Check', 'Status', 'Detail'], $rows);
        $backups = SystemOperate::backups();
        $this->line('Backups: '.count($backups).' on record'.($backups !== [] ? ' (latest '.$backups[0]['at'].')' : ' — run `slice:operate backup` to create one'));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function backups(): int
    {
        $backups = SystemOperate::backups();
        if ($backups === []) {
            $this->info('No backups yet. Run `slice:operate backup` to create one.');

            return self::SUCCESS;
        }
        $this->table(['Backup', 'Size', 'At'], array_map(fn ($b) => [$b['name'], $b['size'], $b['at']], $backups));

        return self::SUCCESS;
    }

    private function backup(): int
    {
        $name = SystemOperate::runBackup();
        $this->info("Backup created: {$name}");

        return self::SUCCESS;
    }

    private function unknown(string $action): int
    {
        $this->error("Unknown action '{$action}'. Use: health | backups | backup | manual");

        return self::INVALID;
    }
}
