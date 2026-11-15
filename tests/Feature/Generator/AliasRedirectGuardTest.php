<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Legacy alias redirects: a slice generated under a domain
 * emits redirects from its bare prefix to its domain
 * prefix. Those aliases must cover only the slice's own
 * legacy record URLs (bare, numeric show, numeric edit) —
 * NEVER a '.*' wildcard. A wildcard swallows the whole
 * bare-prefix namespace, which may be another module's
 * live route prefix (gate-caught defect, ERPNext port
 * 13 Nov 2026: CRM Contacts' 'contacts/{any}' alias made
 * the Contacts module's Addresses/Genders/Salutations
 * unreachable).
 */
class AliasRedirectGuardTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-alias-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->slicesPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->slicesPath);
        }
        parent::tearDown();
    }

    public function test_domain_slice_aliases_are_numeric_record_shapes_only(): void
    {
        $generator = new SliceGenerator($this->slicesPath, 'App\\Slices');
        $path = $generator->generate('Contact', [
            ['name' => 'first_name', 'type' => 'string', 'label' => 'First Name'],
        ], false, ['domain' => 'CRM', 'table' => 'contacts']);

        $routes = file_get_contents($path.'/Routes/web.php');

        $this->assertStringContainsString("Route::redirect('contacts', '/crm/contacts');", $routes);
        $this->assertStringContainsString("Route::redirect('contacts/{id}', '/crm/contacts/{id}')->where('id', '[0-9]+');", $routes);
        $this->assertStringContainsString("Route::redirect('contacts/{id}/edit', '/crm/contacts/{id}/edit')->where('id', '[0-9]+');", $routes);
        $this->assertStringNotContainsString('{any}', $routes);
        $this->assertStringNotContainsString(".*", $routes);
    }
}
