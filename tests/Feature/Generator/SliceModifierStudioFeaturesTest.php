<?php

namespace LaraSlice\Tests\Feature\Generator;

use Illuminate\Support\Facades\Schema;
use LaraSlice\Generator\SliceModifier;
use LaraSlice\Tests\TestCase;

class SliceModifierStudioFeaturesTest extends TestCase
{
    private string $slicesPath;

    private string $slicePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-studio-tests-'.bin2hex(random_bytes(8));
        $this->slicePath = $this->slicesPath.DIRECTORY_SEPARATOR.'ShopProducts';
        foreach (['Contracts', 'Controllers', 'Migrations', 'Models', 'Resources/views', 'Routes', 'Services'] as $directory) {
            mkdir($this->slicePath.DIRECTORY_SEPARATOR.$directory, 0755, true);
        }

        file_put_contents($this->slicePath.'/slice.json', json_encode([
            'name' => 'ShopProducts',
            'version' => '1.0.0',
            'tables' => ['shop_products'],
            'fields' => [
                'title' => ['type' => 'string', 'nullable' => false, 'label' => 'Title', 'width' => 50],
            ],
            'relations' => [],
            'navigation' => [
                'title' => 'Products',
                'url' => '/e-commerce/products',
                'icon' => 'basket',
            ],
            'version_history' => [
                [
                    'version' => '1.0.0',
                    'description' => 'Initial vertical slice scaffold for ShopProducts',
                    'date' => '2026-09-29 12:00:00',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        file_put_contents(
            $this->slicePath.'/Models/ShopProduct.php',
            "<?php\nnamespace App\\Slices\\ShopProducts\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nclass ShopProduct extends Model\n{\n    protected \$table = 'shop_products';\n}\n"
        );
        file_put_contents($this->slicePath.'/Routes/web.php', "<?php\nuse Illuminate\\Support\\Facades\\Route;\n");
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

    public function test_it_synchronizes_schema_fields_with_migration_and_manifest(): void
    {
        $modifier = new SliceModifier($this->slicesPath, 'App\\Slices');

        $result = $modifier->syncFields(
            sliceName: 'ShopProducts',
            targetTable: 'shop_products',
            newFields: [
                ['name' => 'sku', 'type' => 'string', 'length' => 100, 'nullable' => true],
                ['name' => 'price', 'type' => 'decimal', 'length' => '10,2', 'nullable' => false, 'default' => 0],
            ],
            deletedFields: ['old_unused_column'],
            allFields: [
                ['handle' => 'title', 'label' => 'Product Title', 'type' => 'string', 'width' => 100, 'required' => true],
                ['handle' => 'sku', 'label' => 'SKU Code', 'type' => 'string', 'width' => 50, 'nullable' => true],
                ['handle' => 'price', 'label' => 'Base Price', 'type' => 'decimal', 'width' => 50, 'required' => true],
            ],
            author: 'Developer'
        );

        $this->assertTrue($result['success']);
        $this->assertFileExists($result['migration']);

        $migrationContent = file_get_contents($result['migration']);
        $this->assertStringContainsString("\$table->string('sku', 100)->nullable();", $migrationContent);
        $this->assertStringContainsString("\$table->decimal('price', 10,2)", $migrationContent);
        $this->assertStringContainsString("\$table->dropColumn('old_unused_column');", $migrationContent);

        $manifest = json_decode(file_get_contents($this->slicePath.'/slice.json'), true);
        $this->assertSame('1.0.1', $manifest['version']);
        $this->assertArrayHasKey('sku', $manifest['fields']);
        $this->assertArrayHasKey('price', $manifest['fields']);
        $this->assertSame('Product Title', $manifest['fields']['title']['label']);
        $this->assertSame(100, $manifest['fields']['title']['width']);
        $this->assertCount(2, $manifest['version_history']);
    }

    public function test_it_saves_and_injects_eloquent_relationships(): void
    {
        $modifier = new SliceModifier($this->slicesPath, 'App\\Slices');

        $result = $modifier->saveRelationships('ShopProducts', [
            [
                'source_model' => 'shop_product',
                'type' => 'belongsTo',
                'model' => 'category',
                'foreign_key' => 'shop_category_id',
                'method' => 'category',
            ],
            [
                'source_model' => 'shop_product',
                'type' => 'hasMany',
                'model' => 'shop_variant',
                'foreign_key' => 'shop_product_id',
                'method' => 'variants',
            ],
        ]);

        $this->assertTrue($result['success']);

        $manifest = json_decode(file_get_contents($this->slicePath.'/slice.json'), true);
        $this->assertCount(2, $manifest['relations']);
        $this->assertSame('belongsTo', $manifest['relations'][0]['type']);
        $this->assertSame('hasMany', $manifest['relations'][1]['type']);

        $modelContent = file_get_contents($this->slicePath.'/Models/ShopProduct.php');
        $this->assertStringContainsString('public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo', $modelContent);
        $this->assertStringContainsString("\$this->belongsTo(\\App\\Models\\Category::class, 'shop_category_id')", $modelContent);
        $this->assertStringContainsString('public function variants(): \Illuminate\Database\Eloquent\Relations\HasMany', $modelContent);
    }

    public function test_it_rolls_back_to_target_version(): void
    {
        $modifier = new SliceModifier($this->slicesPath, 'App\\Slices');

        // First add fields to bump to v1.0.1
        $modifier->syncFields(
            sliceName: 'ShopProducts',
            targetTable: 'shop_products',
            newFields: [['name' => 'barcode', 'type' => 'string', 'nullable' => true]],
        );

        $manifest = json_decode(file_get_contents($this->slicePath.'/slice.json'), true);
        $this->assertSame('1.0.1', $manifest['version']);
        $this->assertArrayHasKey('barcode', $manifest['fields']);

        // The synced migration ran against a real table
        Schema::create('shop_products', function ($table) {
            $table->id();
            $table->string('barcode')->nullable();
        });

        // Now rollback to v1.0.0
        $rollbackResult = $modifier->rollbackVersion('ShopProducts', '1.0.0');
        $this->assertFalse(Schema::hasColumn('shop_products', 'barcode'), 'the migration down() really ran');

        $this->assertTrue($rollbackResult['success']);
        $this->assertSame('1.0.0', $rollbackResult['version']);

        $updatedManifest = json_decode(file_get_contents($this->slicePath.'/slice.json'), true);
        $this->assertSame('1.0.0', $updatedManifest['version']);
        $this->assertArrayNotHasKey('barcode', $updatedManifest['fields']);
    }
}
