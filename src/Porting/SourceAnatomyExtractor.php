<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

use Illuminate\Support\Str;

/**
 * Source anatomy extractor (P2 of the page-structure plan):
 * turns a rendered-page probe — landmarks and structure
 * captured from the SOURCE application by the capture harness
 * (geometry + selects + headers + pagination text) — into a
 * Body UI-spec: the evidence a port declares from.
 *
 * The classification rules live here, in the framework, so
 * every port reads the source the same way:
 *  - facets = visible selects above the grid, minus the
 *    page-size menu (option sets of pure 10/25/50/100);
 *  - placement by landmark geometry against the Body v4
 *    vocabulary (title row / facets / card / grid);
 *  - density is only named when the observed row height is
 *    single-line territory — rich multi-line cells confound
 *    it, so the raw height is recorded as evidence instead.
 *
 * The extractor asserts facts about the source, never design
 * decisions: every entry is marked observed.
 */
class SourceAnatomyExtractor
{
    /**
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed> UI-spec (Body evidence)
     */
    public static function extractList(array $probe, string $slice): array
    {
        $tableTop = $probe['tableTop'] ?? null;
        $cardTop = $probe['cardTop'] ?? null;
        $titleY = $probe['titleY'] ?? null;
        $primary = $probe['primaryAction'] ?? null;

        $facets = [];
        $facetRows = [];
        $positiveY = [];
        foreach ($probe['selects'] ?? [] as $select) {
            $y = (float) ($select['y'] ?? 0);
            if ($y > 0 && ($tableTop === null || $y < $tableTop)) {
                $opts = array_values(array_filter(array_map('strval', $select['options'] ?? [])));
                if (! self::isPageSizeOptions($opts)) {
                    $positiveY[] = $y;
                }
            }
        }
        $primaryToolbarY = $positiveY !== [] ? min($positiveY) : null;

        foreach ($probe['selects'] ?? [] as $select) {
            $y = (float) ($select['y'] ?? 0);
            if ($tableTop !== null && $y >= $tableTop) {
                continue; // below the grid: not a facet
            }
            $options = array_values(array_filter(array_map('strval', $select['options'] ?? [])));
            if (self::isPageSizeOptions($options)) {
                continue; // the length menu is pagination furniture
            }
            $scope = 'toolbar';
            if ($y <= 0) {
                $scope = 'action_bar';
            } elseif ($primaryToolbarY !== null && $y > ($primaryToolbarY + 30)) {
                $scope = 'drawer';
            }
            $facets[] = [
                'item' => (string) ($select['name'] ?? $select['id'] ?? 'select'),
                'kind' => 'select',
                'scope' => $scope,
                'options_observed' => $options,
                'decision' => 'observed',
            ];
            $facetRows[] = (int) $y;
        }
        foreach ($probe['tabGroups'] ?? [] as $group) {
            $facets[] = [
                'item' => 'tabs',
                'kind' => 'tabs',
                'options_observed' => array_values($group['items'] ?? []),
                'decision' => 'observed',
            ];
        }
        $searchObserved = ($probe['searches'] ?? []) !== [];

        $headers = array_values(array_map('strval', $probe['headers'] ?? []));
        $hasSelectColumn = ($headers[0] ?? null) === '';
        $columns = [];
        foreach ($headers as $header) {
            if ($header === '') {
                continue;
            }
            $columns[] = ['item' => $header, 'decision' => 'observed'];
        }

        $pagination = ['decision' => 'observed'];
        if (preg_match('/Showing\s+(\d+)\s+to\s+(\d+)\s+of\s+(\d+)/i', (string) ($probe['paginationText'] ?? ''), $m)) {
            $pagination['style'] = 'showing-x-to-y-of-z';
            $pagination['page_size_observed'] = (int) $m[2] - (int) $m[1] + 1;
            $pagination['total_observed'] = (int) $m[3];
        }

        $rowH = $probe['rowH'] ?? null;
        $density = null;
        // A table without column headers is not a grid (the
        // source's calendar/matrix views): its row height is
        // not row density, and its anatomy is the view itself.
        $viewObserved = $columns === [] && $tableTop !== null ? 'non-grid' : 'grid';
        if (is_int($rowH) && $viewObserved === 'grid') {
            $density = $rowH <= 52 ? 'compact' : ($rowH <= 64 ? 'comfortable' : null);
        }

        $actions = [];
        foreach ($probe['actions'] ?? [] as $action) {
            $text = (string) ($action['text'] ?? '');
            if ($text !== '' && ! in_array($text, $actions, true)) {
                $actions[] = $text;
            }
        }

        return [
            'slice' => $slice,
            'pages' => [
                'list' => [
                    'path_source' => (string) ($probe['url'] ?? ''),
                    'view_observed' => $viewObserved,
                    'placement' => self::classifyPlacement($probe, $facets),
                    'placement_basis' => self::placementBasis($probe),
                    'density' => $density,
                    'row_height_observed' => $rowH,
                    'facets' => $facets,
                    'facet_rows_observed' => count(array_unique(array_map(
                        fn ($y) => (int) round($y / 24), $facetRows
                    ))),
                    'search_observed' => $searchObserved,
                    'actions_observed' => $actions,
                    'columns' => $columns,
                    'select_column_observed' => $hasSelectColumn,
                    'pagination' => $pagination,
                ],
            ],
        ];
    }

