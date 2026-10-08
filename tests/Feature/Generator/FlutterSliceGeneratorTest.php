<?php

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Generator\FlutterSliceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The Dart SDK is not available in CI, so generated code is checked structurally.
 */
class FlutterSliceGeneratorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-flutter-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function generate(array $options = []): string
    {
        return (new FlutterSliceGenerator($this->dir))->generate('Invoice', $options + [
            'api_path' => 'billing/invoices',
            'fields' => [
                ['name' => 'amount', 'type' => 'decimal', 'label' => "Customer's amount"],
                ['name' => 'quantity', 'type' => 'integer'],
                ['name' => 'is_paid', 'type' => 'boolean'],
                ['name' => 'customer_id', 'type' => 'foreign_id'],
            ],
        ]);
    }

    public function test_model_uses_an_integer_id_and_typed_custom_fields(): void
    {
        $model = file_get_contents($this->generate().'/models/invoice_model.dart');

        $this->assertStringContainsString('final int? id;', $model);
        $this->assertStringContainsString("id: _toInt(json['id']),", $model);
        $this->assertStringContainsString('final double? amount;', $model);
        $this->assertStringContainsString('final int? quantity;', $model);
        $this->assertStringContainsString('final bool? isPaid;', $model);
        $this->assertStringContainsString("'customer_id': customerId,", $model);
        $this->assertStringContainsString("if (id != null) 'id': id,", $model);
    }

    public function test_service_calls_the_real_api_path_with_a_bearer_token(): void
    {
        $service = file_get_contents($this->generate().'/services/invoice_api_service.dart');

        $this->assertStringContainsString("Uri.parse('\$baseUrl/api/billing/invoices\$path')", $service);
        $this->assertStringContainsString("'Authorization': 'Bearer \$token'", $service);
        $this->assertStringContainsString('response.statusCode < 200 || response.statusCode >= 300', $service, '201 Created counts as success');
    }

    public function test_form_has_inputs_for_custom_fields_and_escapes_labels(): void
    {
        $form = file_get_contents($this->generate().'/views/invoice_form_view.dart');

        $this->assertStringContainsString("labelText: 'Customer\\'s amount'", $form);
        $this->assertStringContainsString('_quantityCtrl', $form);
        $this->assertStringContainsString('SwitchListTile(', $form);
        $this->assertStringContainsString('isPaid: _isPaid,', $form);
    }

    public function test_generated_dart_has_balanced_brackets(): void
    {
        $dir = $this->generate();
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            // Strip string literals before counting
            $code = preg_replace("/'(?:\\\\.|[^'\\\\])*'/", "''", file_get_contents($file->getPathname()));
            foreach (['{' => '}', '(' => ')', '[' => ']'] as $open => $close) {
                $this->assertSame(substr_count($code, $open), substr_count($code, $close), "{$open}{$close} in {$file->getFilename()}");
            }
        }
    }

    public function test_existing_files_are_kept_unless_forced(): void
    {
        $dir = $this->generate();
        file_put_contents($dir.'/models/invoice_model.dart', '// customised');

        try {
            $this->generate();
            $this->fail('Expected a refusal to overwrite.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--force', $e->getMessage());
        }
        $this->assertSame('// customised', file_get_contents($dir.'/models/invoice_model.dart'));

        $this->generate(['force' => true]);
        $this->assertStringContainsString('class InvoiceModel', file_get_contents($dir.'/models/invoice_model.dart'));
    }
}
