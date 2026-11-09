<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Metadata\MetadataProjector;
use LaraSlice\Tests\TestCase;

/**
 * Proposal #1 (ServiceStack R&D, 8 Nov 2026): the runtime
 * Heart→metadata projection.
 *
 * Pinned behaviour:
 *  - describe projects the Heart faithfully: field name/type/
 *    required, declared enum values, the FK target of a field,
 *    relations deduplicated with targets resolved to tables,
 *    operations read from the slice's own routes files;
 *  - the UI tier stays out of describe unless asked for —
 *    roughly half a Heart is UI material;
 *  - the index is identity + declared counts only (no per-field
 *    detail may leak into it) and its totals come from the one
 *    discovery source (SliceManager::inventorySummary);
 *  - the projection cache is keyed on the Heart's CONTENT HASH:
 *    editing the Heart re-projects that slice with no cache
 *    flush and no regeneration step;
 *  - the endpoints sit behind the Studio's studio.access gate.
 */
final class MetadataProjectionTest extends TestCase
{
    private string $root;

    private string $sliceDir;

    private const HEART = <<<'YAML'
        name: ProbeWidgets
        title: Probe Widgets
        version: 1.0.0
        active: true
        app: Operations
        module: Inventory
        fields:
          name:
            name: name
            label: Name
            type: string
            nullable: false
          status:
            name: status
            label: Status
            type: string
            nullable: true
            options:
              open: Open
              closed: Closed
          department_id:
            name: department_id
            label: Department
            type: integer
            nullable: true
        models:
          - handle: probe_widget
            table: probe_widgets
            root: true
            fields:
              - handle: name
                label: Name
                type: string
                required: true
              - handle: status
                label: Status
                type: string
                required: false
              - handle: department_id
                label: Department
                type: integer
                required: false
            relations:
              - name: department
                type: belongsTo
                model: Department
              - name: department
                type: belongsTo
                model: Department
                foreign_key: department_id
                table: departments
              - name: department
                type: belongsTo
                model: Department
                foreign_key: dept_id
                table: departments
              - name: entries
                type: hasMany
                model: ProbeEntry
                foreign_key: probe_widget_id
              - name: tags
                type: belongsToMany
                model: Tag
                pivot:
                  table: probe_widget_tag
                  foreign_pivot_key: probe_widget_id
                  related_pivot_key: tag_id
          - handle: probe_entry
            table: probe_entries
            root: false
            fields:
              - handle: note
                label: Note
                type: text
                required: false
            relations:
              - name: widget
                type: belongsTo
                model: probe_widget
                foreign_key: probe_widget_id
        permissions:
          - probe_widget.view
          - probe_widget.create
        ui:
          directory:
            columns:
              - handle: name
                label: Name
              - handle: status
                label: Status
            views:
              - table
        YAML;

    private const HEART_EDITED = <<<'YAML'
        name: ProbeWidgets
        title: Probe Widgets
        version: 1.0.0
        active: true
        app: Operations
        module: Inventory
        fields:
          name:
            name: name
            label: Name
            type: string
            nullable: false
          status:
            name: status
            label: Status
            type: string
            nullable: true
            options:
              open: Open
              closed: Closed
          department_id:
            name: department_id
            label: Department
            type: integer
            nullable: true
        models:
          - handle: probe_widget
            table: probe_widgets
            root: true
            fields:
              - handle: name
                label: Name
                type: string
                required: true
              - handle: status
                label: Status
                type: string
                required: false
              - handle: department_id
                label: Department
                type: integer
                required: false
              - handle: sku
                label: Sku
                type: string
                required: false
            relations:
              - name: department
                type: belongsTo
                model: Department
                foreign_key: department_id
                table: departments
        permissions:
          - probe_widget.view
          - probe_widget.create
        YAML;

