<?php

namespace LaraSlice\Tests\Feature\Generator;

use InvalidArgumentException;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeneratorCorrectnessTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laraslice-correctness-' . bin2hex(random_bytes(8));
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

    public static function reservedNames(): array
    {
        return [['List'], ['Class'], ['Match'], ['Function'], ['Print'], ['Model'], ['Builder'], ['Validator']];
    }

    #[DataProvider('reservedNames')]
    public function test_reserved_slice_names_are_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        SliceName::canonical($name);
    }

    public function test_domains_must_produce_a_valid_namespace_segment(): void
    {
        $this->assertSame('HumanResources', SliceName::domainSegment('Human Resources'));

        $this->expectException(InvalidArgumentException::class);
        (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Report', [], false, ['domain' => '2024 Sales']);
    }

    public function test_dto_defaults_match_their_property_types(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Gadget', [
            ['name' => 'status', 'type' => 'boolean', 'label' => 'Enabled', 'default' => false],
            ['name' => 'title', 'type' => 'string', 'label' => 'Title', 'nullable' => true],
            ['name' => 'weight', 'type' => 'decimal', 'label' => 'Weight', 'default' => '1.5'],
            ['name' => 'stock', 'type' => 'integer', 'label' => 'Stock', 'default' => 3],
        ]);

        $dto = file_get_contents($path . '/Contracts/GadgetFormBusinessObject.php');
        $this->assertStringContainsString('public bool $status = false;', $dto);
        $this->assertStringContainsString('public ?string $title = NULL;', $dto);
        $this->assertStringContainsString('public ?float $weight = 1.5;', $dto);
        $this->assertStringContainsString('public ?int $stock = 3;', $dto);

        // The generated class must load and accept typical form input
        require_once $path . '/Contracts/GadgetFormBusinessObject.php';
        $form = \App\Slices\Gadgets\Contracts\GadgetFormBusinessObject::fromArray([
            'status' => '1', 'title' => null, 'weight' => '2.25', 'stock' => '7',
        ]);
        $this->assertTrue($form->status);
        $this->assertSame(2.25, $form->weight);
        $this->assertSame(7, $form->stock);
    }

    public function test_generated_schema_class_can_build_its_fields(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Widget');
        $schemaFile = glob($path . '/Schemas/*.php')[0] ?? null;
        $this->assertNotNull($schemaFile);

        require_once $schemaFile;
        $class = 'App\\Slices\\Widgets\\Schemas\\' . basename($schemaFile, '.php');
        $fields = $class::fields();

        $this->assertNotEmpty($fields);
    }

    public function test_form_objects_coerce_request_values_instead_of_throwing(): void
    {
        $form = TypedForm::fromArray([
            'name' => null,          // empty input converted to null
            'count' => 'abc',        // not a number: keeps the default
            'price' => '9.5',
            'active' => 'on',
            'tags' => 'not-an-array',
            0 => 'ignored',
        ]);

        $this->assertSame('', $form->name);
        $this->assertSame(1, $form->count);
        $this->assertSame(9.5, $form->price);
        $this->assertTrue($form->active);
        $this->assertSame([], $form->tags);
    }
}

class TypedForm extends BaseFormBusinessObject
{
    public string $name = 'default';
    public int $count = 1;
    public float $price = 0.0;
    public bool $active = false;
    public array $tags = [];
}
