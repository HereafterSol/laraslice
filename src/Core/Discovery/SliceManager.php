<?php

namespace LaraSlice\Core\Discovery;

// ManifestRepository is same namespace

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Security\Access;
use LaraSlice\Support\SchemaCache;
use Symfony\Component\Yaml\Yaml;

class SliceManager
{
    protected Application $app;

    /** @var array<string, SliceManifest> */
    protected array $slices = [];

    /** @var array<string, string|null> Model classes already resolved by modelClass(). */
    protected array $resolvedModels = [];

    /** @var array<string, array> navigation per user, built by getNavigableSlices() */
    protected array $navigationCache = [];

    /** @var array<int, string> files changed by the current repairSlice() call */
    protected array $repairedFiles = [];

    /**
     * Registry name collisions found during discovery: slices whose
     * raw names are identical (the later registration wins the key)
     * or whose names differ only by spaces/underscores/dashes/case
     * (both register, but normalized lookups are ambiguous).
     *
     * @var array<int, array{type: string, key: string, slices: array<int, array{name: string, path: string}>}>
     */
    protected array $nameCollisions = [];

    /** @var array<string, SliceManifest> first holder of each normalized name key */
    protected array $normalizedNameHolders = [];

    /** @var array<string, array{model: string, winner: string, candidates: array<int, string>}> ambiguous modelClass() resolutions */
    protected array $modelCollisions = [];

    /**
     * Every manifest seen during discovery, including slices whose
     * registry key was taken by a same-named twin. Shadowed slices
     * are still booted (routes + views live), so filesystem-level
     * tooling (slice:repair) must iterate THIS list, not the
     * name-keyed registry.
     *
     * @var array<int, SliceManifest>
     */
    protected array $discoveredSlices = [];

    protected bool $repairDryRun = false;

    /**
     * True once this instance has completed a discovery pass.
     * discover() is an "ensure discovered" operation: repeat calls
     * in one process are no-ops unless forced (24 Oct 2026 — a
     * hub detail page was issuing ~60 full rescans, ~0.9s each,
     * and blowing the 30s server limit). Mutation flows invalidate
     * via clearCache(), which every toggle/destroy/sync path
     * already calls after writing.
     */
    protected bool $discovered = false;

    /**
     * Parsed manifest data cache (backlog B1): manifest
     * path => ['mtime' => int, 'size' => int, 'data' => array],
     * loaded lazily from bootstrap/cache. Null = not loaded
     * yet. Written by cacheSlices() alongside the path list;
     * entries are only trusted when the file's current
     * mtime + size match, so an edited manifest re-parses
     * (and only that one) until the next slice:cache.
     *
     * @var array<string, array{mtime: int, size: int, data: array}>|null
     */
    protected ?array $manifestDataCache = null;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function getManifestDataCachePath(): string
    {
        if (function_exists('app') && method_exists($this->app, 'bootstrapPath')) {
            return $this->app->bootstrapPath('cache/laraslice_manifest_data.php');
        }

        return sys_get_temp_dir().'/laraslice_manifest_data.php';
    }

    /**
     * @return array<string, array{mtime: int, size: int, data: array}>
     */
    protected function manifestDataCache(): array
    {
        if ($this->manifestDataCache !== null) {
            return $this->manifestDataCache;
        }
        $this->manifestDataCache = [];
        $path = $this->getManifestDataCachePath();
        if (file_exists($path)) {
            $cached = include $path;
            if (is_array($cached)) {
                $this->manifestDataCache = $cached;
            }
        }

        return $this->manifestDataCache;
    }

    /**
     * Construct a manifest, reusing cached parsed data when
     * the manifest file is unchanged (mtime + size match).
     */
    protected function loadManifest(string $manifestPath): SliceManifest
    {
        $cache = $this->manifestDataCache();
        if (isset($cache[$manifestPath]) && is_array($cache[$manifestPath]['data'] ?? null)) {
            $mtime = @filemtime($manifestPath);
            $size = @filesize($manifestPath);
            if ($mtime === ($cache[$manifestPath]['mtime'] ?? null) && $size === ($cache[$manifestPath]['size'] ?? null)) {
                return SliceManifest::fromParsed($manifestPath, $cache[$manifestPath]['data']);
            }
        }

        return new SliceManifest($manifestPath);
    }

    public function discover(bool $force = false): void
    {
        if ($this->discovered && ! $force) {
            return;
        }
        $this->slices = [];
        $this->navigationCache = [];
        $this->resolvedModels = [];
        $this->modelCollisions = [];
        $this->nameCollisions = [];
        $this->normalizedNameHolders = [];
        $this->discoveredSlices = [];
        if ($this->isCached()) {
            $cached = include $this->getCachedSlicesPath();
            if (is_array($cached)) {
                // Values are manifest paths in both cache
                // formats: the legacy name-keyed map and the
                // lossless path list cacheSlices() now writes.
                // Iterating values only registers every cached
                // manifest — including same-named twins the
                // legacy map's keys could not represent as
                // separate entries on the write side.
                foreach ($cached as $manifestPath) {
                    if (is_string($manifestPath) && file_exists($manifestPath)) {
                        $manifest = $this->loadManifest($manifestPath);
                        $this->registerManifest($manifest);
                        if ($manifest->active) {
                            $this->bootSlice($manifest);
                        }
                    }
                }

                $this->discovered = true;

                return;
            }
        }

        $scanPaths = [];

        // 1. Built-in Core Framework Slices
        $coreSlicesPath = dirname(__DIR__, 2).'/Slices';
        if (is_dir($coreSlicesPath)) {
            $scanPaths[] = $coreSlicesPath;
        }

        // 2. Application-level Slices (e.g. app/Slices)
        $appSlicesPath = config('laraslice.slices_path', app_path('Slices'));
        if ($appSlicesPath && is_dir($appSlicesPath) && realpath($appSlicesPath) !== realpath($coreSlicesPath)) {
            $scanPaths[] = $appSlicesPath;
        }

        foreach ($scanPaths as $path) {
            $this->scanDirectoryForSlices($path);
        }

        $this->discovered = true;
    }

    /**
     * Recursively scan directory up to $maxDepth for slices containing slice.yaml.
     */
    protected function scanDirectoryForSlices(string $path, int $depth = 0, int $maxDepth = 6): void
    {
        if (! is_dir($path) || $depth > $maxDepth) {
            return;
        }

        $manifestFile = $this->findManifest($path);
        if ($manifestFile) {
            $manifest = $this->loadManifest($manifestFile);
            $this->registerManifest($manifest);

            if ($manifest->active) {
                $this->bootSlice($manifest);
            }

            // Once a manifest is found, do not scan deeper into this slice's internal directories
            return;
        }

        $subDirs = glob($path.'/*', GLOB_ONLYDIR) ?: [];
        foreach ($subDirs as $subDir) {
            $base = basename($subDir);
            if (in_array(strtolower($base), ['.git', 'node_modules', 'vendor', 'storage', 'public', 'tests', 'cache', '.system_generated'])) {
                continue;
            }
            $this->scanDirectoryForSlices($subDir, $depth + 1, $maxDepth);
        }
    }

    /**
     * Find manifest file prioritizing slice.yaml, then slice.yml.
     */
    public function findManifest(string $dir): ?string
    {
        foreach (['slice.yaml', 'slice.yml', 'slice.yaml'] as $file) {
            $path = $dir.'/'.$file;
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Get path to cached slices manifest map.
     */
    public function getCachedSlicesPath(): string
    {
        if (function_exists('app') && method_exists($this->app, 'bootstrapPath')) {
            return $this->app->bootstrapPath('cache/laraslice_slices.php');
        }

        return sys_get_temp_dir().'/laraslice_slices.php';
    }

    /**
     * Check if slice discovery is cached.
     */
    public function isCached(): bool
    {
        return file_exists($this->getCachedSlicesPath());
    }

    /**
     * Cache discovered slices for high-performance production execution.
     */
    public function cacheSlices(): int
    {
        // Lossless by construction (foundation backlog A2,
        // 24 Oct 2026): the cache is built from EVERY
        // discovered manifest, not from the name index.
        // The old name-keyed map silently dropped every
        // name-shadowed twin (115 cached of 140 discovered
        // on the demo) and a cached boot then 500'd on the
        // missing slices. A manifest whose file cannot be
        // resolved now refuses the whole write instead of
        // shrinking the registry quietly.
        $paths = [];
        foreach ($this->discoveredSlices as $manifest) {
            $manifestFile = $this->findManifest($manifest->path);
            if (! $manifestFile) {
                throw new \RuntimeException(
                    "slice:cache refused: manifest file for slice [{$manifest->name}] at [{$manifest->path}] could not be resolved; ".
                    'writing the cache would silently drop it from the registry.'
                );
            }
            $paths[] = $manifestFile;
        }

        $cachePath = $this->getCachedSlicesPath();
        $cacheDir = dirname($cachePath);
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $export = var_export($paths, true);
        file_put_contents($cachePath, "<?php\n\nreturn {$export};\n");

        // Parsed-data cache (B1): the path list alone still
        // left discovery parsing every manifest's YAML on
        // every request (~0.44s over 140 manifests). Store
        // each manifest's parsed data beside its freshness
        // stamp; loadManifest() reuses it while the file is
        // unchanged, so a warm boot skips the YAML parser.
        $dataCache = [];
        foreach ($this->discoveredSlices as $manifest) {
            $manifestFile = $this->findManifest($manifest->path);
            if ($manifestFile && file_exists($manifestFile)) {
                $dataCache[$manifestFile] = [
                    'mtime' => filemtime($manifestFile),
                    'size' => filesize($manifestFile),
                    'data' => $manifest->raw,
                ];
            }
        }
        $dataPath = $this->getManifestDataCachePath();
        if (! is_dir(dirname($dataPath))) {
            @mkdir(dirname($dataPath), 0755, true);
        }
        file_put_contents($dataPath, "<?php\n\nreturn ".var_export($dataCache, true).";\n");
        $this->manifestDataCache = $dataCache;

        return count($paths);
    }

    /**
     * Clear cached slice discovery file.
     */
    /**
     * Remove an auto-heal child-route group whose controller
     * does not exist (backlog A3). Matches only the generated
     * block shape: a Route::prefix('<prefix>') group whose body
     * references [<Child>WebController::class, ...] and ends at
     * the first line that is exactly `});`. Returns the stripped
     * content, or null when no such block is present.
     */
    private function stripInventedChildRoutes(string $content, string $childUrlPrefix, string $childStudly): ?string
    {
        $lines = explode("\n", $content);
        $start = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, "Route::prefix('{$childUrlPrefix}')")) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return null;
        }
        $end = null;
        $referencesController = false;
        for ($j = $start; $j < count($lines) && $j <= $start + 12; $j++) {
            if (str_contains($lines[$j], $childStudly.'WebController::class')) {
                $referencesController = true;
            }
            if (trim($lines[$j]) === '});') {
                $end = $j;
                break;
            }
        }
        if ($end === null || ! $referencesController) {
            return null;
        }
        // The generated block starts with a blank separator line.
        $from = ($start > 0 && trim($lines[$start - 1]) === '') ? $start - 1 : $start;
        array_splice($lines, $from, $end - $from + 1);

        return implode("\n", $lines);
    }

    /**
     * Repair (x): move an index blade's standalone quick-filter
     * block into the data table's quick-filters slot. Returns the
     * rewritten content, or null when the block shape is not the
     * generated one (hand-edited blades are left alone).
     */
    private function slotQuickFilters(string $content): ?string
    {
        if (! str_contains($content, '<x-ui.data-table')) {
            return null;
        }
        $lines = explode("\n", $content);
        $ifIdx = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, '@if(!empty($quickFilters))')) {
                $ifIdx = $i;
                break;
            }
        }
        if ($ifIdx === null) {
            return null;
        }
        $foreachIdx = null;
        $endforeachIdx = null;
        $endIdx = null;
        foreach ($lines as $i => $line) {
            if ($i <= $ifIdx) {
                continue;
            }
            if ($foreachIdx === null && str_contains($line, '@foreach($quickFilters as $qf)')) {
                $foreachIdx = $i;
                continue;
            }
            if ($foreachIdx !== null && $endforeachIdx === null && str_contains($line, '@endforeach')) {
                $endforeachIdx = $i;
                continue;
            }
            if ($endforeachIdx !== null && trim($line) === '@endif') {
                $endIdx = $i;
                break;
            }
        }
        if ($foreachIdx === null || $endforeachIdx === null || $endIdx === null) {
            return null;
        }
        $indent = str_repeat(' ', strlen($lines[$ifIdx]) - strlen(ltrim($lines[$ifIdx])));
        $inner = array_slice($lines, $foreachIdx, $endforeachIdx - $foreachIdx + 1);
        $slot = array_merge(
            [$indent.'@if(!empty($quickFilters))', $indent.'    <x-slot:quick-filters>'],
            $inner,
            [$indent.'    </x-slot:quick-filters>', $indent.'@endif']
        );
        $startIdx = $ifIdx;
        if ($startIdx > 0 && str_contains($lines[$startIdx - 1], 'Quick Filter Tabs')) {
            $startIdx--;
        }
        array_splice($lines, $startIdx, $endIdx - $startIdx + 1);
        $dtIdx = null;
        $gtIdx = null;
        foreach ($lines as $i => $line) {
            if ($dtIdx === null && str_contains($line, '<x-ui.data-table')) {
                $dtIdx = $i;
                continue;
            }
            if ($dtIdx !== null && str_ends_with(rtrim($line), '>')) {
                $gtIdx = $i;
                break;
            }
        }
        if ($gtIdx === null) {
            return null;
        }
        array_splice($lines, $gtIdx + 1, 0, $slot);

        return implode("\n", $lines);
    }

    /**
     * Repair (y, revised 25 Oct 2026): the Filters button belongs
     * in the quick-filters slot — the strip the source composition
     * places directly under the topbar (on ERP pages the data
     * table pushes that slot's bar into the layout strip). Blades
     * carrying the button in a table-actions slot get it
     * relocated (an emptied slot is removed); blades missing it
     * get it inserted; a missing quick-filters slot is created.
     * The button dispatches the window event the filters drawer
     * listens for; the drawer's standalone trigger row removes
     * itself at runtime when this button exists. Returns null
     * when nothing applies.
     */
    /**
     * Repair rule (aa): retire the in-page title row on ERP-app
     * slices. The shell topbar carries the section title + Home
     * breadcrumb on ERP pages (the source composition, 25 Oct
     * 2026); the generated title block beside it duplicated both.
     * Two shapes: (B) 'Compact Top Bar' — an inner title div
     * inside the row; (A) the current generator's row with the
     * h1 + breadcrumb as direct children. The title block is
     * deleted; the row keeps any header-right actions block and
     * gains `empty:hidden` so a row left with only a conditional
     * block renders nothing when the condition is off. Gated by
     * the caller to ERP-shell slices (routes prefix erp/hr/crm —
     * the layout's own test); platform slices keep their in-page
     * titles (their shell has no topbar title).
     */
    /**
     * (af) Restore the ownership card's data guard in show
     * blades generated before the guard existed: the card
     * reads $record->assigned_to_role / assigned_to_department
     * with boilerplate fallbacks ('General Staff' /
     * 'Operations'), so unguarded blades print that invented
     * ownership on EVERY record. The current generator wraps
     * the card in the @if; this rule adds the same wrap.
     */
    private function guardOwnershipCard(string $content): ?string
    {
        $marker = '<!-- Responsibility & Ownership Card -->';
        $pos = strpos($content, $marker);
        if ($pos === false) {
            return null;
        }
        $before = substr($content, max(0, $pos - 240), min(240, $pos));
        if (str_contains($before, 'assigned_to_role')) {
            return null;
        }
        $endTag = '</x-ui.card>';
        $end = strpos($content, $endTag, $pos);
        if ($end === false) {
            return null;
        }
        $end += strlen($endTag);
        $guard = '@if(!empty($record->assigned_to_role) || !empty($record->assigned_to_department))'."\n";

        return substr($content, 0, $pos).$guard.substr($content, $pos, $end - $pos)."\n                @endif".substr($content, $end);
    }

    /**
     * (ag) Point show blades' $label line at
     * DirectoryUi::recordLabel() (the chain that also tries
     * document numbers and {thing}_name attributes) instead of
     * the five-field chain that titled projects and invoices
     * "Slice #id" (QA5, 25 Oct 2026).
     */
    /**
     * (ah) Unghost the legacy Compact Top Bar: its wrapper div
     * renders even when the slice's actions placement is not
     * 'header-right', because the placement @if sits INSIDE the
     * div — the page gets a 9px bordered sliver plus the
     * wrapper's space-y gap of dead space above the table
     * (owner-caught, 25 Oct 2026). Move the @if OUTSIDE the div
     * so the bar exists only when its branch renders. Guarded:
     * only when the div contains exactly the @if block.
     */
    private function unghostCompactTopBar(string $content): ?string
    {
        $ifLine = "@if((\$placement ?? 'below-facets-left') === 'header-right')";
        $divOpen = '<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-border/40">';

        // Broken intermediate (first (ah) run): the @if ended up
        // BOTH before and inside the div. Drop the inner one.
        $brokenPattern = "        ".$ifLine."\n    ".$divOpen."\n        ".$ifLine;
        if (str_contains($content, $brokenPattern)) {
            return str_replace($brokenPattern, "    ".$ifLine."\n    ".$divOpen, $content);
        }

        // Original shape: div (with empty:hidden) wrapping the
        // @if. Move the @if outside and swap the closing pair.
        $open = '<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-2 border-b border-border/40 empty:hidden">';
        $pos = strpos($content, $open);
        if ($pos === false) {
            return null;
        }
        $afterOpen = substr($content, $pos + strlen($open));
        if (! preg_match('/^(\s*)'.preg_quote($ifLine, '/').'/', $afterOpen, $m)) {
            return null;
        }
        $closePair = "        @endif\n    </div>";
        $closePos = strpos($content, $closePair, $pos);
        if ($closePos === false) {
            return null;
        }
        $inner = substr($content, $pos + strlen($open), $closePos - ($pos + strlen($open)));
        // Strip the @if line itself from the head of $inner;
        // what follows must be the actions wrapper div.
        $inner = preg_replace('/^\s*'.preg_quote($ifLine, '/').'\n/', '', $inner, 1);
        if (! preg_match('/^\s*<div class="ml-auto flex flex-wrap items-center gap-2">/', (string) $inner)) {
            return null;
        }
        $bar = "    ".$ifLine."\n    ".$divOpen.$inner."    </div>\n    @endif";

        return substr($content, 0, $pos).$bar.substr($content, $closePos + strlen($closePair));
    }

    /**
     * (ai) Conditional band rhythm on index pages: the section
     * wrapper's space-y-* margin lands on the zero-height
     * chrome (bulk bar, filters drawer) when no KPI cards
     * render, reading as dead space above the actions row
     * (owner-caught, 25 Oct 2026). Apply the spacing class
     * only when KPI cards will render.
     */
    private function conditionalIndexRhythm(string $content): ?string
    {
        $pattern = "/(@section\('content'\)\n)<div class=\"(space-y-\d+)\">/";
        if (! preg_match($pattern, $content, $m)) {
            return null;
        }
        $replacement = $m[1]
            ."@php \$hasKpiCards = !empty(\$summaryCards) || !empty(\$dashWidgets); @endphp\n"
            .'<div class="{{ $hasKpiCards ? \''.$m[2].'\' : \'\' }}">';

        return preg_replace($pattern, $replacement, $content, 1);
    }

    /**
     * (aj) The first-run empty panel must not swallow the
     * directory when a list is merely FILTERED to zero: extend
     * its condition so it renders only with no active filters
     * (the data-table then shows its in-table empty row and
     * the filter bar stays visible/clearable).
     */
    private function scopeEmptyPanelToUnfiltered(string $content): ?string
    {
        $old = "@if (count(\$rows) === 0 && (\$defaultView ?? 'table') !== 'calendar')";
        if (! str_contains($content, $old)) {
            return null;
        }

        return str_replace(
            $old,
            "@if (count(\$rows) === 0 && empty(array_filter(\$activeFilters ?? [])) && (\$defaultView ?? 'table') !== 'calendar')",
            $content
        );
    }

    /**
     * (ak) Show-page widget placements: the overview widgets
     * region renders MAIN-placement widgets only (with the
     * stats/list card branches), and RAIL-placement widgets
     * render as stacked cards at the top of the right sidebar
     * — the source hubs' overview composition (progress gauge
     * and detail sections in the main column; tasks pie,
     * statistics and appreciation lists in the rail).
     */
    private function splitShowWidgetPlacements(string $content): ?string
    {
        if (str_contains($content, 'Declared rail widgets')) {
            return null;
        }
        $gridAnchor = '<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">';
        $gridPos = strpos($content, $gridAnchor);
        if ($gridPos === false) {
            return null;
        }
        $changed = false;

        // Rail loop at the top of the right sidebar.
        $sidebar = "<!-- Right 1/3 Action & Responsibility Sidebar -->\n            <div class=\"space-y-6\">";
        if (str_contains($content, $sidebar)) {
            $content = str_replace($sidebar, $sidebar."\n".self::showRailLoop(), $content);
            $changed = true;
            $gridPos = strpos($content, $gridAnchor);
        }

        // Main region: replace any existing widgets region
        // before the grid, or insert the canonical one.
        $region = self::showMainWidgetsRegion();
        $existing = strpos($content, '@if(!empty($showWidgets');
        if ($existing !== false && $existing < $gridPos) {
            $lineStart = strrpos(substr($content, 0, $existing), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            // Swallow a directly preceding declaration comment.
            $before = substr($content, 0, $lineStart);
            if (preg_match('/\{\{--[^}]*show\.widgets[^}]*--\}\}\s*$/', $before, $cm)) {
                $lineStart -= strlen($cm[0]);
            }
            $content = substr($content, 0, $lineStart).$region."\n".substr($content, $gridPos);
            $changed = true;
        } else {
            $content = substr($content, 0, $gridPos).$region."\n".substr($content, $gridPos);
            $changed = true;
        }

        return $changed ? $content : null;
    }

    private static function showMainWidgetsRegion(): string
    {
        return <<<'BLADE'
        {{-- Declared overview widgets (ui.show.widgets, S-UI 3): gauges and charts computed server-side --}}
        @if(!empty($showWidgets ?? []))
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            @foreach($showWidgets as $widget)
            @if(($widget['placement'] ?? 'main') === 'main')
            @if(($widget['type'] ?? '') === 'gauge')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <x-ui.gauge :value="$widget['value']" :max="$widget['max']" :label="$widget['title']" :display="$widget['display']" />
            </x-ui.card>
            @elseif(($widget['type'] ?? '') === 'chart')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ $widget['title'] }}</p>
                <x-ui.chart :type="$widget['chart']" :labels="$widget['labels']" :series="$widget['series']" :height="200" />
            </x-ui.card>
            @elseif(($widget['type'] ?? '') === 'stats')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ $widget['title'] }}</p>
                <dl class="space-y-2">
                    @foreach($widget['rows'] as $row)
                    <div class="flex items-center justify-between gap-3 text-[13px]">
                        <dt class="text-muted-foreground">{{ $row['label'] }}</dt>
                        <dd class="font-semibold text-foreground">{{ $row['value'] }}</dd>
                    </div>
                    @endforeach
                </dl>
            </x-ui.card>
            @elseif(($widget['type'] ?? '') === 'list')
            <x-ui.card class="p-5 bg-card border shadow-xs">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ $widget['title'] }}</p>
                @if(($widget['rows'] ?? []) === [])
                <p class="text-[13px] text-muted-foreground">{{ $widget['empty'] ?? 'Nothing to show.' }}</p>
                @else
                <ul class="divide-y divide-border/60">
                    @foreach($widget['rows'] as $row)
                    <li class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                        <span class="text-[13px] font-medium text-foreground">{{ $row['label'] }}</span>
                        @if(($row['meta'] ?? '') !== '')<span class="text-xs text-muted-foreground">{{ $row['meta'] }}</span>@endif
                    </li>
                    @endforeach
                </ul>
                @endif
            </x-ui.card>
            @endif
            @endif
            @endforeach
        </div>
        @endif
