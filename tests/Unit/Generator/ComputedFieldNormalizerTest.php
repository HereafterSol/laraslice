<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use LaraSlice\Generator\SliceFieldDefinitionException;
use PHPUnit\Framework\TestCase;

/**
 * Computed values + same-record fallback (the source
 * controllers' validate() derivations declared as data):
 * the normalizer validates the grammar and carries it
 * verbatim so every regen keeps the derivation. Runtime
 * enforcement lives in BaseSliceWebController and is
 * proven live (Stage D pattern).
 */
class ComputedFieldNormalizerTest extends TestCase
{
    private function normalize(array $fields): array
    {
        return (new FieldDefinitionNormalizer)->normalize($fields);
    }

    public function test_join_computation_is_carried(): void
    {
        $defs = $this->normalize([
            ['name' => 'lead_name', 'label' => 'Full Name', 'type' => 'string',
                'computed' => ['from' => ['salutation', 'first_name', 'middle_name', 'last_name'], 'join' => ' ', 'requires' => 'first_name']],
        ]);

        $this->assertSame(
            ['from' => ['salutation', 'first_name', 'middle_name', 'last_name'], 'join' => ' ', 'pick' => null, 'requires' => 'first_name'],
            $defs[0]['computed']
        );
        $this->assertNull($defs[0]['fallback_from']);
    }

    public function test_pick_first_and_fallback_are_carried(): void
    {
        $defs = $this->normalize([
            ['name' => 'title', 'label' => 'Title', 'type' => 'string',
                'computed' => ['from' => ['company_name', 'lead_name'], 'pick' => 'first']],
            ['name' => 'lead_name', 'label' => 'Full Name', 'type' => 'string', 'fallback_from' => 'company_name'],
        ]);

        $this->assertSame('first', $defs[0]['computed']['pick']);
        $this->assertNull($defs[0]['computed']['join']);
        $this->assertSame('company_name', $defs[1]['fallback_from']);
    }

    public function test_bare_from_defaults_to_pick_first(): void
    {
        $defs = $this->normalize([
            ['name' => 'display', 'label' => 'Display', 'type' => 'string', 'computed' => ['from' => ['name']]],
        ]);

        $this->assertSame('first', $defs[0]['computed']['pick']);
    }

    public function test_join_and_pick_together_are_rejected(): void
    {
        $this->expectException(SliceFieldDefinitionException::class);
        $this->normalize([
            ['name' => 'x', 'label' => 'X', 'type' => 'string',
                'computed' => ['from' => ['a'], 'join' => ' ', 'pick' => 'first']],
        ]);
    }

    public function test_bad_part_names_are_rejected(): void
    {
        $this->expectException(SliceFieldDefinitionException::class);
        $this->normalize([
            ['name' => 'x', 'label' => 'X', 'type' => 'string',
                'computed' => ['from' => ['not a field'], 'join' => ' ']],
        ]);
    }

    public function test_bad_fallback_is_rejected(): void
    {
        $this->expectException(SliceFieldDefinitionException::class);
        $this->normalize([
            ['name' => 'x', 'label' => 'X', 'type' => 'string', 'fallback_from' => 'two.words'],
        ]);
    }
}
