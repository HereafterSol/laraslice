<?php

namespace LaraSlice\Tests\Unit\Generator;

use LaraSlice\Generator\SliceName;
use PHPUnit\Framework\TestCase;

/**
 * F2 (Stage A, Stage C-6 fix): Str::snake shreds a
 * leading acronym — CRM -> c_r_m, CRMNotes ->
 * c_r_m_notes — so slices/domains named with acronyms
 * got unreadable table and title names. SliceName::snake
 * collapses the acronym run first.
 */
class SliceNameSnakeTest extends TestCase
{
    public function test_acronyms_survive(): void
    {
        $this->assertSame('crm', SliceName::snake('CRM'));
        $this->assertSame('crm_notes', SliceName::snake('CRMNotes'));
        $this->assertSame('it_assets', SliceName::snake('ITAssets'));
        $this->assertSame('url', SliceName::snake('URL'));
    }

    public function test_ordinary_names_are_unchanged(): void
    {
        $this->assertSame('employee_details', SliceName::snake('EmployeeDetails'));
        $this->assertSame('frappe_crm_allowed_users', SliceName::snake('FrappeCrmAllowedUsers'));
        $this->assertSame('leads', SliceName::snake('Leads'));
        $this->assertSame('already_snake', SliceName::snake('already_snake'));
    }

    public function test_space_delimiter_for_titles(): void
    {
        $this->assertSame('crm notes', SliceName::snake('CRMNotes', ' '));
    }
}