BLADE;
    }

    private static function showRailLoop(): string
    {
        return <<<'BLADE'
        {{-- Declared rail widgets (ui.show.widgets placement: rail) --}}
        @foreach($showWidgets ?? [] as $widget)
        @if(($widget['placement'] ?? 'main') === 'rail')
        <x-ui.card class="p-5 bg-card border shadow-xs">
            @if(($widget['type'] ?? '') === 'gauge')
            <x-ui.gauge :value="$widget['value']" :max="$widget['max']" :label="$widget['title']" :display="$widget['display']" />
            @elseif(($widget['type'] ?? '') === 'chart')
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ $widget['title'] }}</p>
            <x-ui.chart :type="$widget['chart']" :labels="$widget['labels']" :series="$widget['series']" :height="200" />
            @elseif(($widget['type'] ?? '') === 'stats')
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ $widget['title'] }}</p>
            <dl class="space-y-2">
                @foreach($widget['rows'] as $row)
                <div class="flex items-center justify-between gap-3 text-[13px]">
                    <dt class="text-muted-foreground">{{ $row['label'] }}</dt>
                    <dd class="font-semibold text-foreground">{{ $row['value'] }}</dd>
                </div>
                @endforeach
            </dl>
            @elseif(($widget['type'] ?? '') === 'list')
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-3">{{ $widget['title'] }}</p>
            @if(($widget['rows'] ?? []) === [])
            <p class="text-[13px] text-muted-foreground">{{ $widget['empty'] ?? 'Nothing to show.' }}</p>
            @else
            <ul class="divide-y divide-border/60">
                @foreach($widget['rows'] as $row)
                <li class="flex items-center justify-between gap-3 py-1.5 first:pt-0 last:pb-0">
                    <span class="text-[13px] font-medium text-foreground">{{ $row['label'] }}</span>
                    @if(($row['meta'] ?? '') !== '')<span class="text-xs text-muted-foreground">{{ $row['meta'] }}</span>@endif
                </li>
                @endforeach
            </ul>
            @endif
            @endif
        </x-ui.card>
        @endif
        @endforeach