    /**
     * Detail-page anatomy: tabs, field sections, header
     * actions. The capture layer is source-flavoured (each
     * source renders details differently); this method only
     * cleans and structures what the probe observed:
     *  - a lone "field" whose label is the page title is the
     *    identity block, not a section;
     *  - sections need a title or at least two fields —
     *    single stray labels are sidebar/widget noise.
     *
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>
     */
    public static function extractDetail(array $probe, string $slice): array
    {
        $title = (string) ($probe['title'] ?? '');
        $sections = [];
        $identityObserved = null;
        foreach ($probe['sections'] ?? [] as $section) {
            $fields = [];
            foreach ($section['fields'] ?? [] as $field) {
                $label = (string) ($field['label'] ?? '');
                if ($label === '') {
                    continue;
                }
                if ($title !== '' && $label === $title) {
                    $identityObserved = $label;

                    continue;
                }
                $fields[] = [
                    'item' => $label,
                    'value_observed' => $field['value'] ?? null,
                    'decision' => 'observed',
                ];
            }
            $sectionTitle = $section['title'] ?? null;
            // A section groups fields; a lone pair is a
            // highlight or widget noise, never a section.
            if (count($fields) < 2) {
                continue;
            }
            // A section TITLED with the page title is the
            // identity block (name card), not a field section:
            // its lines are identity highlights.
            if ($title !== '' && $sectionTitle === $title) {
                $identityObserved = $identityObserved ?? $title;

                continue;
            }
            $sections[] = [
                'title' => $sectionTitle,
                'fields' => $fields,
            ];
        }

        return [
            'slice' => $slice,
            'pages' => [
                'detail' => [
                    'path_source' => (string) ($probe['url'] ?? ''),
                    'identity_observed' => $identityObserved,
                    'tabs_observed' => array_values(array_map('strval', $probe['tabs'] ?? [])),
                    'sections' => $sections,
                    'actions_observed' => array_values(array_map('strval', $probe['actions'] ?? [])),
                    'sections_note' => $sections === []
                        ? 'no field sections matched the pair/table patterns — this detail page composes differently; verify by interaction or screenshot'
                        : null,
                    'actions_note' => ($probe['actions'] ?? []) === []
                        ? 'no header actions visible to the probe — this source hides them behind a dropdown; verify by interaction'
                        : null,
                ],
            ],
        ];
    }

