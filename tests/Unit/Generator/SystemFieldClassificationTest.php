<?php

use LaraSlice\Generator\FieldDefinitionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * isSystemField false positives: business fields whose
 * names end in _by (campaign_naming_by, qualified_by)
 * were silently dropped from generated forms because the
 * audit-stamp heuristic was a bare suffix match. Audit
 * stamps stay hidden; deliberate show_in_form still wins.
 */
class SystemFieldClassificationTest extends TestCase
{
    private function normalize(array $fields): array
    {
        return (new FieldDefinitionNormalizer)->normalize($fields);
    }

    public function test_business_by_fields_stay_on_forms(): void
    {
        $defs = $this->normalize([
            ['name' => 'campaign_naming_by', 'label' => 'Campaign Naming By', 'type' => 'string'],
            ['name' => 'qualified_by', 'label' => 'Qualified By', 'type' => 'integer'],
        ]);

        $this->assertTrue($defs[0]['show_in_form']);
        $this->assertTrue($defs[1]['show_in_form']);
        $this->assertFalse($defs[0]['system']);
    }

    public function test_audit_stamps_stay_hidden(): void
    {
        $defs = $this->normalize([
            ['name' => 'created_by', 'label' => 'Created By', 'type' => 'integer'],
            ['name' => 'approved_by', 'label' => 'Approved By', 'type' => 'integer'],
            ['name' => 'clock_in_ip', 'label' => 'Clock In IP', 'type' => 'string'],
        ]);

        $this->assertFalse($defs[0]['show_in_form']);
        $this->assertFalse($defs[1]['show_in_form']);
        $this->assertFalse($defs[2]['show_in_form']);
    }

    public function test_explicit_show_in_form_wins_over_the_classification(): void
    {
        $defs = $this->normalize([
            ['name' => 'created_by', 'label' => 'Created By', 'type' => 'integer', 'show_in_form' => true],
        ]);

        $this->assertTrue($defs[0]['show_in_form']);
    }
}
