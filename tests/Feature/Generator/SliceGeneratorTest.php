<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SliceGeneratorTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slicesPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraslice-tests-' . bin2hex(random_bytes(8));
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

    public function test_it_generates_a_complete_slice_in_the_configured_directory(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Purchase Order');

        $this->assertDirectoryExists($path);
        $this->assertFileExists($path . '/slice.json');
        $this->assertFileExists($path . '/Models/PurchaseOrder.php');
        $this->assertDirectoryExists($path . '/Migrations');
        $this->assertFileExists($path . '/Resources/views/index.blade.php');
        $this->assertFileExists($path . '/Routes/web.php');
        $this->assertSame([], glob($this->slicesPath . '/.laraslice-*') ?: []);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
            }
        }
    }

    public function test_custom_fields_drive_the_migration_forms_validation_and_mass_assignment_allowlist(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Purchase Order', [
            ['name' => 'sku', 'type' => 'string', 'label' => 'Stock keeping unit', 'required' => true],
            ['name' => 'unit_price', 'type' => 'decimal', 'label' => 'Unit price', 'required' => true],
            ['name' => 'is_taxable', 'type' => 'boolean', 'label' => 'Taxable'],
            ['name' => 'fulfillment_status', 'type' => 'select', 'label' => 'Fulfillment status', 'options' => [
                'pending' => 'Pending',
                'shipped' => 'Shipped',
            ], 'default' => 'pending'],
        ]);

        $migration = file_get_contents(glob($path . '/Migrations/*.php')[0]);
        $form = file_get_contents($path . '/Resources/views/form.blade.php');
        $model = file_get_contents($path . '/Models/PurchaseOrder.php');
        $service = file_get_contents($path . '/Services/PurchaseOrderSliceService.php');
        $schema = file_get_contents($path . '/Schemas/PurchaseOrderSchema.php');
        $manifest = json_decode(file_get_contents($path . '/slice.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString("\$table->string('sku');", $migration);
        $this->assertStringContainsString("\$table->decimal('unit_price', 12, 2);", $migration);
        $this->assertStringContainsString("name=\"fulfillment_status\"", $form);
        foreach (['sku', 'unit_price', 'is_taxable', 'fulfillment_status'] as $fieldName) {
            $this->assertStringContainsString("'{$fieldName}'", $model);
        }
        $this->assertStringContainsString("'fulfillment_status' => array (", $service);
        $this->assertStringContainsString("Column::make('unit_price')", $schema);
        $this->assertSame(['sku', 'unit_price', 'is_taxable', 'fulfillment_status'], array_column($manifest['fields'], 'name'));

        $blade = new BladeCompiler(new Filesystem(), $this->slicesPath . '/compiled');
        $viewWithoutComponents = preg_replace('~</?x-[A-Za-z0-9_.:-]+[^>]*>~', '', $form);
        $compiledView = $blade->compileString($viewWithoutComponents);
        $this->assertStringContainsString('fulfillment_status', $compiledView);
    }

    public function test_invalid_field_definitions_fail_before_a_slice_directory_is_created(): void
    {
        try {
            (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Unsafe Demo', [
                ['name' => 'sku; drop table users', 'type' => 'string'],
            ]);
            $this->fail('Expected invalid field definition to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Invalid field name', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->slicesPath);
    }

    public function test_generator_options_are_reflected_in_the_manifest_and_api_files(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'Example\\Features'))->generate('Audit Record', [], false, [
            'description' => 'Security audit entries',
            'author' => 'QA Team',
            'api' => false,
        ]);

        $manifest = json_decode(file_get_contents($path . '/slice.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Security audit entries', $manifest['description']);
        $this->assertSame('QA Team', $manifest['author']);
        $this->assertFileDoesNotExist($path . '/Controllers/AuditRecordApiController.php');
        $this->assertFileDoesNotExist($path . '/Routes/api.php');
        $this->assertStringContainsString('namespace Example\\Features\\AuditRecords', file_get_contents($path . '/Models/AuditRecord.php'));
    }

    public function test_invalid_namespace_is_rejected_without_creating_the_slices_directory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SliceGenerator($this->slicesPath, 'App\\Slices; phpinfo()');
    }

    public function test_it_refuses_to_overwrite_an_existing_slice(): void
    {
        $path = $this->slicesPath . DIRECTORY_SEPARATOR . 'Products';
        mkdir($path, 0755, true);
        file_put_contents($path . '/keep.txt', 'user content');

        try {
            (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Product');
            $this->fail('Expected generation to refuse the existing destination.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame('user content', file_get_contents($path . '/keep.txt'));
    }

    public function test_it_generates_domain_scoped_urls_manifest_and_alias_redirects(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Order', [], false, [
            'domain' => 'E-Commerce',
            'description' => 'Online orders module',
        ]);

        $manifest = json_decode(file_get_contents($path . '/slice.json'), true, flags: JSON_THROW_ON_ERROR);
        $webRoutes = file_get_contents($path . '/Routes/web.php');
        $apiRoutes = file_get_contents($path . '/Routes/api.php');

        // Directory and namespace assertions
        $this->assertStringEndsWith('ECommerce' . DIRECTORY_SEPARATOR . 'Orders', $path);
        $this->assertSame('App\\Slices\\ECommerce\\Orders', $manifest['namespace']);
        $this->assertStringContainsString('namespace App\\Slices\\ECommerce\\Orders\\Models;', file_get_contents($path . '/Models/Order.php'));
        $this->assertStringContainsString('use App\\Slices\\ECommerce\\Orders\\Controllers\\OrderWebController;', $webRoutes);

        // Manifest assertions
        $this->assertSame('E-Commerce', $manifest['domain']);
        $this->assertSame('E-Commerce', $manifest['navigation']['group']);
        $this->assertSame('/e-commerce/orders', $manifest['navigation']['url']);

        // Web routes: domain prefix and legacy flat redirect. No alias group with the same URIs:
        // a compiled route cache would keep only one of the names.
        $this->assertStringContainsString("Route::prefix('e-commerce/orders')->name('orders.')", $webRoutes);
        $this->assertStringNotContainsString("->name('e_commerce.orders.')", $webRoutes);
        $this->assertStringContainsString("Route::redirect('orders', '/e-commerce/orders');", $webRoutes);
        $this->assertStringContainsString("Route::redirect('orders/{any}', '/e-commerce/orders/{any}')", $webRoutes);

        // API routes: domain prefix and flat alias fallback
        $this->assertStringContainsString("Route::prefix('e-commerce/orders')", $apiRoutes);
        $this->assertStringContainsString("Route::prefix('orders')", $apiRoutes);
    }

    public function test_generation_supports_custom_permissions(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Shipment', [], false, [
            'permissions' => [
                'shipment.view',
                'shipment.create',
                'shipment.edit',
                'shipment.delete',
                'shipment.dispatch',
            ],
        ]);

        $manifest = json_decode(file_get_contents($path . '/slice.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'shipment.view',
            'shipment.create',
            'shipment.edit',
            'shipment.delete',
            'shipment.dispatch',
        ], $manifest['permissions']);
    }

    public function test_generation_supports_custom_status_field_definition(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Ticket', [
            ['name' => 'ticket_number', 'type' => 'string', 'label' => 'Ticket #', 'required' => true],
            ['name' => 'priority', 'type' => 'select', 'label' => 'Priority', 'options' => [
                'low' => 'Low',
                'medium' => 'Medium',
                'high' => 'High',
            ], 'default' => 'medium'],
            ['name' => 'status', 'type' => 'select', 'label' => 'Ticket Status', 'required' => true, 'options' => [
                'open' => 'Open',
                'in_progress' => 'In Progress',
                'resolved' => 'Resolved',
                'closed' => 'Closed',
            ], 'default' => 'open'],
        ]);

        $this->assertDirectoryExists($path);
        $migration = file_get_contents(glob($path . '/Migrations/*.php')[0]);
        $form = file_get_contents($path . '/Resources/views/form.blade.php');
        $model = file_get_contents($path . '/Models/Ticket.php');
        $service = file_get_contents($path . '/Services/TicketSliceService.php');
        $formDto = file_get_contents($path . '/Contracts/TicketFormBusinessObject.php');

        // Check migration has custom default and no duplicate status column
        $this->assertStringContainsString("\$table->string('status')->default('open');", $migration);
        $this->assertSame(1, substr_count($migration, "'status'"));

        // Check Form DTO has custom status default and no duplicate property
        $this->assertStringContainsString("public string \$status = 'open';", $formDto);
        $this->assertSame(1, substr_count($formDto, "\$status"));

        // Check Service validation has custom options
        $this->assertStringContainsString("'in:open,in_progress,resolved,closed'", $service);

        // Check form blade has options
        $this->assertStringContainsString('name="status"', $form);
        $this->assertStringContainsString('value="in_progress"', $form);

        // Check model fillable
        $this->assertStringContainsString("'status'", $model);
    }

    public function test_generation_supports_domain_grouping_and_multi_slice_suites(): void
    {
        $generator = new SliceGenerator($this->slicesPath, 'App\\Slices');

        $companyPath = $generator->generate('Company', [
            ['name' => 'industry', 'type' => 'string', 'required' => false],
        ], false, ['domain' => 'CRM']);

        $contactPath = $generator->generate('Contact', [
            ['name' => 'email', 'type' => 'string', 'required' => true],
        ], false, ['domain' => 'CRM']);

        $this->assertDirectoryExists($companyPath);
        $this->assertDirectoryExists($contactPath);

        $this->assertStringContainsString('Crm' . DIRECTORY_SEPARATOR . 'Companies', $companyPath);
        $this->assertStringContainsString('Crm' . DIRECTORY_SEPARATOR . 'Contacts', $contactPath);

        $companyWebRoutes = file_get_contents($companyPath . '/Routes/web.php');
        $contactWebRoutes = file_get_contents($contactPath . '/Routes/web.php');

        $this->assertStringContainsString("Route::prefix('crm/companies')", $companyWebRoutes);
        $this->assertStringContainsString("Route::prefix('crm/contacts')", $contactWebRoutes);

        $companyManifest = json_decode(file_get_contents($companyPath . '/slice.json'), true);
        $contactManifest = json_decode(file_get_contents($contactPath . '/slice.json'), true);

        $this->assertSame('CRM', $companyManifest['domain']);
        $this->assertSame('CRM', $contactManifest['domain']);
        $this->assertContains('industry', array_column($companyManifest['fields'], 'name'));
        $this->assertContains('email', array_column($contactManifest['fields'], 'name'));
    }
}
