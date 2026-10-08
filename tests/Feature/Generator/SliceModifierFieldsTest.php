<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceModifier;
use PHPUnit\Framework\TestCase;

class SliceModifierFieldsTest extends TestCase
{
    private string $slicesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->slicesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-modifier-'.bin2hex(random_bytes(8));
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

    private function assertParses(string $file): void
    {
        token_get_all(file_get_contents($file), TOKEN_PARSE);
        $this->addToAssertionCount(1);
    }

    public function test_added_fields_become_fillable_validated_and_typed(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');

        (new SliceModifier($this->slicesPath, 'App\\Slices'))->addFieldsBatch('Invoice', [
            ['name' => 'reference', 'type' => 'string', 'length' => 40, 'nullable' => false],
            ['name' => 'amount', 'type' => 'decimal', 'nullable' => true],
            ['name' => 'desc', 'type' => 'text', 'nullable' => true],
        ]);

        $model = file_get_contents($path.'/Models/Invoice.php');
        foreach (['reference', 'amount', 'desc', 'title'] as $column) {
            $this->assertStringContainsString("'{$column}'", $model);
        }
        $this->assertParses($path.'/Models/Invoice.php');

        $service = file_get_contents($path.'/Services/InvoiceSliceService.php');
        $this->assertMatchesRegularExpression("/'reference' =>\s*array \(\s*0 => 'required',\s*1 => 'string',\s*2 => 'max:40',/", $service);
        $this->assertStringContainsString("'amount' =>", $service);
        $this->assertParses($path.'/Services/InvoiceSliceService.php');

        // "desc" must be added even though the DTO already declares $description
        $dto = file_get_contents($path.'/Contracts/InvoiceFormBusinessObject.php');
        $this->assertMatchesRegularExpression('/public \?string \$desc = null;/', $dto);
        $this->assertParses($path.'/Contracts/InvoiceFormBusinessObject.php');
    }

    public function test_child_models_written_with_long_array_syntax_get_new_fillable_columns(): void
    {
        $path = (new SliceGenerator($this->slicesPath, 'App\\Slices'))->generate('Invoice');
        $childModel = $path.'/Models/InvoiceLine.php';
        file_put_contents($childModel, "<?php\nnamespace App\\Slices\\Invoices\\Models;\nclass InvoiceLine extends \\Illuminate\\Database\\Eloquent\\Model\n{\n    protected \$fillable = array (\n  0 => 'invoice_id',\n  1 => 'quantity',\n);\n}\n");

        (new SliceModifier($this->slicesPath, 'App\\Slices'))->addFieldsBatch('Invoice', [
            ['name' => 'unit_price', 'type' => 'decimal'],
        ], targetTable: 'invoice_lines');

        $content = file_get_contents($childModel);
        foreach (['invoice_id', 'quantity', 'unit_price'] as $column) {
            $this->assertStringContainsString("'{$column}'", $content);
        }
        $this->assertParses($childModel);
    }
}