    /**
     * Design capture (Tier-5 P1): normalizes a probe's
     * `design` block (computed styles sampled from the
     * running source by the capture harness) into a
     * DESIGN.md-shaped token spec, plus the probe's
     * observed form presentations. Raw values pass through
     * verbatim — the extractor asserts facts, never design
     * decisions; the only derivation is the sidebar scheme
     * (dark/light from the sampled background luminance),
     * which the visual gate asserts on. Returns null when
     * the probe carries no design block (pre-Tier-5 probes
     * stay valid).
     *
     * @param  array<string, mixed>  $probe
     * @return array<string, mixed>|null
     */
    public static function extractDesign(array $probe): ?array
    {
        $design = $probe['design'] ?? null;
        if (! is_array($design)) {
            return null;
        }
        $body = $design['body'] ?? [];
        $sidebar = $design['sidebar'] ?? [];
        $button = $design['primary_button'] ?? [];
        $heading = $design['heading'] ?? [];
        $th = $design['table_header'] ?? [];
        $card = $design['card'] ?? [];
        $search = $design['search'] ?? [];
        $pill = $design['status_pill'] ?? null;

        return [
            'colors' => [
                'primary' => $button['backgroundColor'] ?? null,
                'background' => $body['backgroundColor'] ?? null,
                'foreground' => $body['color'] ?? null,
                'sidebar' => $sidebar['backgroundColor'] ?? null,
                'table_header' => $th['color'] ?? null,
            ],
            'typography' => [
                'sans' => $body['fontFamily'] ?? null,
                'base_size' => $body['fontSize'] ?? null,
                'heading_size' => $heading['fontSize'] ?? null,
                'heading_weight' => $heading['fontWeight'] ?? null,
                'table_header_size' => $th['fontSize'] ?? null,
                'table_header_weight' => $th['fontWeight'] ?? null,
                'cell_size' => $design['cell']['fontSize'] ?? null,
            ],
            'shell' => [
                'sidebar_width' => $sidebar['width'] ?? null,
                'sidebar_scheme' => self::schemeFromColor($sidebar['backgroundColor'] ?? null),
            ],
            'components' => [
                'button' => [
                    'height' => $button['height'] ?? null,
                    'radius' => $button['borderRadius'] ?? null,
                    'font_size' => $button['fontSize'] ?? null,
                    'font_weight' => $button['fontWeight'] ?? null,
                    'color' => $button['color'] ?? null,
                ],
                'card' => [
                    'radius' => $card['borderRadius'] ?? null,
                    'shadow' => $card['boxShadow'] ?? null,
                ],
                'search' => [
                    'height' => $search['height'] ?? null,
                    'radius' => $search['borderRadius'] ?? null,
                ],
                'status_pill' => $pill === null ? null : [
                    'background' => $pill['backgroundColor'] ?? null,
                    'radius' => $pill['borderRadius'] ?? null,
                    'font_size' => $pill['fontSize'] ?? null,
                    'color' => $pill['color'] ?? null,
                ],
            ],
            'forms' => array_filter(
                is_array($probe['forms'] ?? null) ? $probe['forms'] : [],
                static fn ($v) => is_string($v) && $v !== ''
            ),
            'decision' => 'observed',
        ];
    }

