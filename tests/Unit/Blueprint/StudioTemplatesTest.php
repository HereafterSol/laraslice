<?php

namespace LaraSlice\Tests\Unit\Blueprint;

use LaraSlice\Blueprint\BlueprintApplier;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Blueprint\BlueprintValidationException;
use LaraSlice\Blueprint\BlueprintValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StudioTemplatesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-templates-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dir);
    }

    public static function templates(): array
    {
        return array_map(fn ($t) => [$t], [
            'hr-module', 'shop-categories', 'shop', 'shop-orders', 'crm',
            'ecommerce_suite', 'crm_suite', 'billing_suite', 'blog', 'service-desk',
        ]);
    }

    private function template(string $name): array
    {
        $controller = (new \ReflectionClass(BlueprintStudioController::class))->newInstanceWithoutConstructor();
        $source = (new \ReflectionMethod($controller, 'getTemplateContent'))->invoke($controller, $name);

        return (new BlueprintLoader)->parse($source, 'yaml');
    }

    #[DataProvider('templates')]
    public function test_every_built_in_template_validates_and_applies(string $name): void
    {
        $blueprint = (new BlueprintValidator)->validate($this->template($name));
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->dir.'/Slices');
        $target = (new BlueprintApplier)->apply($blueprint, $plan, $this->dir.'/Slices', 'App\\Slices');

        $this->assertDirectoryExists($target);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php') && ! str_ends_with($file->getFilename(), '.blade.php')) {
                token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
            }
        }
    }

    public function test_length_soft_deletes_and_encryption_reach_the_generated_code(): void
    {
        $blueprint = (new BlueprintValidator)->validate([
            'schema_version' => 1,
            'name' => 'Vault Entries',
            'handle' => 'vault_entries',
            'models' => [[
                'handle' => 'vault_entry',
                'table' => 'vault_entries',
                'root' => true,
                'soft_deletes' => true,
                'fields' => [
                    ['handle' => 'code', 'type' => 'string', 'length' => 40, 'required' => true],
                    ['handle' => 'amount', 'type' => 'decimal', 'length' => '14,4'],
                    ['handle' => 'secret_note', 'type' => 'text', 'encrypted' => true],
                    ['handle' => 'website', 'type' => 'url'],
                ],
            ]],
        ]);
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->dir.'/Slices');
        $target = (new BlueprintApplier)->apply($blueprint, $plan, $this->dir.'/Slices', 'App\\Slices');

        $migration = file_get_contents(glob($target.'/Migrations/*.php')[0]);
        $this->assertStringContainsString("\$table->string('code', 40)", $migration);
        $this->assertStringContainsString("\$table->decimal('amount', 14, 4)", $migration);
        $this->assertStringContainsString("\$table->text('secret_note')", $migration);
        $this->assertStringContainsString('$table->softDeletes();', $migration);

        $model = file_get_contents($target.'/Models/VaultEntry.php');
        $this->assertStringContainsString('use SoftDeletes;', $model);
        $this->assertMatchesRegularExpression("/'secret_note' => 'encrypted'/", $model);

        $service = file_get_contents($target.'/Services/VaultEntrySliceService.php');
        $this->assertStringContainsString("'max:40'", $service);
        $this->assertStringContainsString("'url'", $service);
    }

    public function test_unsupported_options_are_rejected_instead_of_ignored(): void
    {
        $this->expectException(BlueprintValidationException::class);
        $this->expectExceptionMessageMatches('/tenant is not supported.*timestamps cannot be false.*encrypted is only supported/s');

        (new BlueprintValidator)->validate([
            'schema_version' => 1,
            'name' => 'Things',
            'handle' => 'things',
            'models' => [[
                'handle' => 'thing',
                'table' => 'things',
                'root' => true,
                'tenant' => true,
                'timestamps' => false,
                'fields' => [['handle' => 'count', 'type' => 'integer', 'encrypted' => true]],
            ]],
        ]);
    }
}
