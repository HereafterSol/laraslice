<?php

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use LaraSlice\Support\DirectoryConditions;
use PHPUnit\Framework\TestCase;

/**
 * Seat 3: free-form directory conditions — parsing is
 * Heart-validated (unknown fields/operators dropped)
 * and application carries real operator semantics.
 */
class DirectoryConditionsTest extends TestCase
{
    private array $manifest = [
        'fields' => [
            'lead_name' => ['name' => 'lead_name', 'label' => 'Full Name', 'type' => 'string'],
            'status' => ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                ['value' => 'lead', 'label' => 'Lead'],
                ['value' => 'converted', 'label' => 'Converted'],
            ]],
            'annual_revenue' => ['name' => 'annual_revenue', 'label' => 'Annual Revenue', 'type' => 'number'],
        ],
    ];

    public function test_parse_validates_and_labels_conditions(): void
    {
        $parsed = DirectoryConditions::parse([
            ['field' => 'status', 'op' => 'is', 'value' => 'converted'],
            ['field' => 'lead_name', 'op' => 'like', 'value' => 'Ayesha'],
            ['field' => 'status', 'op' => 'in', 'value' => 'lead, converted'],
        ], $this->manifest);

        $this->assertCount(3, $parsed);
        $this->assertSame('Converted', $parsed[0]['value_label']);
        $this->assertSame('≈', $parsed[1]['glyph']);
        $this->assertSame(['lead', 'converted'], $parsed[2]['value']);
        $this->assertSame('Lead, Converted', $parsed[2]['value_label']);
    }

    public function test_field_options_emit_map_keys_as_values(): void
    {
        // D-080: Hearts declare options as a {slug: Label} map.
        // The filter builder must offer the KEY as the value —
        // offering the label made equality filters on select
        // fields return zero rows (labels are never stored).
        $specs = DirectoryConditions::fieldOptions([
            'fields' => [
                'status' => ['name' => 'status', 'label' => 'Status', 'type' => 'string',
                    'options' => ['lead' => 'Lead', 'open' => 'Open', 'converted' => 'Converted']],
            ],
        ]);
        $status = collect($specs)->firstWhere('name', 'status');

        $this->assertSame(
            [['value' => 'lead', 'label' => 'Lead'], ['value' => 'open', 'label' => 'Open'], ['value' => 'converted', 'label' => 'Converted']],
            $status['options']
        );
    }

    public function test_parse_drops_unknown_fields_operators_and_empties(): void
    {
        $parsed = DirectoryConditions::parse([
            ['field' => 'password', 'op' => 'is', 'value' => 'x'],
            ['field' => 'status', 'op' => 'regex', 'value' => 'x'],
            ['field' => 'status', 'op' => 'is', 'value' => ''],
            ['field' => 'created_at', 'op' => 'gte', 'value' => '2026-01-01'],
            ['field' => 'lead_name', 'op' => 'not_empty'],
        ], $this->manifest);

        $this->assertCount(2, $parsed);
        $this->assertSame('Created On', $parsed[0]['label']);
        $this->assertNull($parsed[1]['value']);
    }

    public function test_apply_narrows_rows_with_operator_semantics(): void
    {
        $capsule = new Capsule;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $db = $capsule->getConnection();
        $db->statement('CREATE TABLE leads (id INTEGER PRIMARY KEY, lead_name TEXT, status TEXT, annual_revenue INTEGER)');
        $db->table('leads')->insert([
            ['id' => 1, 'lead_name' => 'Ayesha Bibi Khan', 'status' => 'lead', 'annual_revenue' => 5000],
            ['id' => 2, 'lead_name' => 'Fallback Traders', 'status' => 'converted', 'annual_revenue' => 9000],
            ['id' => 3, 'lead_name' => null, 'status' => 'lead', 'annual_revenue' => null],
        ]);

        $ids = fn (array $conditions) => DirectoryConditions::apply($db->table('leads'), $conditions)->pluck('id')->all();

        $this->assertSame([1], $ids(DirectoryConditions::parse([['field' => 'lead_name', 'op' => 'like', 'value' => 'Ayesha']], $this->manifest)));
        $this->assertSame([1, 3], $ids(DirectoryConditions::parse([['field' => 'status', 'op' => 'is_not', 'value' => 'converted']], $this->manifest)));
        $this->assertSame([2], $ids(DirectoryConditions::parse([['field' => 'annual_revenue', 'op' => 'gt', 'value' => '6000']], $this->manifest)));
        $this->assertSame([3], $ids(DirectoryConditions::parse([['field' => 'lead_name', 'op' => 'empty']], $this->manifest)));
        $this->assertSame([1, 2], $ids(DirectoryConditions::parse([['field' => 'lead_name', 'op' => 'not_empty']], $this->manifest)));
    }
}
