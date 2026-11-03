<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * Read-only Git state for the DECLARATIONS (porting/ +
 * app/Slices) — never the whole working tree, and never a
 * write. Teams share a system through its declaration
 * files; this answers the three questions that matter
 * before syncing or handing over: is this project under
 * Git at all, which branch, and which declaration files
 * carry uncommitted changes.
 *
 * Degrades honestly: no Git binary, or not a repository,
 * returns available=false with the reason in plain words —
 * Declaration History still tracks changes offline either
 * way, so nothing is blocked, just less visible.
 */
class GitStatus
{
    /**
     * @return array{available: bool, reason?: string, branch?: string, dirty?: list<array{status: string, path: string}>, dirty_count?: int, last_commit?: ?array{hash: string, subject: string, when: string}}
     */
    public static function forDeclarations(?string $root = null): array
    {
        $root ??= base_path();

        if (self::git($root, ['--version']) === null) {
            return ['available' => false, 'reason' => 'Git is not available on this server. Declaration History (below) still tracks every change offline.'];
        }
        if (trim((string) self::git($root, ['rev-parse', '--is-inside-work-tree'])) !== 'true') {
            return ['available' => false, 'reason' => 'This application is not a Git repository. The declarations are plain files — put the project under Git and this card will show their uncommitted state; until then, Declaration History is the record.'];
        }

        $branch = trim((string) self::git($root, ['branch', '--show-current']));
        if ($branch === '') {
            $head = trim((string) self::git($root, ['rev-parse', '--short', 'HEAD']));
            $branch = $head !== '' ? 'detached @ '.$head : '';
        }

        $dirty = [];
        $porcelain = self::git($root, ['status', '--porcelain', '--untracked-files=all', '--', 'porting', 'app/Slices']);
        if ($porcelain !== null && $porcelain !== '') {
            foreach (explode("\n", $porcelain) as $line) {
                if (strlen($line) < 4) {
                    continue;
                }
                $dirty[] = ['status' => trim(substr($line, 0, 2)), 'path' => trim(substr($line, 3))];
            }
        }

        $last = null;
        $log = trim((string) self::git($root, ['log', '-1', '--format=%h|%s|%cr', '--', 'porting', 'app/Slices']));
        if ($log !== null && $log !== '') {
            $parts = explode('|', $log, 3);
            if (count($parts) === 3) {
                $last = ['hash' => $parts[0], 'subject' => $parts[1], 'when' => $parts[2]];
            }
        }

        return [
            'available' => true,
            'branch' => $branch,
            'dirty' => $dirty,
            'dirty_count' => count($dirty),
            'last_commit' => $last,
        ];
    }

    /**
     * Run git with an argv array (no shell, so paths and
     * arguments are never reinterpreted). Null on any
     * failure: missing binary, non-zero exit, no proc_open.
     *
     * @param  list<string>  $args
     */
    private static function git(string $root, array $args): ?string
    {
        if (! function_exists('proc_open')) {
            return null;
        }
        $process = @proc_open(
            array_merge(['git', '-C', $root], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (! is_resource($process)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        // Only the trailing newline goes: porcelain output is
        // column-sensitive (the first line's leading status
        // space is data), so callers trim scalar reads
        // themselves and parse status lines at fixed offsets.
        return $code === 0 ? rtrim((string) $out, "\r\n") : null;
    }
}