BLADE;
    }

    /**
     * (al) The first-run empty panel replaces the whole list
     * composition — including the actions band — so declared
     * directory actions (and Import) vanished exactly when a
     * slice was empty. The panel's button row gains the
     * declared-actions include and the import button.
     */
    private function emptyPanelActions(string $content): ?string
    {
        $start = strpos($content, '@if (count($rows) === 0');
        if ($start === false) {
            return null;
        }
        $else = strpos($content, "\n    @else", $start);
        if ($else === false) {
            return null;
        }
        $region = substr($content, $start, $else - $start);
        if (str_contains($region, 'directory.actions')) {
            return null;
        }
        $seedLine = "                @if((\$can['seed'] ?? true) && Route::has('laraslice.wizard.seed_slice'))";
        $seedPos = strpos($region, $seedLine);
        if ($seedPos === false) {
            return null;
        }
        $insert = "                @include('laraslice::directory.actions')\n"
            ."                @isset(\$importUrl)\n"
            ."                <x-ui.button href=\"{{ \$importUrl }}\" as=\"a\" variant=\"secondary\" size=\"sm\" class=\"border border-border gap-1.5\">\n"
            ."                    <x-lucide-upload class=\"size-3.5\" /> <span>Import</span>\n"
            ."                </x-ui.button>\n"
            ."                @endisset\n";
        $region = substr($region, 0, $seedPos).$insert.substr($region, $seedPos);

        return substr($content, 0, $start).$region.substr($content, $else);
    }

    private function useRecordLabelHelper(string $content): ?string
    {
        if (! str_contains($content, '$record->title ??')) {
            return null;
        }
        $out = preg_replace(
            '/\$label = \$record->title \?\? \$record->name \?\? \$record->full_name \?\? \$record->heading \?\? \$record->subject \?\? \(\'([^\']+) #\' \. \$id\);/',
            '\$label = \\LaraSlice\\Support\\DirectoryUi::recordLabel(\$record) ?? (\'$1 #\' . \$id);',
            $content,
            1,
            $count
        );

        return $count > 0 ? $out : null;
    }

    private function retireInPageTitleRow(string $content): ?string
    {
        $lines = explode("\n", $content);
        $count = count($lines);
        $deleteBlock = function (int $start) use (&$lines, $count): ?int {
            // Balanced-<div> scan from $start; returns the end
            // line index (inclusive) or null when unbalanced.
            $depth = 0;
            for ($i = $start; $i < $count; $i++) {
                $depth += substr_count($lines[$i], '<div') - substr_count($lines[$i], '</div>');
                if ($i > $start && $depth <= 0) {
                    return $i;
                }
                if ($i === $start && $depth === 0 && str_contains($lines[$i], '</div>')) {
                    return $i;
                }
            }

            return null;
        };

        for ($i = 0; $i < $count; $i++) {
            $isRowB = str_contains($lines[$i], '<!-- Compact Top Bar');
            $isRowA = str_contains($lines[$i], 'flex items-center gap-3 pb-2 border-b border-border/40')
                && isset($lines[$i + 1]) && str_contains($lines[$i + 1], '<h1');
            if (! $isRowB && ! $isRowA) {
                continue;
            }
            $rowIdx = $isRowB ? $i + 1 : $i;
            if (! isset($lines[$rowIdx]) || ! str_contains($lines[$rowIdx], '<div')) {
                return null;
            }
            // Locate the title block: shape B nests it in an
            // inner div on the next line; shape A holds the h1
            // directly.
            $titleStart = null;
            if ($isRowB && isset($lines[$rowIdx + 1]) && trim($lines[$rowIdx + 1]) === '<div class="flex items-center gap-3">') {
                $titleStart = $rowIdx + 1;
                $titleEnd = $deleteBlock($titleStart);
                if ($titleEnd === null) {
                    return null;
                }
                $block = implode("\n", array_slice($lines, $titleStart, $titleEnd - $titleStart + 1));
                if (! str_contains($block, '<h1') || ! str_contains($block, '>Home<')) {
                    return null;
                }
                array_splice($lines, $titleStart, $titleEnd - $titleStart + 1);
            } elseif ($isRowA) {
                // Delete the h1 line and the breadcrumb div
                // block that follows it.
                $h1 = $i + 1;
                $crumbStart = null;
                for ($j = $h1 + 1; $j < min($count, $h1 + 4); $j++) {
                    if (str_contains($lines[$j], '<div') && str_contains(implode("\n", array_slice($lines, $j, 6)), '>Home<')) {
                        $crumbStart = $j;
                        break;
                    }
                }
                if ($crumbStart === null) {
                    return null;
                }
                $crumbEnd = $deleteBlock($crumbStart);
                if ($crumbEnd === null) {
                    return null;
                }
                array_splice($lines, $h1, $crumbEnd - $h1 + 1);
            } else {
                return null;
            }
            // The row may now hold only conditional blocks:
            // hide it when it renders empty.
            if (! str_contains($lines[$rowIdx], 'empty:hidden')) {
                $lines[$rowIdx] = preg_replace('/class="([^"]*)"/', 'class="$1 empty:hidden"', $lines[$rowIdx], 1);
            }

            return implode("\n", $lines);
        }

        return null;
    }

    /**
     * Repair rule (z): widen the slots guard. Finds the narrow
     * @if(!empty($quickFilters)) line that opens the block
     * containing <x-slot:toolbar-lead> or <x-slot:quick-filters>
     * (within the next two non-blank lines) and widens it to
     * admit sidebar filters, matching the current generator.
     * Returns the updated blade, or null when already widened /
     * not applicable.
     */
    private function widenQuickFilterGuard(string $content): ?string
    {
        if (! str_contains($content, '<x-ui.data-table') || ! str_contains($content, 'data-ls-filters-btn')) {
            return null;
        }
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (trim($line) !== '@if(!empty($quickFilters))') {
                continue;
            }
            $seen = 0;
            for ($j = $i + 1; $j < count($lines) && $seen < 2; $j++) {
                if (trim($lines[$j]) === '') {
                    continue;
                }
                $seen++;
                if (str_contains($lines[$j], '<x-slot:toolbar-lead>') || str_contains($lines[$j], '<x-slot:quick-filters>')) {
                    $lines[$i] = str_replace(
                        '@if(!empty($quickFilters))',
                        '@if(!empty($quickFilters) || !empty($sidebarFilters ?? []))',
                        $line
                    );

                    return implode("\n", $lines);
                }
            }
        }

        return null;
    }

    private function slotTableActions(string $content): ?string
    {
        if (! str_contains($content, '<x-ui.data-table')) {
            return null;
        }
        $button = <<<'BLADE'
            @if(!empty($sidebarFilters ?? []))
            <button type="button" data-ls-filters-btn @click="$dispatch('open-ls-filters')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-border bg-card text-foreground hover:bg-muted text-xs font-medium shadow-2xs transition-colors cursor-pointer">
                <x-lucide-filter class="size-3.5" />
                <span>Filters</span>
                @if(!empty($activeFilters ?? []))
                <span class="inline-flex items-center justify-center size-4 rounded-full bg-primary text-[10px] font-bold text-primary-foreground">{{ count(array_filter($activeFilters)) }}</span>
                @endif
            </button>
            @endif
BLADE;
        $buttonLines = explode("\n", $button);
        $lines = explode("\n", $content);
        $changed = false;

        // The data-table invocation's closing line.
        $gtIdx = null;
        foreach ($lines as $i => $line) {
            if ($gtIdx === null && str_contains($line, '<x-ui.data-table')) {
                $gtIdx = $i;
                continue;
            }
            if ($gtIdx !== null && str_ends_with(rtrim($line), '>')) {
                $gtIdx = $i;
                break;
            }
        }
        if ($gtIdx === null) {
            return null;
        }

        // 1) Relocate a button sitting in a table-actions slot.
        if (str_contains($content, 'data-ls-filters-btn')) {
            $taOpen = $taClose = $btnStart = $btnEnd = null;
            foreach ($lines as $i => $line) {
                if ($taOpen === null && str_contains($line, '<x-slot:table-actions>')) {
                    $taOpen = $i;
                }
                if ($taOpen !== null && str_contains($line, '</x-slot:table-actions>')) {
                    $taClose = $i;
                    break;
                }
            }
            if ($taOpen !== null && $taClose !== null) {
                for ($i = $taOpen; $i <= $taClose; $i++) {
                    if (str_contains($lines[$i], 'data-ls-filters-btn')) {
                        $btnStart = $i;
                    }
                    if ($btnStart !== null && str_contains($lines[$i], '</button>')) {
                        $btnEnd = $i;
                        break;
                    }
                }
            }
            if ($btnStart === null) {
                return null; // already placed outside table-actions
            }
            array_splice($lines, $btnStart, $btnEnd - $btnStart + 1);
            $changed = true;
            // Re-locate the slot after the removal.
            $taOpen = $taClose = null;
            foreach ($lines as $i => $line) {
                if ($taOpen === null && str_contains($line, '<x-slot:table-actions>')) {
                    $taOpen = $i;
                }
                if ($taOpen !== null && str_contains($line, '</x-slot:table-actions>')) {
                    $taClose = $i;
                    break;
                }
            }
            if ($taOpen !== null && $taClose !== null) {
                $residual = array_filter(
                    array_slice($lines, $taOpen + 1, $taClose - $taOpen - 1),
                    fn ($l) => trim($l) !== ''
                );
                if ($residual === []) {
                    $from = $taOpen;
                    $to = $taClose;
                    if ($from > 0 && str_contains($lines[$from - 1], '@if(!empty($sidebarFilters')) {
                        $from--;
                    }
                    if (isset($lines[$to + 1]) && trim($lines[$to + 1]) === '@endif') {
                        $to++;
                    }
                    array_splice($lines, $from, $to - $from + 1);
                }
            }
            // gtIdx may have shifted; recompute it.
            $gtIdx = null;
            foreach ($lines as $i => $line) {
                if ($gtIdx === null && str_contains($line, '<x-ui.data-table')) {
                    $gtIdx = $i;
                    continue;
                }
                if ($gtIdx !== null && str_ends_with(rtrim($line), '>')) {
                    $gtIdx = $i;
                    break;
                }
            }
            if ($gtIdx === null) {
                return null;
            }
        }

        // 2) Insert the button at the end of the quick-filters
        // slot, creating the slot when the blade has none.
        $qfClose = null;
        for ($i = $gtIdx; $i < count($lines); $i++) {
            if (str_contains($lines[$i], '</x-slot:quick-filters>')) {
                $qfClose = $i;
                break;
            }
            if ($i > $gtIdx && str_contains($lines[$i], '</x-ui.data-table>')) {
                break;
            }
        }
        if ($qfClose !== null) {
            array_splice($lines, $qfClose, 0, $buttonLines);
        } else {
            $slotLines = array_merge(
                ['            <x-slot:quick-filters>'],
                $buttonLines,
                ['            </x-slot:quick-filters>']
            );
            array_splice($lines, $gtIdx + 1, 0, $slotLines);
        }

        return implode("\n", $lines);
    }

    /**
     * Repair (ac): restructure an index blade's page-top to the
     * source composition (list contract v3) — title row keeps
     * only title + breadcrumb (version badge dropped); the
     * actions cluster moves into the data table's below-toolbar
     * band, reordered primary-first (Add, declared actions,
     * Import, Bulk, Report, Matrix, Export, Seed). Returns null
     * when the blade's top bar is not the generated shape.
     */
    private function relocateDirectoryActions(string $content): ?string
    {
        if (str_contains($content, 'x-slot:below-toolbar') || ! str_contains($content, 'flex items-center gap-2 flex-wrap')) {
            return null;
        }
        $lines = explode("\n", $content);
        $barStart = null;
        $sessIdx = null;
        foreach ($lines as $i => $line) {
            if ($barStart === null && str_contains($line, '<!-- Compact Top Bar')) {
                $barStart = $i;
            }
            if ($barStart !== null && str_contains($line, "@if (session('success'))")) {
                $sessIdx = $i;
                break;
            }
        }
        if ($barStart === null || $sessIdx === null) {
            return null;
        }
        // Top bar close: the last 4-space </div> before the alerts.
        $barClose = null;
        for ($i = $sessIdx - 1; $i > $barStart; $i--) {
            if ($lines[$i] === '    </div>') {
                $barClose = $i;
                break;
            }
        }
        $clusterStart = null;
        for ($i = $barStart; $i < ($barClose ?? $sessIdx); $i++) {
            if (str_contains($lines[$i], '<div class="flex items-center gap-2 flex-wrap">')) {
                $clusterStart = $i;
                break;
            }
        }
        if ($barClose === null || $clusterStart === null) {
            return null;
        }
        $clusterEnd = $barClose - 1; // the cluster's own </div>
        if (trim($lines[$clusterEnd]) !== '</div>') {
            return null;
        }
        $blockLines = array_slice($lines, $clusterStart + 1, $clusterEnd - $clusterStart - 1);
        // Split the cluster into named blocks.
        $blocks = [];
        $i = 0;
        $n = count($blockLines);
        $takeBalanced = function (int $from) use ($blockLines, $n): int {
            $depth = 0;
            for ($j = $from; $j < $n; $j++) {
                $l = $blockLines[$j];
                if (preg_match('/@(if|isset|foreach|for|while|section|push|once)\b/', $l)) {
                    $depth++;
                }
                if (preg_match('/@(endif|endisset|endforeach|endfor|endwhile|endsection|endpush|endonce)\b/', $l)) {
                    $depth--;
                    if ($depth === 0) {
                        return $j;
                    }
                }
            }

            return $n - 1;
        };
        while ($i < $n) {
            $l = $blockLines[$i];
            if (trim($l) === '') {
                $i++;
                continue;
            }
            if (str_contains($l, "\$can['seed']")) {
                $j = $takeBalanced($i);
                $seedBlock = array_slice($blockLines, $i, $j - $i + 1);
                $seedBlock[0] = str_replace(
                    "@if((\$can['seed'] ?? true) && Route::has('laraslice.wizard.seed_slice'))",
                    "@if((\$demoSeed ?? false) && (\$can['seed'] ?? true) && Route::has('laraslice.wizard.seed_slice'))",
                    $seedBlock[0]
                );
                $blocks['seed'] = $seedBlock;
                $i = $j + 1;
            } elseif (str_contains($l, 'window.dispatchEvent')) {
                $j = $i;
                while ($j < $n && ! str_contains($blockLines[$j], '</button>')) {
                    $j++;
                }
                $blocks['export'] = array_slice($blockLines, $i, $j - $i + 1);
                $i = $j + 1;
            } elseif (str_contains($l, "@include('laraslice::directory.actions')")) {
                $blocks['actions'] = [$l];
                $i++;
            } elseif (preg_match('/@isset\(\$(reportUrl|matrixUrl|importUrl|bulkEntryUrl)\)/', $l, $m)) {
                $j = $takeBalanced($i);
                $blocks[strtolower(str_replace('Url', '', $m[1]))] = array_slice($blockLines, $i, $j - $i + 1);
                $i = $j + 1;
            } elseif (str_contains($l, "\$can['create']")) {
                $j = $takeBalanced($i);
                $blocks['add'] = array_slice($blockLines, $i, $j - $i + 1);
                $i = $j + 1;
            } else {
                // Unknown segment: keep it with the actions group
                // so nothing the page had is lost.
                $blocks['actions'][] = $l;
                $i++;
            }
        }
        if (! isset($blocks['add']) && ! isset($blocks['export'])) {
            return null;
        }
        $ordered = [];
        foreach (['add', 'actions', 'import', 'bulk', 'report', 'matrix', 'export', 'seed'] as $k) {
            if (! empty($blocks[$k])) {
                $ordered = array_merge($ordered, $blocks[$k]);
            }
        }
        $slot = array_merge(
            ['        <x-slot:below-toolbar>'],
            $ordered,
            ['        </x-slot:below-toolbar>']
        );
        // Rebuild the page top: title lines up to the cluster,
        // then the top bar close; drop the badge line.
        $head = [];
        for ($i = $barStart; $i < $clusterStart; $i++) {
            if (str_contains($lines[$i], 'font-mono text-[10px]')) {
                continue;
            }
            $head[] = $lines[$i];
        }
        while ($head !== [] && trim(end($head)) === '') {
            array_pop($head);
        }
        $tail = array_slice($lines, $barClose + 1);
        $lines = array_merge(array_slice($lines, 0, $barStart), $head, ['    </div>', ''], $tail);
        // Insert the slot into the data-table invocation: after
        // the table-actions slot when present, else after the
        // quick-filters slot, else after the invocation tag.
        $dtIdx = null;
        $gtIdx = null;
        foreach ($lines as $i => $line) {
            if ($dtIdx === null && str_contains($line, '<x-ui.data-table')) {
                $dtIdx = $i;
                continue;
            }
            if ($dtIdx !== null && str_ends_with(rtrim($line), '>')) {
                $gtIdx = $i;
                break;
            }
        }
        if ($gtIdx === null) {
            return null;
        }
        $insertAt = $gtIdx;
        for ($i = $gtIdx + 1; $i < min(count($lines), $gtIdx + 40); $i++) {
            if (str_contains($lines[$i], '</x-slot:table-actions>') || str_contains($lines[$i], '</x-slot:quick-filters>')) {
                $insertAt = $i;
                if (isset($lines[$i + 1]) && trim($lines[$i + 1]) === '@endif') {
                    $insertAt = $i + 1;
                }
            }
            if (str_contains($lines[$i], '</x-ui.data-table>')) {
                break;
            }
        }
        array_splice($lines, $insertAt + 1, 0, $slot);

        return implode("\n", $lines);
    }

    public function clearCache(): bool
    {
        // clearCache() is the invalidation signal: every mutation
        // flow calls it after writing, so the next discover()
        // must rescan instead of returning the memoized pass.
        $this->discovered = false;
        $this->manifestDataCache = null;
        $dataPath = $this->getManifestDataCachePath();
        if (file_exists($dataPath)) {
            @unlink($dataPath);
        }
        $cachePath = $this->getCachedSlicesPath();
        if (file_exists($cachePath)) {
            return @unlink($cachePath);
        }

        return true;
    }


    /**
     * Register the G2 declared routes (document, matrix) for a slice.
     * Silent no-op when the slice declares neither, its routes file
     * cannot be parsed, or its controller class does not exist.
     */
    protected function registerDeclaredRoutes(SliceManifest $slice): void
    {
        $document = is_array($slice->raw['ui']['show']['document'] ?? null);
        $matrix = is_array($slice->raw['ui']['directory']['matrix'] ?? null);
        $report = is_array($slice->raw['ui']['directory']['report'] ?? null);
        $namedReports = [];
        foreach (($slice->raw['ui']['directory']['reports'] ?? []) as $named) {
            if (is_array($named) && ! empty($named['handle'])) {
                $namedReports[] = (string) $named['handle'];
            }
        }
        $inline = ! empty($slice->raw['ui']['directory']['inline_edit'] ?? []);
        $bulkEntry = is_array($slice->raw['ui']['directory']['bulk_entry'] ?? null);
        $timer = is_array($slice->raw['ui']['show']['timer'] ?? null);
        $punch = false;
        foreach (($slice->raw['ui']['dashboard']['widgets'] ?? []) as $widget) {
            if (is_array($widget) && ($widget['type'] ?? '') === 'clock' && ! empty($widget['punch'])) {
                $punch = true;
                break;
            }
        }
        $import = is_array($slice->raw['ui']['directory']['import'] ?? null);
        $seed = is_array($slice->raw['ui']['directory']['reference_sets'] ?? null);
        $invite = is_array($slice->raw['ui']['directory']['invite'] ?? null);
        $conversation = is_array($slice->raw['ui']['conversation'] ?? null);
        if (! $document && ! $matrix && ! $report && $namedReports === [] && ! $inline && ! $bulkEntry && ! $timer && ! $import && ! $seed && ! $invite && ! $punch && ! $conversation) {
            return;
        }
        try {
            $routesPath = $slice->getRoutesPath();
            $webFile = $routesPath ? $routesPath.'/web.php' : null;
            if (! $webFile || ! is_file($webFile)) {
                return;
            }
            $contents = (string) file_get_contents($webFile);
            if (! preg_match("/Route::prefix\('([^']+)'\)->name\('([^']+)\.'\)/", $contents, $m)) {
                return;
            }
            [, $uriPrefix, $namePrefix] = $m;
            // The controller class comes from the routes file's own
            // use statement — deriving it from the slice namespace
            // breaks on segment casing (Financialaccounting vs
            // FinancialAccounting) under PSR-4.
            if (! preg_match("/use\s+([A-Za-z0-9_\\\\]+WebController);/", $contents, $um)) {
                return;
            }
            $controllerClass = $um[1];
            if (! class_exists($controllerClass)) {
                return;
            }
            $middleware = config('laraslice.generated_routes.web_middleware', ['web', 'auth']);
            if ($document && ! Route::has($namePrefix.'.document')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/{id}/document', [$controllerClass, 'document'])
                    ->name($namePrefix.'.document');
            }
            if ($matrix && ! Route::has($namePrefix.'.matrix')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/matrix', [$controllerClass, 'matrix'])
                    ->name($namePrefix.'.matrix');
            }
            if ($conversation && ! Route::has($namePrefix.'.chat')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/chat', [$controllerClass, 'conversationPage'])
                    ->name($namePrefix.'.chat');
                Route::middleware($middleware)
                    ->post($uriPrefix.'/chat', [$controllerClass, 'conversationStart'])
                    ->name($namePrefix.'.chat.start');
                Route::middleware($middleware)
                    ->post($uriPrefix.'/chat/{id}/reply', [$controllerClass, 'conversationReply'])
                    ->name($namePrefix.'.chat.reply');
            }
            if ($report && ! Route::has($namePrefix.'.report')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/report', [$controllerClass, 'report'])
                    ->name($namePrefix.'.report');
            }
            foreach ($namedReports as $namedHandle) {
                if (! Route::has($namePrefix.'.report.'.$namedHandle)) {
                    Route::middleware($middleware)
                        ->get($uriPrefix.'/report/{reportHandle}', [$controllerClass, 'report'])
                        ->name($namePrefix.'.report.'.$namedHandle);
                }
            }
            if ($bulkEntry && ! Route::has($namePrefix.'.bulk_entry')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/bulk-entry', [$controllerClass, 'bulkEntry'])
                    ->name($namePrefix.'.bulk_entry');
                Route::middleware($middleware)
                    ->post($uriPrefix.'/bulk-entry', [$controllerClass, 'bulkEntryStore'])
                    ->name($namePrefix.'.bulk_entry_store');
            }
            if ($punch && ! Route::has($namePrefix.'.punch')) {
                Route::middleware($middleware)
                    ->post($uriPrefix.'/punch', [$controllerClass, 'punch'])
                    ->name($namePrefix.'.punch');
            }
            if ($timer && ! Route::has($namePrefix.'.timer_start')) {
                Route::middleware($middleware)
                    ->post($uriPrefix.'/{id}/timer/start', [$controllerClass, 'timerStart'])
                    ->name($namePrefix.'.timer_start');
                Route::middleware($middleware)
                    ->post($uriPrefix.'/{id}/timer/stop', [$controllerClass, 'timerStop'])
                    ->name($namePrefix.'.timer_stop');
            }
            if ($import && ! Route::has($namePrefix.'.import')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/import', [$controllerClass, 'importForm'])
                    ->name($namePrefix.'.import');
                Route::middleware($middleware)
                    ->post($uriPrefix.'/import', [$controllerClass, 'importStore'])
                    ->name($namePrefix.'.import_store');
                Route::middleware($middleware)
                    ->get($uriPrefix.'/import/template', [$controllerClass, 'importTemplate'])
                    ->name($namePrefix.'.import_template');
            }
            if ($seed && ! Route::has($namePrefix.'.seed_action')) {
                Route::middleware($middleware)
                    ->post($uriPrefix.'/actions/seed', [$controllerClass, 'seedAction'])
                    ->name($namePrefix.'.seed_action');
            }
            if ($invite && ! Route::has($namePrefix.'.invite')) {
                Route::middleware($middleware)
                    ->get($uriPrefix.'/invite', [$controllerClass, 'inviteForm'])
                    ->name($namePrefix.'.invite');
                Route::middleware($middleware)
                    ->post($uriPrefix.'/invite', [$controllerClass, 'inviteStore'])
                    ->name($namePrefix.'.invite_store');
            }
            if ($inline && ! Route::has($namePrefix.'.inline')) {
                Route::middleware($middleware)
                    ->post($uriPrefix.'/{id}/inline', [$controllerClass, 'inlineUpdate'])
                    ->name($namePrefix.'.inline');
            }
        } catch (\Throwable) {
            // Declared routes must never break slice booting.
        }
    }

    /**
     * Deterministic, collision-free view namespace for the slice at
     * the given directory: 'ls_' + the path relative to the slices
     * base, snake-cased (e.g. ERP/ERP/Work/Tasks ->
     * ls_erp_erp_work_tasks; a root slice Tasks -> ls_tasks). (G6)
     */
    public static function viewNamespaceForPath(string $sliceDir, ?string $slicesBase = null): string
    {
        $dir = str_replace('\\', '/', rtrim($sliceDir, '/'));
        $base = $slicesBase ?? (string) config('laraslice.slices_path', function_exists('app_path') ? app_path('Slices') : '');
        $base = str_replace('\\', '/', rtrim((string) $base, '/'));

        $relative = ($base !== '' && str_starts_with($dir, $base.'/'))
            ? substr($dir, strlen($base) + 1)
            : basename($dir);

        // Segment-wise slug: acronyms stay whole (ERP -> erp, not
        // e_r_p — Str::snake shreds those), StudlyCase splits on
        // lower-to-upper boundaries (EmployeeShifts -> employee_shifts).
        $parts = [];
        foreach (explode('/', $relative) as $segment) {
            $segment = preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $segment) ?? $segment;
            $segment = preg_replace('/[^a-zA-Z0-9]+/', '_', $segment) ?? $segment;
            $parts[] = strtolower(trim($segment, '_'));
        }

        return 'ls_'.implode('_', array_filter($parts));
    }

    protected function bootSlice(SliceManifest $slice): void
    {
        // 1. Register Migrations
        if ($migrationsPath = $slice->getMigrationsPath()) {
            if ($this->app->resolved('migrator')) {
                $this->app['migrator']->path($migrationsPath);
            }
            $this->app->afterResolving('migrator', function ($migrator) use ($migrationsPath) {
                $migrator->path($migrationsPath);
            });
        }

        // 2. Register Views (e.g. view('product::index') or view('products::index'))
        if ($viewsPath = $slice->getViewsPath()) {
            $snake = Str::snake($slice->name);
            $plural = Str::plural($snake);
            $singular = Str::singular($snake);

            $namespaces = array_unique([$snake, $plural, $singular, str_replace('_', '', $snake)]);
            foreach ($namespaces as $ns) {
                if ($ns) {
                    $this->app['view']->addNamespace($ns, $viewsPath);
                }
            }

            // G6: the name-variant namespaces above are SHARED by every
            // same-named slice (first hint wins), so also register a
            // namespace derived from the slice's own directory path —
            // unique per slice — which BaseSliceWebController resolves
            // when the shared namespace is shadowed by another slice.
            $this->app['view']->addNamespace(self::viewNamespaceForPath($slice->path), $viewsPath);
        }

        // 2b. Declared document/matrix routes (G2) — registered
        // BEFORE the slice's own resource routes on purpose: the
        // resource show route ({id}) would otherwise swallow the
        // /matrix segment. Name prefix + controller are derived from
        // the slice's routes file and generator conventions; the
        // declarations live in the manifest (ui.show.document,
        // ui.directory.matrix). Existing installs need no file edits.
        $this->registerDeclaredRoutes($slice);

        // 3. Register Routes (web.php and api.php)
        if ($routesPath = $slice->getRoutesPath()) {
            $webRoute = $routesPath.'/web.php';
            if (file_exists($webRoute)) {
                Route::middleware(['web'])->group($webRoute);
            }

            $apiRoute = $routesPath.'/api.php';
            if (file_exists($apiRoute)) {
                Route::prefix('api')->middleware(['api'])->group($apiRoute);
            }
        }

        // 4. Register custom SliceServiceProvider if exists
        $sliceNamespace = $slice->getNamespace();
        $providerClass = "{$sliceNamespace}\\{$slice->name}SliceServiceProvider";
        if (class_exists($providerClass)) {
            $this->app->register($providerClass);
        }
    }

    /**
     * (ag) Page Structure Contract (Body v4): dual-emit the list
     * page's actions cluster so the declared `placement` decides
     * at runtime where it renders — title row under header-right,
     * below-toolbar band otherwise. The band cluster is wrapped
     * in the !== conditional, and a header copy (Seed Demo
     * stripped: seed is band-only by contract) is inserted into
     * the title row under the === conditional.
     *
     * Conservative by design: returns null (blade untouched)
     * unless the blade has a below-toolbar slot, an h1 before
     * it, a recognisable title-row shape, and — when a Seed
     * block sits in the band — a cleanly removable one (the
     * generator's trailing demoSeed block). Idempotent: blades
     * already carrying the header-right conditional are skipped.
     */
    /**
     * (ah) Page Structure Contract (Body v4), second half: upgrade
     * a dual-emitted blade (ag) to all four placement sites. The
     * band condition tightens to === 'below-facets-left'; a
     * command-bar strip is inserted after the title row; a
     * toolbar-lead slot carrying the cluster is added inside the
     * data-table invocation. The cluster markup is reused from
     * the blade's own header-right copy (balanced-div extract),
     * so each blade keeps its own buttons/drawer variants.
     * All-or-nothing per blade: any missing anchor returns null.
     */
    private function quadEmitPlacementCluster(string $content): ?string
    {
        if (! str_contains($content, "=== 'header-right'") || str_contains($content, "=== 'command-bar'")) {
            return null; // not dual-emitted yet, or already quad
        }
        $bandCondition = "@if((\$placement ?? 'below-facets-left') !== 'header-right')";
        if (! str_contains($content, $bandCondition) || ! str_contains($content, '<x-slot:quick-filters>')) {
            return null;
        }

        // Extract the header cluster (balanced <div> scan from
        // the ml-auto wrapper the (ag)/generator emission uses).
        $headerIf = strpos($content, "@if((\$placement ?? 'below-facets-left') === 'header-right')");
        if ($headerIf === false) {
            return null;
        }
        $divOpen = strpos($content, '<div class="ml-auto flex flex-wrap items-center gap-2">', $headerIf);
        if ($divOpen === false) {
            return null;
        }
        $clusterStart = $divOpen + strlen('<div class="ml-auto flex flex-wrap items-center gap-2">');
        $depth = 1;
        $scan = $clusterStart;
        $clusterEnd = null;
        while (preg_match('/<div\b|<\/div>/', $content, $dm, PREG_OFFSET_CAPTURE, $scan)) {
            $token = $dm[0][0];
            $scan = $dm[0][1] + strlen($token);
            $depth += $token === '</div>' ? -1 : 1;
            if ($depth === 0) {
                $clusterEnd = $dm[0][1];
                break;
            }
        }
        if ($clusterEnd === null) {
            return null;
        }
        $cluster = trim(substr($content, $clusterStart, $clusterEnd - $clusterStart));
        if ($cluster === '') {
            return null;
        }

        // 1) Tighten the band condition to the exact placement.
        $content = str_replace(
            $bandCondition,
            "@if((\$placement ?? 'below-facets-left') === 'below-facets-left')",
            $content
        );

        // 2) Command-bar strip after the title row: the header
        // block's @endif, then the row's closing </div>.
        $endifPos = strpos($content, '@endif', $clusterEnd);
        if ($endifPos === false) {
            return null;
        }
        $rowClose = strpos($content, '</div>', $endifPos);
        if ($rowClose === false) {
            return null;
        }
        $insertAt = $rowClose + strlen('</div>');
        $commandBar = "\n    @if((\$placement ?? 'below-facets-left') === 'command-bar')\n"
            ."    <div class=\"flex flex-wrap items-center gap-2 rounded-xl border border-border/60 bg-muted/20 px-3 py-2\">\n"
            .$cluster."\n"
            ."    </div>\n"
            ."    @endif";
        $content = substr($content, 0, $insertAt).$commandBar.substr($content, $insertAt);

        // 3) Toolbar-lead slot inside the data-table invocation.
        $qfPos = strpos($content, '<x-slot:quick-filters>');
        $toolbarLead = "<x-slot:toolbar-lead>\n"
            ."            @if((\$placement ?? 'below-facets-left') === 'toolbar-left')\n"
            .$cluster."\n"
            ."            @endif\n"
            ."            </x-slot:toolbar-lead>\n            ";
        $content = substr($content, 0, $qfPos).$toolbarLead.substr($content, $qfPos);

        return $content;
    }

    private function dualEmitPlacementCluster(string $content): ?string
    {
        if (str_contains($content, "=== 'header-right'")) {
            return null; // already dual-emitted
        }
        $slotOpen = '<x-slot:below-toolbar>';
        $slotClose = '</x-slot:below-toolbar>';
        $openPos = strpos($content, $slotOpen);
        $closePos = $openPos === false ? false : strpos($content, $slotClose, $openPos);
        if ($openPos === false || $closePos === false) {
            return null;
        }
        $h1Pos = strpos($content, '<h1');
        if ($h1Pos === false || $h1Pos > $openPos) {
            return null;
        }

        $clusterStart = $openPos + strlen($slotOpen);
        $cluster = substr($content, $clusterStart, $closePos - $clusterStart);

        // Header copy: strip the Seed Demo block (band-only by
        // contract). Generator shape = trailing demoSeed @if.
        $headerCluster = $cluster;
        if (str_contains($headerCluster, 'Seed Demo')) {
            $seedStart = strpos($headerCluster, '@if(($demoSeed');
            if ($seedStart === false) {
                return null;
            }
            $tail = substr($headerCluster, $seedStart);
            $lastEndif = strrpos($tail, '@endif');
            if ($lastEndif === false || trim(substr($tail, $lastEndif + strlen('@endif'))) !== '') {
                return null; // seed block is not the trailing block
            }
            $headerCluster = substr($headerCluster, 0, $seedStart);
        }
        $headerCluster = trim($headerCluster);
        if ($headerCluster === '') {
            return null;
        }

        $headerBlock = "\n        @if((\$placement ?? 'below-facets-left') === 'header-right')\n"
            ."        <div class=\"ml-auto flex flex-wrap items-center gap-2\">\n"
            .$headerCluster."\n"
            ."        </div>\n"
            ."        @endif";

        // Insert into the title row: shape A (title + breadcrumb
        // in one flex row) and shape B (Compact Top Bar: inner
        // title group inside an outer row) share the breadcrumb
        // tail — the span close followed by one or two div closes.
        $pattern = '/(<span class="text-foreground">[^<]*<\/span>\s*<\/div>)(\s*<\/div>)?(\s*<\/div>)/';
        if (! preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $m = null;
        foreach ($matches[0] as $i => $whole) {
            if ($whole[1] > $h1Pos && $whole[1] < $openPos) {
                $m = [$whole, $matches[1][$i], $matches[2][$i], $matches[3][$i]];
                break;
            }
        }
        if ($m === null) {
            return null; // no title-row breadcrumb tail before the table
        }
        $insertAt = $m[0][1] + strlen($m[1][0]) + strlen($m[2][0] ?? '');
        // Shape A keeps its row close after the block; shape B's
        // second close belongs to the inner group and stays
        // before it. Both read: closes-so-far + block + final close.
        $content = substr($content, 0, $insertAt).$headerBlock.substr($content, $insertAt);

        // Wrap the band cluster in the !== conditional.
        $openPos = strpos($content, $slotOpen);
        $closePos = strpos($content, $slotClose, $openPos);
        $content = substr($content, 0, $openPos + strlen($slotOpen))
            ."\n            @if((\$placement ?? 'below-facets-left') !== 'header-right')"
            .substr($content, $openPos + strlen($slotOpen), $closePos - ($openPos + strlen($slotOpen)))
            ."\n            @endif"
            .substr($content, $closePos);

        return $content;
    }

    /**
     * Rewrite files of slices generated by older LaraSlice versions to the current layout
     * (view widths, embedded child panels, child services/controllers, domain-prefixed routes).
     *
     * Runs only from `php artisan slice:repair`; booting a slice never writes files.
     *
     * @return array<int, string> files that were (or, in a dry run, would be) changed
     */
    public function repairSlice(SliceManifest $slice, bool $dryRun = false): array
    {
        $this->repairedFiles = [];
        $this->repairDryRun = $dryRun;

        // Files inside the package (vendor/) are never rewritten
        $packageSlices = realpath(dirname(__DIR__, 2).'/Slices');
        if ($packageSlices !== false && str_starts_with((string) realpath($slice->path), $packageSlices)) {
            return [];
        }

        // ERP-shell slice? The routes file's URI prefix decides,
        // the same evidence the layout's ERP test uses (its first
        // segment): erp / hr / crm. Manifest fields are unreliable
        // here — older slices declare neither app nor a nav URL.
        $isErpSlice = false;
        $routesWeb = $slice->getRoutesPath() ? $slice->getRoutesPath().'/web.php' : null;
        if ($routesWeb && is_file($routesWeb)
            && preg_match("/Route::prefix\('([^']+)'\)/", (string) file_get_contents($routesWeb), $pm)) {
            $isErpSlice = in_array(explode('/', $pm[1])[0] ?? '', ['erp', 'hr', 'crm'], true);
        }

        $viewsPath = $slice->getViewsPath();
        if ($viewsPath) {
            // Auto-heal view layout widths
            try {
                $viewFiles = glob($viewsPath.'/*.blade.php') ?: [];
                $subDirs = glob($viewsPath.'/*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $sub) {
                    $viewFiles = array_merge($viewFiles, glob($sub.'/*.blade.php') ?: []);
                }
                foreach ($viewFiles as $vf) {
                    $vContent = file_get_contents($vf);
                    $vChanged = false;
                    if (str_contains($vContent, 'max-w-2xl mx-auto space-y-6') || str_contains($vContent, 'max-w-3xl mx-auto space-y-6')) {
                        $vContent = str_replace(
                            ['max-w-2xl mx-auto space-y-6', 'max-w-3xl mx-auto space-y-6'],
                            'w-full max-w-5xl mx-auto space-y-6',
                            $vContent
                        );
                        $vChanged = true;
                    }

                    // Template-upgrade pass: bring blades generated by
                    // older templates up to the current hooks. Each
                    // upgrade is marker-guarded (idempotent) and only
                    // fires when its anchor exists.
                    $base = basename($vf);
                    if (in_array($base, ['create.blade.php', 'edit.blade.php', 'form.blade.php'], true)) {
                        // (a) Validation error banner — without it every
                        // validation failure bounced silently.
                        if (! str_contains($vContent, '$errors->any()') && str_contains($vContent, '<form ')) {
                            $banner = "    @if (\$errors->any())\n"
                                ."        <div class=\"p-4 rounded-xl border border-destructive/30 bg-destructive/10 text-destructive text-sm shadow-xs\" role=\"alert\">\n"
                                ."            <div class=\"font-semibold flex items-center gap-2\">\n"
                                ."                <x-lucide-alert-circle class=\"size-4 shrink-0\" />\n"
                                ."                <span>Please fix the following:</span>\n"
                                ."            </div>\n"
                                ."            <ul class=\"list-disc pl-9 mt-1 space-y-0.5\">\n"
                                ."                @foreach (\$errors->all() as \$error)\n"
                                ."                    <li>{{ \$error }}</li>\n"
                                ."                @endforeach\n"
                                ."            </ul>\n"
                                ."        </div>\n"
                                ."    @endif\n\n    ";
                            $pos = strpos($vContent, '<form ');
                            $vContent = substr($vContent, 0, $pos).$banner.substr($vContent, $pos);
                            $vChanged = true;
                        }
                        // (b) Overlay form markers (S-UI 3): section grids
                        // collapse to one column inside drawers/modals
                        // only when they carry data-ls-section-grid.
                        if (! str_contains($vContent, 'data-ls-section-grid')) {
                            $marked = preg_replace('/(<div class="grid [^"]*grid-cols-[^"]*")>/', '$1 data-ls-section-grid>', $vContent);
                            if ($marked !== null && $marked !== $vContent) {
                                $vContent = $marked;
                                $vChanged = true;
                            }
                        }
                        if (! str_contains($vContent, 'data-ls-form-root') && str_contains($vContent, '<div class="w-full max-w-5xl mx-auto space-y-6">')) {
                            $vContent = str_replace(
                                '<div class="w-full max-w-5xl mx-auto space-y-6">',
                                '<div class="w-full max-w-5xl mx-auto space-y-6" data-ls-form-root>',
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'show.blade.php' && str_contains($vContent, "x-show=\"activeTab === 'overview'\"")) {
                        // (c) Timer include + declared-stats strip inside
                        // the overview panel.
                        $inject = '';
                        if (! str_contains($vContent, 'timer-card')) {
                            $inject .= "\n    @include('laraslice::partials.timer-card')";
                        }
                        if (! str_contains($vContent, 'showStats')) {
                            $inject .= "\n    <!-- KPI Metric Strip — declared ui.show.stats (F3c) -->\n"
                                ."    @if(!empty(\$showStats ?? []))\n"
                                ."    <div class=\"grid grid-cols-2 sm:grid-cols-4 gap-4\">\n"
                                ."        @foreach(\$showStats as \$stat)\n"
                                ."        <x-ui.card class=\"p-4 bg-card border shadow-xs\">\n"
                                ."            <span class=\"text-[11px] font-semibold text-muted-foreground uppercase tracking-wider block\">{{ \$stat['label'] }}</span>\n"
                                ."            <span class=\"text-xl font-semibold text-foreground mt-1 block\">{{ \$stat['value'] }}</span>\n"
                                ."        </x-ui.card>\n"
                                ."        @endforeach\n"
                                ."    </div>\n"
                                ."    @endif";
                        }
                        if ($inject !== '') {
                            $lines = explode("\n", $vContent);
                            foreach ($lines as $i => $line) {
                                if (str_contains($line, "x-show=\"activeTab === 'overview'\"")) {
                                    array_splice($lines, $i + 1, 0, [$inject]);
                                    break;
                                }
                            }
                            $vContent = implode("\n", $lines);
                            $vChanged = true;
                        }
                    }
                    // (u) List contract v3 wiring: existing
                    // index blades predate the density + inline
                    // bindings — without them the data table
                    // renders 'comfortable' and the declared
                    // inline editor never receives its endpoint.
                    if (str_contains($vContent, '<x-ui.data-table') && ! str_contains($vContent, 'density')) {
                        $wired = preg_replace_callback(
                            '/<x-ui\.data-table\b[^>]*>/s',
                            static function (array $m): string {
                                if (str_contains($m[0], 'density')) {
                                    return $m[0];
                                }
                                $tag = rtrim(substr($m[0], 0, -1));
                                if (! str_contains($tag, 'inline-update-url')) {
                                    $tag .= ' :inline-update-url="$inlineUpdateUrl ?? null"';
                                }
                                $tag .= ' :density="$density ?? \'compact\'"';

                                return $tag.' >';
                            },
                            $vContent,
                            1
                        );
                        if (is_string($wired) && $wired !== $vContent) {
                            $vContent = $wired;
                            $vChanged = true;
                        }
                    }
                    // (v) The top-bar Export button shipped
                    // dead (no handler). The data table listens
                    // for the window 'export-table' event — wire
                    // the button to dispatch it.
                    if (str_contains($vContent, '<span>Export</span>') && ! str_contains($vContent, 'export-table')) {
                        $exported = preg_replace(
                            '/<button type="button" class="([^"]*shadow-2xs transition-colors)">(\s*<x-lucide-download)/',
                            '<button type="button" onclick="window.dispatchEvent(new CustomEvent(\'export-table\'))" class="$1">$2',
                            $vContent,
                            1
                        );
                        if (is_string($exported) && $exported !== $vContent) {
                            $vContent = $exported;
                            $vChanged = true;
                        }
                    }
                    // (x) Quick filters render inside the data
                    // table toolbar slot (list contract v3), not
                    // on a stacked row of their own.
                    if ($base === 'index.blade.php' && str_contains($vContent, 'x-ui.filter-tabs') && ! str_contains($vContent, 'x-slot:quick-filters')) {
                        $slottedQf = $this->slotQuickFilters($vContent);
                        if ($slottedQf !== null) {
                            $vContent = $slottedQf;
                            $vChanged = true;
                        }
                    }
                    // (aa) Facet selects (list contract v3): a
                    // quick filter declared display: select renders
                    // as a labeled dropdown in the toolbar, not as
                    // tabs — the loop body gains the branch.
                    if ($base === 'index.blade.php' && str_contains($vContent, 'x-slot:quick-filters') && ! str_contains($vContent, "'display'] ?? 'tabs'")) {
                        $oldLoop = <<<'BLADE'
            @foreach($quickFilters as $qf)
            <x-ui.filter-tabs
                :tabs="$qf['options'] ?? []"
                :param="$qf['handle']"
                :activeTab="$activeFilters[$qf['handle']] ?? null"
                :default="$filterDefaults[$qf['handle']] ?? null"
            />
            @endforeach
BLADE;
                        $newLoop = <<<'BLADE'
            @foreach($quickFilters as $qf)
            @if(($qf['display'] ?? 'tabs') === 'select')
            <label class="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                <span class="font-medium">{{ $qf['label'] ?? $qf['handle'] }}</span>
                <select onchange="const u = new window.URL(window.location.href); if (this.value) { u.searchParams.set('{{ $qf['handle'] }}', this.value); } else { u.searchParams.delete('{{ $qf['handle'] }}'); } window.location.href = u.toString();" class="h-8 rounded-lg border border-border bg-background px-2 text-xs text-foreground cursor-pointer">
                    <option value="">All</option>
                    @foreach($qf['options'] ?? [] as $opt)
                    <option value="{{ $opt['value'] }}" @selected((string) ($activeFilters[$qf['handle']] ?? '') === (string) $opt['value'])>{{ $opt['label'] }}</option>
                    @endforeach
                </select>
            </label>
            @else
            <x-ui.filter-tabs
                :tabs="$qf['options'] ?? []"
                :param="$qf['handle']"
                :activeTab="$activeFilters[$qf['handle']] ?? null"
                :default="$filterDefaults[$qf['handle']] ?? null"
            />
            @endif
            @endforeach
BLADE;
                        if (str_contains($vContent, $oldLoop)) {
                            $vContent = str_replace($oldLoop, $newLoop, $vContent);
                            $vChanged = true;
                        }
                    }
                    // (ab) Facet-select handler scope fix: in
                    // an inline handler, bare `URL` resolves to
                    // document.URL (a string) — window.URL is the
                    // constructor. (The (aa) loop shipped the
                    // bare form.)
                    if ($base === 'index.blade.php' && str_contains($vContent, 'const u = new URL(location.href)')) {
                        $vContent = str_replace(
                            'const u = new URL(location.href); if (this.value) { u.searchParams.set(',
                            'const u = new window.URL(window.location.href); if (this.value) { u.searchParams.set(',
                            $vContent
                        );
                        $vContent = str_replace(
                            "'); } location.href = u.toString();",
                            "'); } window.location.href = u.toString();",
                            $vContent
                        );
                        $vChanged = true;
                    }
                    // (y) Filters button in the quick-filters
                    // slot (the strip under the topbar; revised
                    // 25 Oct 2026): the drawer's standalone
                    // trigger row yields to it.
                    if ($base === 'index.blade.php' && str_contains($vContent, 'filters-drawer')) {
                        $slottedTa = $this->slotTableActions($vContent);
                        if ($slottedTa !== null) {
                            $vContent = $slottedTa;
                            $vChanged = true;
                        }
                    }
                    // (z) The slots guard must admit
                    // sidebar-only slices: blades generated
                    // before the widening wrap toolbar-lead +
                    // quick-filters in @if(!empty($quickFilters)),
                    // which silently drops the Filters button
                    // (and the whole slot block) on slices whose
                    // filters live only in the drawer — the
                    // drawer's fallback trigger then renders in
                    // the wrong row (caught on Attendances,
                    // 25 Oct 2026).
                    if ($base === 'index.blade.php' && str_contains($vContent, 'data-ls-filters-btn')) {
                        $widened = $this->widenQuickFilterGuard($vContent);
                        if ($widened !== null) {
                            $vContent = $widened;
                            $vChanged = true;
                        }
                    }
                    // (aa) Retire the in-page title row on
                    // ERP slices: the shell topbar carries the
                    // title + breadcrumb there now (gate
                    // computed at the top of repairSlice from
                    // the routes prefix).
                    if ($base === 'index.blade.php' && $isErpSlice) {
                        $retired = $this->retireInPageTitleRow($vContent);
                        if ($retired !== null) {
                            $vContent = $retired;
                            $vChanged = true;
                        }
                    }
                    // (ai) Conditional band rhythm: the
                    // section wrapper's space-y only when
                    // KPI cards render above the table.
                    if ($base === 'index.blade.php') {
                        $rhythmed = $this->conditionalIndexRhythm($vContent);
                        if ($rhythmed !== null) {
                            $vContent = $rhythmed;
                            $vChanged = true;
                        }
                        // (aj) Empty panel only when unfiltered.
                        $scoped = $this->scopeEmptyPanelToUnfiltered($vContent);
                        if ($scoped !== null) {
                            $vContent = $scoped;
                            $vChanged = true;
                        }
                    }
                    // (ah) Unghost the legacy Compact Top
                    // Bar: move its placement @if outside the
                    // wrapper div so non-header-right pages
                    // stop rendering an empty bordered sliver
                    // above the table.
                    // (al) Declared actions + Import survive
                    // the first-run empty panel.
                    if ($base === 'index.blade.php') {
                        $paneled = $this->emptyPanelActions($vContent);
                        if ($paneled !== null) {
                            $vContent = $paneled;
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php' && str_contains($vContent, 'Compact Top Bar')) {
                        $unghosted = $this->unghostCompactTopBar($vContent);
                        if ($unghosted !== null) {
                            $vContent = $unghosted;
                            $vChanged = true;
                        }
                    }
                    // (af) + (ag) Show blades: restore the
                    // ownership card's data guard, and point
                    // the $label line at recordLabel().
                    if ($base === 'show.blade.php') {
                        $guarded = $this->guardOwnershipCard($vContent);
                        if ($guarded !== null) {
                            $vContent = $guarded;
                            $vChanged = true;
                        }
                        $labeled = $this->useRecordLabelHelper($vContent);
                        if ($labeled !== null) {
                            $vContent = $labeled;
                            $vChanged = true;
                        }
                        // (ak) Widget placements: main grid +
                        // right-rail loops on show pages.
                        $placed = $this->splitShowWidgetPlacements($vContent);
                        if ($placed !== null) {
                            $vContent = $placed;
                            $vChanged = true;
                        }
                    }
                    // (ac) Page-top composition (list contract
                    // v3): title-only top bar; actions move to
                    // the data table's below-toolbar band.
                    // (al) Declared actions + Import survive
                    // the first-run empty panel.
                    if ($base === 'index.blade.php') {
                        $paneled = $this->emptyPanelActions($vContent);
                        if ($paneled !== null) {
                            $vContent = $paneled;
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php' && str_contains($vContent, 'Compact Top Bar')) {
                        $relocated = $this->relocateDirectoryActions($vContent);
                        if ($relocated !== null) {
                            $vContent = $relocated;
                            $vChanged = true;
                        }
                    }
                    // (ae) Columns menu becomes a declared
                    // directory option (columns_menu).
                    if ($base === 'index.blade.php' && str_contains($vContent, ":density=\"\$density ?? 'compact'\"") && ! str_contains($vContent, 'columns-menu')) {
                        $vContent = str_replace(
                            ":density=\"\$density ?? 'compact'\"",
                            ":density=\"\$density ?? 'compact'\"\n            :columns-menu=\"\$columnsMenu ?? true\"",
                            $vContent
                        );
                        $vChanged = true;
                    }
                    // (af) Cleanup for (ac)'s first run: it
                    // emitted the top-bar close twice. Remove the
                    // duplicate when the exact sequence is present.
                    if ($base === 'index.blade.php' && str_contains($vContent, 'x-slot:below-toolbar')) {
                        $dupPattern = "    </div>\n\n    </div>\n\n    @if (session('success'))";
                        if (str_contains($vContent, $dupPattern)) {
                            $vContent = str_replace($dupPattern, "    </div>\n\n    @if (session('success'))", $vContent);
                            $vChanged = true;
                        }
                    }
                    // (ag) Page Structure Contract (Body v4):
                    // dual-emit the actions cluster so the
                    // declared list placement decides at runtime
                    // where it renders (title row vs band).
                    if ($base === 'index.blade.php' && str_contains($vContent, 'x-slot:below-toolbar')) {
                        $dual = $this->dualEmitPlacementCluster($vContent);
                        if ($dual !== null) {
                            $vContent = $dual;
                            $vChanged = true;
                        }
                        // (ah) …then upgrade dual emission to
                        // all four placement sites.
                        $quad = $this->quadEmitPlacementCluster($vContent);
                        if ($quad !== null) {
                            $vContent = $quad;
                            $vChanged = true;
                        }
                    }
                    // (w) Chrome consolidation (list contract v3):
                    // declared directory actions (Invite etc.) belong
                    // in the top bar's action cluster — one actions
                    // row like the source systems — not on a row of
                    // their own; and the page root spacing tightens.
                    if ($base === 'index.blade.php' && str_contains($vContent, "@include('laraslice::directory.actions')")) {
                        $lines = explode("\n", $vContent);
                        $incIdx = null;
                        $exportIdx = null;
                        $createIdx = null;
                        foreach ($lines as $i => $line) {
                            if (str_contains($line, "@include('laraslice::directory.actions')")) {
                                $incIdx = $i;
                            }
                            if (str_contains($line, '<span>Export</span>')) {
                                $exportIdx = $i;
                            }
                            if ($createIdx === null && str_contains($line, "@if(\$can['create']")) {
                                $createIdx = $i;
                            }
                        }
                        // Only relocate when the include still sits
                        // below the top bar (after the Export button).
                        if ($incIdx !== null && $exportIdx !== null && $createIdx !== null && $incIdx > $exportIdx) {
                            $incLine = $lines[$incIdx];
                            array_splice($lines, $incIdx, 1);
                            if ($incIdx < $createIdx) {
                                $createIdx--;
                            }
                            array_splice($lines, $createIdx, 0, [$incLine]);
                            $vContent = implode("\n", $lines);
                            $vChanged = true;
                        }
                        if (str_contains($vContent, '<div class="space-y-3">')) {
                            $vContent = str_replace('<div class="space-y-3">', '<div class="space-y-2">', $vContent);
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (e) Controller-preference for directory view
                        // settings: old blades recompute tableViews /
                        // defaultView / inlineEdit from a manifest file
                        // read whose relative path silently misses in
                        // compiled views, clobbering the values the
                        // controller passes from the real manifest —
                        // declared views and inline editing vanished.
                        $prefer = [
                            '$tableViews = $dirUi[\'views\'] ?? [\'table\'];' => '$tableViews = $tableViews ?? $dirUi[\'views\'] ?? [\'table\'];',
                            '$defaultView = $dirUi[\'default_view\'] ?? \'table\';' => '$defaultView = $defaultView ?? $dirUi[\'default_view\'] ?? \'table\';',
                            '$inlineEdit = $dirUi[\'inline_edit\'] ?? [];' => '$inlineEdit = $inlineEdit ?? $dirUi[\'inline_edit\'] ?? [];',
                        ];
                        foreach ($prefer as $stale => $current) {
                            if (str_contains($vContent, $stale)) {
                                $vContent = str_replace($stale, $current, $vContent);
                                $vChanged = true;
                            }
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (f) Fidelity defaults: quick-filter tabs need
                        // the active value + declared default from the
                        // controller, or a default filter applies
                        // invisibly and the All tab cannot clear it.
                        if (str_contains($vContent, '<x-ui.filter-tabs') && ! str_contains($vContent, 'filterDefaults')) {
                            $vContent = str_replace(
                                ':param="$qf[\'handle\']"',
                                ':param="$qf[\'handle\']"' . "\n" . '                :activeTab="$activeFilters[$qf[\'handle\']] ?? null"' . "\n" . '                :default="$filterDefaults[$qf[\'handle\']] ?? null"',
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (g) Fidelity actions: blades predating the
                        // declared-actions include never render
                        // ui.directory.actions buttons.
                        if (! str_contains($vContent, 'directory.actions') && str_contains($vContent, 'Quick Filter Tabs')) {
                            $vContent = str_replace(
                                '<!-- Quick Filter Tabs',
                                "@include('laraslice::directory.actions')\n\n        <!-- Quick Filter Tabs",
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (h) Fidelity: criteria-only filters
                        // (ui: false — relation-exists declarations)
                        // must not render an empty drawer select.
                        if (str_contains($vContent, '@foreach($sidebarFilters as $sf)') && ! str_contains($vContent, "sf['ui']")) {
                            $vContent = str_replace(
                                '@foreach($sidebarFilters as $sf)',
                                "@foreach(\$sidebarFilters as \$sf)\n                @if((\$sf['ui'] ?? true) === false) @continue @endif",
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (j) Fidelity: a calendar-default list must
                        // show its (possibly empty) month grid, not
                        // the empty state — the source calendar page
                        // renders with zero records.
                        if (str_contains($vContent, '@if (count($rows) === 0)') && ! str_contains($vContent, "?? 'table') !== 'calendar'")) {
                            $vContent = str_replace(
                                '@if (count($rows) === 0)',
                                "@if (count(\$rows) === 0 && (\$defaultView ?? 'table') !== 'calendar')",
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (k) Fidelity: the bulk-entry toolbar button
                        // takes the slice's declared title (Mark
                        // Attendance), not the generic label.
                        if (str_contains($vContent, '<span>Bulk Entry</span>')) {
                            $vContent = str_replace(
                                '<span>Bulk Entry</span>',
                                '<span>{{ $bulkEntryLabel ?? \'Bulk Entry\' }}</span>',
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (l) P13: the declared Import button joins
                        // the toolbar next to bulk entry.
                        if (! str_contains($vContent, '$importUrl') && str_contains($vContent, '@isset($bulkEntryUrl)')) {
                            $vContent = str_replace(
                                '@isset($bulkEntryUrl)',
                                "@isset(\$importUrl)\n                        <x-ui.button href=\"{{ \$importUrl }}\" as=\"a\" variant=\"secondary\" class=\"border border-border\">\n                            <x-lucide-upload class=\"size-4\" /> <span>Import</span>\n                        </x-ui.button>\n                        @endisset\n                        @isset(\$bulkEntryUrl)",
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (m) The declared-filters drawer is a
                        // package partial now; blades from older
                        // generations that never composed it get
                        // the include before the data table.
                        if (! str_contains($vContent, 'filters-drawer')
                            && ! str_contains($vContent, 'Declared Filters (ui.directory.sidebar_filters)')
                            && str_contains($vContent, '<x-ui.data-table')) {
                            $vContent = preg_replace(
                                '/<x-ui\.data-table/',
                                "@include('laraslice::directory.filters-drawer')\n        <x-ui.data-table",
                                $vContent,
                                1
                            );
                            $vChanged = true;
                        }
                        // (n) Strip-placed declared ranges render
                        // beside the directory actions.
                        if (! str_contains($vContent, 'strip-filters') && str_contains($vContent, "@include('laraslice::directory.actions')")) {
                            $vContent = str_replace(
                                "@include('laraslice::directory.actions')",
                                "@include('laraslice::directory.actions')\n        @include('laraslice::directory.strip-filters')",
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (o) Fidelity: a declared
                        // ui.directory.create_label replaces the
                        // generated 'Add <Slice>' strings (sources
                        // vary: 'Create Estimate', 'Add Payment').
                        try {
                            $manifestData = ManifestRepository::read($slice->path.'/slice.yaml');
                            $createLabel = $manifestData['ui']['directory']['create_label'] ?? null;
                            if (is_string($createLabel) && $createLabel !== '') {
                                $generated = 'Add '.\Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($slice->name));
                                if ($generated !== $createLabel && str_contains($vContent, $generated)) {
                                    // Idempotency (fixed 25 Oct
                                    // 2026): when the declared
                                    // label CONTAINS the generated
                                    // string ('Add Lead Contact'
                                    // vs 'Add Lead'), a plain
                                    // str_replace re-matches its
                                    // own output and appends the
                                    // suffix on EVERY run. Shield
                                    // already-correct occurrences,
                                    // replace the rest, unshield.
                                    $shield = "\u{0001}LSLABEL\u{0001}";
                                    $relabeled = str_replace(
                                        $shield,
                                        $createLabel,
                                        str_replace(
                                            $generated,
                                            $createLabel,
                                            str_replace($createLabel, $shield, $vContent)
                                        )
                                    );
                                    if ($relabeled !== $vContent) {
                                        $vContent = $relabeled;
                                        $vChanged = true;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {
                            // Manifest unreadable — leave the blade.
                        }
                    }
                    if ($base === 'index.blade.php') {
                        // (p) Summary-card values resolve by the
                        // card's key when one is declared (several
                        // cards may share a column).
                        $summaryNeedle = '$summaryData[$card[\'handle\']]';
                        if (str_contains($vContent, $summaryNeedle)) {
                            $vContent = str_replace(
                                $summaryNeedle,
                                '$summaryData[$card[\'key\'] ?? $card[\'handle\']]',
                                $vContent
                            );
                            $vChanged = true;
                        }
                        $summaryNeedle2 = '$summaryData[$card[\'handle\'] ?? \'\']';
                        if (str_contains($vContent, $summaryNeedle2)) {
                            $vContent = str_replace(
                                $summaryNeedle2,
                                '$summaryData[$card[\'key\'] ?? $card[\'handle\'] ?? \'\']',
                                $vContent
                            );
                            $vChanged = true;
                        }
                    }
                    if ($base === 'show.blade.php') {
                        // (q) Show-page identity: records whose
                        // display name lives in full_name (people)
                        // or heading/subject fall back to it before
                        // the generic '<Slice> #id'.
                        $labelNeedle = '$record->title ?? $record->name ??';
                        if (str_contains($vContent, $labelNeedle) && ! str_contains($vContent, '$record->full_name')) {
                            $vContent = str_replace(
                                $labelNeedle,
                                '$record->title ?? $record->name ?? $record->full_name ?? $record->heading ?? $record->subject ??',
                                $vContent
                            );
                            $vChanged = true;
                        }
                        // (r) H1 hub tabs: wrap the legacy ID/Summary/
                        // Status skeleton block in the enriched branch —
                        // when DirectoryUi resolved the related slice's
                        // declared columns + rows, render those through
                        // the shared partial; the skeleton remains only
                        // as the unresolved fallback.
                        if (str_contains($vContent, '>Summary</th>') && ! str_contains($vContent, 'related-tab-table')) {
                            $wrapped = preg_replace_callback(
                                '/@php\s*\n\s*\$relName = \$rtab\[\'relation\'\].*?@endif/s',
                                static function (array $m): string {
                                    return '@if(!empty($rtab[\'columns\']))'."\n"
                                        .'                @if(count($rtab[\'rows\'] ?? []) === 0)'."\n"
                                        .'                    <div class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">'."\n"
                                        .'                        No related records yet.'."\n"
                                        .'                    </div>'."\n"
                                        .'                @else'."\n"
                                        .'                    @include(\'laraslice::partials.related-tab-table\', [\'rtab\' => $rtab])'."\n"
                                        .'                @endif'."\n"
                                        .'            @else'."\n"
                                        .'            '.$m[0]."\n"
                                        .'            @endif';
                                },
                                $vContent,
                                1
                            );
                            if ($wrapped !== null && $wrapped !== $vContent) {
                                $vContent = $wrapped;
                                $vChanged = true;
                            }
                        }
                        // (s) H3 detail composition: old show blades
                        // render the parameters card from RAW record
                        // attributes (system columns and all). Give
                        // them the detailRows-first structure the
                        // current generator emits, plus the declared
                        // detail title.
                        if (str_contains($vContent, 'getAttributes()') && ! str_contains($vContent, 'detailRows')) {
                            $sliceNameEsc = str_replace("'", "\\'", (string) $slice->name);
                            $wrapped = preg_replace_callback(
                                '/@foreach\(\(\$record->getAttributes\(\).*?@endforeach/s',
                                static function (array $m) use ($sliceNameEsc): string {
                                    return '@php $detailRows = \\LaraSlice\\Support\\DirectoryUi::detailRows(\''.$sliceNameEsc.'\', $record); @endphp'."\n"
                                        .'                            @if(! empty($detailRows))'."\n"
                                        .'                                @foreach($detailRows as $detailRow)'."\n"
                                        .'                                    <div>'."\n"
                                        .'                                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ $detailRow[\'label\'] }}</dt>'."\n"
                                        .'                                        <dd class="mt-1 text-sm font-medium text-foreground whitespace-pre-wrap break-words">@if($detailRow[\'html\']){!! $detailRow[\'value\'] !!}@else{{ $detailRow[\'value\'] }}@endif</dd>'."\n"
                                        .'                                    </div>'."\n"
                                        .'                                @endforeach'."\n"
                                        .'                            @else'."\n"
                                        .'                            '.$m[0]."\n"
                                        .'                            @endif';
                                },
                                $vContent,
                                1
                            );
                            if ($wrapped !== null && $wrapped !== $vContent) {
                                $vContent = $wrapped;
                                $vChanged = true;
                            }
                            $titled = str_replace(
                                '<span>Attribute Parameters</span>',
                                '<span>{{ \\LaraSlice\\Support\\DirectoryUi::detailTitle(\''.$sliceNameEsc.'\') }}</span>',
                                $vContent
                            );
                            if ($titled !== $vContent) {
                                $vContent = $titled;
                                $vChanged = true;
                            }
                        }
                        // (z) Identity header compaction (list/
                        // detail chrome standard): slimmer card
                        // padding, avatar and tab strip.
                        if ($base === 'show.blade.php') {
                            $compacted = str_replace(
                                ['<x-ui.card class="p-6 bg-card border shadow-xs">',
                                 'size-14 rounded-2xl bg-primary/10 text-primary font-bold text-lg',
                                 '<div class="space-y-6" x-data="{ activeTab:',
                                 'px-4 py-2.5 text-[13px] font-medium border-b-2'],
                                ['<x-ui.card class="p-4 bg-card border shadow-xs">',
                                 'size-11 rounded-xl bg-primary/10 text-primary font-bold text-base',
                                 '<div class="space-y-4" x-data="{ activeTab:',
                                 'px-4 py-2 text-[13px] font-medium border-b-2'],
                                $vContent
                            );
                            if ($compacted !== $vContent) {
                                $vContent = $compacted;
                                $vChanged = true;
                            }
                        }
                        // (t) H3 identity subtitle: declared
                        // ui.show.identity_fields (designation •
                        // department for a person) win over the
                        // generic subtitle fallback chain.
                        if (str_contains($vContent, '$subtitle = $record->subtitle ??') && ! str_contains($vContent, 'identitySubtitle')) {
                            $subtitled = str_replace(
                                '$subtitle = $record->subtitle ??',
                                '$subtitle = \\LaraSlice\\Support\\DirectoryUi::identitySubtitle(\''.str_replace("'", "\\'", (string) $slice->name).'\', $record) ?? $record->subtitle ??',
                                $vContent
                            );
                            if ($subtitled !== $vContent) {
                                $vContent = $subtitled;
                                $vChanged = true;
                            }
                        }
                    }
                    if ($base === 'index.blade.php' && ! str_contains($vContent, 'matrixUrl') && str_contains($vContent, "@if(\$can['create']")) {
                        // (d) Declared-view toolbar buttons (matrix /
                        // report / bulk entry) — the controller passes
                        // the URLs; old toolbars never rendered them.
                        $buttons = "            @isset(\$reportUrl)\n"
                            ."            <x-ui.button href=\"{{ \$reportUrl }}\" as=\"a\" size=\"sm\" variant=\"outline\" class=\"gap-1.5 font-semibold\">\n"
                            ."                <x-lucide-bar-chart-3 class=\"size-3.5\" />\n"
                            ."                <span>Report</span>\n"
                            ."            </x-ui.button>\n"
                            ."            @endisset\n"
                            ."            @isset(\$matrixUrl)\n"
                            ."            <x-ui.button href=\"{{ \$matrixUrl }}\" as=\"a\" size=\"sm\" variant=\"outline\" class=\"gap-1.5 font-semibold\">\n"
                            ."                <x-lucide-grid-3x3 class=\"size-3.5\" />\n"
                            ."                <span>Matrix</span>\n"
                            ."            </x-ui.button>\n"
                            ."            @endisset\n"
                            ."            @isset(\$bulkEntryUrl)\n"
                            ."            <x-ui.button href=\"{{ \$bulkEntryUrl }}\" as=\"a\" size=\"sm\" variant=\"outline\" class=\"gap-1.5 font-semibold\">\n"
                            ."                <x-lucide-clipboard-check class=\"size-3.5\" />\n"
                            ."                <span>Bulk Entry</span>\n"
                            ."            </x-ui.button>\n"
                            ."            @endisset\n";
                        $lines = explode("\n", $vContent);
                        foreach ($lines as $i => $line) {
                            if (str_contains($line, "@if(\$can['create']")) {
                                array_splice($lines, $i, 0, [$buttons]);
                                break;
                            }
                        }
                        $vContent = implode("\n", $lines);
                        $vChanged = true;
                    }

                    // Upgrade legacy single child button to Embedded Related Records Panel
                    if (str_contains($vContent, '>Manage ') && preg_match('/@if \(!\$isNew && \\\\Illuminate\\\\Support\\\\Facades\\\\Route::has\(\'([a-zA-Z0-9_\.]+)\'\)\)[\s\S]*?<x-ui\.button[^>]*>Manage ([^<]+)<\/x-ui\.button>[\s\S]*?@endif/m', $vContent, $btnMatch)) {
                        $cRoute = $btnMatch[1];
                        $cLabel = trim($btnMatch[2]);
                        $cSingular = Str::singular($cLabel);
                        $createRoute = preg_replace('/\.index$/', '.create', $cRoute);
                        $editRoute = preg_replace('/\.index$/', '.edit', $cRoute);
                        $pLabel = Str::headline(Str::singular($slice->name));
                        $fk = Str::snake(Str::singular($slice->name)).'_id';
                        $childModel = $slice->namespace.'\\Models\\'.$cSingular;

                        $embeddedCard = "@if (!\$isNew && \\Illuminate\\Support\\Facades\\Route::has('{$cRoute}'))\n"
                            ."    @php\n"
                            ."        \$childRecords = null;\n"
                            ."        try {\n"
                            ."            if (class_exists('{$childModel}')) {\n"
                            ."                \$childRecords = \\{$childModel}::where('{$fk}', \$form->id)->latest()->take(10)->get();\n"
                            ."            }\n"
                            ."        } catch (\\Throwable \$e) {}\n"
                            ."        \$childCount = \$childRecords ? count(\$childRecords) : 0;\n"
                            ."    @endphp\n"
                            ."    <div class=\"mt-8 pt-6 border-t border-border space-y-4\">\n"
                            ."        <div class=\"flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3\">\n"
                            ."            <div class=\"flex items-center gap-2.5\">\n"
                            ."                <div class=\"size-8 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold\">\n"
                            ."                    <x-lucide-users class=\"size-4 text-primary\" />\n"
                            ."                </div>\n"
                            ."                <div>\n"
                            ."                    <h3 class=\"text-base font-semibold text-foreground flex items-center gap-2\">\n"
                            ."                        Associated {$cLabel}\n"
                            ."                        <x-ui.badge variant=\"secondary\" class=\"text-xs font-mono\">{{ \$childCount }}</x-ui.badge>\n"
                            ."                    </h3>\n"
                            ."                    <p class=\"text-xs text-muted-foreground\">Manage records directly linked to this {$pLabel}</p>\n"
                            ."                </div>\n"
                            ."            </div>\n"
                            ."            <div class=\"flex items-center gap-2\">\n"
                            ."                @if (\\Illuminate\\Support\\Facades\\Route::has('{$createRoute}'))\n"
                            ."                    <x-ui.button href=\"{{ route('{$createRoute}', ['parentId' => \$form->id]) }}\" as=\"a\" size=\"sm\" class=\"bg-primary text-primary-foreground font-semibold shadow-xs\">\n"
                            ."                        <x-lucide-plus class=\"size-3.5 mr-1\" /> Add {$cSingular}\n"
                            ."                    </x-ui.button>\n"
                            ."                @endif\n"
                            ."                <x-ui.button href=\"{{ route('{$cRoute}', ['parentId' => \$form->id]) }}\" as=\"a\" variant=\"outline\" size=\"sm\" class=\"text-xs\">\n"
                            ."                    View All <x-lucide-arrow-up-right class=\"size-3.5 ml-1\" />\n"
                            ."                </x-ui.button>\n"
                            ."            </div>\n"
                            ."        </div>\n\n"
                            ."        @if (\$childRecords && count(\$childRecords) > 0)\n"
                            ."            <div class=\"rounded-xl border border-border overflow-hidden bg-card/40\">\n"
                            ."                <table class=\"w-full text-sm text-left\">\n"
                            ."                    <thead class=\"text-xs uppercase bg-muted/50 text-muted-foreground border-b border-border\">\n"
                            ."                        <tr>\n"
                            ."                            <th class=\"px-4 py-2.5 font-medium\">Name</th>\n"
                            ."                            <th class=\"px-4 py-2.5 font-medium\">Details</th>\n"
                            ."                            <th class=\"px-4 py-2.5 font-medium text-right\">Actions</th>\n"
                            ."                        </tr>\n"
                            ."                    </thead>\n"
                            ."                    <tbody class=\"divide-y divide-border\">\n"
                            ."                        @foreach (\$childRecords as \$item)\n"
                            ."                            <tr class=\"hover:bg-muted/20 transition-colors\">\n"
                            ."                                <td class=\"px-4 py-2.5 font-medium text-foreground\">\n"
                            ."                                    {{ \$item->name ?? (\$item->first_name ? \$item->first_name . ' ' . (\$item->last_name ?? '') : (\$item->title ?? '#' . \$item->id)) }}\n"
                            ."                                </td>\n"
                            ."                                <td class=\"px-4 py-2.5 text-muted-foreground text-xs\">\n"
                            ."                                    {{ \$item->email ?? \$item->job_title ?? \$item->phone ?? \$item->status ?? '—' }}\n"
                            ."                                </td>\n"
                            ."                                <td class=\"px-4 py-2.5 text-right\">\n"
                            ."                                    @if (\\Illuminate\\Support\\Facades\\Route::has('{$editRoute}'))\n"
                            ."                                        <x-ui.button href=\"{{ route('{$editRoute}', ['parentId' => \$form->id, 'id' => \$item->id]) }}\" as=\"a\" variant=\"ghost\" size=\"sm\" class=\"size-7 p-0\">\n"
                            ."                                            <x-lucide-pencil class=\"size-3.5 text-muted-foreground\" />\n"
                            ."                                        </x-ui.button>\n"
                            ."                                    @endif\n"
                            ."                                </td>\n"
                            ."                            </tr>\n"
                            ."                        @endforeach\n"
                            ."                    </tbody>\n"
                            ."                </table>\n"
                            ."            </div>\n"
                            ."        @else\n"
                            ."            <div class=\"rounded-xl border border-dashed border-border/80 p-6 text-center bg-muted/10\">\n"
                            ."                <div class=\"flex flex-col items-center justify-center gap-1.5\">\n"
                            ."                    <x-lucide-layers class=\"size-6 text-muted-foreground/40\" />\n"
                            ."                    <p class=\"text-xs font-medium text-foreground\">No {$cLabel} linked yet</p>\n"
                            ."                    <p class=\"text-[11px] text-muted-foreground\">Add records associated with this {$pLabel}</p>\n"
                            ."                    @if (\\Illuminate\\Support\\Facades\\Route::has('{$createRoute}'))\n"
                            ."                        <x-ui.button href=\"{{ route('{$createRoute}', ['parentId' => \$form->id]) }}\" as=\"a\" size=\"sm\" variant=\"outline\" class=\"mt-2 text-xs\">\n"
                            ."                            <x-lucide-plus class=\"size-3 mr-1\" /> Add First {$cSingular}\n"
                            ."                        </x-ui.button>\n"
                            ."                    @endif\n"
                            ."                </div>\n"
                            ."            </div>\n"
                            ."        @endif\n"
                            ."    </div>\n"
                            .'@endif';

                        $vContent = str_replace($btnMatch[0], $embeddedCard, $vContent);
                        $vChanged = true;
                    }

                    if ($vChanged) {
                        $this->writeRepair($vf, $vContent);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        // Auto-heal legacy child services, controllers & views for global top-level access
        try {
            $parentPlural = Str::plural(Str::snake($slice->name));
            $parentSingular = Str::singular(Str::snake($slice->name));
            $parentStudly = Str::studly($slice->name);
            $fkDefault = $parentSingular.'_id';

            $servicesPath = $slice->getServicesPath();
            if ($servicesPath && is_dir($servicesPath)) {
                foreach (glob($servicesPath.'/*SliceService.php') ?: [] as $sFile) {
                    $sContent = file_get_contents($sFile);
                    if (str_contains($sContent, 'A parent record is required to access')) {
                        $fk = $fkDefault;
                        if (preg_match('/->where\([\'"]([a-zA-Z0-9_]+)[\'"],\s*\$this->parentId\)/', $sContent, $fkMatch)) {
                            $fk = $fkMatch[1];
                        }

                        // 1. Heal newQuery
                        $newQueryPattern = '/protected function newQuery\(\): Builder\s*\{[\s\S]*?return parent::newQuery\(\)->where\([\'"][a-zA-Z0-9_]+[\'"],\s*\$this->parentId\);\s*\}/';
                        $newQueryReplacement = "protected function newQuery(): Builder\n    {\n        \$query = parent::newQuery();\n        if (\$this->parentId !== null) {\n            \$query->where('{$fk}', \$this->parentId);\n        }\n\n        return \$query;\n    }";
                        $sContent = preg_replace($newQueryPattern, $newQueryReplacement, $sContent);

                        // 2. Heal prepareModelForSave
                        $prepPattern = '/protected function prepareModelForSave\(IBusinessObject \$form, Model \$model, bool \$isNew\): void\s*\{[\s\S]*?\$model->setAttribute\([\'"][a-zA-Z0-9_]+[\'"],\s*\$this->parentId\);\s*\}/';
                        $prepReplacement = "protected function prepareModelForSave(IBusinessObject \$form, Model \$model, bool \$isNew): void\n    {\n        if (\$this->parentId !== null) {\n            \$model->setAttribute('{$fk}', \$this->parentId);\n        } elseif (isset(\$form->{$fk}) && \$form->{$fk}) {\n            \$model->setAttribute('{$fk}', \$form->{$fk});\n        }\n    }";
                        $sContent = preg_replace($prepPattern, $prepReplacement, $sContent);

                        // 3. Heal validate
                        $valPattern = '/if \(\$this->parentId === null\) \{\s*throw new \\\?LogicException\([^\)]*\);\s*\}\s*\$data = \$form->toArray\(\);\s*\$data\[[\'"][a-zA-Z0-9_]+[\'"]\] = \$this->parentId;/';
                        $valReplacement = "\$data = \$form->toArray();\n        if (\$this->parentId !== null) {\n            \$data['{$fk}'] = \$this->parentId;\n        }";
                        $sContent = preg_replace($valPattern, $valReplacement, $sContent);

                        $this->writeRepair($sFile, $sContent);
                    }
                }
            }

            $controllersPath = $slice->getControllersPath();
            if ($controllersPath && is_dir($controllersPath)) {
                foreach (glob($controllersPath.'/*WebController.php') ?: [] as $cFile) {
                    $cContent = file_get_contents($cFile);
                    $cChanged = false;

                    if (! str_contains($cContent, 'extends BaseSliceWebController') || ! str_contains($cContent, 'routeParameters')) {
                        continue;
                    }

                    $baseName = basename($cFile, 'WebController.php');
                    $childPlural = Str::plural(Str::snake($baseName));

                    // Route parameters: check for null parentId cleanly without recursion
                    if (str_contains($cContent, "request()->route('parentId')")) {
                        $cleanedController = preg_replace(
                            '/protected function routeParameters\(array \$extra = \[\]\): array\s*\{[\s\S]*?return array_merge\([\s\S]*?request\(\)->route\([\'"]parentId[\'"]\)[\s\S]*?\$extra\);\s*\}/',
                            "protected function routeParameters(array \$extra = []): array\n    {\n        \$parentId = request()->route('parentId');\n        if (\$parentId === null) {\n            return \$extra;\n        }\n        return array_merge(['parentId' => \$parentId], \$extra);\n    }",
                            $cContent
                        );
                        if ($cleanedController && $cleanedController !== $cContent) {
                            $cContent = $cleanedController;
                            $cChanged = true;
                        }
                    }

                    if ($childPlural !== $parentPlural && ! str_contains($cContent, 'function getRoutePrefix()')) {
                        $methodStr = "\n    protected function getRoutePrefix(): string\n    {\n        if (request()->route('parentId') === null) {\n            return '{$childPlural}.';\n        }\n        return '{$parentPlural}.{$childPlural}.';\n    }\n";
                        $pos = strrpos($cContent, '}');
                        if ($pos !== false) {
                            $cContent = substr($cContent, 0, $pos).$methodStr."\n}\n";
                            $cChanged = true;
                        }
                    }

                    if ($cChanged) {
                        $this->writeRepair($cFile, $cContent);
                    }
                }
            }

            // Child views auto-healing
            if ($viewsPath && is_dir($viewsPath)) {
                $subDirs = glob($viewsPath.'/*', GLOB_ONLYDIR) ?: [];
                foreach ($subDirs as $subDir) {
                    $childName = basename($subDir);
                    $indexFile = $subDir.'/index.blade.php';
                    $formFile = $subDir.'/form.blade.php';

                    if (file_exists($indexFile)) {
                        $idx = file_get_contents($indexFile);
                        $idxChanged = false;
                        if (! str_contains($idx, '$parentId ?') && str_contains($idx, "route('{$parentPlural}.{$childName}.create', ['parentId' => \$parentId])")) {
                            $idx = str_replace(
                                "route('{$parentPlural}.{$childName}.create', ['parentId' => \$parentId])",
                                "(\$parentId ? route('{$parentPlural}.{$childName}.create', ['parentId' => \$parentId]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.create') ? route('{$childName}.create') : url('/crm/{$childName}/create')))",
                                $idx
                            );
                            $idxChanged = true;
                        }
                        if (! str_contains($idx, '$parentId ?') && preg_match("/route\('{$parentPlural}\.{$childName}\.edit',\s*\[[\'\"]parentId[\'\"]\s*=>\s*\\\$parentId,\s*[\'\"](?:contact|id)[\'\"]\s*=>\s*\\\$item->id\]\)/", $idx, $mEdit)) {
                            $idx = str_replace(
                                $mEdit[0],
                                "(\$parentId ? route('{$parentPlural}.{$childName}.edit', ['parentId' => \$parentId, 'id' => \$item->id]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.edit') ? route('{$childName}.edit', \$item->id) : route('{$parentPlural}.{$childName}.edit', ['parentId' => \$item->{$fkDefault} ?? 0, 'id' => \$item->id])))",
                                $idx
                            );
                            $idxChanged = true;
                        }
                        if ($idxChanged) {
                            $this->writeRepair($indexFile, $idx);
                        }
                    }

                    if (file_exists($formFile)) {
                        $ff = file_get_contents($formFile);
                        $ffChanged = false;

                        if (! str_contains($ff, '$parentId ?') && str_contains($ff, "href=\"{{ route('{$parentPlural}.{$childName}.index', ['parentId' => \$parentId]) }}\"")) {
                            $ff = str_replace(
                                "href=\"{{ route('{$parentPlural}.{$childName}.index', ['parentId' => \$parentId]) }}\"",
                                "href=\"{{ \$parentId ? route('{$parentPlural}.{$childName}.index', ['parentId' => \$parentId]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.index') ? route('{$childName}.index') : url('/crm/{$childName}')) }}\"",
                                $ff
                            );
                            $ffChanged = true;
                        }

                        if (! str_contains($ff, '$parentId ?') && str_contains($ff, "\$isNew ? route('{$parentPlural}.{$childName}.store', ['parentId' => \$parentId])")) {
                            $oldAction = "\$isNew ? route('{$parentPlural}.{$childName}.store', ['parentId' => \$parentId]) : route('{$parentPlural}.{$childName}.update', ['parentId' => \$parentId, 'id' => \$form->id])";
                            $newAction = "\$isNew ? (\$parentId ? route('{$parentPlural}.{$childName}.store', ['parentId' => \$parentId]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.store') ? route('{$childName}.store') : route('{$parentPlural}.{$childName}.store', ['parentId' => old('{$fkDefault}', 0)]))) : (\$parentId ? route('{$parentPlural}.{$childName}.update', ['parentId' => \$parentId, 'id' => \$form->id]) : (\\Illuminate\\Support\\Facades\\Route::has('{$childName}.update') ? route('{$childName}.update', \$form->id) : route('{$parentPlural}.{$childName}.update', ['parentId' => \$form->{$fkDefault} ?? 0, 'id' => \$form->id])))";
                            $ff = str_replace($oldAction, $newAction, $ff);
                            $ffChanged = true;
                        }

                        $hiddenInput = '<input type="hidden" name="'.$fkDefault.'" value="{{ $parentId }}">';
                        if (str_contains($ff, $hiddenInput) && ! str_contains($ff, '@if($parentId)')) {
                            $parentLabel = Str::headline($parentSingular);
                            $comboboxSnippet = "@if(\$parentId)\n"
                                ."                <input type=\"hidden\" name=\"{$fkDefault}\" value=\"{{ \$parentId }}\">\n"
                                ."                @else\n"
                                ."                <div class=\"space-y-1.5\">\n"
                                ."                    <x-ui.combobox-relationship\n"
                                ."                        name=\"{$fkDefault}\"\n"
                                ."                        label=\"{$parentLabel}\"\n"
                                ."                        :options=\"\${$parentPlural}Options ?? \${$parentSingular}Options ?? []\"\n"
                                ."                        :selected=\"old('{$fkDefault}', \$form->{$fkDefault} ?? '')\"\n"
                                ."                        placeholder=\"Select {$parentLabel}...\"\n"
                                ."                        required=\"true\"\n"
                                ."                    />\n"
                                ."                    @error('{$fkDefault}')\n"
                                ."                        <p class=\"text-xs text-destructive font-medium\">{{ \$message }}</p>\n"
                                ."                    @enderror\n"
                                ."                </div>\n"
                                .'                @endif';
                            $ff = str_replace($hiddenInput, $comboboxSnippet, $ff);
                            $ffChanged = true;
                        }

                        if ($ffChanged) {
                            $this->writeRepair($formFile, $ff);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        if ($routesPath = $slice->getRoutesPath()) {
            $webRoute = $routesPath.'/web.php';
            if (file_exists($webRoute)) {
                $domain = $slice->domain ?? $slice->raw['domain'] ?? null;
                $webContent = file_get_contents($webRoute);
                $parentTable = Str::plural(Str::snake($slice->name));

                if (! $domain) {
                    if (preg_match("/Route::prefix\(['\"]([^'\"]+)\/{$parentTable}['\"]\)/", $webContent, $m)) {
                        $domain = trim($m[1], '/');
                    }
                }

                if ($domain) {
                    $domainSlug = Str::slug($domain);
                    $parentSingular = Str::singular($parentTable);

                    // Auto-heal legacy or child routes missing domain prefix (e.g. companies/{parentId} -> crm/companies/{parentId})
                    $pattern = "/Route::prefix\(['\"](?!".preg_quote($domainSlug, '/')."\/)(".preg_quote($parentTable, '/').'|'.preg_quote($parentSingular, '/').")\/\{parentId\}['\"]\)/";
                    if (preg_match($pattern, $webContent)) {
                        $updatedContent = preg_replace($pattern, "Route::prefix('{$domainSlug}/$1/{parentId}')", $webContent);
                        if ($updatedContent && $updatedContent !== $webContent) {
                            $this->writeRepair($webRoute, $updatedContent);
                            $webContent = $updatedContent;
                        }
                    }

                    // Auto-heal missing or nested top-level routes for child tables (e.g. /crm/contacts)
                    if (! empty($slice->tables) && is_array($slice->tables)) {
                        $topRoutesNeeded = '';
                        $contentChanged = false;
                        foreach ($slice->tables as $tbl) {
                            if ($tbl !== $parentTable && $tbl !== $parentSingular) {
                                $childPluralSnake = $tbl;
                                $childStudly = Str::studly(Str::singular($tbl));
                                $childUrlPrefix = "{$domainSlug}/{$childPluralSnake}";

                                // Never invent routes for a child
                                // controller that does not exist:
                                // the dangling reference breaks
                                // route:list / route:cache for the
                                // WHOLE application (backlog A3 —
                                // 18 pivot/child controllers, e.g.
                                // ProjectMember, TaskUser,
                                // BillStatus). Children without
                                // their own controller are served
                                // by the parent's hub tabs, not a
                                // standalone directory. Strip any
                                // block an older heal wrote for one.
                                if (! file_exists($slice->path.'/Controllers/'.$childStudly.'WebController.php')) {
                                    $stripped = $this->stripInventedChildRoutes($webContent, $childUrlPrefix, $childStudly);
                                    if ($stripped !== null) {
                                        $webContent = $stripped;
                                        $contentChanged = true;
                                    }
                                    continue;
                                }

                                // A single named group: identical URIs under two names break route:cache
                                $explicitRoutes = "\nRoute::prefix('{$childUrlPrefix}')->name('{$childPluralSnake}.')->middleware(config('laraslice.generated_routes.web_middleware', ['web', 'auth']))->group(function () {\n"
                                    ."    Route::get('/', [{$childStudly}WebController::class, 'index'])->name('index');\n"
                                    ."    Route::get('/create', [{$childStudly}WebController::class, 'create'])->name('create');\n"
                                    ."    Route::post('/', [{$childStudly}WebController::class, 'store'])->name('store');\n"
                                    ."    Route::get('/{id}/edit', [{$childStudly}WebController::class, 'edit'])->name('edit');\n"
                                    ."    Route::put('/{id}', [{$childStudly}WebController::class, 'update'])->name('update');\n"
                                    ."    Route::delete('/{id}', [{$childStudly}WebController::class, 'destroy'])->name('destroy');\n"
                                    ."});\n";

                                if (str_contains($webContent, "Route::prefix('{$childUrlPrefix}')") && str_contains($webContent, "Route::resource('{$childPluralSnake}'")) {
                                    $quotedPrefix = preg_quote($childUrlPrefix, '#');
                                    $quotedChild = preg_quote($childPluralSnake, '#');
                                    $legacyPattern1 = "#Route::prefix\('{$quotedPrefix}'\)[\s\S]*?Route::resource\('{$quotedChild}'[\s\S]*?\}\);\s*Route::prefix\('{$quotedPrefix}'\)[\s\S]*?Route::resource\('{$quotedChild}'[\s\S]*?\}\);#m";
                                    if (preg_match($legacyPattern1, $webContent)) {
                                        $webContent = preg_replace($legacyPattern1, trim($explicitRoutes), $webContent);
                                        $contentChanged = true;
                                    }
                                } elseif (! str_contains($webContent, "Route::prefix('{$childUrlPrefix}')")) {
                                    $topRoutesNeeded .= $explicitRoutes;
                                }
                            }
                        }
                        if ($topRoutesNeeded !== '' || $contentChanged) {
                            $webContent .= $topRoutesNeeded;
                            $this->writeRepair($webRoute, $webContent);
                        }
                    }
                }

            }

            $apiRoute = $routesPath.'/api.php';
            if (file_exists($apiRoute)) {
                $domain = $slice->domain ?? $slice->raw['domain'] ?? null;
                $apiContent = file_get_contents($apiRoute);
                $parentTable = Str::plural(Str::snake($slice->name));

                if (! $domain && isset($webContent)) {
                    if (preg_match("/Route::prefix\(['\"]([^'\"]+)\/{$parentTable}['\"]\)/", $webContent, $m)) {
                        $domain = trim($m[1], '/');
                    }
                }

                if ($domain) {
                    $domainSlug = Str::slug($domain);
                    $parentSingular = Str::singular($parentTable);

                    $patternApi = "/Route::prefix\(['\"](?!".preg_quote($domainSlug, '/')."\/)(".preg_quote($parentTable, '/').'|'.preg_quote($parentSingular, '/').")\/\{parentId\}\/([a-zA-Z0-9_]+)['\"]\)/";
                    if (preg_match($patternApi, $apiContent)) {
                        $updatedApi = preg_replace($patternApi, "Route::prefix('{$domainSlug}/$1/{parentId}/$2')", $apiContent);
                        if ($updatedApi && $updatedApi !== $apiContent) {
                            $this->writeRepair($apiRoute, $updatedApi);
                        }
                    }
                }

            }
        }


        // Ensure index data-table reflects YAML ui.directory (views, inline_edit, date-key)
        $this->repairIndexDirectoryUi($slice);

        return $this->repairedFiles;
    }

    /**
     * Patch index.blade.php so <x-ui.data-table> receives views / inline_edit / date-key from slice.yaml.
     */
    protected function repairIndexDirectoryUi(SliceManifest $slice): void
    {
        $viewsPath = $slice->getViewsPath();
        if (! $viewsPath) {
            return;
        }
        $index = $viewsPath.'/index.blade.php';
        if (! is_file($index)) {
            return;
        }
        $manifestFile = $slice->path.'/slice.yaml';
        if (! is_file($manifestFile)) {
            return;
        }
        try {
            $manifest = ManifestRepository::read($manifestFile);
        } catch (\Throwable $e) {
            return;
        }

        $dir = is_array($manifest['ui']['directory'] ?? null) ? $manifest['ui']['directory'] : [];
        $views = is_array($dir['views'] ?? null) && $dir['views'] !== [] ? array_values($dir['views']) : ['table'];
        $defaultView = is_string($dir['default_view'] ?? null) ? $dir['default_view'] : 'table';
        $inlineEdit = is_array($dir['inline_edit'] ?? null) ? array_values($dir['inline_edit']) : [];
        $statusKey = 'status';
        $dateKey = null;
        foreach ($dir['columns'] ?? [] as $c) {
            if (! is_array($c)) {
                continue;
            }
            $ck = (string) ($c['key'] ?? $c['handle'] ?? '');
            if (in_array($ck, ['status', 'state', 'stage'], true)) {
                $statusKey = $ck;
            }
            if ($dateKey === null && (str_contains($ck, 'date') || str_ends_with($ck, '_at'))) {
                $dateKey = $ck;
            }
        }
        foreach (['due_date', 'start_date', 'issue_date', 'date'] as $prefer) {
            foreach ($dir['columns'] ?? [] as $c) {
                if (is_array($c) && (($c['key'] ?? $c['handle'] ?? '') === $prefer)) {
                    $dateKey = $prefer;
                    break 2;
                }
            }
        }

        $original = file_get_contents($index);
        if ($original === false || ! str_contains($original, 'x-ui.data-table')) {
            return;
        }
        $content = $original;
        $content = $this->injectRuntimeDirectoryColumns($content, $slice->name);

        $viewsExport = var_export($views, true);
        $inlineExport = var_export($inlineEdit, true);
        $dateExport = $dateKey === null ? 'null' : var_export($dateKey, true);
        $defaultViewEsc = addslashes($defaultView);
        $statusKeyEsc = addslashes($statusKey);

        if (! str_contains($content, '$tableViews')) {
            $sliceJsonPath = str_replace('\\', '/', $slice->path.'/slice.yaml');
            $bootstrap = <<<PHP
@php
    if (! isset(\$manifest) || ! is_array(\$manifest)) {
        \$manifest = [];
        try {
            \$_mf = '{$sliceJsonPath}';
            if (is_file(\$_mf)) {
                \$manifest = json_decode((string) file_get_contents(\$_mf), true) ?: [];
            }
        } catch (\\Throwable \$e) {
            \$manifest = [];
        }
    }
    \$dirUi = \$manifest['ui']['directory'] ?? [];
    \$tableViews = is_array(\$dirUi['views'] ?? null) && \$dirUi['views'] !== [] ? \$dirUi['views'] : {$viewsExport};
    \$defaultView = \$dirUi['default_view'] ?? '{$defaultViewEsc}';
    \$inlineEdit = is_array(\$dirUi['inline_edit'] ?? null) ? \$dirUi['inline_edit'] : {$inlineExport};
    \$statusKey = '{$statusKeyEsc}';
    \$dateKey = {$dateExport};
@endphp

PHP;
            $content = preg_replace('/(<x-ui\.data-table\b)/', $bootstrap.'$1', $content, 1) ?? $content;
        }

        $requiredAttrs = [
            ':views' => '$tableViews',
            ':default-view' => '$defaultView',
            ':status-key' => '$statusKey',
            ':inline-edit' => '$inlineEdit',
            ':date-key' => '$dateKey',
        ];
        if (preg_match('/<x-ui\.data-table\b[^>]*>/s', $content, $m)) {
            $tag = $m[0];
            $newTag = $tag;
            foreach ($requiredAttrs as $attr => $var) {
                if (! str_contains($newTag, $attr.'=') && ! str_contains($newTag, $attr.' =')) {
                    $newTag = rtrim(substr($newTag, 0, -1))."\n            {$attr}=\"{$var}\">";
                }
            }
            if ($newTag !== $tag) {
                $content = preg_replace('/<x-ui\.data-table\b[^>]*>/s', $newTag, $content, 1) ?? $content;
            }
        }

        if ($content !== $original) {
            $this->writeRepair($index, $content);
        }
    }

    /** Record (and unless dry-running, write) one repaired file. */
    protected function writeRepair(string $path, string $contents): void
    {
        if (! in_array($path, $this->repairedFiles, true)) {
            $this->repairedFiles[] = $path;
        }
        if (! $this->repairDryRun && file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Unable to write {$path}");
        }
    }

    /**
     * Find the Eloquent class for a model name (e.g. "ShopCategory") among discovered slices,
     * then App\Models. Generated belongsTo relations use this instead of guessing namespaces.
     */
    public function modelClass(string $studlyName): ?string
    {
        if (array_key_exists($studlyName, $this->resolvedModels)) {
            return $this->resolvedModels[$studlyName];
        }

        $packageSlices = realpath(dirname(__DIR__, 2).'/Slices');
        $candidates = [];
        foreach ($this->slices as $slice) {
            // Core slices and older manifests have no namespace; derive it from the location
            $namespace = $slice->namespace;
            if (empty($namespace)) {
                $insidePackage = $packageSlices !== false && str_starts_with((string) realpath($slice->path), $packageSlices);
                $namespace = $insidePackage
                    ? 'LaraSlice\\Slices\\'.basename($slice->path)
                    : rtrim((string) config('laraslice.slices_namespace', 'App\\Slices'), '\\')
                        .(! empty($slice->domain) ? '\\'.Str::studly(Str::slug($slice->domain)) : '')
                        .'\\'.basename($slice->path);
            }
            $candidates[] = rtrim($namespace, '\\').'\\Models\\'.$studlyName;

            // Deeply nested slices (App/Module/Slice) do not match the
            // domain+basename derivation above; the directory path under
            // the slices root maps 1:1 onto the namespace (PSR-4).
            $real = realpath($slice->path);
            if ($real !== false) {
                $marker = DIRECTORY_SEPARATOR.'Slices'.DIRECTORY_SEPARATOR;
                $pos = strrpos($real, $marker);
                if ($pos !== false) {
                    $relative = substr($real, $pos + strlen($marker));
                    $segments = array_map(static fn ($s) => Str::studly($s), explode(DIRECTORY_SEPARATOR, $relative));
                    $base = ($packageSlices !== false && str_starts_with($real, $packageSlices))
                        ? 'LaraSlice\\Slices'
                        : rtrim((string) config('laraslice.slices_namespace', 'App\\Slices'), '\\');
                    $candidates[] = $base.'\\'.implode('\\', $segments).'\\Models\\'.$studlyName;
                }
            }
        }
        $candidates[] = 'App\\Models\\'.$studlyName;

        // Filesystem fallback: slices shadowed by a duplicate name never
        // enter the name-keyed registry above, but their model classes
        // still exist on disk — locate Models/<Name>.php under a slices
        // root and derive the class from its PSR-4 path.
        $roots = [];
        if ($packageSlices !== false) {
            $roots[] = [$packageSlices, 'LaraSlice\\Slices'];
        }
        if (function_exists('app_path')) {
            $roots[] = [app_path('Slices'), rtrim((string) config('laraslice.slices_namespace', 'App\\Slices'), '\\')];
        }
        foreach ($roots as [$root, $baseNs]) {
            if (! is_dir($root)) {
                continue;
            }
            try {
                // Collect first, then SORT by path: directory
                // iteration order is filesystem-dependent, so
                // an unsorted fallback made the winning model
                // class a coin flip across machines whenever
                // duplicate trees carried the same model name
                // (backlog A5). Sorted order is deterministic;
                // on the reference install it reproduces the
                // previously observed winners.
                $found = [];
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                $it->setMaxDepth(6);
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getFilename() === $studlyName.'.php'
                        && basename(dirname($file->getPathname())) === 'Models') {
                        $found[] = $file->getPathname();
                    }
                }
                sort($found, SORT_STRING);
                foreach ($found as $pathname) {
                    $relative = substr($pathname, strlen($root) + 1, -4);
                    $segments = array_map(static fn ($s) => Str::studly($s), explode(DIRECTORY_SEPARATOR, $relative));
                    $candidates[] = $baseNs.'\\'.implode('\\', $segments);
                }
            } catch (\Throwable $e) {
                // A broken tree must not break model resolution.
            }
        }

        $existing = [];
        foreach ($candidates as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class) && ! in_array($class, $existing, true)) {
                $existing[] = $class;
            }
        }
        if (count($existing) > 1) {
            // Ambiguity is recorded, mirroring nameCollisions():
            // the winner stays the first candidate (registry
            // order, then sorted fallback), but the choice is
            // now visible instead of silent.
            $this->modelCollisions[$studlyName] = [
                'model' => $studlyName,
                'winner' => $existing[0],
                'candidates' => array_values(array_unique($existing)),
            ];
        }
        if ($existing !== []) {
            return $this->resolvedModels[$studlyName] = $existing[0];
        }

        return $this->resolvedModels[$studlyName] = null;
    }

    /**
     * Model names that resolved ambiguously (more than one
     * installed model class shares the name), keyed by model
     * name. Populated lazily as modelClass() resolves.
     *
     * @return array<string, array{model: string, winner: string, candidates: array<int, string>}>
     */
    public function modelCollisions(): array
    {
        return $this->modelCollisions;
    }

    public function getAllSlices(): array
    {
        return $this->slices;
    }

    /**
     * Register one discovered manifest, recording name collisions.
     * Raw keys keep their historic semantics (a later registration
     * with the same raw name wins the key); normalized collisions
     * keep both slices but are recorded so tooling can surface the
     * ambiguity instead of resolving it by scan order.
     */
    protected function registerManifest(SliceManifest $manifest): void
    {
        $this->discoveredSlices[] = $manifest;
        $existing = $this->slices[$manifest->name] ?? null;
        if ($existing !== null && $existing->path !== $manifest->path) {
            $this->nameCollisions[] = [
                'type' => 'duplicate-name',
                'key' => $manifest->name,
                'slices' => [
                    ['name' => $existing->name, 'path' => $existing->path],
                    ['name' => $manifest->name, 'path' => $manifest->path],
                ],
            ];
        }

        $norm = strtolower(str_replace([' ', '_', '-'], '', $manifest->name));
        $holder = $this->normalizedNameHolders[$norm] ?? null;
        if ($holder !== null && $holder->name !== $manifest->name) {
            $this->nameCollisions[] = [
                'type' => 'normalized-name',
                'key' => $norm,
                'slices' => [
                    ['name' => $holder->name, 'path' => $holder->path],
                    ['name' => $manifest->name, 'path' => $manifest->path],
                ],
            ];
        } elseif ($holder === null) {
            $this->normalizedNameHolders[$norm] = $manifest;
        }

        $this->slices[$manifest->name] = $manifest;
    }

    /**
     * Name collisions recorded during discovery (see registerManifest).
     *
     * @return array<int, array{type: string, key: string, slices: array<int, array{name: string, path: string}>}>
     */
    public function nameCollisions(): array
    {
        return $this->nameCollisions;
    }

    /**
     * Every manifest discovered in the last discover() run,
     * including name-shadowed twins (see $discoveredSlices).
     *
     * @return array<int, SliceManifest>
     */
    public function discoveredSlices(): array
    {
        return $this->discoveredSlices;
    }

    public function getSlices(): array
    {
        return $this->slices;
    }

    public function getActiveSlices(): array
    {
        return array_filter($this->slices, fn (SliceManifest $s) => $s->active);
    }

    public function getSlice(string $name): ?SliceManifest
    {
        if (isset($this->slices[$name])) {
            return $this->slices[$name];
        }

        $norm = strtolower(str_replace([' ', '_', '-'], '', $name));
        $singNorm = Str::singular($norm);
        $plurNorm = Str::plural($norm);
        $norms = array_unique([$norm, $singNorm, $plurNorm]);
        $normalize = fn (string $v): string => strtolower(str_replace([' ', '_', '-'], '', $v));

        // Matching runs in strictness passes: an exact name/plural match on
        // ANY slice must beat a loose path-contains match on an earlier
        // slice. The old single-pass loop let getSlice('Employee') resolve
        // to the EmployeeDetails slice (path contains "employee") before
        // the loop ever reached the Employees slice itself, which made
        // pages render with another slice's columns and formatters.
        $passes = [
            fn (SliceManifest $slice): bool => in_array($normalize($slice->name), $norms, true),
            fn (SliceManifest $slice): bool => in_array($normalize(basename($slice->path)), $norms, true),
            fn (SliceManifest $slice): bool => isset($slice->title) && in_array($normalize($slice->title), $norms, true),
            fn (SliceManifest $slice): bool => isset($slice->raw['handle']) && in_array($normalize((string) $slice->raw['handle']), $norms, true),
            fn (SliceManifest $slice): bool => file_exists($slice->path.'/Models/'.Str::studly(Str::singular($name)).'.php'),
            fn (SliceManifest $slice): bool => ! empty($slice->tables) && in_array(Str::snake(Str::plural($name)), $slice->tables, true),
            function (SliceManifest $slice) use ($norms, $normalize): bool {
                $relPathNorm = $normalize(str_replace(['\\', '/'], '', $slice->path));
                foreach ($norms as $n) {
                    if ($n !== '' && str_contains($relPathNorm, $n)) {
                        return true;
                    }
                }

                return false;
            },
        ];

        foreach ($passes as $pass) {
            $matches = [];
            foreach ($this->slices as $slice) {
                if ($pass($slice)) {
                    $matches[] = $slice;
                }
            }
            if ($matches === []) {
                continue;
            }
            if (count($matches) === 1) {
                return $matches[0];
            }

            // Ambiguous pass (twin names like 'Email Templates' vs
            // 'EmailTemplates' both normalize identically): pick the
            // winner DETERMINISTICALLY instead of by scan order, so
            // the same query resolves to the same slice on every
            // machine. Preference: exact raw name, then a path
            // basename matching the query, then the shorter path,
            // then path order.
            usort($matches, static function (SliceManifest $a, SliceManifest $b) use ($name, $norm, $normalize): int {
                $exactA = $a->name === $name ? 0 : 1;
                $exactB = $b->name === $name ? 0 : 1;
                if ($exactA !== $exactB) {
                    return $exactA <=> $exactB;
                }
                $baseA = $normalize(basename($a->path)) === $norm ? 0 : 1;
                $baseB = $normalize(basename($b->path)) === $norm ? 0 : 1;
                if ($baseA !== $baseB) {
                    return $baseA <=> $baseB;
                }
                $len = strlen($a->path) <=> strlen($b->path);
                if ($len !== 0) {
                    return $len;
                }

                return strcmp($a->path, $b->path);
            });

            return $matches[0];
        }

        return null;
    }

    /**
     * Get list of active slices formatted for navigation bars / sidebars
     */

    /**
     * Whether the user has any of the given role slugs (YAML navigation.roles).
     * Works with HasSlicePermissions or direct role_user table.
     *
     * @param  list<string>  $roleSlugs
     */
    protected function userHasAnyRole(object $user, array $roleSlugs): bool
    {
        if ($roleSlugs === []) {
            return true;
        }
        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole($roleSlugs);
        }
        if (method_exists($user, 'roleSlugs')) {
            return array_intersect($roleSlugs, $user->roleSlugs()) !== [];
        }
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('role_user') || ! \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                return false;
            }
            $uid = $user->id ?? $user->getAuthIdentifier();
            if (! $uid) {
                return false;
            }
            return \Illuminate\Support\Facades\DB::table('role_user')
                ->join('roles', 'role_user.role_id', '=', 'roles.id')
                ->where('role_user.user_id', $uid)
                ->whereIn('roles.slug', $roleSlugs)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getNavigableSlices(): array
    {
        $currentUser = auth()->check() ? auth()->user() : null;

        // Built once per user per request: every view and component asks for it
        $cacheKey = $currentUser ? 'user:'.$currentUser->getAuthIdentifier() : 'guest';
        if (isset($this->navigationCache[$cacheKey])) {
            return $this->navigationCache[$cacheKey];
        }

        $nav = [];

        $isSuperAdmin = $currentUser && Access::isSuperAdmin($currentUser);

        foreach ($this->getActiveSlices() as $slice) {
            if (isset($slice->navigation['visible']) && $slice->navigation['visible'] === false) {
                continue;
            }
            if (! empty($slice->navigation['hidden'])) {
                continue;
            }
            if (isset($slice->navigation['show_in_navigation']) && $slice->navigation['show_in_navigation'] === false) {
                continue;
            }

            $studly = Str::studly($slice->name);
            $snake = Str::snake($slice->name);
            $kebab = Str::kebab($slice->name);
            $slug = strtolower($slice->name);

            $singularSnake = Str::snake(Str::singular($slice->name));
            $singularKebab = Str::kebab(Str::singular($slice->name));
            $singular = Str::singular($slug);

            // Determine required RBAC permission for this slice
            $requiredPermission = $slice->navigation['permission'] ?? null;
            if (empty($requiredPermission)) {
                $candidate = $singularSnake.'.view';
                if (! empty($slice->permissions) && in_array($candidate, (array) $slice->permissions, true)) {
                    $requiredPermission = $candidate;
                } elseif (! empty($slice->permissions[0])) {
                    $first = $slice->permissions[0];
                    $requiredPermission = is_array($first) ? ($first['slug'] ?? $first['key'] ?? null) : (string) $first;
                }
            }

            // Permission check (Super Admin bypasses). When navigation.roles is set, role gate below is the ERP persona source of truth.
            $allowedRolesEarly = $slice->navigation['roles'] ?? $slice->navigation['personas'] ?? null;
            $hasPersonaRoles = is_array($allowedRolesEarly) && $allowedRolesEarly !== [];
            if ($requiredPermission && $currentUser && ! $isSuperAdmin && ! $hasPersonaRoles) {
                if (method_exists($currentUser, 'hasPermission')) {
                    if (! $currentUser->hasPermission($requiredPermission)) {
                        continue;
                    }
                } elseif (method_exists($currentUser, 'can')) {
                    if (! $currentUser->can($requiredPermission)) {
                        continue;
                    }
                }
            }

            // YAML-centric persona / role gates (ERP-class: admin | employee | client)
            // navigation.roles: list of role slugs; user must match at least one (unless super-admin)
            // navigation.personas: alias for roles for Studio readability
            $allowedRoles = $slice->navigation['roles']
                ?? $slice->navigation['personas']
                ?? $slice->raw['navigation']['roles']
                ?? null;
            if (is_array($allowedRoles) && $allowedRoles !== [] && $currentUser && ! $isSuperAdmin) {
                $normalized = array_values(array_filter(array_map(static function ($r) {
                    $r = strtolower(trim((string) $r));
                    // accept "role:employee" from journeys.v2 gates
                    return str_starts_with($r, 'role:') ? substr($r, 5) : $r;
                }, $allowedRoles)));
                if ($normalized !== []) {
                    $ok = $this->userHasAnyRole($currentUser, $normalized);
                    if (! $ok) {
                        continue;
                    }
                }
            }

            $group = $slice->navigation['group'] ?? $slice->raw['domain'] ?? $slice->domain ?? null;
            $groupSlug = $group ? Str::slug($group) : null;
            $groupDot = $groupSlug ? str_replace('-', '_', $groupSlug).'.' : '';

            // The slice's OWN declared route-name prefix (parsed
            // from its routes file) outranks every guess: slices
            // from the path-named generation register names like
            // erp_erp_work_projects.* that none of the slug
            // candidates below can ever match — their nav entries
            // never turned active (no sidebar highlight, no
            // topbar title) until the declared prefix was tried.
            $declaredNamePrefix = null;
            $routesFile = $slice->getRoutesPath() ? $slice->getRoutesPath().'/web.php' : null;
            if ($routesFile && is_file($routesFile)
                && preg_match("/->name\('([^']+)'\)/", (string) file_get_contents($routesFile), $nm)) {
                $declaredNamePrefix = $nm[1];
            }

            // Determine route: explicit in slice.yaml, or fallback to registered candidate route names
            $routeCandidates = array_values(array_filter([
                $slice->navigation['route'] ?? null,
                $declaredNamePrefix ? $declaredNamePrefix.'index' : null,
                $groupDot ? $groupDot.$snake.'.index' : null,
                $groupDot ? $groupDot.$singularSnake.'.index' : null,
                $snake.'.index',
                $singularSnake.'.index',
                $kebab.'.index',
                $singularKebab.'.index',
                $slug.'.index',
                $singular.'.index',
            ]));

            $route = null;
            foreach ($routeCandidates as $candidate) {
                if (Route::has($candidate)) {
                    $route = $candidate;
                    break;
                }
            }

            $defaultPath = '/'.($groupSlug ? "{$groupSlug}/{$snake}" : $snake);
            $url = $slice->navigation['url'] ?? ($route ? route($route) : url($defaultPath));
            $icon = $slice->navigation['icon'] ?? $slice->icon ?? 'package';
            $label = $slice->navigation['label'] ?? $slice->title ?: Str::headline($slice->name);
            $order = (int) ($slice->navigation['order'] ?? 50);

            $slugsToCheck = array_unique([$slug, $singular, $snake, $singularSnake, $kebab, $singularKebab]);
            $isActive = ($route && request()->routeIs($route));
            if (! $isActive) {
                foreach ($slugsToCheck as $s) {
                    if (request()->is($s) || request()->is($s.'/*') || request()->routeIs($s.'.*')) {
                        $isActive = true;
                        break;
                    }
                    if ($groupSlug && (request()->is($groupSlug.'/'.$s) || request()->is($groupSlug.'/'.$s.'/*') || request()->routeIs($groupDot.$s.'.*'))) {
                        $isActive = true;
                        break;
                    }
                }
            }

            $children = [];
            if (! empty($slice->navigation['children']) && is_array($slice->navigation['children'])) {
                foreach ($slice->navigation['children'] as $child) {
                    $childPerm = $child['permission'] ?? null;
                    if ($childPerm && $currentUser && ! $isSuperAdmin) {
                        if (method_exists($currentUser, 'hasPermission') && ! $currentUser->hasPermission($childPerm)) {
                            continue;
                        } elseif (method_exists($currentUser, 'can') && ! $currentUser->can($childPerm)) {
                            continue;
                        }
                    }
                    $childRoles = $child['roles'] ?? $child['personas'] ?? null;
                    if (is_array($childRoles) && $childRoles !== [] && $currentUser && ! $isSuperAdmin) {
                        $cn = array_values(array_filter(array_map(static function ($r) {
                            $r = strtolower(trim((string) $r));
                            return str_starts_with($r, 'role:') ? substr($r, 5) : $r;
                        }, $childRoles)));
                        if ($cn !== [] && ! $this->userHasAnyRole($currentUser, $cn)) {
                            continue;
                        }
                    }

                    $childUrl = $child['url'] ?? (isset($child['route']) && Route::has($child['route']) ? route($child['route']) : '#');
                    $isChildActive = (! empty($child['url']) && (request()->is(ltrim($child['url'], '/')) || request()->is(ltrim($child['url'], '/').'/*')))
                                     || (! empty($child['route']) && request()->routeIs($child['route']));
                    if ($isChildActive) {
                        $isActive = true;
                    }
                    $children[] = [
                        'label' => $child['label'] ?? $child['title'] ?? 'Sub Item',
                        'url' => $childUrl,
                        'route' => $child['route'] ?? null,
                        'icon' => $child['icon'] ?? 'circle',
                        'active' => $isChildActive,
                        'permission' => $childPerm,
                    ];
                }
            } elseif (! empty($slice->tables) && is_array($slice->tables)) {
                // Auto-discover child tables from manifest
                $primaryTable = Str::snake(Str::plural($slice->name));
                foreach ($slice->tables as $tbl) {
                    if ($tbl !== $primaryTable) {
                        $groupSlug = ! empty($slice->navigation['group']) ? strtolower(Str::slug($slice->navigation['group'])) : '';
                        $groupDot = $groupSlug ? $groupSlug.'.' : '';
                        $candidateRoutes = [
                            $tbl.'.index',
                            $groupDot.$tbl.'.index',
                            $slice->name.'.'.$tbl.'.index',
                            $primaryTable.'.'.$tbl.'.index',
                            $groupDot.$primaryTable.'.'.$tbl.'.index',
                        ];
                        $childRoute = null;
                        foreach ($candidateRoutes as $cand) {
                            if (Route::has($cand)) {
                                $childRoute = $cand;
                                break;
                            }
                        }
                        if ($childRoute) {
                            try {
                                $childUrl = route($childRoute);
                            } catch (\Throwable $e) {
                                $childUrl = url(($groupSlug ? $groupSlug.'/' : '').$tbl);
                            }
                            $isChildActive = request()->routeIs($tbl.'.*') || request()->is($tbl) || request()->is($tbl.'/*')
                                || request()->is('*'.$tbl) || request()->is('*'.$tbl.'/*');
                            if ($isChildActive) {
                                $isActive = true;
                            }
                            $children[] = [
                                'label' => Str::headline($tbl),
                                'url' => $childUrl,
                                'route' => $childRoute,
                                'icon' => in_array($tbl, ['contacts', 'users', 'members', 'employees']) ? 'users' : 'layers',
                                'active' => $isChildActive,
                            ];
                        }
                    }
                }
            }

            $nav[] = [
                'name' => $slice->name,
                'label' => $label,
                'route' => $route,
                'url' => $url,
                'icon' => $icon,
                'order' => $order,
                'active' => $isActive,
                'badge' => $slice->navigation['badge'] ?? null,
                'version' => $slice->version,
                'super_domain' => $slice->super_domain,
                'domain' => $slice->domain,
                'sub_domain' => $slice->sub_domain,
                'group' => $slice->navigation['group'] ?? $slice->domain ?? null,
                'table_prefix' => $slice->table_prefix,
                'multitenant' => $slice->multitenant,
                'tenant_key' => $slice->tenant_key,
                'core' => (bool) ($slice->raw['core'] ?? false),
                'permission' => $requiredPermission,
                'children' => $children,
            ];
        }

        usort($nav, fn ($a, $b) => $a['order'] <=> $b['order']);

        return $this->navigationCache[$cacheKey] = $nav;
    }

    /**
     * Get unique list of Super Domains across active slices.
     */
    public function getSuperDomains(): array
    {
        $supers = [];
        foreach ($this->getActiveSlices() as $slice) {
            if ($slice->super_domain && ! in_array($slice->super_domain, $supers, true)) {
                $supers[] = $slice->super_domain;
            }
        }

        return $supers;
    }

    /**
     * Get unique list of Domains across active slices, optionally filtered by Super Domain.
     */
    public function getDomains(?string $superDomain = null): array
    {
        $domains = [];
        foreach ($this->getActiveSlices() as $slice) {
            if ($superDomain && $slice->super_domain !== $superDomain) {
                continue;
            }
            $dom = $slice->domain ?? $slice->navigation['group'] ?? null;
            if ($dom && ! in_array($dom, $domains, true)) {
                $domains[] = $dom;
            }
        }

        return $domains;
    }

    /**
     * Build full 3-Tier Hierarchical Navigation Tree (SuperDomain -> Domain -> SubDomain/Slice).
     */
    public function getHierarchicalNavigation(): array
    {
        $slices = $this->getNavigableSlices();
        $tree = [];

        foreach ($slices as $item) {
            $super = $item['super_domain'] ?: 'Default';
            $domain = $item['domain'] ?: ($item['group'] ?: 'General');
            $sub = $item['sub_domain'] ?: null;

            if ($sub) {
                $tree[$super][$domain][$sub][] = $item;
            } else {
                $tree[$super][$domain]['_slices'][] = $item;
            }
        }

        return $tree;
    }

    /**
     * Automatically sync declared slice permissions into the database permissions table
     */
    /**
     * Sync permissions only when the slices' declared permissions changed since the last sync.
     * Cheap enough for page loads: normally a single cache lookup.
     */
    public function syncPermissionsIfChanged(): int
    {
        if (Cache::get('laraslice:permissions-fingerprint:v2') === $this->permissionsFingerprint()) {
            return 0;
        }

        return $this->syncPermissions();
    }

    /** What the discovered slices declare, as far as permission rows are concerned. */
    protected function permissionsFingerprint(): string
    {
        return md5(serialize(array_map(
            fn (SliceManifest $slice) => [$slice->name, $slice->title, $slice->domain, $slice->permissions, $slice->navigation['group'] ?? null],
            $this->slices
        )));
    }

    /**
     * Create or update the permissions declared by discovered slices and the studio.
     *
     * With $prune, permissions this method created earlier (source "laraslice") that no slice
     * declares any more are deleted. Permissions created by the application are never pruned,
     * and nothing is pruned when no slices were discovered.
     */
    public function syncPermissions(bool $prune = false): int
    {
        if (! SchemaCache::hasTable('permissions')) {
            return 0;
        }

        $hasDomainColumn = SchemaCache::hasColumn('permissions', 'domain');
        $hasSourceColumn = SchemaCache::hasColumn('permissions', 'source');

        $count = 0;
        $validSlugs = [];
        foreach ($this->slices as $slice) {
            $sliceTitle = ! empty($slice->title) ? $slice->title : ucwords(str_replace(['_', '-'], ' ', $slice->name));
            $domain = ! empty($slice->domain) ? $slice->domain : ($slice->navigation['group'] ?? 'Vertical Slices');
            $group = $sliceTitle;
            $perms = $slice->permissions ?? [];

            foreach ($perms as $perm) {
                $slug = is_array($perm) ? ($perm['key'] ?? $perm['slug'] ?? '') : $perm;
                $name = is_array($perm)
                    ? ($perm['name'] ?? $perm['label'] ?? Str::title(str_replace(['.', '_', '-'], ' ', $slug)))
                    : Str::title(str_replace(['.', '_', '-'], ' ', $slug));

                if (! empty($slug)) {
                    $payload = [
                        'name' => $name,
                        'group' => $group,
                        'description' => "Permission to {$name}",
                        'updated_at' => now(),
                        'created_at' => now(),
                    ];

                    if ($hasDomainColumn) {
                        $payload['domain'] = $domain;
                    }
                    if ($hasSourceColumn) {
                        $payload['source'] = 'laraslice';
                    }

                    DB::table('permissions')->updateOrInsert(
                        ['slug' => $slug],
                        $payload
                    );
                    $validSlugs[] = $slug;
                    $count++;
                }
            }
        }

        // 2. Register System-Level Core Slice Permissions
        $coreSystemPerms = [
            ['slug' => 'ai.copilot.use', 'name' => 'Use AI Copilot', 'desc' => 'Allows opening the AI Copilot and using its chat, context and record-creation endpoints.', 'group' => 'AI Copilot', 'domain' => 'Platform Engine'],
            ['slug' => 'ai.settings.edit', 'name' => 'Edit AI Settings', 'desc' => 'Allows changing AI providers, models and Copilot configuration.', 'group' => 'AI Copilot', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.access', 'name' => 'Access Slice Studio & Wizard', 'desc' => 'Allows opening and accessing Slice Studio and Blueprint Studio interfaces.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.create', 'name' => 'Scaffold & Generate Feature Slices', 'desc' => 'Allows generating new vertical slices, child tables, and fields via the wizard.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.blueprint', 'name' => 'Blueprint Studio Schema Design', 'desc' => 'Allows designing, introspecting, and applying declarative schemas in Blueprint Studio.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.migrate', 'name' => 'Execute Migrations from Studio', 'desc' => 'Allows running database migrations directly from Slice Studio.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'studio.wipe', 'name' => 'Wipe / Truncate Slice Records', 'desc' => 'Allows wiping demo or production records from slice tables.', 'group' => 'Slice Studio & Architecture Wizard', 'domain' => 'Platform Engine'],
            ['slug' => 'system.slices.view', 'name' => 'View Slice Studio & Slices', 'desc' => 'Allows viewing of all installed vertical slices in studio and directory.'],
            ['slug' => 'system.slices.toggle', 'name' => 'Toggle Slices (Enable/Disable)', 'desc' => 'Allows enabling or disabling slices and domains from navigation.'],
            ['slug' => 'system.slices.seed', 'name' => 'Seed Demo Data', 'desc' => 'Allows generating realistic demo data at slice and domain levels.'],
            ['slug' => 'system.slices.wipe', 'name' => 'Wipe Slice Data', 'desc' => 'Allows truncating data across slice and domain tables.'],
            ['slug' => 'system.slices.delete', 'name' => 'Delete Slices & Domains', 'desc' => 'Allows complete or selective destruction of slice code and databases.'],
            ['slug' => 'slice.disable', 'name' => 'Disable Slices', 'desc' => 'Allows deactivating slices from navigation and routing.'],
            ['slug' => 'slice.seed', 'name' => 'Seed Slices', 'desc' => 'Allows generating mock seed data for individual slices.'],
            ['slug' => 'slice.wipe', 'name' => 'Wipe Slice Records', 'desc' => 'Allows truncating records in slice tables.'],
            ['slug' => 'slice.delete', 'name' => 'Delete Slices', 'desc' => 'Allows removal and destruction of slices.'],
            ['slug' => 'domain.manage', 'name' => 'Manage Domains', 'desc' => 'Allows domain-level configuration, toggle, and lifecycle control.'],
            ['slug' => 'domain.seed', 'name' => 'Seed Domains', 'desc' => 'Allows domain-wide demo record generation.'],
            ['slug' => 'domain.wipe', 'name' => 'Wipe Domains', 'desc' => 'Allows truncating all tables across a domain.'],
            ['slug' => 'domain.delete', 'name' => 'Delete Domains', 'desc' => 'Allows complete or granular removal of domains.'],
        ];

        // Also add domain-specific management permissions
        $domainsSeen = [];
        foreach ($this->slices as $slice) {
            $d = ! empty($slice->domain) ? $slice->domain : ($slice->navigation['group'] ?? null);
            if ($d && ! in_array($d, $domainsSeen, true)) {
                $domainsSeen[] = $d;
                $dSlug = strtolower(Str::slug($d));
                $coreSystemPerms[] = [
                    'slug' => "{$dSlug}.manage",
                    'name' => "Manage {$d} Domain",
                    'desc' => "Complete administrative management of all slices in {$d} domain.",
                ];
                $coreSystemPerms[] = [
                    'slug' => "{$dSlug}.seed",
                    'name' => "Seed {$d} Data",
                    'desc' => "Generate demo seed records for all slices in {$d} domain.",
                ];
            }
        }

        foreach ($coreSystemPerms as $sp) {
            $payload = [
                'name' => $sp['name'],
                'group' => $sp['group'] ?? 'System Administration',
                'description' => $sp['desc'],
                'updated_at' => now(),
                'created_at' => now(),
            ];
            if ($hasDomainColumn) {
                $payload['domain'] = $sp['domain'] ?? 'System';
            }
            if ($hasSourceColumn) {
                $payload['source'] = 'laraslice';
            }
            DB::table('permissions')->updateOrInsert(
                ['slug' => $sp['slug']],
                $payload
            );
            $validSlugs[] = $sp['slug'];
            $count++;
        }

        // 3. Prune permissions of removed slices: only on request, only rows this method created
        if ($prune && $this->slices !== [] && $hasSourceColumn) {
            $orphanIds = DB::table('permissions')
                ->where('source', 'laraslice')
                ->whereNotIn('slug', $validSlugs)
                ->pluck('id')
                ->all();

            if ($orphanIds !== []) {
                DB::table('permission_role')->whereIn('permission_id', $orphanIds)->delete();
                DB::table('permissions')->whereIn('id', $orphanIds)->delete();
            }
        }

        // 3b. Prune orphaned roles from destroyed slices
        if ($prune && Schema::hasTable('roles')) {
            $protectedRoleSlugs = ['super-admin', 'super_admin', 'super-dev', 'super_dev', 'admin', 'administrator', 'manager', 'user', 'standard-user', 'guest', 'system'];
            $activeRoleSlugs = [];
            foreach ($this->slices as $s) {
                $manifestRoles = $s->raw['roles'] ?? ($s->raw['workflow']['roles'] ?? []);
                if (empty($manifestRoles) && file_exists($s->path.'/slice.yaml')) {
                    $sJson = json_decode(@file_get_contents($s->path.'/slice.yaml'), true) ?: [];
                    $manifestRoles = $sJson['roles'] ?? ($sJson['workflow']['roles'] ?? []);
                }
                foreach ($manifestRoles as $r) {
                    $slug = is_array($r) ? ($r['slug'] ?? '') : (is_string($r) ? $r : ($r->slug ?? ''));
                    if ($slug) {
                        $activeRoleSlugs[] = $slug;
                        $activeRoleSlugs[] = str_replace('_', '-', $slug);
                        $activeRoleSlugs[] = str_replace('-', '_', $slug);
                    }
                }
            }
            $activeRoleSlugs = array_values(array_unique(array_filter($activeRoleSlugs)));

            $candidateRoles = DB::table('roles')
                ->whereNotIn('slug', array_merge($protectedRoleSlugs, $activeRoleSlugs))
                ->get();

            foreach ($candidateRoles as $crole) {
                $cleanSlug = str_replace('_', '-', $crole->slug);
                $demoEmails = ["{$cleanSlug}@laraslice.com", "{$crole->slug}@laraslice.com"];
                $hasDemoUser = Schema::hasTable('users') && DB::table('users')->whereIn('email', $demoEmails)->exists();
                if ($hasDemoUser || str_contains(strtolower($crole->description ?? ''), 'operational role') || str_contains(strtolower($crole->description ?? ''), 'laraslice')) {
                    if (Schema::hasTable('permission_role')) {
                        DB::table('permission_role')->where('role_id', $crole->id)->delete();
                    }
                    if (Schema::hasTable('role_user')) {
                        DB::table('role_user')->where('role_id', $crole->id)->delete();
                    }
                    if (Schema::hasTable('users')) {
                        DB::table('users')->whereIn('email', $demoEmails)->delete();
                    }
                    DB::table('roles')->where('id', $crole->id)->delete();
                }
            }
        }

        // Ensure super-admin and super-dev roles automatically receive all synced
        // permissions by default. super-dev is the developer master key: whole-system
        // access including all studios, immune to legacy data ports.
        try {
            if (Schema::hasTable('roles') && Schema::hasTable('permission_role')) {
                $allPermIds = DB::table('permissions')->pluck('id');
                foreach (['super-admin', 'super-dev'] as $privilegedSlug) {
                    $role = DB::table('roles')->where('slug', $privilegedSlug)->first();
                    if (! $role) {
                        continue;
                    }
                    $existing = DB::table('permission_role')
                        ->where('role_id', $role->id)
                        ->pluck('permission_id')
                        ->toArray();

                    $missing = $allPermIds->diff($existing);
                    if ($missing->isNotEmpty()) {
                        $newRows = [];
                        foreach ($missing as $mId) {
                            $newRows[] = [
                                'role_id' => $role->id,
                                'permission_id' => $mId,
                            ];
                        }
                        DB::table('permission_role')->insert($newRows);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail-safe if DB schema not ready
        }

        // Every full sync records what it synced. Otherwise a slice set that returns to an earlier
        // state (destroyed, then generated again) matches a stale fingerprint and is never synced.
        Cache::forever('laraslice:permissions-fingerprint:v2', $this->permissionsFingerprint());

        return $count;
    }

    /**
     * Enable or disable a single slice.
     */
    public function toggleSlice(string $sliceName, ?bool $active = null): bool
    {
        $this->discover();
        $slice = $this->getSlice($sliceName);
        if (! $slice) {
            foreach ($this->getAllSlices() as $s) {
                if (strtolower($s->name) === strtolower($sliceName)) {
                    $slice = $s;
                    break;
                }
            }
        }

        if (! $slice) {
            throw new \InvalidArgumentException("Slice [{$sliceName}] not found.");
        }

        $newActive = ($active !== null) ? (bool) $active : ! $slice->active;

        // Update slice.yaml
        $jsonPath = $slice->path.'/slice.yaml';
        if (file_exists($jsonPath)) {
            $data = json_decode(file_get_contents($jsonPath), true) ?: [];
            $data['active'] = $newActive;
            file_put_contents($jsonPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Update slice.yaml
        foreach (['slice.yaml', 'slice.yml'] as $yf) {
            $yamlPath = $slice->path.'/'.$yf;
            if (file_exists($yamlPath) && class_exists(Yaml::class)) {
                $yData = Yaml::parse(file_get_contents($yamlPath)) ?: [];
                $yData['active'] = $newActive;
                file_put_contents($yamlPath, Yaml::dump($yData, 10, 2));
            }
        }

        $slice->active = $newActive;
        $this->clearCache();

        return $newActive;
    }

    /**
     * Enable or disable all slices in a domain.
     */
    /**
     * Slices whose domain (or navigation group) matches $domainName, case-insensitively.
     *
     * @return array<string, SliceManifest>
     */
    public function getDomainSlices(string $domainName): array
    {
        $wanted = strtolower(trim($domainName));

        return array_filter($this->getAllSlices(), function (SliceManifest $slice) use ($wanted) {
            $sliceDomain = $slice->domain ?? $slice->navigation['group'] ?? $slice->raw['domain'] ?? null;
            $sliceApp = $slice->app ?? $slice->super_domain ?? $slice->raw['app'] ?? null;
            $hierarchical = ($sliceApp && $sliceDomain && strtolower($sliceApp) !== strtolower($sliceDomain))
                ? "{$sliceApp} › {$sliceDomain}"
                : ($sliceDomain ?: $sliceApp);

            return strtolower(trim((string) $sliceDomain)) === $wanted
                || strtolower(trim((string) $sliceApp)) === $wanted
                || strtolower(trim((string) $hierarchical)) === $wanted;
        });
    }

    public function toggleDomain(string $domainName, ?bool $active = null): array
    {
        $this->discover();
        $updated = [];

        foreach ($this->getDomainSlices($domainName) as $slice) {
            $updated[$slice->name] = $this->toggleSlice($slice->name, $active);
        }

        $this->clearCache();

        return $updated;
    }

    /**
     * Discover all database tables associated with a slice.
     */
    /**
     * Core slices (Users, Roles, Settings, Auth, or any manifest with "core": true)
     * and slices inside the package itself are never destroyed or wiped.
     */
    public function isProtectedSlice(SliceManifest $slice): bool
    {
        if (filter_var($slice->raw['core'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $packageSlices = realpath(dirname(__DIR__, 2).'/Slices');
        $slicePath = realpath($slice->path);

        return $packageSlices !== false && $slicePath !== false
            && str_starts_with($slicePath.DIRECTORY_SEPARATOR, $packageSlices.DIRECTORY_SEPARATOR);
    }

    /**
     * Tables owned by protected slices; these are never dropped or truncated.
     *
     * @return array<int, string>
     */
    public function protectedTables(): array
    {
        $tables = [];
        foreach ($this->getAllSlices() as $slice) {
            if ($this->isProtectedSlice($slice)) {
                $tables = array_merge($tables, $this->getSliceTables($slice), $slice->tables ?? []);
            }
        }

        return array_values(array_unique(array_filter($tables)));
    }

    public function getSliceTables(SliceManifest $slice): array
    {
        $primary = $slice->name ? strtolower(Str::plural(Str::snake($slice->name))) : '';
        $tables = array_filter([$primary]);

        if (! empty($slice->tables)) {
            foreach ($slice->tables as $t) {
                if (! in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }

        $modelFiles = glob($slice->path.'/Models/*.php') ?: [];
        foreach ($modelFiles as $mf) {
            $c = @file_get_contents($mf);
            if ($c && preg_match('/protected\\s+\\$table\\s*=\\s*[\\\'\"]([^\\\'\"]+)[\\\'\"]/', $c, $m)) {
                if (! in_array($m[1], $tables, true)) {
                    $tables[] = $m[1];
                }
            } else {
                $t = strtolower(Str::plural(Str::snake(basename($mf, '.php'))));
                if (! in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }

        $migrationFiles = array_merge(
            glob($slice->path.'/Database/Migrations/*.php') ?: [],
            glob($slice->path.'/Migrations/*.php') ?: []
        );
        foreach ($migrationFiles as $mf) {
            $c = @file_get_contents($mf);
            if ($c && preg_match_all('/Schema::(?:create|table)\\([\\\'\"]([^\\\'\"]+)[\\\'\"]/', $c, $matches)) {
                foreach ($matches[1] as $t) {
                    if (! in_array($t, $tables, true)) {
                        $tables[] = $t;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($tables)));
    }

    /**
     * Destroy a slice with granular removal modes.
     * Modes: 'complete' (code + db), 'code_only', 'db_only', 'wipe_data'
     */
    public function destroySlice(string $sliceName, string $mode = 'complete'): array
    {
        $this->discover();
        $slice = $this->getSlice($sliceName);
        if (! $slice) {
            foreach ($this->getAllSlices() as $s) {
                if (strtolower($s->name) === strtolower($sliceName)) {
                    $slice = $s;
                    break;
                }
            }
        }

        if (! $slice) {
            throw new \InvalidArgumentException("Slice [{$sliceName}] not found.");
        }

        if ($this->isProtectedSlice($slice)) {
            throw new \RuntimeException("Slice [{$slice->name}] is a core LaraSlice slice and cannot be destroyed.");
        }

        $tables = array_values(array_diff($this->getSliceTables($slice), $this->protectedTables()));

        // Collect role slugs associated with this slice before code deletion
        $sliceRoleSlugs = [];
        $sliceManifestRoles = $slice->raw['roles'] ?? ($slice->raw['workflow']['roles'] ?? []);
        if (empty($sliceManifestRoles) && file_exists($slice->path.'/slice.yaml')) {
            $sJson = json_decode(@file_get_contents($slice->path.'/slice.yaml'), true) ?: [];
            $sliceManifestRoles = $sJson['roles'] ?? ($sJson['workflow']['roles'] ?? []);
        }
        if (empty($sliceManifestRoles) && file_exists($slice->path.'/slice.yaml') && class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $sYaml = \Symfony\Component\Yaml\Yaml::parse(@file_get_contents($slice->path.'/slice.yaml')) ?: [];
            $sliceManifestRoles = $sYaml['roles'] ?? ($sYaml['workflow']['roles'] ?? []);
        }
        foreach ($sliceManifestRoles as $r) {
            $slug = is_array($r) ? ($r['slug'] ?? '') : (is_string($r) ? $r : ($r->slug ?? ''));
            if ($slug) {
                $sliceRoleSlugs[] = $slug;
                $sliceRoleSlugs[] = str_replace('_', '-', $slug);
                $sliceRoleSlugs[] = str_replace('-', '_', $slug);
            }
        }
        $sliceRoleSlugs = array_values(array_unique(array_filter($sliceRoleSlugs)));

        $droppedTables = [];
        $wipedTables = [];
        $codeRemoved = false;

        // 1. Database operations
        if ($mode === 'complete' || $mode === 'db_only') {
            Schema::disableForeignKeyConstraints();
            foreach ($tables as $t) {
                if (! empty($t) && Schema::hasTable($t)) {
                    Schema::dropIfExists($t);
                    $droppedTables[] = $t;
                }
            }
            try {
                if (Schema::hasTable('migrations')) {
                    $migrationFiles = array_merge(
                        glob($slice->path.'/Database/Migrations/*.php') ?: [],
                        glob($slice->path.'/Migrations/*.php') ?: []
                    );
                    foreach ($migrationFiles as $mf) {
                        $migBase = basename($mf, '.php');
                        DB::table('migrations')->where('migration', $migBase)->delete();
                    }
                }
            } catch (\Throwable) {
            }

            // Remove workflows associated with this slice
            try {
                if (Schema::hasTable('laraslice_workflows')) {
                    $sliceWfs = DB::table('laraslice_workflows')
                        ->where('slice_name', $slice->name)
                        ->orWhere('slice_name', strtolower($slice->name))
                        ->orWhere('slice_name', Str::snake($slice->name))
                        ->get();

                    foreach ($sliceWfs as $mw) {
                        if (Schema::hasTable('laraslice_workflow_transitions')) {
                            $transIds = DB::table('laraslice_workflow_transitions')->where('workflow_id', $mw->id)->pluck('id');
                            if (Schema::hasTable('laraslice_workflow_routing_rules') && $transIds->isNotEmpty()) {
                                DB::table('laraslice_workflow_routing_rules')->whereIn('transition_id', $transIds)->delete();
                            }
                            DB::table('laraslice_workflow_transitions')->where('workflow_id', $mw->id)->delete();
                        }
                        if (Schema::hasTable('laraslice_workflow_states')) {
                            DB::table('laraslice_workflow_states')->where('workflow_id', $mw->id)->delete();
                        }
                        if (Schema::hasTable('laraslice_workflow_logs')) {
                            DB::table('laraslice_workflow_logs')->where('workflow_id', $mw->id)->delete();
                        }
                        DB::table('laraslice_workflows')->where('id', $mw->id)->delete();
                    }
                }
            } catch (\Throwable) {
            }

            // Remove slice-defined roles and their demo users
            try {
                if (Schema::hasTable('roles') && ! empty($sliceRoleSlugs)) {
                    $protectedRoleSlugs = ['super-admin', 'super_admin', 'admin', 'administrator', 'manager', 'user', 'standard-user', 'guest', 'system'];
                    $rolesToRemove = DB::table('roles')
                        ->whereIn('slug', $sliceRoleSlugs)
                        ->whereNotIn('slug', $protectedRoleSlugs)
                        ->get();

                    foreach ($rolesToRemove as $rObj) {
                        if (Schema::hasTable('permission_role')) {
                            DB::table('permission_role')->where('role_id', $rObj->id)->delete();
                        }
                        if (Schema::hasTable('role_user')) {
                            DB::table('role_user')->where('role_id', $rObj->id)->delete();
                        }
                        if (Schema::hasTable('users')) {
                            $cleanSlug = str_replace('_', '-', $rObj->slug);
                            DB::table('users')->whereIn('email', ["{$cleanSlug}@laraslice.com", "{$rObj->slug}@laraslice.com"])->delete();
                        }
                        DB::table('roles')->where('id', $rObj->id)->delete();
                    }
                }
            } catch (\Throwable) {
            }

            Schema::enableForeignKeyConstraints();
        } elseif ($mode === 'wipe_data') {
            Schema::disableForeignKeyConstraints();
            foreach ($tables as $t) {
                if (! empty($t) && Schema::hasTable($t)) {
                    DB::table($t)->truncate();
                    $wipedTables[] = $t;
                }
            }
            Schema::enableForeignKeyConstraints();
        }

        // 2. Code deletion
        if ($mode === 'complete' || $mode === 'code_only') {
            $slicePath = $slice->path;
            if (is_dir($slicePath)) {
                $this->recursiveDeleteDir($slicePath);
                $codeRemoved = true;

                // A domain folder (app/Slices/Helpdesk) goes with its last slice; the slices root stays
                $domainDir = dirname($slicePath);
                $root = realpath((string) config('laraslice.slices_path', app_path('Slices')));
                if ($root !== false && realpath($domainDir) !== $root && str_starts_with((string) realpath($domainDir), $root) && glob($domainDir.'/*') === []) {
                    @rmdir($domainDir);
                }
            }
            unset($this->slices[$slice->name]);
        }

        $this->clearCache();
        try {
            $this->syncPermissions(prune: true);
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'slice' => $sliceName,
            'mode' => $mode,
            'code_removed' => $codeRemoved,
            'tables_dropped' => $droppedTables,
            'tables_wiped' => $wipedTables,
            'message' => "Slice [{$sliceName}] successfully processed with mode [{$mode}].",
        ];
    }

    /**
     * Destroy an entire domain with granular options.
     */
    public function destroyDomain(string $domainName, string $mode = 'complete'): array
    {
        $this->discover();
        $slices = [];
        foreach ($this->getAllSlices() as $s) {
            $sliceDomain = $s->domain ?? $s->navigation['group'] ?? $s->raw['domain'] ?? null;
            $sliceApp = $s->app ?? $s->super_domain ?? $s->raw['app'] ?? null;
            $hierarchical = ($sliceApp && $sliceDomain && strtolower($sliceApp) !== strtolower($sliceDomain))
                ? "{$sliceApp} › {$sliceDomain}"
                : ($sliceDomain ?: $sliceApp);

            if ((strtolower(trim((string) $sliceDomain)) === strtolower(trim($domainName))
                || strtolower(trim((string) $sliceApp)) === strtolower(trim($domainName))
                || strtolower(trim((string) $hierarchical)) === strtolower(trim($domainName)))
                && ! $this->isProtectedSlice($s)) {
                $slices[] = $s;
            }
        }

        $results = [];
        $domainDirs = [];

        foreach ($slices as $slice) {
            $domainDirs[] = dirname($slice->path);
            $results[$slice->name] = $this->destroySlice($slice->name, $mode);
        }

        if ($mode === 'complete' || $mode === 'code_only') {
            // 1. Remove master data seeder files from database/seeders
            $studlyDomain = Str::studly($domainName);
            $seederFiles = array_filter(array_unique(array_merge(
                [
                    database_path("seeders/{$studlyDomain}MasterDataSeeder.php"),
                    database_path("seeders/{$domainName}MasterDataSeeder.php"),
                    database_path('seeders/'.Str::slug($domainName).'MasterDataSeeder.php'),
                ],
                glob(database_path("seeders/*{$studlyDomain}*Seeder.php")) ?: []
            )));
            foreach ($seederFiles as $sf) {
                if (file_exists($sf)) {
                    @unlink($sf);
                }
            }

            // 2. Remove entire domain folder under app/Slices (including MasterData, leftovers)
            $slicesBasePath = realpath((string) config('laraslice.slices_path', app_path('Slices')));
            if ($slicesBasePath && is_dir($slicesBasePath)) {
                $domainFolders = glob($slicesBasePath.'/*', GLOB_ONLYDIR) ?: [];
                foreach ($domainFolders as $df) {
                    if (strtolower(basename($df)) === strtolower(trim($domainName))) {
                        $this->recursiveDeleteDir($df);
                    }
                }
            }

            foreach (array_unique($domainDirs) as $dDir) {
                if (is_dir($dDir)) {
                    $this->recursiveDeleteDir($dDir);
                }
            }
        }

        $this->clearCache();
        try {
            $this->syncPermissions(prune: true);
        } catch (\Throwable) {
        }

        return [
            'success' => true,
            'domain' => $domainName,
            'mode' => $mode,
            'slices' => $results,
            'message' => "Domain [{$domainName}] processed with mode [{$mode}].",
        ];
    }

    protected function recursiveDeleteDir(string $dir): bool
    {
        if (! is_dir($dir)) {
            return false;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $p = $dir.DIRECTORY_SEPARATOR.$file;
            is_dir($p) ? $this->recursiveDeleteDir($p) : @unlink($p);
        }

        return @rmdir($dir);
    }

    /**
     * Ensure index.blade.php loads directory columns (with formatters) from slice.yaml at runtime.
     */
    protected function injectRuntimeDirectoryColumns(string $content, string $sliceName): string
    {
        $marker = 'DirectoryUi::columnsForSlice';
        $hasRuntime = str_contains($content, $marker);
        $hasStale = str_contains($content, '__DIR__')
            && str_contains($content, 'load formatters from slice.yaml');
        $hasBakedAbs = str_contains($content, 'absolute path baked at repair time');

        if ($hasRuntime && ! $hasStale && ! $hasBakedAbs) {
            return $content;
        }

        // Strip prior loader variants (DIR, absolute, previous DirectoryUi)
        if (str_contains($content, 'load formatters from slice.yaml')
            || str_contains($content, '$__mf')
            || str_contains($content, $marker)
            || $hasBakedAbs) {
            $content = preg_replace(
                '/\n\s*\/\/ Runtime: load formatters from slice\.json.*?(?=\n\s*\$rows\s*=)/s',
                "\n",
                $content,
                1
            ) ?? $content;
            $content = preg_replace(
                '/\n\s*\$columns\s*=\s*\\\\LaraSlice\\\\Support\\\\DirectoryUi::columnsForSlice\([^;]+;\s*/s',
                "\n",
                $content,
                1
            ) ?? $content;
        }

        $nameEsc = addslashes($sliceName);
        $snippet = <<<BLADE

        // Runtime: load formatters from slice.yaml via registered slice path (works for vendor + path installs)
        \$columns = \\LaraSlice\\Support\\DirectoryUi::columnsForSlice('{$nameEsc}', \$columns);

BLADE;

        $needles = [
            '$rows = \LaraSlice\Support\DataTableRows::from',
            '$rows = \\LaraSlice\\Support\\DataTableRows::from',
        ];
        foreach ($needles as $needle) {
            $pos = strpos($content, $needle);
            if ($pos !== false) {
                return substr($content, 0, $pos).$snippet.$needle.substr($content, $pos + strlen($needle));
            }
        }

        if (preg_match('/\$rows\s*=\s*\\\\?LaraSlice\\\\Support\\\\DataTableRows::from/', $content, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1];
            $matched = $m[0][0];

            return substr($content, 0, $pos).$snippet.$matched.substr($content, $pos + strlen($matched));
        }

        return $content;
    }

}
