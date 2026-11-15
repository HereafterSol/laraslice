<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use LaraSlice\Generator\SliceFieldDefinitionException;
use PHPUnit\Framework\TestCase;

/**
 * Stage C-5: dynamic option sources. A field declaring
 * `options_from: slices` (a registry link — the value is a
 * slice NAME) or `options_from: slice_records:<via>` (a
 * dynamic link) must normalize to a plain select fed by
 * the controller-resolved {handle}Options var, with the
 * via marker on dynamic links, no static `in:` rule, and
 * its source carried through the definition. Before this,
 * the uniform Link→integer treatment rendered Frappe's
 * Opportunity.opportunity_from as a required number input
 * and the form could not be completed truthfully (F5).
 */
class DynamicOptionsNormalizerTest extends TestCase
{
    private function normalize(array $fields): array
    {
        return (new FieldDefinitionNormalizer)->normalize($fields);
    }

    public function test_registry_link_renders_select_fed_by_options_var(): void
    {
        $defs = $this->normalize([
            ['name' => 'opportunity_from', 'label' => 'Opportunity From', 'type' => 'select', 'required' => true, 'options_from' => 'slices'],
        ]);

        $def = $defs[0];
        $this->assertSame('slices', $def['options_from']);
        $this->assertStringContainsString('<select id="opportunity_from"', $def['form']);
        $this->assertStringContainsString('$opportunityFromOptions', $def['form']);
        $this->assertStringNotContainsString('type="number"', $def['form']);
        $this->assertStringNotContainsString('data-ls-dynamic-from', $def['form']);
        // No static in: rule — the vocabulary is the live registry.
        $this->assertSame(['required', 'string', 'max:255'], $def['rules']);
    }

    public function test_dynamic_link_carries_the_via_marker(): void
    {
        $defs = $this->normalize([
            ['name' => 'party_name', 'label' => 'Party', 'type' => 'select', 'required' => true, 'options_from' => 'slice_records:opportunity_from'],
        ]);

        $def = $defs[0];
        $this->assertSame('slice_records:opportunity_from', $def['options_from']);
        $this->assertStringContainsString('data-ls-dynamic-from="opportunity_from"', $def['form']);
        $this->assertStringContainsString('$partyNameOptions', $def['form']);
        $this->assertStringContainsString('<select id="party_name"', $def['form']);
    }

    public function test_unsupported_source_is_rejected(): void
    {
        $this->expectException(SliceFieldDefinitionException::class);
        $this->normalize([
            ['name' => 'weird', 'label' => 'Weird', 'type' => 'select', 'options_from' => 'carrier_pigeons'],
        ]);
    }

    public function test_static_options_are_unaffected(): void
    {
        $defs = $this->normalize([
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['open' => 'Open', 'won' => 'Won']],
        ]);

        $this->assertNull($defs[0]['options_from']);
        $this->assertStringContainsString('in:open,won', implode(',', $defs[0]['rules']));
    }
}
