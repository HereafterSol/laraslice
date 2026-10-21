<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * F3: detail pages compose related-record tabs from the manifest.
 * Precedence: declared ui.show.tabs (type: relation) → root model
 * hasMany relations → repeater form fields, deduplicated by relation,
 * with live counts when the record exposes the relation method.
 */
class DetailCompositionTest extends TestCase
{
    public function test_declared_show_tabs_win_and_deduplicate(): void
    {
        $manifest = [
            'ui' => [
                'show' => ['tabs' => [
                    ['handle' => 'overview', 'label' => 'Overview', 'type' => 'fields'],
                    ['handle' => 'lines', 'label' => 'Lines', 'type' => 'relation', 'relation' => 'items'],
                    ['handle' => 'activity', 'label' => 'Activity', 'type' => 'audit'],
                ]],
                'forms' => ['sections' => [
                    ['fields' => [
                        ['handle' => 'items', 'type' => 'repeater', 'label' => 'Items'],
                    ]],
                ]],
            ],
            'models' => [
                ['name' => 'invoice', 'relations' => [
                    ['name' => 'items', 'type' => 'hasMany'],
                    ['name' => 'payments', 'type' => 'hasMany'],
                    ['name' => 'customer', 'type' => 'belongsTo'],
                ]],
            ],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest);

        $this->assertSame(['items', 'payments'], array_column($tabs, 'relation'));
        $this->assertSame('Lines', $tabs[0]['label'], 'declared tab label wins over relation fallbacks');
        $this->assertSame('Payments', $tabs[1]['label']);
    }

    public function test_repeater_fields_become_tabs_when_nothing_else_declared(): void
    {
        $manifest = [
            'ui' => ['forms' => ['sections' => [
                ['fields' => [
                    ['handle' => 'number', 'type' => 'string'],
                    ['handle' => 'lines', 'type' => 'repeater', 'label' => 'Order Lines'],
                ]],
            ]]],
            'models' => [['name' => 'order', 'relations' => []]],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest);

        $this->assertCount(1, $tabs);
        $this->assertSame('lines', $tabs[0]['relation']);
        $this->assertSame('Order Lines', $tabs[0]['label']);
    }

    public function test_counts_attach_only_when_relation_method_exists(): void
    {
        $record = new class
        {
            public function items(): object
            {
                // Like an Eloquent relation: count() arrives via __call,
                // not as a real method — the resolver must probe by calling.
                return new class
                {
                    public function __call(string $name, array $arguments): int
                    {
                        return $name === 'count' ? 3 : 0;
                    }
                };
            }
        };

        $manifest = [
            'models' => [['name' => 'invoice', 'relations' => [
                ['name' => 'items', 'type' => 'hasMany'],
                ['name' => 'payments', 'type' => 'hasMany'],
            ]]],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $record);

        $this->assertSame(3, $tabs[0]['count']);
        $this->assertArrayNotHasKey('count', $tabs[1], 'missing relation method must not break or fabricate a count');
    }

    public function test_empty_manifest_yields_no_tabs(): void
    {
        $this->assertSame([], DirectoryUi::relatedTabsFromManifest([]));
    }
}
