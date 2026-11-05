<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\EnterpriseShell;
use LaraSlice\Tests\TestCase;

class EnterpriseShellTest extends TestCase
{
    public function test_document_title_uses_section_when_provided(): void
    {
        config(['app.name' => 'Acme ERP']);

        $this->assertSame('Projects · Acme ERP', EnterpriseShell::documentTitle('Projects'));
    }

    public function test_document_title_returns_app_name_when_section_empty_and_no_nav(): void
    {
        config(['app.name' => 'Acme ERP']);

        $this->assertSame('Acme ERP', EnterpriseShell::documentTitle(''));
    }

    public function test_document_title_does_not_duplicate_app_name(): void
    {
        config(['app.name' => 'Acme ERP']);

        $this->assertSame('Acme ERP', EnterpriseShell::documentTitle('Acme ERP'));
    }
}
