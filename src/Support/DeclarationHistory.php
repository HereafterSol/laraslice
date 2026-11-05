<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * Declaration history: a git-lite ledger over the files
 * that DEFINE the system — the Mind (porting/system.yaml),
 * the Bodies (porting/ui-spec/*.ui.yaml) and the Hearts
 * (app/Slices/**\/slice.yaml).
 *
 * Runtime data has the audit trail; declarations get this.
 * Every record pass hashes the tracked files and, when
 * anything changed, appends one entry to
 * porting/history/index.json and stores the new contents
 * content-addressed under porting/history/blobs/<sha1>
 * (deduped, so the store stays small). Entries are
 * append-only: who changed which declaration, when, and
 * what the change was (diffable against the parent state)
 * is answerable offline, on any runtime, forever — the
 * store is plain files + JSON on purpose.
 *
 * Recording is hooked into slice:cache and slice:sync, so
 * the porter, the Studio and CI all leave the same trail
 * without anyone remembering to.
 */
class DeclarationHistory
{
    /** Line cap per side for the LCS middle segment of a diff. */
    private const DIFF_MIDDLE_CAP = 400;

    public function __construct(private readonly ?string $appRoot = null) {}

    private function root(): string
    {
        return $this->appRoot ?? base_path();
    }

    private function storeDir(): string
    {
        return $this->root().'/porting/history';
    }

    private function indexPath(): string
    {
        return $this->storeDir().'/index.json';
    }

    private function blobPath(string $sha): string
    {
        return $this->storeDir().'/blobs/'.$sha;
    }

    /**
     * The declaration files, as relative path => absolute
     * path, sorted by relative path.
     *
     * @return array<string, string>
     */
    public function trackedFiles(): array
    {
        $root = $this->root();
        $files = [];

        foreach (['porting/system.yaml', 'system.yaml'] as $mind) {
            if (is_file($root.'/'.$mind)) {
                $files[$mind] = $root.'/'.$mind;
                break;
            }
        }

        $uiSpec = $root.'/porting/ui-spec';
        if (is_dir($uiSpec)) {
            foreach (glob($uiSpec.'/*.ui.yaml') ?: [] as $body) {
                $files['porting/ui-spec/'.basename($body)] = $body;
            }
        }

        // Team library blueprints are declarations too: a
        // shared design changing hands belongs in the same
        // ledger as the Mind, Bodies and Hearts it feeds.
        $library = $root.'/porting/blueprints';
        if (is_dir($library)) {
            foreach (glob($library.'/*.blueprint.yaml') ?: [] as $blueprint) {
                $files['porting/blueprints/'.basename($blueprint)] = $blueprint;
            }
        }

        $slices = $root.'/app/Slices';
        if (is_dir($slices)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($slices, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getFilename() === 'slice.yaml') {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $files[$rel] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Record one pass: append an entry iff any tracked
     * file was added, modified or removed since the last
     * entry. Returns the entry, or null when nothing
     * changed (or nothing is tracked).
     *
     * @return array{id: string, recorded_at: string, actor: string, by: ?string, changes: list<array{path: string, kind: string, from: ?string, to: ?string}>}|null
     */
    /**
     * The person behind the current action, when knowable:
     * the signed-in user's name and email in a web request,
     * the OS account on the CLI, null when neither exists.
     * The ledger records the TOOL in `actor` and the PERSON
     * in `by` — a team needs both answers.
     */
    public static function currentPerson(): ?string
    {
        try {
            $user = auth()->user();
            if ($user !== null) {
                $label = trim(trim((string) ($user->name ?? '')).' <'.trim((string) ($user->email ?? '')).'>', ' <>');
                if ($label !== '') {
                    return $label;
                }
            }
        } catch (\Throwable) {
            // No auth context (CLI, tests) — fall through.
        }
        $os = getenv('USER') ?: getenv('USERNAME');

        return $os !== false && $os !== '' ? (string) $os : null;
    }

    public function record(string $actor, ?string $by = null): ?array
    {
        $tracked = $this->trackedFiles();
        if ($tracked === []) {
            return null;
        }

        $index = $this->readIndex();
        $previous = $index['state'];

        $current = [];
        foreach ($tracked as $rel => $abs) {
            $sha = sha1_file($abs);
            if ($sha !== false) {
                $current[$rel] = $sha;
            }
        }

        $changes = [];
        foreach ($current as $rel => $sha) {
            if (! isset($previous[$rel])) {
                $changes[] = ['path' => $rel, 'kind' => 'added', 'from' => null, 'to' => $sha];
            } elseif ($previous[$rel] !== $sha) {
                $changes[] = ['path' => $rel, 'kind' => 'modified', 'from' => $previous[$rel], 'to' => $sha];
            }
        }
        foreach ($previous as $rel => $sha) {
            if (! isset($current[$rel])) {
                $changes[] = ['path' => $rel, 'kind' => 'removed', 'from' => $sha, 'to' => null];
            }
        }
        if ($changes === []) {
            return null;
        }

        // Store new contents before the index points at them.
        foreach ($changes as $change) {
            if ($change['to'] !== null && ! is_file($this->blobPath($change['to']))) {
                $this->ensureStore();
                copy($tracked[$change['path']], $this->blobPath($change['to']));
            }
        }

        $id = date('Ymd-His').'-'.str_pad((string) (count($index['entries']) + 1), 3, '0', STR_PAD_LEFT);
        $entry = [
            'id' => $id,
            'recorded_at' => date('c'),
            'actor' => $actor,
            'by' => $by,
            'changes' => $changes,
        ];
        $index['entries'][] = $entry;
        $index['state'] = $current;
        $this->writeIndex($index);

        return $entry;
    }

    /**
     * All entries, latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(): array
    {
        return array_reverse($this->readIndex()['entries']);
    }

    /**
     * Unified diffs for one entry, per changed file,
     * against the entry's parent state.
     *
     * @return array<string, string> relative path => diff text
     */
    public function diff(string $entryId): array
    {
        $index = $this->readIndex();
        $target = null;
        foreach ($index['entries'] as $entry) {
            if ($entry['id'] === $entryId) {
                $target = $entry;
                break;
            }
        }
        if ($target === null) {
            return [];
        }

        $diffs = [];
        foreach ($target['changes'] as $change) {
            $from = $change['from'] !== null ? $this->blobLines($change['from']) : [];
            $to = $change['to'] !== null ? $this->blobLines($change['to']) : [];
            if ($from === null || $to === null) {
                $diffs[$change['path']] = '(blob missing from the history store — the change is recorded, its contents are not)';

                continue;
            }
            $diffs[$change['path']] = self::unifiedDiff($from, $to, $change['path']);
        }

        return $diffs;
    }

    /** @return list<string>|null */
    private function blobLines(string $sha): ?array
    {
        $path = $this->blobPath($sha);
        if (! is_file($path)) {
            return null;
        }
        $content = (string) file_get_contents($path);

        return $content === '' ? [] : explode("\n", $content);
    }

    /**
     * A small unified diff: common prefix/suffix are
     * trimmed first (declaration edits are local), the
     * remaining middle goes through an LCS. Middles beyond
     * the cap are elided honestly — the blobs hold the
     * full contents either way.
     *
     * @param  list<string>  $from
     * @param  list<string>  $to
     */
    public static function unifiedDiff(array $from, array $to, string $label): string
    {
        $aCount = count($from);
        $bCount = count($to);
        $start = 0;
        while ($start < $aCount && $start < $bCount && $from[$start] === $to[$start]) {
            $start++;
        }
        $endA = $aCount - 1;
        $endB = $bCount - 1;
        while ($endA >= $start && $endB >= $start && $from[$endA] === $to[$endB]) {
            $endA--;
            $endB--;
        }
        $midA = array_slice($from, $start, $endA - $start + 1);
        $midB = array_slice($to, $start, $endB - $start + 1);
        if (count($midA) > self::DIFF_MIDDLE_CAP || count($midB) > self::DIFF_MIDDLE_CAP) {
            return "--- {$label}\n(diff elided: the changed region exceeds ".self::DIFF_MIDDLE_CAP.' lines; the history blobs hold the full before/after contents)';
        }

        // LCS table over the middles.
        $n = count($midA);
        $m = count($midB);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $midA[$i] === $midB[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        // Op list for the middle: [op, line] with op in ' ', '-', '+'.
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($midA[$i] === $midB[$j]) {
                $ops[] = [' ', $midA[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['-', $midA[$i]];
                $i++;
            } else {
                $ops[] = ['+', $midB[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $midA[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['+', $midB[$j]];
            $j++;
        }

        // Full op list: prefix context + middle ops + suffix context.
        $full = [];
        for ($k = 0; $k < $start; $k++) {
            $full[] = [' ', $from[$k], $k + 1, $k + 1];
        }
        $aLine = $start + 1;
        $bLine = $start + 1;
        foreach ($ops as [$op, $line]) {
            $full[] = [$op, $line, $op === '+' ? null : $aLine, $op === '-' ? null : $bLine];
            if ($op !== '+') {
                $aLine++;
            }
            if ($op !== '-') {
                $bLine++;
            }
        }
        for ($k = $endA + 1; $k < $aCount; $k++) {
            $full[] = [' ', $from[$k], $k + 1, $k + 1 + ($bCount - $aCount)];
        }

        // Emit hunks with 3 context lines around changes.
        $changedIdx = array_keys(array_filter($full, static fn ($row) => $row[0] !== ' '));
        if ($changedIdx === []) {
            return "--- {$label}\n(no line differences)";
        }
        $out = ["--- {$label} (before)", "+++ {$label} (after)"];
        $context = 3;
        $groups = [];
        $group = [$changedIdx[0]];
        foreach (array_slice($changedIdx, 1) as $idx) {
            if ($idx - end($group) <= 2 * $context + 1) {
                $group[] = $idx;
            } else {
                $groups[] = $group;
                $group = [$idx];
            }
        }
        $groups[] = $group;
        foreach ($groups as $g) {
            $fromIdx = max(0, $g[0] - $context);
            $toIdx = min(count($full) - 1, end($g) + $context);
            $aStart = $full[$fromIdx][2] ?? $full[$fromIdx][3] ?? 1;
            $bStart = $full[$fromIdx][3] ?? $full[$fromIdx][2] ?? 1;
            $aLen = 0;
            $bLen = 0;
            for ($k = $fromIdx; $k <= $toIdx; $k++) {
                if ($full[$k][0] !== '+') {
                    $aLen++;
                }
                if ($full[$k][0] !== '-') {
                    $bLen++;
                }
            }
            $out[] = "@@ -{$aStart},{$aLen} +{$bStart},{$bLen} @@";
            for ($k = $fromIdx; $k <= $toIdx; $k++) {
                $out[] = $full[$k][0].$full[$k][1];
            }
        }

        return implode("\n", $out);
    }

    /** @return array{version: int, state: array<string, string>, entries: list<array<string, mixed>>} */
    private function readIndex(): array
    {
        $path = $this->indexPath();
        if (! is_file($path)) {
            return ['version' => 1, 'state' => [], 'entries' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['state'], $data['entries'])) {
            throw new \RuntimeException("Declaration history index is corrupt: {$path} — repair or remove it; refusing to silently re-baseline.");
        }

        return ['version' => 1, 'state' => $data['state'], 'entries' => $data['entries']];
    }

    /** @param array<string, mixed> $index */
    private function writeIndex(array $index): void
    {
        $this->ensureStore();
        $tmp = $this->indexPath().'.tmp';
        file_put_contents($tmp, json_encode($index, JSON_PRETTY_PRINT));
        rename($tmp, $this->indexPath());
    }

    private function ensureStore(): void
    {
        $blobs = $this->storeDir().'/blobs';
        if (! is_dir($blobs)) {
            mkdir($blobs, 0777, true);
        }
    }
}
