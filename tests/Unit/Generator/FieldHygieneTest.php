<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\FieldDefinitionNormalizer;
use LaraSlice\Generator\SliceFieldDefinitionException;
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

    public function test_hash_token_is_a_system_field_off_forms(): void
    {
        // `hash` columns are server-generated public-link tokens
        // (invoices, projects, lead contacts): the comparative test
        // found them rendered as editable textareas on create forms.
        $n = new FieldDefinitionNormalizer;
        $out = $n->normalize([
            ['name' => 'hash', 'type' => 'string'],
        ]);
        $this->assertTrue($out[0]['system']);
        $this->assertFalse($out[0]['show_in_form']);
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

    public function test_string_typed_id_suffix_is_a_code_not_a_picker(): void
    {
        // employee_details.employee_id stores a human code
        // ('EMP-12') as a string. Forcing it into a relationship
        // picker leaves the code unmatched by any option, so an
        // edit submits '' and wipes it. An integer *_id stays a
        // picker (a real foreign key).
        $n = new FieldDefinitionNormalizer;
        $out = [];
        foreach ($n->normalize([
            ['name' => 'employee_id', 'type' => 'string'],
            ['name' => 'user_id', 'type' => 'integer'],
        ]) as $d) {
            $out[$d['name']] = $d;
        }
        $this->assertStringNotContainsString('combobox-relationship', $out['employee_id']['form']);
        $this->assertStringContainsString('name="employee_id"', $out['employee_id']['form']);
        $this->assertStringContainsString('combobox-relationship', $out['user_id']['form']);
    }

    public function test_date_inputs_format_the_stored_value_to_the_wire_format(): void
    {
        // A stored datetime string rendered raw into a date input
        // is invalid: the browser shows the field empty and an
        // untouched edit submits '' — wiping the stored date
        // (Gate T A2d). The markup must format via Carbon, like
        // BladeFieldInjector does.
        $n = new FieldDefinitionNormalizer;
        $out = [];
        foreach ($n->normalize([
            ['name' => 'joining_date', 'type' => 'datetime'],
            ['name' => 'logged_at', 'type' => 'datetime'],
        ]) as $d) {
            $out[$d['name']] = $d;
        }
        $this->assertStringContainsString("Carbon::parse(\$form->joining_date)->format('Y-m-d')", $out['joining_date']['form']);
        $this->assertStringContainsString("Carbon::parse(\$form->logged_at)->format('Y-m-d\\TH:i')", $out['logged_at']['form']);
    }

    public function test_widened_date_domain_names_render_date_inputs(): void
    {
        // The comparative test's full set: every day-granularity
        // name the source ERP uses must render a date input even
        // when the storage type is timestamp; genuine timestamps
        // keep datetime-local.
        $n = new FieldDefinitionNormalizer;
        $out = [];
        foreach ($n->normalize([
            ['name' => 'date_of_birth', 'type' => 'timestamp'],
            ['name' => 'valid_till', 'type' => 'timestamp'],
            ['name' => 'date', 'type' => 'timestamp'],
            ['name' => 'deadline', 'type' => 'timestamp'],
            ['name' => 'submitted_at', 'type' => 'timestamp'],
            ['name' => 'clock_in_at', 'type' => 'timestamp'],
        ]) as $d) {
            $out[$d['name']] = $d;
        }
        foreach (['date_of_birth', 'valid_till', 'date', 'deadline'] as $name) {
            $this->assertStringContainsString("'{$name}', 'date'", $out[$name]['schema'], $name);
            $this->assertStringContainsString('type="date"', $out[$name]['form'], $name);
        }
        $this->assertStringContainsString("'submitted_at', 'datetime-local'", $out['submitted_at']['schema']);
        $this->assertStringContainsString("'clock_in_at', 'datetime-local'", $out['clock_in_at']['schema']);
    }

    public function test_relation_field_renders_relationship_combobox(): void
    {
        // award_to breaks the *_id convention; its declared
        // relation must still produce a picker bound to the
        // relation's options var (usersOptions), never a raw
        // number input.
        $n = new FieldDefinitionNormalizer;
        $out = $n->normalize([
            ['name' => 'award_to', 'type' => 'integer', 'nullable' => true, 'relation' => 'user'],
            ['name' => 'quantity', 'type' => 'integer', 'nullable' => true],
        ]);
        $this->assertStringContainsString('x-ui.combobox-relationship', $out[0]['form']);
        $this->assertStringContainsString('name="award_to"', $out[0]['form']);
        $this->assertStringContainsString('$usersOptions', $out[0]['form']);
        $this->assertStringNotContainsString('x-ui.combobox-relationship', $out[1]['form']);
    }

    public function test_option_values_may_contain_spaces_but_not_commas(): void
    {
        // Source enum vocabularies store values with spaces
        // ("in progress"); those must be declarable. A comma
        // would corrupt the generated `in:` rule and stays out.
        $n = new FieldDefinitionNormalizer;
        $out = $n->normalize([
            ['name' => 'status', 'type' => 'string', 'nullable' => true, 'options' => ['in progress' => 'In Progress', 'on hold' => 'On Hold']],
        ]);
        $this->assertStringContainsString('value="in progress"', $out[0]['form']);
        $this->assertContains('in:in progress,on hold', $out[0]['rules']);

        $this->expectException(SliceFieldDefinitionException::class);
        $n->normalize([
            ['name' => 'status', 'type' => 'string', 'nullable' => true, 'options' => ['a,b' => 'AB']],
        ]);
    }
}
