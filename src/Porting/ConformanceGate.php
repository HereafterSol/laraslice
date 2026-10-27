<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

/**
 * Conformance gate (P3): the structure gate of the fidelity
 * stack, plus the deviation register semantics.
 *
 * The definition of 100%: every page gated; every difference
 * between the source anatomy and the port either FIXED or
 * APPROVED-AND-RECORDED in the register. The unforgivable
 * state is an UNRECORDED difference — invisible drift.
 *
 * compare() derives deviation candidates from the anatomy
 * spec (source evidence) vs the slice Heart (the port's
 * declarations). evaluate() judges candidates against the
 * register:
 *  - no entry            -> error (unrecorded)
 *  - entry, status open  -> open deviation (visible; counts
 *                           against the 100% claim)
 *  - entry, approved with reason + approver -> approved
 *                           (counts as conforming, recorded)
 *  - approved entry missing reason/approver -> error
 *  - register entry with no candidate -> stale (warning)
 */
class ConformanceGate
{
    /**
     * @param  array<string, mixed>  $anatomy  pages.list (+ pages.detail) from the anatomy spec
     * @param  array<string, mixed>  $ui       the Heart's `ui` array
     * @return list<array{id: string, kind: string, source: string, port: string}>
     */
    public static function compare(array $anatomy, array $ui, string $slug): array
    {
        $candidates = [];
        $list = $anatomy['list'] ?? [];

        // Placement.
        $sourcePlacement = $list['placement'] ?? null;
        if ($sourcePlacement !== null) {
            $portPlacement = $ui['pages']['list']['placement']
                ?? $ui['directory']['placement']
                ?? 'below-facets-left';
            if ($portPlacement !== $sourcePlacement) {
                $candidates[] = [
                    'id' => "{$slug}.placement",
                    'kind' => 'placement',
                    'source' => (string) $sourcePlacement,
                    'port' => (string) $portPlacement,
                ];
            }
        }

        // Facets: source facet items vs declared filter handles
        // (quick filters + sidebar/drawer filters both count —
        // a facet may live in either zone).
        $portFilters = [];
        foreach (['quick_filters', 'sidebar_filters'] as $zone) {
            foreach ($ui['directory'][$zone] ?? [] as $filter) {
                if (! is_array($filter)) {
                    $portFilters[] = self::normFacet((string) $filter);
                    continue;
                }
                // A facet's identity is what the user sees AND
                // what the code calls it: match on handle or
                // label (the source names its select 'employee';
                // the port's handle is 'id', its label 'Employee'
                // — the same facet). Date-range filters declare
                // their identity as column_param/column_label.
                foreach (['handle', 'field', 'label', 'column_param', 'column_label'] as $key) {
                    if (isset($filter[$key])) {
                        $portFilters[] = self::normFacet((string) $filter[$key]);
                    }
                }
            }
        }
        $missingFacets = [];
        foreach ($list['facets'] ?? [] as $facet) {
            $item = self::normFacet((string) ($facet['item'] ?? ''));
            if ($item !== '' && ! in_array($item, $portFilters, true)) {
                $missingFacets[] = (string) ($facet['item'] ?? '');
            }
        }
        if ($missingFacets !== []) {
            $candidates[] = [
                'id' => "{$slug}.facets",
                'kind' => 'facet',
                'source' => 'facets: '.implode(', ', $missingFacets),
                'port' => 'not declared as filters',
            ];
        }

        // Columns: source labels vs declared column labels
        // ('Action' is row-actions furniture, not a column).
        $portColumns = [];
        foreach ($ui['directory']['columns'] ?? [] as $column) {
            if (is_array($column) && isset($column['label'])) {
                $portColumns[] = strtolower(trim((string) $column['label']));
            }
        }
        $missingColumns = [];
        foreach ($list['columns'] ?? [] as $column) {
            $label = (string) ($column['item'] ?? '');
            if ($label === '' || strtolower($label) === 'action') {
                continue;
            }
            if (! in_array(strtolower(trim($label)), $portColumns, true)) {
                $missingColumns[] = $label;
            }
        }
        // Matrix pages: when the source's columns are mostly
        // day-of-month labels ("1 Thu", "2 Fri", ...) the list
        // IS a date matrix, and a declared directory.matrix
        // (row key + date field) is the corresponding spec —
        // the day cells are the matrix's output, not directory
        // columns.
        if ($missingColumns !== [] && ! empty($ui['directory']['matrix'])) {
            $dayLike = array_filter(
                $list['columns'] ?? [],
                fn ($c) => preg_match('/^\d{1,2}\s+\w{3}$/', (string) ($c['item'] ?? '')) === 1
            );
            $total = max(1, count($list['columns'] ?? []));
            if (count($dayLike) / $total >= 0.6) {
                $missingColumns = [];
            }
        }
        if ($missingColumns !== []) {
            $candidates[] = [
                'id' => "{$slug}.columns",
                'kind' => 'column',
                'source' => 'columns: '.implode(', ', $missingColumns),
                'port' => 'not declared as columns',
            ];
        }

        // Detail tabs (when both sides declare/observe them).
        $sourceTabs = $anatomy['detail']['tabs_observed'] ?? [];
        $portTabs = [];
        foreach ($ui['show']['tabs'] ?? [] as $tab) {
            if (is_array($tab) && isset($tab['label'])) {
                $portTabs[] = (string) $tab['label'];
            }
        }
        if ($sourceTabs !== [] && $portTabs !== []) {
            $missingTabs = array_values(array_filter(
                $sourceTabs,
                fn ($t) => ! in_array($t, $portTabs, true)
            ));
            if ($missingTabs !== []) {
                $candidates[] = [
                    'id' => "{$slug}.tabs-missing",
                    'kind' => 'tab',
                    'source' => 'tabs: '.implode(', ', $missingTabs),
                    'port' => 'not declared as detail tabs',
                ];
            }
            $extraTabs = array_values(array_filter(
                $portTabs,
                fn ($t) => ! in_array($t, $sourceTabs, true)
            ));
            if ($extraTabs !== []) {
                $candidates[] = [
                    'id' => "{$slug}.tabs-extra",
                    'kind' => 'tab',
                    'source' => 'tabs not on the source page',
                    'port' => 'tabs: '.implode(', ', $extraTabs),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Visual assertions (Tier-5 P4, SKILL §19B): the token
     * keys a port must match to claim the source's
     * appearance. Colours, font family, sidebar scheme and
     * width are EXACT; geometry keys carry a ±2px tolerance.
     * Keys not listed here are reported by visualDiffs() for
     * diagnosis but never gated — the assertion set is the
     * contract, not everything measurable.
     */
    private const VISUAL_EXACT = ['colors.primary', 'colors.sidebar', 'typography.sans', 'shell.sidebar_scheme', 'shell.sidebar_width'];

    private const VISUAL_TOLERANCE = ['components.button.height', 'components.search.height', 'typography.base_size', 'typography.table_header_size', 'typography.cell_size'];

    /**
     * Per-key differences over the asserted set.
     *
     * @param  array<string, mixed>  $sourceTokens  normalized design tokens (extractor shape)
     * @param  array<string, mixed>  $portTokens
     * @return list<array{key: string, source: string, port: string}>
     */
    public static function visualDiffs(array $sourceTokens, array $portTokens): array
    {
        $diffs = [];
        foreach (self::VISUAL_EXACT as $key) {
            $source = self::tokenAt($sourceTokens, $key);
            $port = self::tokenAt($portTokens, $key);
            if ($source === null) {
                continue; // nothing asserted without source evidence
            }
            if ($port === null) {
                // Missing port evidence must fail loudly — a
                // broken capture once passed every page by
                // skipping (P4 build, 24 Oct).
                $diffs[] = ['key' => $key, 'source' => (string) $source, 'port' => 'not captured'];
                continue;
            }
            if ((string) $source !== (string) $port) {
                $diffs[] = ['key' => $key, 'source' => (string) $source, 'port' => (string) $port];
            }
        }
        foreach (self::VISUAL_TOLERANCE as $key) {
            $source = self::tokenAt($sourceTokens, $key);
            $port = self::tokenAt($portTokens, $key);
            if ($source === null) {
                continue;
            }
            if ($port === null) {
                $diffs[] = ['key' => $key, 'source' => (string) $source, 'port' => 'not captured'];
                continue;
            }
            $s = is_numeric($source) ? (float) $source : (float) preg_replace('/[^0-9.]/', '', (string) $source);
            $p = is_numeric($port) ? (float) $port : (float) preg_replace('/[^0-9.]/', '', (string) $port);
            if (abs($s - $p) > 2) {
                $diffs[] = ['key' => $key, 'source' => (string) $source, 'port' => (string) $port];
            }
        }

        return $diffs;
    }

    /**
     * One aggregated visual candidate per page: the register
     * judges a page's appearance as a unit (the replica kit
     * closes it as a unit), while visualDiffs() keeps the
     * per-key detail for the report.
     *
     * @param  array<string, mixed>  $sourceTokens
     * @param  array<string, mixed>  $portTokens
     * @return list<array{id: string, kind: string, source: string, port: string}>
     */
    public static function visualCandidates(array $sourceTokens, array $portTokens, string $slug): array
    {
        $diffs = self::visualDiffs($sourceTokens, $portTokens);
        if ($diffs === []) {
            return [];
        }
        $fmt = static fn (array $d, string $side) => $d['key'].'='.$d[$side];

        return [[
            'id' => "{$slug}.visual",
            'kind' => 'visual',
            'source' => implode('; ', array_map(static fn ($d) => $fmt($d, 'source'), $diffs)),
            'port' => implode('; ', array_map(static fn ($d) => $fmt($d, 'port'), $diffs)),
        ]];
    }

    /** @param array<string, mixed> $tokens */
    private static function tokenAt(array $tokens, string $dotted): mixed
    {
        $cursor = $tokens;
        foreach (explode('.', $dotted) as $part) {
            if (! is_array($cursor) || ! array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }

        return is_scalar($cursor) ? $cursor : null;
    }

    /**
     * @param  list<array{id: string, kind: string, source: string, port: string}>  $candidates
     * @param  list<array<string, mixed>>  $register
     * @return array{errors: list<string>, open: list<string>, approved: list<string>, stale: list<string>}
     */
    public static function evaluate(array $candidates, array $register): array
    {
        $byId = [];
        foreach ($register as $entry) {
            if (isset($entry['id'])) {
                $byId[(string) $entry['id']] = $entry;
            }
        }

        $errors = [];
        $open = [];
        $approved = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $seen[$candidate['id']] = true;
            $entry = $byId[$candidate['id']] ?? null;
            if ($entry === null) {
                $errors[] = "{$candidate['id']}: UNRECORDED deviation — source has '{$candidate['source']}', port has '{$candidate['port']}'. Fix it or record it in porting/deviations.yaml.";
                continue;
            }
            $status = (string) ($entry['status'] ?? 'open');
            if ($status === 'approved') {
                $reason = trim((string) ($entry['reason'] ?? ''));
                $by = trim((string) ($entry['approved_by'] ?? ''));
                if ($reason === '' || $by === '') {
                    $errors[] = "{$candidate['id']}: register entry is 'approved' but lacks a reason or approver — an approval must say why and who.";
                } else {
                    $approved[] = "{$candidate['id']}: approved deviation ({$candidate['kind']}) — {$reason}";
                }
            } else {
                $open[] = "{$candidate['id']}: open deviation — source '{$candidate['source']}' vs port '{$candidate['port']}' (recorded; fix or approve it).";
            }
        }
        $stale = [];
        foreach ($byId as $id => $entry) {
            if (! isset($seen[$id])) {
                $stale[] = "{$id}: register entry no longer matches any deviation — the port was fixed or the anatomy changed; remove or update the entry.";
            }
        }

        return ['errors' => $errors, 'open' => $open, 'approved' => $approved, 'stale' => $stale];
    }

    private static function normFacet(string $handle): string
    {
        // Identity ignores separators: the source's select name
        // 'award_type', the port's label 'Award Type' and its
        // handle 'award_id'-style variants are compared on
        // their alphanumeric core.
        return preg_replace('/[^a-z0-9]/', '', strtolower(str_replace('[]', '', trim($handle)))) ?? '';
    }
}
