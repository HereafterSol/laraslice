<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * H1 (HR visual parity program): hub tabs render the RELATED slice's
 * declared list definition instead of a generic ID/Summary/Status
 * skeleton. DirectoryUi enriches each tab with the related slice's
 * directory columns + DataTableRows-rendered rows; the generator
 * template and the repair pass both render them through the shared
 * partial, keeping the skeleton only as the unresolved fallback.
 */
class RelatedTabsEnrichmentTest extends TestCase
{
    public function test_generator_show_template_renders_enriched_tab_columns(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Generator/SliceGenerator.php');
        $this->assertStringContainsString('partials.related-tab-table', $src);
        $this->assertStringContainsString("\$rtab['columns']", $src);
    }

    public function test_shared_partial_renders_declared_columns(): void
    {
        $partial = (string) file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/partials/related-tab-table.blade.php');
        $this->assertStringContainsString("\$rtab['columns']", $partial);
        $this->assertStringContainsString("\$rtab['rows']", $partial);
        $this->assertStringNotContainsString('Summary</th>', $partial, 'the partial must never resurrect the skeleton headers');
    }

    public function test_repair_pass_wraps_legacy_skeleton(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('(r) H1 hub tabs', $src);
        $this->assertStringContainsString('related-tab-table', $src);
    }

    public function test_repair_pass_brings_detail_composition_and_identity(): void
    {
        // (s) detailRows-first parameters card + declared detail title;
        // (t) declared identity subtitle. Old blades must keep
        // receiving both through slice:repair.
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('(s) H3 detail composition', $src);
        $this->assertStringContainsString('(t) H3 identity subtitle', $src);
    }

    public function test_detail_composition_helpers_exist(): void
    {
        // H3 contracts on DirectoryUi: declared detail_fields win over
        // the attribute dump, the card title is declarable, and the
        // identity subtitle resolves declared `from` paths.
        $this->assertTrue(method_exists(DirectoryUi::class, 'detailTitle'));
        $this->assertTrue(method_exists(DirectoryUi::class, 'identitySubtitle'));
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/DirectoryUi.php');
        $this->assertStringContainsString("['ui']['show']['detail_fields']", $src);
        $this->assertStringContainsString('listingBusinessObjects', $src, 'tabs decorate through the related slice service (H1)');
    }

    public function test_tab_content_contract_v2_surface_exists(): void
    {
        // Tab content contract v2: a resolved tab carries the
        // related slice's action surface (routes, inline editor,
        // scoped create) — derived from its Heart, never hardcoded
        // per hub.
        $ui = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/DirectoryUi.php');
        $this->assertStringContainsString('function tabActionSurface', $ui);
        $this->assertStringContainsString('function routePrefixForSlice', $ui);
        $this->assertStringContainsString('function fieldOptions', $ui);
        $partial = (string) file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/partials/related-tab-table.blade.php');
        $this->assertStringContainsString('data-tab-list', $partial);
        $this->assertStringContainsString('data-inline-select', $partial);
        $this->assertStringContainsString('data-tab-export', $partial);
    }

    public function test_list_contract_v3_repair_rules_exist(): void
    {
        // List contract v3: density + inline wiring (u), live
        // Export (v), chrome consolidation (w), quick-filter
        // slotting (x) — existing blades reconcile via repair.
        $src = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        foreach (['(u) List contract v3 wiring', '(v) The top-bar Export button shipped', '(w) Chrome consolidation (list contract v3)', '(x) Quick filters render inside the data'] as $marker) {
            $this->assertStringContainsString($marker, $src);
        }
        $this->assertStringContainsString('function slotQuickFilters', $src);
        $rows = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/DataTableRows.php');
        $this->assertStringContainsString('$pct >= 90', $rows, 'progress thresholds follow the source standard (QA6 evidence: <50 red, 50-89 amber, >=90 green)');
    }

    public function test_v3_followthrough_machinery_exists(): void
    {
        $mgr = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Discovery/SliceManager.php');
        $this->assertStringContainsString('function slotTableActions', $mgr, 'repair (y): Filters button slot');
        $this->assertStringContainsString('Identity header compaction', $mgr, 'repair (z)');
        $ctl = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('inlineFieldWriter', $ctl, 'inline actions delegate to the slice service writer');
        $ui = (string) file_get_contents(dirname(__DIR__, 3).'/src/Support/DirectoryUi.php');
        $this->assertStringContainsString('normalizeOptions($c[\'options\'] ?? null)', $ui, 'column-declared options win');
        $drawer = (string) file_get_contents(dirname(__DIR__, 3).'/src/Wizard/views/directory/filters-drawer.blade.php');
        $this->assertStringContainsString('open-ls-filters.window', $drawer, 'drawer opens from the toolbar button event');
        $this->assertStringContainsString('(aa) Facet selects', $mgr, 'repair (aa): facet select loop');
        $this->assertStringContainsString('new window.URL', $mgr, 'inline handlers must use window.URL (document.URL shadows the constructor)');
        $ctl2 = (string) file_get_contents(dirname(__DIR__, 3).'/src/Core/Base/BaseSliceWebController.php');
        $this->assertStringContainsString('function resolveLookupOptions', $ctl2, 'lookup: facet option source');
        $this->assertStringContainsString('function relocateDirectoryActions', $mgr, 'repair (ac): page-top composition');
        $this->assertStringContainsString('belowToolbar', (string) file_get_contents(dirname(__DIR__, 3).'/resources/stubs/starter/views/components/ui/data-table.blade.php'), 'component carries the below-toolbar actions band');
        $this->assertStringContainsString("'demoSeed'", $ctl2, 'seed chrome is declaration-gated');
    }

    public function test_enrichment_never_breaks_tab_resolution_without_a_container(): void
    {
        // A record whose relation exists but cannot be resolved to a
        // slice (no app container in unit context) must come back with
        // its tab intact — enrichment is best-effort, never fatal.
        $record = new class
        {
            public function items(): object
            {
                return new class
                {
                    public function count(): int
                    {
                        return 0;
                    }
                };
            }
        };
        $manifest = [
            'ui' => ['show' => ['tabs' => [
                ['handle' => 'items', 'label' => 'Items', 'type' => 'relation', 'relation' => 'items'],
            ]]],
            'models' => [['name' => 'order', 'relations' => []]],
        ];
        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);
        $this->assertCount(1, $tabs);
        $this->assertSame('items', $tabs[0]['relation']);
    }
}
