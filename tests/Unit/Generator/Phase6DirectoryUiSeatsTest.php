<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Support\DirectoryUi;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 quick-entry seats (D-052/D-059/D-066/D-039/D-040):
 * static-select first-option defaults, field descriptions,
 * Heart-dialect behaviour clauses converted for the modal,
 * and the companion declaration's validation.
 */
class Phase6DirectoryUiSeatsTest extends TestCase
{
    private function manifest(): array
    {
        return ['name' => 'Widget', 'ui' => ['quick_entry' => ['fields' => ['kind', 'notes']]], 'fields' => [
            'kind' => ['name' => 'kind', 'label' => 'Kind', 'type' => 'string', 'options' => ['alpha', 'beta']],
            'notes' => ['name' => 'notes', 'label' => 'Notes', 'type' => 'string', 'description' => 'Free text.',
                'depends_on' => ['field' => 'kind', 'is' => 'alpha']],
        ]];
    }

    public function test_static_select_defaults_to_its_first_option(): void
    {
        $fields = DirectoryUi::quickEntryFields($this->manifest());

        $this->assertSame('alpha', $fields[0]['default']);
        $this->assertSame('select', $fields[0]['input']);
    }

    public function test_description_and_behaviour_clauses_are_carried(): void
    {
        $fields = DirectoryUi::quickEntryFields($this->manifest());

        $this->assertSame('Free text.', $fields[1]['description']);
        $this->assertSame(
            [['field' => 'kind', 'op' => 'is', 'value' => 'alpha']],
            $fields[1]['depends_on']
        );
    }

    public function test_companion_declaration_requires_its_seat_keys(): void
    {
        $incomplete = ['ui' => ['quick_entry' => ['companion' => ['slice' => 'Addresses']]]];
        $this->assertNull(DirectoryUi::quickEntryCompanionDecl($incomplete));

        $complete = ['ui' => ['quick_entry' => ['companion' => [
            'slice' => 'Addresses', 'fields' => ['city'],
            'title_field' => 'address_title', 'link_field' => 'customer_primary_address',
        ]]]];
        $decl = DirectoryUi::quickEntryCompanionDecl($complete);
        $this->assertSame('Addresses', $decl['slice']);
        $this->assertSame(['city'], $decl['fields']);
        $this->assertSame('customer_primary_address', $decl['link_field']);
    }
}
