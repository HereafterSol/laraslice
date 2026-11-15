<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use LaraSlice\Generator\SliceFieldDefinitionException;
use LaraSlice\Support\FieldCondition;
use PHPUnit\Framework\TestCase;

/**
 * Seat 1 (Stage D): the field-behaviour grammar.
 * Conditions are declarative clause lists — validated
 * at generation, evaluated identically by the PHP
 * reference evaluator (the shell's JS mirrors it).
 */
class FieldConditionTest extends TestCase
{
    public function test_single_clause_sugar_and_evaluation(): void
    {
        $clauses = FieldCondition::normalize(['field' => 'status', 'is' => 'lost']);
        $this->assertSame([['field' => 'status', 'op' => 'is', 'value' => 'lost']], $clauses);
        $this->assertTrue(FieldCondition::evaluate($clauses, ['status' => 'lost']));
        $this->assertFalse(FieldCondition::evaluate($clauses, ['status' => 'open']));
    }

    public function test_operators(): void
    {
        $this->assertTrue(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'not_empty' => true]), ['x' => 'a']));
        $this->assertFalse(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'not_empty' => true]), ['x' => '']));
        // An unchecked Check is empty (source truthiness):
        // false and 0 must not satisfy not_empty, and a
        // submitted string '0' stays a real, non-empty choice.
        $this->assertFalse(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'not_empty' => true]), ['x' => false]));
        $this->assertFalse(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'not_empty' => true]), ['x' => 0]));
        $this->assertTrue(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'not_empty' => true]), ['x' => '0']));
        $this->assertTrue(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'empty' => true]), ['x' => false]));
        $this->assertTrue(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'x', 'in' => ['a', 'b']]), ['x' => 'b']));
        $this->assertTrue(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'n', 'gt' => 5]), ['n' => '7']));
        $this->assertFalse(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'n', 'gt' => 5]), ['n' => '3']));
        // form values arrive as strings; typed declarations still match
        $this->assertTrue(FieldCondition::evaluate(FieldCondition::normalize(['field' => 'n', 'is' => 3]), ['n' => '3']));
    }

    public function test_malformed_clauses_are_rejected(): void
    {
        foreach ([
            ['field' => 'x'],
            ['field' => 'x', 'is' => 'a', 'is_not' => 'b'],
            ['is' => 'a'],
            ['field' => 'x', 'in' => 'notalist'],
            ['field' => 'x', 'gt' => 'abc'],
            'status==lost',
        ] as $bad) {
            try {
                FieldCondition::normalize($bad, 'f');
                $this->fail('Expected rejection for '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_normalizer_carries_behaviour_and_marks_the_wrapper(): void
    {
        $normalizer = new FieldDefinitionNormalizer;
        $fields = $normalizer->normalize([
            ['name' => 'status', 'type' => 'string', 'options' => ['open' => 'Open', 'lost' => 'Lost']],
            ['name' => 'lost_reason', 'type' => 'string',
                'depends_on' => ['field' => 'status', 'is' => 'lost'],
                'mandatory_depends_on' => ['field' => 'status', 'is' => 'lost'],
                'in_list_view' => true],
        ]);
        $byName = [];
        foreach ($fields as $f) {
            $byName[$f['name']] = $f;
        }
        $this->assertSame([['field' => 'status', 'op' => 'is', 'value' => 'lost']], $byName['lost_reason']['depends_on']);
        $this->assertTrue($byName['lost_reason']['in_list_view']);
        $this->assertStringContainsString('data-ls-depends-on=', $byName['lost_reason']['form']);
        $this->assertStringContainsString('data-ls-field="lost_reason"', $byName['lost_reason']['form']);
        // a field without behaviour keeps byte-identical markup (no attrs)
        $this->assertStringNotContainsString('data-ls-field', $byName['status']['form']);
    }

    public function test_normalizer_rejects_bad_behaviour_loudly(): void
    {
        $normalizer = new FieldDefinitionNormalizer;
        $this->expectException(SliceFieldDefinitionException::class);
        $normalizer->normalize([
            ['name' => 'x', 'type' => 'string', 'depends_on' => 'eval:doc.status==1'],
        ]);
    }

    public function test_fetch_from_shape_is_validated(): void
    {
        $normalizer = new FieldDefinitionNormalizer;
        $fields = $normalizer->normalize([
            ['name' => 'email', 'type' => 'email', 'fetch_from' => 'lead.email_id'],
        ]);
        $this->assertSame('lead.email_id', $fields[0]['fetch_from']);
        $this->assertStringContainsString('data-ls-fetch-from="lead.email_id"', $fields[0]['form']);
    }
public function test_any_groups_hold_when_one_group_holds(): void
    {
        $groups = \LaraSlice\Support\FieldCondition::normalizeGroups([
            'any' => [
                ['field' => 'opportunity_from', 'is' => 'Customer'],
                ['field' => 'party_name', 'not_empty' => true],
            ],
        ], 'contact_display');

        $this->assertTrue(\LaraSlice\Support\FieldCondition::evaluateGroups($groups, ['opportunity_from' => 'Leads', 'party_name' => '15']));
        $this->assertTrue(\LaraSlice\Support\FieldCondition::evaluateGroups($groups, ['opportunity_from' => 'Customer', 'party_name' => null]));
        $this->assertFalse(\LaraSlice\Support\FieldCondition::evaluateGroups($groups, ['opportunity_from' => 'Leads', 'party_name' => null]));
    }

    public function test_normalize_is_idempotent_for_normalized_clauses(): void
    {
        $once = \LaraSlice\Support\FieldCondition::normalize([['field' => 'status', 'is' => 'open']], 'x');
        $twice = \LaraSlice\Support\FieldCondition::normalize($once, 'x');

        $this->assertSame($once, $twice);
    }

    public function test_date_operands_and_today_token(): void
    {
        // Phase 8 (D-078): ordered comparisons accept ISO
        // dates and the @today token — a signed contract
        // whose start lies in the future is not yet active.
        $future = FieldCondition::normalize([['field' => 'start_date', 'gt' => '@today']], 'status');
        $this->assertTrue(FieldCondition::evaluate($future, ['start_date' => '2999-01-01']));
        $this->assertFalse(FieldCondition::evaluate($future, ['start_date' => '2000-01-01']));
        $this->assertFalse(FieldCondition::evaluate($future, ['start_date' => date('Y-m-d')]));
        // A missing date never orders.
        $this->assertFalse(FieldCondition::evaluate($future, ['start_date' => null]));
        $this->assertFalse(FieldCondition::evaluate($future, []));

        $past = FieldCondition::normalize([['field' => 'end_date', 'lt' => '@today']], 'status');
        $this->assertTrue(FieldCondition::evaluate($past, ['end_date' => '2000-01-01']));
        $this->assertFalse(FieldCondition::evaluate($past, ['end_date' => '2999-01-01']));

        $literal = FieldCondition::normalize([['field' => 'd', 'gte' => '2026-01-01']], 'x');
        $this->assertTrue(FieldCondition::evaluate($literal, ['d' => '2026-06-01']));
        $this->assertFalse(FieldCondition::evaluate($literal, ['d' => '2025-06-01']));
    }

    public function test_from_operands_compare_field_to_field(): void
    {
        // The '_from' sugar: the operand is a sibling
        // field's value (collection aggregates included).
        $clauses = FieldCondition::normalize([
            ['field' => 'terms.fulfilled.count', 'gt' => 0],
            ['field' => 'terms.fulfilled.count', 'is_from' => 'terms.count'],
        ], 'fulfilment_status');
        $this->assertSame('terms.count', $clauses[1]['value_from']);
        $this->assertTrue(FieldCondition::evaluate($clauses, ['terms.fulfilled.count' => 2, 'terms.count' => 2]));
        $this->assertFalse(FieldCondition::evaluate($clauses, ['terms.fulfilled.count' => 1, 'terms.count' => 2]));
        $this->assertFalse(FieldCondition::evaluate($clauses, ['terms.fulfilled.count' => 0, 'terms.count' => 0]));

        // Normalization stays idempotent with value_from.
        $twice = FieldCondition::normalize($clauses, 'fulfilment_status');
        $this->assertSame($clauses, $twice);
    }
}