    /**
     * Interaction inventory extraction (step-4 L1): from a
     * whole-app crawl (every page's header/dropdown actions,
     * bulk + file-input flags) produce, per page, the evidence
     * the completeness checklist needs — page type, export /
     * import presence, bulk selection, and the page's workflow
     * actions beyond CRUD. Action texts appearing on more than
     * 60% of pages are global chrome (quick-create, user menu)
     * and are excluded from the per-page workflow list.
     *
     * @param  array<string, mixed>  $inventory  path => page probe
     * @return array<string, mixed>
     */
    public static function extractInteractions(array $inventory): array
    {
        $verbs = '/\b(send|remind|duplicate|copy|archive|convert|download|print|share|public|signature|cancel|approve|reject|mark|log time|start timer|bulk|assign|invite|export|import|recurring|template|gantt|reminder|pdf)\b/i';
        $freq = [];
        $pages = [];
        foreach ($inventory as $path => $page) {
            if (! is_array($page) || ! isset($page['actions']) || ! is_array($page['actions'])) {
                continue;
            }
            $texts = [];
            foreach ($page['actions'] as $action) {
                if (! is_array($action) || ! in_array($action['region'] ?? '', ['header', 'dropdown'], true)) {
                    continue;
                }
                $text = trim((string) ($action['t'] ?? ''));
                if (strlen($text) > 1) {
                    $texts[$text] = true;
                    $freq[$text] = ($freq[$text] ?? 0) + 1;
                }
            }
            $pages[$path] = ['texts' => array_map('strval', array_keys($texts)), 'page' => $page];
        }
        $total = max(1, count($pages));
        $chrome = array_filter($freq, fn (int $n): bool => $n / $total > 0.6);

        $out = [];
        foreach ($pages as $path => $data) {
            $texts = $data['texts'];
            $has = fn (string $word): bool => array_filter($texts, fn (string $t): bool => strcasecmp($t, $word) === 0) !== [];
            $workflow = [];
            foreach ($texts as $text) {
                if (isset($chrome[$text]) || in_array(strtolower($text), ['export', 'import'], true)) {
                    continue;
                }
                if (preg_match($verbs, $text) === 1) {
                    $workflow[] = $text;
                }
            }
            sort($workflow);
            $segments = explode('/', trim((string) $path, '/'));
            $last = (string) end($segments);
            $type = 'list';
            if (is_numeric($last)) {
                $type = 'detail';
            } elseif (str_contains((string) $path, '/settings') || str_ends_with((string) $path, 'company-settings')) {
                $type = 'settings';
            } elseif (str_contains($last, 'report')) {
                $type = 'report';
            } elseif (str_contains((string) $path, 'dashboard')) {
                $type = 'dashboard';
            }
            $out[$path] = [
                'type' => $type,
                'export' => $has('Export'),
                'import' => $has('Import'),
                'bulk' => (bool) ($data['page']['hasCheckbox'] ?? false),
                'file_input' => (bool) ($data['page']['hasFileInput'] ?? false),
                'workflow_actions' => $workflow,
            ];
        }
        ksort($out);

        return $out;
    }

    /**
     * Declaration hints (step-4 L2): evidence the porter can
     * surface so a human declares it in the reviewed ui-spec
     * instead of discovering it by audit. Currently: the
     * create label — when the source's primary action text is
     * not the generator's default ("Add <Singular>"), the
     * Heart should declare ui.directory.create_label with the
     * source's words (the E5 lesson: 'New Leave', 'Create
     * Invoice' were found by hand in P2).
     *
     * @param  array<string, mixed>  $listProbe
     * @return array<string, string>
     */
    public static function declarationHints(array $listProbe, string $slug, array $workflowActions = []): array
    {
        $hints = [];
        $primary = $listProbe['primaryAction'] ?? null;
        $text = is_array($primary) ? trim((string) ($primary['text'] ?? '')) : '';
        if ($text === '') {
            // Fallback: the crawl's workflow actions — a
            // module-verb create ('Mark Attendance') is the
            // page's primary action even when the older probe
            // never captured it.
            foreach ($workflowActions as $action) {
                if (preg_match('/^(mark|add|new|create|invite)\b/i', (string) $action) === 1) {
                    $text = (string) $action;
                    break;
                }
            }
        }
        if ($text !== '' && strlen($text) <= 40) {
            $singular = Str::studly(Str::singular($slug));
            if ($text !== 'Add '.$singular) {
                $hints['create_label'] = $text;
            }
        }

        return $hints;
    }

