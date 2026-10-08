<?php

namespace LaraSlice\Tests\Feature\Generator;

use InvalidArgumentException;
use LaraSlice\Blueprint\BlueprintValidationException;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Generator\BladeSafeText;
use LaraSlice\Generator\FlutterSliceGenerator;
use LaraSlice\Generator\SliceFieldDefinitionException;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * User-supplied names, labels and URLs must never become executable PHP or Blade in generated files.
 */
class GeneratorInjectionTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-injection-'.bin2hex(random_bytes(8));
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

    public static function bladePayloads(): array
    {
        return [
            'echo' => ["{{ system('id') }}"],
            'raw echo' => ['{!! phpinfo() !!}'],
            'directive' => ['Name @php system("id") @endphp'],
            'php tag' => ['<?php system("id"); ?>'],
            'closing echo' => ['value }} breaks'],
        ];
    }

    #[DataProvider('bladePayloads')]
    public function test_generator_rejects_blade_syntax_in_labels(string $payload): void
    {
        $this->expectException(SliceFieldDefinitionException::class);

        (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice', [
            ['name' => 'reference', 'type' => 'string', 'label' => $payload],
        ]);
    }

    public function test_generator_rejects_blade_syntax_in_string_defaults_and_option_labels(): void
    {
        $generator = new SliceGenerator($this->slicesPath, 'App\\Slices');

        try {
            $generator->generate('Invoice', [['name' => 'reference', 'type' => 'string', 'default' => 'a}}b']]);
            $this->fail('A default containing }} must be rejected.');
        } catch (SliceFieldDefinitionException) {
        }

        $this->expectException(SliceFieldDefinitionException::class);
        $generator->generate('Invoice', [[
            'name' => 'stage', 'type' => 'select', 'options' => ['open' => '{{ evil() }}'],
        ]]);
    }

    public function test_plain_text_with_email_addresses_and_braces_is_still_allowed(): void
    {
        $this->assertTrue(BladeSafeText::isSafe('Contact (support@example.com) {primary}'));
        $this->assertFalse(BladeSafeText::isSafe('@if(true) x @endif'));
    }

    public function test_blueprint_validator_rejects_blade_syntax(): void
    {
        try {
            (new BlueprintValidator)->validate([
                'schema_version' => 1,
                'name' => 'Tickets',
                'handle' => 'tickets',
                'models' => [[
                    'handle' => 'ticket',
                    'fields' => [
                        ['handle' => 'subject', 'type' => 'string', 'label' => '{{ system("id") }}'],
                        ['handle' => 'note', 'type' => 'string', 'default' => '@php echo 1; @endphp'],
                    ],
                ]],
            ]);
            $this->fail('The blueprint must be rejected.');
        } catch (BlueprintValidationException $e) {
            $this->assertStringContainsString('.label cannot contain Blade', $e->getMessage());
            $this->assertStringContainsString('.default cannot contain Blade', $e->getMessage());
        }
    }

    public function test_navigation_urls_cannot_inject_php_into_route_files(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');
        $routes = file_get_contents($path.'/Routes/web.php');

        $modifier = new SliceModifier($this->slicesPath, 'App\\Slices');

        try {
            $modifier->updateNavigation('Invoice', ['url' => "x'); system('id'); //"]);
            $this->fail('An unsafe navigation URL must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($routes, file_get_contents($path.'/Routes/web.php'));

        $modifier->updateNavigation('Invoice', ['url' => '/billing/invoices-2026']);
        $updated = file_get_contents($path.'/Routes/web.php');
        $this->assertStringContainsString("Route::prefix('billing/invoices-2026')", $updated);
        token_get_all($updated, TOKEN_PARSE);
    }

    public static function relationPayloads(): array
    {
        return [
            'foreign key' => [['foreign_key' => "x'); system('id'); ('"]],
            'model' => [['model' => 'Category::class); system("id"); //']],
            'method' => [['method' => 'x(){} public function y']],
            'type' => [['type' => 'morphTo']],
            'source path traversal' => [['source_model' => '../../../evil']],
        ];
    }

    #[DataProvider('relationPayloads')]
    public function test_relationships_only_accept_identifiers(array $override): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');
        $model = file_get_contents($path.'/Models/Invoice.php');

        $relation = array_merge([
            'source_model' => 'invoice',
            'type' => 'belongsTo',
            'model' => 'customer',
            'foreign_key' => 'customer_id',
            'method' => 'customer',
        ], $override);

        try {
            (new SliceModifier($this->slicesPath, 'App\\Slices'))->saveRelationships('Invoice', [$relation]);
            $this->fail('An unsafe relationship definition must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($model, file_get_contents($path.'/Models/Invoice.php'));
    }

    public function test_flutter_generator_rejects_path_traversal_names(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FlutterSliceGenerator($this->slicesPath.'/flutter'))->generate('../../outside');
    }
}
