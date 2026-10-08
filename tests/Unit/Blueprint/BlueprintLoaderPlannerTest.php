<?php

namespace LaraSlice\Tests\Unit\Blueprint;

use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use PHPUnit\Framework\TestCase;

class BlueprintLoaderPlannerTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-blueprints-'.bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryDirectory)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($entries as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temporaryDirectory);
        }
    }

    public function test_it_loads_yaml_and_creates_a_stable_read_only_plan_for_the_example(): void
    {
        $source = dirname(__DIR__, 3).'/blueprints/examples/service-desk.slice.yaml';
        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($source));
        $planner = new BlueprintPlanner;
        $first = $planner->plan($blueprint, $this->temporaryDirectory.'/Slices');
        $second = $planner->plan($blueprint, $this->temporaryDirectory.'/Slices');

        $this->assertSame($first, $second);
        $this->assertSame('ticket', $first['models'][1]['handle']);
        $ticketTable = array_values(array_filter(
            $first['database_operations'],
            static fn (array $operation): bool => $operation['kind'] === 'create_table' && $operation['table'] === 'tickets'
        ))[0];
        $this->assertSame(
            ['id', 'service_desk_id', 'subject', 'status', 'created_at', 'updated_at'],
            array_column($ticketTable['columns'], 'column')
        );
        $this->assertSame('string', $ticketTable['columns'][3]['type']);
        $this->assertSame('enum', $ticketTable['columns'][3]['field_type']);
        $this->assertSame(['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved'], $ticketTable['columns'][3]['options']);
        $rootTable = array_values(array_filter(
            $first['database_operations'],
            static fn (array $operation): bool => $operation['kind'] === 'create_table' && $operation['table'] === 'service_desks'
        ))[0];
        $this->assertSame(['id', 'title', 'description', 'status', 'name', 'is_active', 'created_at', 'updated_at'], array_column($rootTable['columns'], 'column'));
        $foreignKeys = array_values(array_filter(
            $first['database_operations'],
            static fn (array $operation): bool => $operation['kind'] === 'foreign_key'
        ));
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('plan_only_no_files_or_database_changes', $first['execution']);
        $this->assertSame('requires_apply_capability_check', $first['generation_status']);
        $createTables = array_values(array_filter($first['database_operations'], static fn (array $operation): bool => $operation['kind'] === 'create_table'));
        $this->assertSame(['service_desks', 'tickets'], array_column($createTables, 'table'));
        $this->assertDirectoryDoesNotExist($this->temporaryDirectory.'/Slices');
    }

    public function test_it_reports_existing_output_as_a_conflict_without_touching_it(): void
    {
        $slicesPath = $this->temporaryDirectory.'/Slices';
        mkdir($slicesPath.'/ServiceDesks', 0755, true);
        file_put_contents($slicesPath.'/ServiceDesks/keep.txt', 'preserve');

        $source = dirname(__DIR__, 3).'/blueprints/examples/service-desk.slice.yaml';
        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($source));
        $plan = (new BlueprintPlanner)->plan($blueprint, $slicesPath);

        $this->assertTrue($plan['conflict']);
        $this->assertSame('preserve', file_get_contents($slicesPath.'/ServiceDesks/keep.txt'));
    }

    public function test_it_loads_json_blueprints_as_well_as_yaml(): void
    {
        $jsonPath = $this->temporaryDirectory.'/minimal.json';
        file_put_contents($jsonPath, json_encode([
            'schema_version' => 1,
            'name' => 'Label',
            'handle' => 'label',
            'models' => [[
                'handle' => 'label',
                'table' => 'labels',
                'root' => true,
                'fields' => [['handle' => 'value', 'type' => 'string']],
            ]],
        ], JSON_THROW_ON_ERROR));

        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($jsonPath));

        $this->assertSame('label', $blueprint['handle']);
    }

    public function test_it_parses_yaml_from_an_editor_without_a_temporary_file(): void
    {
        $path = dirname(__DIR__, 3).'/blueprints/examples/service-desk.slice.yaml';
        $source = file_get_contents($path);
        $blueprint = (new BlueprintLoader)->parse($source, 'yaml');

        $this->assertSame('service_desk', $blueprint['handle']);
        $this->assertSame('tickets', $blueprint['models'][0]['relations'][0]['name']);
    }
}
