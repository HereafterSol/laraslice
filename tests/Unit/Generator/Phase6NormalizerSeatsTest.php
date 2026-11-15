<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use LaraSlice\Generator\SliceFieldDefinitionException;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 declaration seats at the normalizer: field
 * descriptions (D-066), label_depends_on (D-019), computed
 * count_of (D-021/D-034), repeater actions (D-020), repeater
 * parent_type (D-036) and the nested-set system fields
 * (D-065). Each is carried verbatim so every regen keeps it;
 * malformed shapes are dropped or rejected at normalize
 * time, never half-carried.
 */
class Phase6NormalizerSeatsTest extends TestCase
{
    private function normalize(array $fields): array
    {
        return (new FieldDefinitionNormalizer)->normalize($fields);
    }

    public function test_nested_set_columns_are_system_fields(): void
    {
        $defs = $this->normalize([
            ['name' => 'lft', 'type' => 'integer'],
            ['name' => 'rgt', 'type' => 'integer'],
            ['name' => 'old_parent', 'type' => 'integer'],
            ['name' => 'title', 'type' => 'string'],
        ]);

        $this->assertTrue($defs[0]['system']);
        $this->assertTrue($defs[1]['system']);
        $this->assertTrue($defs[2]['system']);
        $this->assertFalse($defs[0]['show_in_form']);
        $this->assertFalse($defs[3]['system']);
    }

    public function test_description_is_trimmed_and_carried(): void
    {
        $defs = $this->normalize([
            ['name' => 'is_fixed_asset', 'type' => 'boolean', 'description' => '  Enable if this item is a company asset. '],
            ['name' => 'plain', 'type' => 'string'],
        ]);

        $this->assertSame('Enable if this item is a company asset.', $defs[0]['description']);
        $this->assertNull($defs[1]['description']);
    }

    public function test_label_depends_on_is_carried_and_malformed_is_dropped(): void
    {
        $defs = $this->normalize([
            ['name' => 'party_name', 'type' => 'integer',
                'label_depends_on' => ['field' => 'opportunity_from', 'map' => ['Leads' => 'Lead', 'Customers' => 'Customer']]],
            ['name' => 'broken', 'type' => 'string',
                'label_depends_on' => ['field' => 'kind', 'map' => 'not-a-map']],
        ]);

        $this->assertSame(
            ['field' => 'opportunity_from', 'map' => ['Leads' => 'Lead', 'Customers' => 'Customer']],
            $defs[0]['label_depends_on']
        );
        $this->assertNull($defs[1]['label_depends_on']);
    }

    public function test_computed_count_of_is_carried(): void
    {
        $defs = $this->normalize([
            ['name' => 'total_holidays', 'type' => 'integer', 'computed' => ['count_of' => 'holidays']],
        ]);

        $this->assertSame('holidays', $defs[0]['computed']['count_of']);
    }

    public function test_computed_count_of_renders_readonly_without_mangling_the_value(): void
    {
        // Regression (Phase 6): the readonly injection once
        // matched lazily up to the > of the value's own
        // $form->field expression and produced
        // "$form- readonly>field" — a Blade parse error on
        // every count_of form.
        $defs = $this->normalize([
            ['name' => 'total_holidays', 'type' => 'integer', 'computed' => ['count_of' => 'holidays']],
        ]);

        $this->assertStringContainsString('readonly', $defs[0]['form']);
        $this->assertStringContainsString('$form->total_holidays', $defs[0]['form']);
        $this->assertStringNotContainsString('$form- readonly', $defs[0]['form']);
    }

    public function test_computed_count_of_rejects_a_malformed_target(): void
    {
        $this->expectException(SliceFieldDefinitionException::class);
        $this->normalize([
            ['name' => 'total', 'type' => 'integer', 'computed' => ['count_of' => 'Not A Handle!']],
        ]);
    }

    public function test_repeater_actions_keep_only_built_in_kinds(): void
    {
        $defs = $this->normalize([
            ['name' => 'holidays', 'type' => 'repeater', 'table' => 'holidays', 'foreign_key' => 'holiday_list_id',
                'repeater_fields' => [['handle' => 'holiday_date', 'type' => 'date']],
                'actions' => [
                    ['name' => 'get_weekly_off', 'label' => 'Get Weekly Off Dates', 'kind' => 'fill_weekday_dates',
                        'from' => 'from_date', 'to' => 'to_date', 'weekday' => 'weekly_off',
                        'date_field' => 'holiday_date', 'label_field' => 'description'],
                    ['name' => 'clear_table', 'label' => 'Clear Table', 'kind' => 'clear'],
                    ['name' => 'explode', 'label' => 'Explode', 'kind' => 'explode'],
                ]],
        ]);

        $kinds = array_column($defs[0]['actions'], 'kind');
        $this->assertSame(['fill_weekday_dates', 'clear'], $kinds);
        $this->assertSame('from_date', $defs[0]['actions'][0]['from']);
        $this->assertSame('holiday_date', $defs[0]['actions'][0]['date_field']);
    }

    public function test_repeater_parent_type_is_carried(): void
    {
        $defs = $this->normalize([
            ['name' => 'details', 'type' => 'repeater', 'table' => 'lost_reason_details', 'foreign_key' => 'opportunity_id',
                'parent_type' => 'Opportunity',
                'repeater_fields' => [['handle' => 'lost_reason', 'type' => 'integer']]],
        ]);

        $this->assertSame('Opportunity', $defs[0]['parent_type']);
    }
}
