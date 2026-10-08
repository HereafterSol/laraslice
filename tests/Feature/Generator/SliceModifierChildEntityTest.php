<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

class SliceModifierChildEntityTest extends TestCase
{
    private string $slicesPath;

    private string $slicePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-child-tests-'.bin2hex(random_bytes(8));
        $this->slicePath = $this->slicesPath.DIRECTORY_SEPARATOR.'HumanResources';
        foreach (['Contracts', 'Controllers', 'Migrations', 'Models', 'Resources/views', 'Routes', 'Services'] as $directory) {
            mkdir($this->slicePath.DIRECTORY_SEPARATOR.$directory, 0755, true);
        }

        file_put_contents($this->slicePath.'/slice.json', json_encode([
            'name' => 'HumanResources',
            'tables' => ['human_resources'],
            'relations' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->slicePath.'/Models/HumanResource.php', "<?php\nnamespace App\\Slices\\HumanResources\\Models;\nclass HumanResource extends \\Illuminate\\Database\\Eloquent\\Model { protected \$table = 'human_resources'; }\n");
        file_put_contents($this->slicePath.'/Routes/web.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\n");
        file_put_contents($this->slicePath.'/Routes/api.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\n");
    }

    protected function tearDown(): void
    {
        if (is_dir($this->slicesPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->slicesPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink()
                    ? rmdir($entry->getPathname())
                    : unlink($entry->getPathname());
            }

            rmdir($this->slicesPath);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_complete_child_entity_for_web_api_and_database(): void
    {
        $result = (new SliceModifier($this->slicesPath, 'App\\Slices'))->addChildTable(
            'HumanResources',
            'Departments',
            'hasMany',
            [
                ['name' => 'name', 'type' => 'string', 'required' => true],
                ['name' => 'annual_budget', 'type' => 'decimal', 'nullable' => true],
                ['name' => 'is_active', 'type' => 'boolean', 'nullable' => true, 'default' => false],
            ]
        );

        $migration = file_get_contents($result['migration_file']);
        $apiRoutes = file_get_contents($this->slicePath.'/Routes/api.php');
        $webRoutes = file_get_contents($this->slicePath.'/Routes/web.php');
        $childModel = file_get_contents($this->slicePath.'/Models/Department.php');
        $parentModel = file_get_contents($this->slicePath.'/Models/HumanResource.php');
        $manifest = json_decode(file_get_contents($this->slicePath.'/slice.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString("Schema::create('departments'", $migration);
        $this->assertStringContainsString("foreignId('human_resource_id')->constrained('human_resources')", $migration);
        $this->assertStringContainsString("boolean('is_active')->nullable()->default(false)", $migration);
        $this->assertStringContainsString("Route::prefix('human_resources/{parentId}/departments')", $apiRoutes);
        $this->assertStringContainsString('DepartmentApiController::class', $apiRoutes);
        $this->assertStringContainsString("Route::prefix('human_resources/{parentId}')", $webRoutes);
        $this->assertStringContainsString("Route::resource('departments'", $webRoutes);
        $this->assertStringNotContainsString("Route::resource('departments', DepartmentWebController::class);", $webRoutes);
        $this->assertStringContainsString("'human_resource_id', \$this->parentId", file_get_contents($this->slicePath.'/Services/DepartmentSliceService.php'));
        $this->assertStringContainsString("where('human_resource_id', \$this->parentId)", file_get_contents($this->slicePath.'/Services/DepartmentSliceService.php'));
        $this->assertStringContainsString('protected function routeParameters(array $extra = []): array', file_get_contents($this->slicePath.'/Controllers/DepartmentWebController.php'));
        $this->assertStringContainsString("array_merge(['parentId' => request()->route('parentId')], \$extra)", file_get_contents($this->slicePath.'/Controllers/DepartmentWebController.php'));
        $this->assertStringContainsString("'annual_budget'", $childModel);
        $this->assertStringContainsString('function departments()', $parentModel);
        $this->assertContains('departments', $manifest['tables']);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->slicePath, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                try {
                    token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
                } catch (\ParseError $exception) {
                    throw new \RuntimeException('Generated PHP failed parsing: '.$file->getPathname(), previous: $exception);
                }
            }
        }
    }

    public function test_invalid_child_definitions_do_not_write_migrations_or_change_the_manifest(): void
    {
        $manifestBefore = file_get_contents($this->slicePath.'/slice.json');

        try {
            (new SliceModifier($this->slicesPath, 'App\\Slices'))->addChildTable(
                'HumanResources',
                'safe_table',
                'hasMany',
                [['name' => "notes'); Schema::drop('users'); //", 'type' => 'string']]
            );
            $this->fail('Expected unsafe field name to be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Invalid, reserved, or duplicate', $exception->getMessage());
        }

        $this->assertSame($manifestBefore, file_get_contents($this->slicePath.'/slice.json'));
        $this->assertSame([], glob($this->slicePath.'/Migrations/*') ?: []);
    }

    public function test_default_child_fields_match_the_generated_table(): void
    {
        $result = (new SliceModifier($this->slicesPath, 'App\\Slices'))->addChildTable('HumanResources', 'Positions');
        $migration = file_get_contents($result['migration_file']);
        $formDto = file_get_contents($this->slicePath.'/Contracts/PositionFormBusinessObject.php');

        $this->assertStringContainsString("string('name')", $migration);
        $this->assertStringContainsString("string('code')->nullable()", $migration);
        $this->assertStringContainsString("string('status')->nullable()->default('active')", $migration);
        $this->assertStringContainsString("public ?string \$status = 'active'", $formDto);
    }

    public function test_child_migration_timestamp_sorts_after_existing_parent_migrations(): void
    {
        $parentMigration = $this->slicePath.'/Migrations/2026_09_24_120000_create_human_resources_table.php';
        file_put_contents($parentMigration, "<?php return new class {};\n");

        $result = (new SliceModifier($this->slicesPath, 'App\\Slices'))->addChildTable('HumanResources', 'Departments');

        $this->assertGreaterThan(basename($parentMigration), basename($result['migration_file']));
    }

    public function test_it_prefixes_child_routes_with_domain_when_slice_belongs_to_a_domain(): void
    {
        $crmPath = $this->slicesPath.DIRECTORY_SEPARATOR.'Companies';
        foreach (['Contracts', 'Controllers', 'Migrations', 'Models', 'Resources/views', 'Routes', 'Services'] as $directory) {
            mkdir($crmPath.DIRECTORY_SEPARATOR.$directory, 0755, true);
        }

        file_put_contents($crmPath.'/slice.json', json_encode([
            'name' => 'Companies',
            'domain' => 'CRM',
            'tables' => ['companies'],
            'relations' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($crmPath.'/Models/Company.php', "<?php\nnamespace App\\Slices\\Companies\\Models;\nclass Company extends \\Illuminate\\Database\\Eloquent\\Model { protected \$table = 'companies'; }\n");
        file_put_contents($crmPath.'/Routes/web.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::prefix('crm/companies')->name('companies.')->group(function(){});\n");
        file_put_contents($crmPath.'/Routes/api.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::prefix('crm/companies')->group(function(){});\n");

        $result = (new SliceModifier($this->slicesPath, 'App\\Slices'))->addChildTable('Companies', 'Contacts');

        $webRoutes = file_get_contents($crmPath.'/Routes/web.php');
        $apiRoutes = file_get_contents($crmPath.'/Routes/api.php');

        $this->assertStringContainsString("Route::prefix('crm/companies/{parentId}')", $webRoutes);
        $this->assertStringContainsString("Route::resource('contacts'", $webRoutes);
        $this->assertStringContainsString("Route::prefix('crm/companies/{parentId}/contacts')", $apiRoutes);
    }
}
