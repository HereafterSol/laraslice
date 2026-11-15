<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Blueprint\BlueprintApplier;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Slices\Roles\Models\Role;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Tests\TestCase;

class RelationGenerationTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-relations-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        app('files')->deleteDirectory($this->slicesPath);
        parent::tearDown();
    }

    public function test_belongs_to_relations_use_the_blueprint_name_and_resolve_through_slices(): void
    {
        $blueprint = (new BlueprintValidator)->validate([
            'schema_version' => 1,
            'name' => 'Shop Products',
            'handle' => 'shop_products',
            'models' => [[
                'handle' => 'shop_product',
                'table' => 'shop_products',
                'root' => true,
                'fields' => [
                    ['handle' => 'shop_category_id', 'type' => 'foreign_id', 'nullable' => true],
                    ['handle' => 'owner_id', 'type' => 'foreign_id', 'nullable' => true],
                ],
                'relations' => [
                    ['name' => 'category', 'type' => 'belongsTo', 'model' => 'shop_category', 'foreign_key' => 'shop_category_id', 'external' => true, 'table' => 'shop_categories'],
                ],
            ]],
        ]);
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->slicesPath);
        $target = (new BlueprintApplier)->apply($blueprint, $plan, $this->slicesPath, 'App\\Slices');

        $model = file_get_contents($target.'/Models/ShopProduct.php');
        $this->assertStringContainsString('public function category(): BelongsTo', $model);
        $this->assertStringContainsString("modelClass('ShopCategory')", $model);
        $this->assertStringContainsString('public function owner(): BelongsTo', $model, 'unnamed *_id fields still get a relation');
        $this->assertStringNotContainsString('ECommerce', $model);

        $migration = file_get_contents(glob($target.'/Migrations/*.php')[0]);
        $this->assertStringContainsString("\$table->foreignId('shop_category_id')->nullable()->constrained('shop_categories')->nullOnDelete()", $migration);
        $this->assertStringContainsString("\$table->unsignedBigInteger('owner_id')->nullable()->index()", $migration);
    }

    public function test_model_class_lookup_searches_discovered_slices_and_app_models(): void
    {
        $manager = app(SliceManager::class);

        $this->assertSame(User::class, $manager->modelClass('User'));
        $this->assertSame(Role::class, $manager->modelClass('Role'));
        $this->assertNull($manager->modelClass('NoSuchThing'));
    }

    public function test_foreign_keys_reference_tables_only_when_known(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Ticket', [
            ['name' => 'customer_id', 'type' => 'foreign_id', 'references' => 'customers', 'required' => true],
        ]);

        $migration = file_get_contents(glob($path.'/Migrations/*.php')[0]);
        $this->assertStringContainsString("\$table->foreignId('customer_id')->constrained('customers')->restrictOnDelete()", $migration);
    }

    public function test_string_typed_id_fields_and_polymorphic_parents_get_no_relation(): void
    {
        // Gate T A2d for models: string *_id fields are
        // human/external codes (email_id, tax_id), and a
        // parent_id beside parent_type is a polymorphic
        // link — neither may emit a stem-guessed BelongsTo
        // that throws the first time a surface touches it.
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('LeadNote', [
            ['name' => 'email_id', 'type' => 'string', 'nullable' => true],
            ['name' => 'tax_id', 'type' => 'string', 'nullable' => true],
            ['name' => 'parent_id', 'type' => 'integer', 'nullable' => true],
            ['name' => 'parent_type', 'type' => 'string', 'nullable' => true],
            ['name' => 'owner_id', 'type' => 'foreign_id', 'nullable' => true],
        ]);

        $model = file_get_contents($path.'/Models/LeadNote.php');
        $this->assertStringNotContainsString('function email(', $model);
        $this->assertStringNotContainsString('function tax(', $model);
        $this->assertStringNotContainsString('function parent(', $model);
        $this->assertStringContainsString('public function owner(): BelongsTo', $model, 'real foreign keys still get a relation');
    }
}
