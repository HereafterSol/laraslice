<?php

namespace LaraSlice\Tests\Unit\Blueprint;

use LaraSlice\Blueprint\BlueprintApplier;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Blueprint\BlueprintValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BlueprintApplierTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-blueprint-apply-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temporaryDirectory);
        }
    }

    public function test_it_applies_supported_blueprint_into_complete_php_validated_slice_without_running_migrations(): void
    {
        [$blueprint, $plan] = $this->blueprintAndPlan();
        $target = (new BlueprintApplier)->apply($blueprint, $plan, $this->temporaryDirectory.'/Slices', 'App\\Slices');

        $this->assertDirectoryExists($target);
        $this->assertFileExists($target.'/Models/ServiceDesk.php');
        $this->assertFileExists($target.'/Models/Ticket.php');
        $this->assertCount(1, glob($target.'/Migrations/*_create_service_desks_table.php'));
        $this->assertStringContainsString('value="resolved"', file_get_contents($target.'/Resources/views/tickets/form.blade.php'));
        $migrationNames = array_map('basename', glob($target.'/Migrations/*.php'));
        sort($migrationNames);
        $this->assertLessThan($migrationNames[1], $migrationNames[0]);
        $manifest = \LaraSlice\Core\Discovery\ManifestRepository::read($target.'/slice.yaml');
        $this->assertSame(['service_desks', 'tickets'], $manifest['tables']);
        $this->assertFileExists($target.'/slice.yaml');
        $this->assertCount(2, glob($target.'/Migrations/*.php'));
        $this->assertDirectoryDoesNotExist(dirname($target).DIRECTORY_SEPARATOR.basename($target).'.staging');
    }

    public function test_stale_plan_or_existing_database_table_stops_before_writing_slice_files(): void
    {
        [$blueprint, $plan] = $this->blueprintAndPlan();
        $plan['target'] .= '-changed';
        try {
            (new BlueprintApplier)->apply($blueprint, $plan, $this->temporaryDirectory.'/Slices', 'App\\Slices');
            $this->fail('Expected stale plan rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('stale', $exception->getMessage());
        }

        [$blueprint, $plan] = $this->blueprintAndPlan();
        $applier = new BlueprintApplier(static fn (string $table): bool => $table === 'tickets');
        try {
            $applier->apply($blueprint, $plan, $this->temporaryDirectory.'/Slices', 'App\\Slices');
            $this->fail('Expected database conflict rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('tickets', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->temporaryDirectory.'/Slices/ServiceDesks');
    }

    public function test_apply_rejects_relationship_shapes_it_cannot_generate(): void
    {
        [$blueprint] = $this->blueprintAndPlan();
        $blueprint['models'][0]['relations'][0]['type'] = 'belongsToMany';
        $this->expectException(RuntimeException::class);
        (new BlueprintApplier)->assertSupported($blueprint);
    }

    public function test_apply_rejects_blueprint_root_tables_that_do_not_match_the_generated_slice(): void
    {
        [$blueprint] = $this->blueprintAndPlan();
        $blueprint['models'][0]['table'] = 'support_desks';
        $this->expectException(RuntimeException::class);
        (new BlueprintApplier)->assertSupported($blueprint);
    }

    public function test_prebuilt_studio_templates_are_all_valid_and_supported(): void
    {
        $controller = new BlueprintStudioController;
        $validator = new BlueprintValidator;
        $loader = new BlueprintLoader;
        $planner = new BlueprintPlanner;
        $applier = new BlueprintApplier;

        $templates = ['service-desk', 'hr-module', 'shop', 'shop-orders', 'crm'];
        $method = new \ReflectionMethod($controller, 'getTemplateContent');
        $method->setAccessible(true);

        foreach ($templates as $template) {
            $yaml = $method->invoke($controller, $template);
            $parsed = $loader->parse($yaml, 'yaml');
            $blueprint = $validator->validate($parsed);
            $plan = $planner->plan($blueprint, $this->temporaryDirectory.'/Slices');

            $applier->assertSupported($blueprint);
            $this->assertNotEmpty($blueprint['name']);
            $this->assertNotEmpty($plan['database_operations']);
        }
    }

    public function test_it_applies_shop_template_cleanly(): void
    {
        $controller = new BlueprintStudioController;
        $validator = new BlueprintValidator;
        $loader = new BlueprintLoader;
        $planner = new BlueprintPlanner;
        $applier = new BlueprintApplier;

        $method = new \ReflectionMethod($controller, 'getTemplateContent');
        $method->setAccessible(true);
        $yaml = $method->invoke($controller, 'shop');

        $blueprint = $validator->validate($loader->parse($yaml, 'yaml'));
        $plan = $planner->plan($blueprint, $this->temporaryDirectory.'/Slices');

        $target = $applier->apply($blueprint, $plan, $this->temporaryDirectory.'/Slices', 'App\\Slices');

        $this->assertDirectoryExists($target);
        $this->assertStringEndsWith('ECommerce'.DIRECTORY_SEPARATOR.'ShopProducts', $target);
        $this->assertFileExists($target.'/Models/ShopProduct.php');
        $this->assertFileExists($target.'/Models/ShopVariant.php');
        $this->assertFileExists($target.'/slice.yaml');
        // Phase 4: manifests are YAML-only — no JSON twin is written.
        $this->assertFileDoesNotExist($target.'/slice.json');

        $this->assertStringContainsString('namespace App\\Slices\\ECommerce\\ShopProducts\\Models;', file_get_contents($target.'/Models/ShopProduct.php'));
        $this->assertStringContainsString('namespace App\\Slices\\ECommerce\\ShopProducts\\Models;', file_get_contents($target.'/Models/ShopVariant.php'));

        $manifest = \LaraSlice\Core\Discovery\ManifestRepository::read($target.'/slice.yaml');
        $this->assertSame('E-Commerce', $manifest['domain']);
        $this->assertSame('/e-commerce/shop_products', $manifest['navigation']['url']);
        $this->assertSame('E-Commerce', $manifest['navigation']['group']);
    }

    public function test_declared_belongs_to_relations_reach_the_generated_heart(): void
    {
        // F10 (Day-2 drill): a blueprint declaring an `employee`
        // belongsTo generated a Heart with `relations: { }` — the
        // applier never passed the relations to the generator,
        // and its manifest normalization stripped the field-level
        // declaration too. The picker then had nothing declared
        // to resolve against.
        $path = $this->temporaryDirectory.'/training-record.slice.yaml';
        file_put_contents($path, <<<'YAML'
            schema_version: 1
            name: 'Training Record'
            handle: training_record
            domain: HR
            description: 'Employee training records.'
            models:
              -
                handle: training_record
                table: training_records
                root: true
                fields:
                  -
                    handle: employee_id
                    label: Employee
                    type: foreign_id
                    required: true
                  -
                    handle: course_name
                    label: Course
                    type: string
                    required: true
                relations:
                  -
                    name: employee
                    type: belongsTo
                    model: employee_detail
                    foreign_key: employee_id
                    table: employee_details
            YAML);

        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($path));
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->temporaryDirectory.'/Slices');
        $target = (new BlueprintApplier)->apply($blueprint, $plan, $this->temporaryDirectory.'/Slices', 'App\\Slices');

        $manifest = \LaraSlice\Core\Discovery\ManifestRepository::read($target.'/slice.yaml');
        $rootModel = current(array_filter($manifest['models'] ?? [], static fn (array $m): bool => ($m['root'] ?? false) === true));
        $this->assertIsArray($rootModel);
        $this->assertSame(
            [['name' => 'employee', 'type' => 'belongsTo', 'model' => 'employee_detail', 'foreign_key' => 'employee_id', 'table' => 'employee_details']],
            $rootModel['relations'] ?? null,
            'the declared belongsTo must land on the Heart root model verbatim'
        );
        $this->assertSame(
            ['name' => 'employee', 'model' => 'employee_detail'],
            $manifest['fields']['employee_id']['relation'] ?? null,
            'the field entry keeps its declared relation'
        );
        $this->assertSame('employee_details', $manifest['fields']['employee_id']['references'] ?? null);

        $model = file_get_contents($target.'/Models/TrainingRecord.php');
        $this->assertStringContainsString('function employee(', $model);
        $this->assertStringContainsString("modelClass('EmployeeDetail')", $model);

        // The directory column for the FK carries the declared
        // relation + target table into the Heart: listing rows
        // are BusinessObjects with no relation method to walk,
        // so the column declaration is the renderer's only
        // truthful pointer at the target record (F10 live trap:
        // employee_details #12 rendered as users #12).
        $employeeColumn = null;
        foreach ($manifest['ui']['directory']['columns'] ?? [] as $column) {
            if (($column['handle'] ?? $column['key'] ?? null) === 'employee_id') {
                $employeeColumn = $column;
                break;
            }
        }
        $this->assertIsArray($employeeColumn);
        $this->assertSame('employee', $employeeColumn['relation'] ?? null);
        $this->assertSame('employee_details', $employeeColumn['label_table'] ?? null);
    }

    private function blueprintAndPlan(): array
    {
        $path = dirname(__DIR__, 3).'/blueprints/examples/service-desk.slice.yaml';
        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($path));
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->temporaryDirectory.'/Slices');

        return [$blueprint, $plan];
    }
}