    /**
     * Dotted keys whose scalar values differ between two
     * design specs (reference vs candidate) — the anatomy
     * command uses this to report where pages of the same
     * source disagree about their own design system.
     *
     * @param  array<string, mixed>  $reference
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    public static function designDivergences(array $reference, array $candidate, string $prefix = ''): array
    {
        $keys = [];
        foreach ($reference as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = [...$keys, ...self::designDivergences($value, is_array($candidate[$key] ?? null) ? $candidate[$key] : [], $path)];
            } elseif (($candidate[$key] ?? null) !== $value) {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    /** Dark/light scheme from a colour's luminance (rgb/rgba, or oklch lightness). */
    private static function schemeFromColor(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }
        if (preg_match('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $color, $m)) {
            $luminance = (0.2126 * (int) $m[1] + 0.7152 * (int) $m[2] + 0.0722 * (int) $m[3]) / 255;

            return $luminance < 0.35 ? 'dark' : 'light';
        }
        if (preg_match('/oklch\(\s*([0-9.]+)/', $color, $m)) {
            // oklch lightness is perceptual, 0..1 (or a %).
            $lightness = (float) $m[1];
            if (str_contains($color, '%')) {
                $lightness /= 100;
            }

            return $lightness < 0.35 ? 'dark' : 'light';
        }

        return null;
    }

    /**
     * Placement by landmark geometry (Body v4 vocabulary).
     *
     * @param  array<string, mixed>  $probe
     * @param  list<array<string, mixed>>  $facets
     */
    private static function classifyPlacement(array $probe, array $facets): ?string
    {
        $primary = $probe['primaryAction'] ?? null;
        if ($primary === null) {
            return null;
        }
        $y = (int) $primary['y'];
        $x = (int) $primary['x'];
        $titleY = $probe['titleY'] ?? null;
        $titleX = $probe['titleX'] ?? null;
        $cardTop = $probe['cardTop'] ?? null;
        $tableTop = $probe['tableTop'] ?? null;

        $facetYs = array_map(fn ($s) => (int) ($s['y'] ?? 0), $probe['selects'] ?? []);
        foreach ($probe['searches'] ?? [] as $search) {
            $facetYs[] = (int) ($search['y'] ?? 0);
        }
        $facetsTop = $facetYs === [] ? null : min($facetYs);

        // Level with the title and well to its right.
        if ($titleY !== null && abs($y - (int) $titleY) <= 30 && $titleX !== null && $x > (int) $titleX + 240) {
            return 'header-right';
        }
        // Below the title row but above the facets: a command strip.
        if ($facetsTop !== null && $y < $facetsTop - 10) {
            return 'command-bar';
        }
        // Inside the card, above the grid: the table's own toolbar.
        if ($cardTop !== null && $tableTop !== null && $y >= (int) $cardTop && $y < (int) $tableTop) {
            return 'toolbar-left';
        }
        // Between the facets and the card: the actions band.
        if ($facetsTop !== null && $y > $facetsTop) {
            return 'below-facets-left';
        }

        return null;
    }

    /** @param array<string, mixed> $probe */
    private static function placementBasis(array $probe): string
    {
        $p = $probe['primaryAction'] ?? null;
        if ($p === null) {
            return 'no primary action observed';
        }

        return sprintf(
            'primary action "%s" at x=%d y=%d; titleY=%s cardTop=%s tableTop=%s',
            (string) ($p['text'] ?? ''),
            (int) ($p['x'] ?? 0),
            (int) ($p['y'] ?? 0),
            var_export($probe['titleY'] ?? null, true),
            var_export($probe['cardTop'] ?? null, true),
            var_export($probe['tableTop'] ?? null, true)
        );
    }

    /** @param list<string> $options */
    private static function isPageSizeOptions(array $options): bool
    {
        $sizes = ['10', '25', '50', '100', '15', '20', '30'];
        $numeric = array_filter($options, fn ($o) => in_array(trim($o), $sizes, true));

        return $options !== [] && count($numeric) >= max(2, count($options) - 1);
    }
}
