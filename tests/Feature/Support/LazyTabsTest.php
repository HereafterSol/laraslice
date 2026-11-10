<?php

namespace LaraSlice\Tests\Feature\Support;

use Illuminate\Database\Eloquent\Model;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Tests\TestCase;

class LazyTabsRelationStub
{
    public function __construct(private int $n) {}

    public function count(): int
    {
        return $this->n;
    }

    public function getRelated(): Model
    {
        return new LazyTabsRecord;
    }
}

class LazyTabsRecord extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function tasks(): LazyTabsRelationStub
    {
        return new LazyTabsRelationStub(2);
    }

    public function projects(): LazyTabsRelationStub
    {
        return new LazyTabsRelationStub(3);
    }

    public function notes(): LazyTabsRelationStub
    {
        return new LazyTabsRelationStub(4);
    }
}

/**
 * Lazy hub tabs (performance programme, 9 Nov 2026): with
 * ui.show.lazy_tabs declared, relation + permissions tabs
 * other than the landing tab keep their count but skip row
 * enrichment (the panel loads from the fragment endpoint).
 * In onlyHandle mode exactly one tab is enriched — the
 * fragment endpoint's contract.
 */
class LazyTabsTest extends TestCase
{
    private function manifest(): array
    {
        return ['ui' => ['show' => [
            'lazy_tabs' => true,
            'default_tab' => 'tasks',
            'tabs' => [
                ['handle' => 'tasks', 'label' => 'Tasks', 'type' => 'relation', 'relation' => 'tasks'],
                ['handle' => 'projects', 'label' => 'Projects', 'type' => 'relation', 'relation' => 'projects'],
                ['handle' => 'permissions', 'label' => 'Permissions', 'type' => 'permissions'],
            ],
        ]]];
    }

    public function test_non_default_tabs_are_lazy_with_counts_and_no_rows(): void
    {
        $record = new LazyTabsRecord;
        $record->forceFill(['id' => 7]);

        $tabs = collect(DirectoryUi::relatedTabsFromManifest($this->manifest(), $record))->keyBy('handle');

        // The landing tab is never lazy — it is what the
        // viewer came for and renders with the page.
        $this->assertEmpty($tabs['tasks']['lazy'] ?? null);

        // A non-default relation tab: count present (the
        // strip badge), rows absent (no enrichment ran).
        $this->assertTrue($tabs['projects']['lazy'] ?? false);
        $this->assertSame(3, $tabs['projects']['count']);
        $this->assertArrayNotHasKey('rows', $tabs['projects']);

        // The permissions matrix is the heaviest panel in
        // the framework; lazy mode must skip its enrichment.
        $this->assertTrue($tabs['permissions']['lazy'] ?? false);
        $this->assertArrayNotHasKey('matrix', $tabs['permissions']);
    }

    public function test_only_handle_mode_enriches_exactly_the_requested_tab(): void
    {
        $record = new LazyTabsRecord;
        $record->forceFill(['id' => 7]);

        $tabs = collect(DirectoryUi::relatedTabsFromManifest($this->manifest(), $record, 'projects'))->keyBy('handle');

        // The requested tab went through full enrichment
        // (no lazy flag); in onlyHandle mode the lazy rule
        // must not fire for it even though lazy_tabs is on.
        $this->assertEmpty($tabs['projects']['lazy'] ?? null);
        $this->assertSame(3, $tabs['projects']['count']);
    }

    public function test_thread_render_tabs_are_never_lazy(): void
    {
        // Goldfish finding F1: the fragment endpoint does
        // not serve thread renders — flagging one lazy
        // would strand its panel on the placeholder.
        $manifest = $this->manifest();
        $manifest['ui']['show']['tabs'][] = ['handle' => 'discussion', 'label' => 'Discussion', 'type' => 'relation', 'relation' => 'notes', 'render' => 'thread'];

        $record = new LazyTabsRecord;
        $record->forceFill(['id' => 7]);

        $tabs = collect(DirectoryUi::relatedTabsFromManifest($manifest, $record))->keyBy('handle');

        $this->assertEmpty($tabs['discussion']['lazy'] ?? null);
        $this->assertSame(4, $tabs['discussion']['count']);
    }

    public function test_without_lazy_tabs_nothing_is_flagged(): void
    {
        $manifest = $this->manifest();
        unset($manifest['ui']['show']['lazy_tabs']);

        $record = new LazyTabsRecord;
        $record->forceFill(['id' => 7]);

        foreach (DirectoryUi::relatedTabsFromManifest($manifest, $record) as $tab) {
            $this->assertEmpty($tab['lazy'] ?? null, 'tab '.$tab['handle'].' must not be lazy without the declaration');
        }
    }
}