    private const ROUTES = <<<'PHP'
        <?php
        use Illuminate\Support\Facades\Route;
        use App\Slices\Operations\Inventory\ProbeWidgets\Controllers\ProbeWidgetWebController;
        Route::prefix('ops/inventory/probe_widgets')->name('ops_inventory_probe_widgets.')->middleware(['web', 'auth'])->group(function () {
            Route::get('/', [ProbeWidgetWebController::class, 'index'])->name('index');
            Route::post('/', [ProbeWidgetWebController::class, 'store'])->name('store');
            Route::get('/{id}', [ProbeWidgetWebController::class, 'show'])->name('show');
        });
        Route::redirect('probe-widgets', '/ops/inventory/probe_widgets');
        PHP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/laraslice-metadata-'.uniqid();
        $this->sliceDir = $this->root.'/ProbeWidgets';
        mkdir($this->sliceDir.'/Routes', 0777, true);
        file_put_contents($this->sliceDir.'/slice.yaml', self::HEART);
        file_put_contents($this->sliceDir.'/Routes/web.php', self::ROUTES);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && is_dir($this->root)) {
            $this->app['files']->deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    private function projectorFor(array $manifests): MetadataProjector
    {
        $manager = new class($this->app, $manifests) extends SliceManager
        {
            /** @param array<int, SliceManifest> $fixtures */
            public function __construct($app, private array $fixtures)
            {
                parent::__construct($app);
            }

            public function discover(bool $force = false): void {}

            public function discoveredSlices(): array
            {
                return $this->fixtures;
            }
        };

        return new MetadataProjector($manager);
    }

    private function fixtureManifest(): SliceManifest
    {
        return new SliceManifest($this->sliceDir.'/slice.yaml');
    }

    public function test_describe_projects_fields_enums_and_fk_targets_faithfully(): void
    {
        $describe = $this->projectorFor([$this->fixtureManifest()])->describe('ProbeWidgets');

        $this->assertNotNull($describe);
        $this->assertSame('probe_widgets', $describe['table']);
        $this->assertSame('Operations Inventory', $describe['domain']);

        $fields = [];
        foreach ($describe['fields'] as $field) {
            $fields[$field['name']] = $field;
        }
        $this->assertSame(['name', 'status', 'department_id'], array_keys($fields));
        $this->assertTrue($fields['name']['required']);
        $this->assertFalse($fields['status']['required']);
        // The enum lives only in the top-level fields map — the
        // merge across Heart shapes must surface it.
        $this->assertSame(['open', 'closed'], $fields['status']['enum']);
        // The FK target resolves through the declared belongsTo.
        $this->assertSame('departments', $fields['department_id']['relation']);
    }

    public function test_describe_deduplicates_relations_and_resolves_targets_to_tables(): void
    {
        $describe = $this->projectorFor([$this->fixtureManifest()])->describe('ProbeWidgets');

        $relations = [];
        foreach ($describe['relations'] as $relation) {
            $relations[$relation['name']] = $relation;
        }

        // The porter shape declares `department` three times:
        // bare, then with detail, then a duplicate carrying the
        // same projected keys but a different fk. One entry
        // survives — the richest; a tie keeps the first of the
        // tied, so the surviving fk is `department_id`, never
        // the tied duplicate's `dept_id`.
        $this->assertSame(
            ['department', 'entries', 'tags', 'widget'],
            array_keys($relations)
        );
        $this->assertSame('departments', $relations['department']['target']);
        $this->assertSame('department_id', $relations['department']['fk']);
        // ProbeEntry resolves to its table through the models map.
        $this->assertSame('probe_entries', $relations['entries']['target']);
        $this->assertSame('probe_widget_tag', $relations['tags']['pivot']);
        $this->assertSame('probe_widgets', $relations['widget']['target']);

        // Non-root tables carry their own fields; the root table
        // is counted, not duplicated.
        $tables = [];
        foreach ($describe['tables'] as $table) {
            $tables[$table['table']] = $table;
        }
        $this->assertSame(3, $tables['probe_widgets']['field_count']);
        $this->assertArrayNotHasKey('fields', $tables['probe_widgets']);
        $this->assertSame('note', $tables['probe_entries']['fields'][0]['name']);
        // Live overlay: the fixture tables do not exist in the
        // test database, so the count is honestly null.
        $this->assertNull($tables['probe_widgets']['rows']);
    }

    public function test_describe_reads_operations_from_the_slices_own_routes(): void
    {
        $describe = $this->projectorFor([$this->fixtureManifest()])->describe('ProbeWidgets');

        $this->assertSame([
            ['method' => 'GET', 'uri' => '/ops/inventory/probe_widgets', 'name' => 'ops_inventory_probe_widgets.index'],
            ['method' => 'POST', 'uri' => '/ops/inventory/probe_widgets', 'name' => 'ops_inventory_probe_widgets.store'],
            ['method' => 'GET', 'uri' => '/ops/inventory/probe_widgets/{id}', 'name' => 'ops_inventory_probe_widgets.show'],
        ], $describe['operations']);
        $this->assertSame(['probe_widget.view', 'probe_widget.create'], $describe['auth']['permissions']);
    }

    public function test_ui_tier_is_excluded_unless_requested(): void
    {
        $projector = $this->projectorFor([$this->fixtureManifest()]);

        $core = $projector->describe('ProbeWidgets');
        $this->assertArrayNotHasKey('ui', $core);

        $full = $projector->describe('ProbeWidgets', withUi: true);
        $this->assertSame(['name', 'status'], array_column($full['ui']['columns'], 'handle'));
        $this->assertSame(['table'], $full['ui']['views']);
    }

    public function test_index_is_compact_and_its_totals_come_from_discovery(): void
    {
        $index = $this->projectorFor([$this->fixtureManifest()])->index();

        $this->assertSame(1, $index['total']);
        $this->assertSame(['Operations Inventory' => 1], $index['domains']);
        $this->assertSame(1, $index['returned']);

        $row = $index['slices'][0];
        // Identity + declared counts ONLY: no field detail, no
        // relations detail, no operations may leak into the index.
        $this->assertSame(
            ['id', 'name', 'title', 'domain', 'status', 'table', 'tables', 'fields', 'relations'],
            array_keys($row)
        );
        $this->assertSame(2, $row['tables']);
        $this->assertSame(3, $row['fields']);
        $this->assertSame(4, $row['relations']);

        // Envelope semantics under a domain filter: `total`
        // stays the fleet total while `filtered` counts the
        // filtered row set before pagination — and the label
        // match is case-insensitive.
        $filtered = $this->projectorFor([$this->fixtureManifest()])->index(['domain' => 'operations inventory']);
        $this->assertSame(1, $filtered['total']);
        $this->assertSame(1, $filtered['filtered']);
        $this->assertSame(1, $filtered['returned']);

        $none = $this->projectorFor([$this->fixtureManifest()])->index(['domain' => 'No Such Domain']);
        $this->assertSame(1, $none['total']);
        $this->assertSame(0, $none['filtered']);
        $this->assertSame(0, $none['returned']);
    }

    public function test_index_rows_format_is_tabular_and_pagination_holds(): void
    {
        $projector = $this->projectorFor([$this->fixtureManifest()]);

        $rows = $projector->index(['format' => 'rows']);
        $this->assertSame('rows', $rows['format']);
        $this->assertSame($rows['columns'][1], 'name');
        $this->assertSame('ProbeWidgets', $rows['rows'][0][1]);
        $this->assertArrayNotHasKey('slices', $rows);

        $page = $projector->index(['per_page' => 1, 'page' => 2]);
        $this->assertSame(0, $page['returned']);
        $this->assertSame(1, $page['filtered']);
    }

    public function test_a_heart_edit_reprojects_without_a_cache_flush(): void
    {
        $projector = $this->projectorFor([$this->fixtureManifest()]);
        $before = $projector->describe('ProbeWidgets');
        $this->assertCount(3, $before['fields']);

        // Edit the Heart on disk; discovery hands the projector a
        // fresh manifest for the same path (as SliceManager's
        // mtime+size guard does in production). The content-hash
        // cache key must orphan the old build on its own.
        // The same Heart after an edit: one field (sku) added to
        // the root model. Written as a full constant — no string
        // surgery on indentation-sensitive YAML.
        $heart = self::HEART_EDITED;
        $this->assertNotSame(self::HEART, $heart);
        file_put_contents($this->sliceDir.'/slice.yaml', $heart);

        $after = $this->projectorFor([$this->fixtureManifest()])->describe('ProbeWidgets');
        $this->assertCount(4, $after['fields']);
        $this->assertSame('sku', $after['fields'][3]['name']);
    }

    public function test_endpoints_are_registered_and_gated_by_studio_access(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('laraslice.studio.metadata'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('laraslice.studio.metadata_slice'));

        // Guests are refused before the gate (auth middleware).
        $this->getJson('/laraslice/studio/metadata')->assertUnauthorized();

        // An authenticated user without studio.access is refused
        // by the Studio gate itself.
        $this->actingAs($this->makeUser())
            ->getJson('/laraslice/studio/metadata')
            ->assertForbidden();
        $this->actingAs($this->makeUser())
            ->getJson('/laraslice/studio/metadata/Tasks')
            ->assertForbidden();
    }

    public function test_endpoints_serve_the_projection_and_honour_etags(): void
    {
        // Plant the fixture slice in the test app's slices path so
        // real discovery finds it, then force a rescan.
        $slicesPath = (string) config('laraslice.slices_path');
        $target = $slicesPath.'/ProbeWidgets';
        mkdir($target.'/Routes', 0777, true);
        file_put_contents($target.'/slice.yaml', self::HEART);
        file_put_contents($target.'/Routes/web.php', self::ROUTES);
        app(SliceManager::class)->discover(true);

        $user = $this->makeUserWithPermissions(['studio.access']);

        $index = $this->actingAs($user)->getJson('/laraslice/studio/metadata');
        $index->assertOk();
        $index->assertHeader('ETag');
        $names = array_column($index->json('slices'), 'name');
        $this->assertContains('ProbeWidgets', $names);
        // Discovery truth: the total is the fleet count, and the
        // framework's own core slices are part of it.
        $this->assertSame($index->json('total'), array_sum($index->json('domains')));
        $this->assertGreaterThan(1, $index->json('total'));

        // A repeat request with the served ETag costs a 304.
        $etag = $index->headers->get('ETag');
        $this->actingAs($user)
            ->getJson('/laraslice/studio/metadata', ['If-None-Match' => $etag])
            ->assertNoContent(304);

        $describe = $this->actingAs($user)->getJson('/laraslice/studio/metadata/ProbeWidgets');
        $describe->assertOk();
        $describe->assertJsonPath('table', 'probe_widgets');
        $describe->assertJsonCount(3, 'fields');
        $this->assertArrayNotHasKey('ui', $describe->json());

        $full = $this->actingAs($user)->getJson('/laraslice/studio/metadata/ProbeWidgets?ui=1');
        $full->assertOk();
        $this->assertArrayHasKey('ui', $full->json());

        $this->actingAs($user)
            ->getJson('/laraslice/studio/metadata/NoSuchSlice')
            ->assertNotFound();
    }
}
