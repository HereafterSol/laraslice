<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * Runtime portability audit: the declarations — Mind
 * (porting/system.yaml), Bodies (porting/ui-spec/*.ui.yaml),
 * Hearts (app/Slices/**\/slice.yaml) — are the system's
 * source of truth for EVERY runtime, not just this one.
 * The Laravel runtime reads them today; a sibling runtime
 * (another framework, another language) must be able to
 * read the same files tomorrow.
 *
 * That only works while the declarations stay
 * runtime-neutral. The moment a Heart names a PHP class,
 * a Body points at a .blade.php template, or the Mind
 * invokes a framework helper, the declaration has leaked
 * THIS runtime into the shared truth — and every other
 * runtime now needs Laravel knowledge to understand the
 * system. This audit scans the declaration files line by
 * line for exactly that leakage and reports it with a
 * plain-words fix for each family.
 *
 * The file list is Declaration History's tracked set, so
 * the audit covers precisely the files the framework
 * treats as declarations — no more, no less.
 */
class PortabilityAudit
{
    /**
     * Leakage pattern families, in claim order: each entry
     * is [regex, term extractor, suggestion]. A span of text
     * claimed by an earlier family on a line is not reported
     * again by a later one (a fully-qualified class name is
     * one finding, not three for the words inside it).
     *
     * @return list<array{0: string, 1: callable(string, array<int, string>): string, 2: string}>
     */
    private static function patterns(): array
    {
        $itself = fn (string $match, array $groups): string => $match;

        return [
            // App\Models\User, Illuminate\Database\... — a PHP
            // class named where a concept belongs.
            ['/[A-Z][A-Za-z0-9_]*\\\\[A-Z][A-Za-z0-9_\\\\]*/', $itself, 'name the concept, not the PHP class'],
            // A Blade template referenced by filename.
            ['/\.blade\.php/', $itself, 'reference the page, not the template file'],
            // Any other .php file referenced as a value.
            ['/\.php(?=[\'"\s]|$)/', $itself, 'reference the page, not the template file'],
            // Framework helper calls: route(, config(, env(, ...
            ['/\b(route|config|env|auth|bcrypt|asset|url)\s*\(/', fn (string $match, array $groups): string => $groups[1] ?? $match, 'declare the value or the intent, not the framework call'],
            // The framework's CLI named as the way to do something.
            ['/\bartisan\b/', $itself, 'name the operation, not the CLI command'],
            // Framework namespaces and facades, by name.
            ['/\bIlluminate\b/i', $itself, 'keep the declaration runtime-neutral'],
            ['/\bEloquent\b/i', $itself, 'keep the declaration runtime-neutral'],
            ['/\bBlade\b/i', $itself, 'keep the declaration runtime-neutral'],
            ['/\bCarbon\b/', $itself, 'keep the declaration runtime-neutral'],
            ['/\bStr::/', $itself, 'keep the declaration runtime-neutral'],
            ['/\bDB::/', $itself, 'keep the declaration runtime-neutral'],
            ['/\bSchema::/', $itself, 'keep the declaration runtime-neutral'],
        ];
    }

    /**
     * Scan the declaration files under $root (the app root
     * when null) and report every framework-leakage finding.
     *
     * @return array{available: bool, reason?: string, files_scanned?: int, findings?: list<array{file: string, line: int, term: string, excerpt: string, suggestion: string}>, by_term?: array<string, int>, bindings?: int, clean?: bool}
     */
    public static function run(?string $root = null): array
    {
        $tracked = (new DeclarationHistory($root))->trackedFiles();
        if ($tracked === []) {
            return ['available' => false, 'reason' => 'No declarations tracked yet.'];
        }

        $findings = [];
        $bindings = 0;
        $scanned = 0;
        foreach ($tracked as $relative => $absolute) {
            $lines = @file($absolute, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }
            $scanned++;
            // A Heart's `namespace:` line is not leakage: it
            // is the slice's declared home in THIS runtime —
            // the binding between the neutral declaration
            // and this runtime's code. A sibling runtime
            // declares its own. Those lines are counted as
            // bindings, never as findings.
            $isHeart = str_ends_with($relative, 'slice.yaml');
            foreach ($lines as $index => $line) {
                if ($isHeart && preg_match('/^\s*namespace\s*:/', (string) $line)) {
                    $bindings += count(self::scanLine((string) $line));

                    continue;
                }
                foreach (self::scanLine((string) $line) as $hit) {
                    $findings[] = [
                        'file' => $relative,
                        'line' => $index + 1,
                        'term' => $hit['term'],
                        'excerpt' => mb_substr(trim((string) $line), 0, 120),
                        'suggestion' => $hit['suggestion'],
                    ];
                }
            }
        }

        $byTerm = [];
        foreach ($findings as $finding) {
            $byTerm[$finding['term']] = ($byTerm[$finding['term']] ?? 0) + 1;
        }
        arsort($byTerm);

        return [
            'available' => true,
            'files_scanned' => $scanned,
            'findings' => $findings,
            'by_term' => $byTerm,
            'bindings' => $bindings,
            'clean' => $findings === [],
        ];
    }

    /**
     * All leakage hits on one line, with spans claimed by
     * earlier pattern families excluded from later ones.
     *
     * @return list<array{term: string, suggestion: string}>
     */
    private static function scanLine(string $line): array
    {
        $hits = [];
        $claimed = [];
        foreach (self::patterns() as [$regex, $termOf, $suggestion]) {
            if (! preg_match_all($regex, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $i => [$text, $offset]) {
                $span = [$offset, $offset + strlen($text)];
                foreach ($claimed as [$from, $to]) {
                    if ($span[0] < $to && $span[1] > $from) {
                        continue 2;
                    }
                }
                $claimed[] = $span;
                $groups = [];
                foreach ($matches as $groupIndex => $group) {
                    if ($groupIndex > 0 && isset($group[$i])) {
                        $groups[$groupIndex] = $group[$i][0];
                    }
                }
                $hits[] = [
                    'term' => mb_substr(trim($termOf($text, $groups)), 0, 60),
                    'suggestion' => $suggestion,
                ];
            }
        }

        return $hits;
    }
}
