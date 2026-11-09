<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Blueprint\BlueprintApplier;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Generator\HeartUiCanonicalizer;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

/**
 * OBS-B2 — one producer form for all Hearts.
 *
 * A Studio-born (blueprint-applied) Heart used to be written in
 * the design factory's compact ui shape — directory columns
 * without `key`/`type`, no summary_cards/bulk_actions/views/
 * default_view/inline_edit blocks, forms.presentation as a
 * {create, edit} map — while the Manage editor's save path
 * produces the expanded canonical (port) form. The first Manage
 * save on such a slice therefore previewed a ~5.5K normalization
 * diff around a one-setting change. These tests pin the fix:
 * the applier/generator emit the canonical form at birth, so
 * the first Manage preview shows only the user's change.
 */
final class HeartProducerFormTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-obsb2-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    /**
     * Apply the factory-shaped blueprint (compact ui, exactly as
     * the Studio design factory emits it) and return
     * [sliceDir, manifest].
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function applyFactoryBlueprint(): array
    {
        $path = $this->temporaryDirectory.'/probe.slice.yaml';
        file_put_contents($path, <<<'YAML'
            schema_version: 1
            name: 'Probe Record'
            handle: probe_record
            domain: HR
            description: 'OBS-B2 producer-form probe.'
            ui:
              presentation:
                create: drawer
                edit: drawer
              directory:
                columns:
                  -
                    handle: employee_id
                    label: Employee
                    type: composite_user
                    sortable: true
                  -
                    handle: course_name
                    label: Course Name
                    type: text
                    sortable: true
                quick_filters:
                  -
                    handle: employee_id
                    label: Employee
                    is_searchable: true
                sidebar_filters: []
                row_actions:
                  display: dropdown
                  items:
                    -
                      handle: view
                      label: View Details
                      icon: eye
                      placement: dropdown
                      type: drawer
                bulk_actions: []
              forms:
                presentation:
                  create: drawer
                  edit: drawer
                sections:
                  -
                    key: general_information
                    title: General Information
                    grid: 3
                    fields:
                      -
                        handle: employee_id
                        span: 1
                        is_searchable: true
                      -
                        handle: course_name
                        span: 1
                        is_searchable: false
            models:
              -
                handle: probe_record
                table: probe_records
                root: true
                fields:
                  -
                    handle: employee_id
                    label: Employee
                    type: foreign_id
                    required: true
                  -
                    handle: course_name
                    label: Course Name
                    type: string
                    required: true
                relations:
                  -
                    name: employee
                    type: belongsTo
                    model: employee_detail
                    foreign_key: employee_id
                    table: employee_details
            YAML);

        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($path));
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->temporaryDirectory.'/Slices');
        $target = (new BlueprintApplier)->apply($blueprint, $plan, $this->temporaryDirectory.'/Slices', 'App\\Slices');
        $manifest = ManifestRepository::read($target.'/slice.yaml');

        return [$target, $manifest];
    }

    public function test_applier_born_heart_is_written_in_the_canonical_producer_form(): void
    {
        [$target, $manifest] = $this->applyFactoryBlueprint();
        $directory = $manifest['ui']['directory'] ?? [];

        // Columns carry their identity keys, in the port order,
        // with the F10 relation enrichment preserved as extras.
        $this->assertNotEmpty($directory['columns'] ?? null);
        foreach ($directory['columns'] as $column) {
            $handle = $column['handle'] ?? $column['key'] ?? null;
            $this->assertNotNull($handle);
            $this->assertSame($handle, $column['key'] ?? null, 'column key must equal its handle');
            $this->assertArrayHasKey('formatter', $column);
            $this->assertArrayHasKey('type', $column);
        }
        $employee = $directory['columns'][0];
        $this->assertSame(
            ['handle', 'key', 'label', 'formatter', 'type', 'sortable', 'relation', 'label_table'],
            array_keys($employee),
            'column keys land in the canonical (port) order with declared extras preserved'
        );
        $this->assertSame('employee', $employee['relation']);
        $this->assertSame('employee_details', $employee['label_table']);

        // The resting directory blocks the Manage payload always
        // carries are declared at birth — nothing left for a
        // first save to materialize.
        foreach (['sidebar_filters', 'summary_cards', 'views', 'default_view', 'inline_edit', 'bulk_actions'] as $key) {
            $this->assertArrayHasKey($key, $directory, "directory must declare {$key} at birth");
        }
        $this->assertSame(
            [
                ['handle' => 'delete', 'label' => 'Delete', 'type' => 'native'],
                ['handle' => 'export', 'label' => 'Export', 'type' => 'native'],
            ],
            $directory['bulk_actions'],
            'an undeclared/empty bulk_actions is born as the Manage panel defaults'
        );
        $this->assertSame(['table'], $directory['views']);
        $this->assertSame('table', $directory['default_view']);

        // Forms sit in the port form: no resting mode/presentation.
        $this->assertArrayNotHasKey('mode', $manifest['ui']['forms'] ?? []);
        $this->assertArrayNotHasKey('presentation', $manifest['ui']['forms'] ?? []);
        $this->assertNotEmpty($manifest['ui']['forms']['sections'] ?? null);

        // The emitted Heart is a fixed point of the canonicalizer
        // itself (idempotence on the real artefact).
        $fields = $manifest['models'][0]['fields'] ?? [];
        $this->assertSame(
            $manifest['ui'],
            HeartUiCanonicalizer::canonicalize($manifest['ui'], $fields),
            'the applier-emitted ui must already be in canonical producer form'
        );

        // Canonical serializer round trip: re-writing the read
        // manifest through ManifestRepository::writeYaml (the one
        // writer every producer shares) is byte-identical.
        $rewriteDir = $this->temporaryDirectory.'/Rewrite';
        mkdir($rewriteDir, 0755, true);
        ManifestRepository::writeYaml($rewriteDir, $manifest);
        $this->assertSame(
            file_get_contents($target.'/slice.yaml'),
            file_get_contents($rewriteDir.'/slice.yaml'),
            'the emitted Heart must be byte-stable under the canonical serializer'
        );
    }

    public function test_first_manage_preview_on_an_applier_born_heart_shows_only_the_change(): void
    {
        [$target, $manifest] = $this->applyFactoryBlueprint();
        $modifier = new SliceModifier(dirname($target), 'App\\Slices');
        $sliceName = basename($target);

        // The payload the Manage panel builds from THIS Heart
        // (hydration of declared state + the panel's model
        // overlay and component defaults), with default_view
        // flipped table -> board as the one user change.
        [$ui, $navigation] = $this->panelPayloadFor($manifest, 'board');

        $preview = $modifier->previewStudioConfig($sliceName, $ui, $navigation, null);

        $this->assertTrue($preview['changed']);
        $this->assertStringContainsString('default_view', $preview['diff']);
        $changedLines = array_values(array_filter(
            explode("\n", $preview['diff']),
            static fn (string $line): bool => (str_starts_with($line, '+') || str_starts_with($line, '-')) && ! str_starts_with($line, '+++') && ! str_starts_with($line, '---')
        ));
        $this->assertLessThanOrEqual(
            6,
            count($changedLines),
            'a one-setting change on an applier-born Heart must preview as a one-setting diff; got: '.implode(' | ', $changedLines)
        );
        foreach (['summary_cards', 'bulk_actions', 'sidebar_filters', 'inline_edit', 'label_table'] as $noise) {
            $this->assertStringNotContainsString($noise, $preview['diff'], "the preview must not churn {$noise}");
        }
    }

    public function test_canonicalizer_is_a_no_op_on_port_form_ui(): void
    {
        // The Tasks-shaped port form: fully declared, with
        // unmodelled keys (inferred_from, subtitle_from), a
        // declared report block and bulk actions with icons.
        $portUi = [
            'presentation' => ['create' => 'drawer', 'edit' => 'drawer'],
            'directory' => [
                'columns' => [
                    ['handle' => 'heading', 'key' => 'heading', 'label' => 'Task', 'formatter' => 'text_with_badge', 'type' => 'text_with_badge', 'sortable' => true, 'inferred_from' => 'editColumn', 'subtitle_from' => 'project.project_name'],
                    ['handle' => 'board_column_id', 'key' => 'board_column_id', 'label' => 'Status', 'formatter' => 'text', 'type' => 'text', 'sortable' => true, 'inline_edit' => true],
                ],
                'searchable' => ['heading'],
                'sidebar_filters' => [['handle' => 'project_id', 'label' => 'Project', 'type' => 'select']],
                'summary_cards' => [],
                'views' => ['table', 'board'],
                'default_view' => 'table',
                'inline_edit' => ['board_column_id'],
                'bulk_actions' => [
                    ['handle' => 'export', 'label' => 'Export Selected', 'type' => 'native', 'icon' => 'download'],
                    ['handle' => 'delete', 'label' => 'Delete Selected', 'type' => 'native', 'icon' => 'trash-2', 'danger' => true],
                ],
                'row_actions' => ['display' => 'dropdown', 'items' => [['handle' => 'view', 'label' => 'View', 'icon' => 'eye', 'placement' => 'inline']]],
                'report' => ['title' => 'Task Report'],
            ],
            'forms' => [
                'sections' => [
                    ['key' => 'general', 'title' => 'General Information', 'grid' => 2, 'fields' => [['handle' => 'heading', 'span' => 1, 'is_searchable' => false]]],
                ],
            ],
        ];

        $this->assertSame($portUi, HeartUiCanonicalizer::canonicalize($portUi, [
            ['name' => 'heading', 'type' => 'string', 'label' => 'Task'],
            ['name' => 'board_column_id', 'type' => 'integer', 'label' => 'Status'],
        ]), 'port-form ui is already canonical — the canonicalizer must not touch it');
    }

    public function test_canonicalizer_preserves_non_resting_forms_and_is_idempotent(): void
    {
        // Non-resting forms declarations are the author's: kept.
        $pageForms = ['forms' => ['presentation' => 'page', 'sections' => []]];
        $this->assertSame($pageForms, HeartUiCanonicalizer::canonicalize($pageForms));
        $modeForms = ['forms' => ['mode' => 'page', 'sections' => []]];
        $this->assertSame($modeForms, HeartUiCanonicalizer::canonicalize($modeForms));

        // The factory shape normalizes once, then is stable.
        $factoryUi = [
            'directory' => [
                'columns' => [['handle' => 'title', 'label' => 'Title', 'formatter' => 'text', 'sortable' => true]],
            ],
            'forms' => ['presentation' => ['create' => 'drawer', 'edit' => 'drawer'], 'sections' => [['key' => 'general', 'title' => 'General', 'grid' => 2, 'fields' => []]]],
        ];
        $once = HeartUiCanonicalizer::canonicalize($factoryUi, [['name' => 'title', 'type' => 'string', 'label' => 'Title']]);
        $twice = HeartUiCanonicalizer::canonicalize($once, [['name' => 'title', 'type' => 'string', 'label' => 'Title']]);
        $this->assertSame($once, $twice, 'canonicalize must be idempotent');
        $this->assertArrayNotHasKey('presentation', $once['forms']);
        $this->assertSame('title', $once['directory']['columns'][0]['key']);
        $this->assertSame('text', $once['directory']['columns'][0]['type']);
    }

    /**
     * Build the payload the Manage panel (buildUiConfig +
     * buildStudioConfigPayload) produces for the given Heart:
     * declared state hydrated verbatim, the field-model overlay
     * on columns (key/type always emitted; searchable/sortable
     * only when declared), component defaults where the Heart
     * declares nothing, and the resting forms strings the server
     * prunes. $defaultView is the one user change.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function panelPayloadFor(array $manifest, string $defaultView): array
    {
        $directory = $manifest['ui']['directory'] ?? [];
        $fieldLabels = [];
        foreach ($manifest['models'][0]['fields'] ?? [] as $field) {
            $fieldLabels[$field['handle']] = $field['label'] ?? null;
        }

        $columns = [];
        foreach ($directory['columns'] ?? [] as $declared) {
            $handle = $declared['handle'] ?? $declared['key'];
            $entry = $declared;
            $entry['key'] = $handle;
            $entry['label'] = $declared['label'] ?? $fieldLabels[$handle] ?? $handle;
            $entry['type'] = $declared['formatter'] ?? $declared['type'] ?? 'text';
            $entry['formatter'] = $declared['formatter'] ?? $declared['type'] ?? 'text';
            if (! array_key_exists('sortable', $declared)) {
                unset($entry['sortable']);
            }
            $columns[] = $entry;
        }

        $ui = [
            'presentation' => $manifest['ui']['presentation'] ?? ['create' => 'drawer', 'edit' => 'drawer'],
            'directory' => [
                'presentation' => 'table',
                'columns' => $columns,
                'quick_filters' => $directory['quick_filters'] ?? [],
                'sidebar_filters' => $directory['sidebar_filters'] ?? [],
                'summary_cards' => $directory['summary_cards'] ?? [],
                'bulk_actions' => ! empty($directory['bulk_actions'])
                    ? $directory['bulk_actions']
                    : [
                        ['handle' => 'delete', 'label' => 'Delete', 'type' => 'native'],
                        ['handle' => 'export', 'label' => 'Export', 'type' => 'native'],
                    ],
                'row_actions' => $directory['row_actions'] ?? ['display' => 'dropdown', 'items' => []],
                'views' => $directory['views'] ?? ['table'],
                'default_view' => $defaultView,
                'inline_edit' => $directory['inline_edit'] ?? [],
            ],
            'forms' => [
                'mode' => 'drawer',
                'presentation' => 'drawer',
                'sections' => $manifest['ui']['forms']['sections'] ?? [],
            ],
        ];

        return [$ui, $manifest['navigation'] ?? []];
    }
}
