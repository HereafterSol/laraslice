<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * D3 (Stage C-7): the string relation dialect (the porter
 * dialect — a target entity name on a non-_id integer
 * field, e.g. award_to -> 'user') drives the runtime's
 * relationship combobox, but the normalizer kept only
 * ARRAY relations in the normalized definition, so a
 * regen silently stripped the declaration and the field
 * reverted to a bare id input. The string form must
 * survive normalization and feed the combobox markup.
 */
class StringRelationNormalizerTest extends TestCase
{
    private function definition(array $fields, string $name): array
    {
        $normalizer = new FieldDefinitionNormalizer;
        foreach ($normalizer->normalize($fields) as $definition) {
            if (($definition['name'] ?? null) === $name) {
                return $definition;
            }
        }
        $this->fail("No definition produced for {$name}");
    }

    public function test_string_relation_survives_normalization(): void
    {
        $definition = $this->definition([
            ['name' => 'company', 'type' => 'integer', 'relation' => 'company'],
        ], 'company');

        $this->assertSame('company', $definition['relation']);
    }

    public function test_string_relation_renders_the_relationship_combobox(): void
    {
        $definition = $this->definition([
            ['name' => 'company', 'type' => 'integer', 'relation' => 'company'],
        ], 'company');

        $this->assertStringContainsString('companiesOptions', $definition['form']);
        $this->assertStringContainsString('combobox', $definition['form']);
    }

    public function test_array_relation_still_survives(): void
    {
        $definition = $this->definition([
            ['name' => 'campaign_id', 'type' => 'integer',
                'relation' => ['name' => 'campaign', 'model' => 'Campaign']],
        ], 'campaign_id');

        $this->assertSame(['name' => 'campaign', 'model' => 'Campaign'], $definition['relation']);
    }

    public function test_no_relation_stays_null(): void
    {
        $definition = $this->definition([
            ['name' => 'score', 'type' => 'integer'],
        ], 'score');

        $this->assertNull($definition['relation']);
    }
}
