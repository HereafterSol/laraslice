<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Form hygiene at the generation choke point (Body v2 rules):
 * system/audit columns default off forms, date-domain
 * datetimes render as date inputs, and explicit manifest
 * visibility flags survive normalization.
 */
class FieldHygieneTest extends TestCase
{
    public function test_system_fields_default_off_forms_and_flags_survive(): void
    {
        $n = new FieldDefinitionNormalizer;
        $out = [];
        foreach ($n->normalize([
            ['name' => 'added_by', 'type' => 'integer'],
            ['name' => 'approved_at', 'type' => 'datetime'],
            ['name' => 'reason', 'type' => 'text', 'show_in_form' => false],
            ['name' => 'added_note', 'type' => 'string'],
        ]) as $d) {
            $out[$d['name']] = $d;
        }

        $this->assertFalse($out['added_by']['show_in_form']);
        $this->assertTrue($out['added_by']['system']);
        $this->assertFalse($out['approved_at']['show_in_form']);
        // Server-stamped IP columns are system fields too.
        $ipOut = [];
        foreach ($n->normalize([
            ['name' => 'clock_in_ip', 'type' => 'string'],
            ['name' => 'ip_address', 'type' => 'string'],
            ['name' => 'relationship', 'type' => 'string'],
        ]) as $d) {
            $ipOut[$d['name']] = $d;
        }
        $this->assertFalse($ipOut['clock_in_ip']['show_in_form']);
        $this->assertFalse($ipOut['ip_address']['show_in_form']);
        $this->assertTrue($ipOut['relationship']['show_in_form']);
        // An explicit manifest flag survives normalization now.
        $this->assertFalse($out['reason']['show_in_form']);
        $this->assertTrue($out['added_note']['show_in_form']);
    }

    public function test_explicit_show_in_form_wins_over_system_default(): void
    {
        $n = new FieldDefinitionNormalizer;
        $out = $n->normalize([
            ['name' => 'approved_by', 'type' => 'integer', 'show_in_form' => true],
        ]);
        $this->assertTrue($out[0]['show_in_form']);
    }

    public function test_date_domain_datetime_renders_date_input(): void
    {
        $n = new FieldDefinitionNormalizer;
        $out = [];
        foreach ($n->normalize([
            ['name' => 'leave_date', 'type' => 'datetime'],
            ['name' => 'logged_at', 'type' => 'datetime'],
        ]) as $d) {
            $out[$d['name']] = $d;
        }
        $this->assertStringContainsString("'leave_date', 'date'", $out['leave_date']['schema']);
        $this->assertStringContainsString("'logged_at', 'datetime-local'", $out['logged_at']['schema']);
    }
}
