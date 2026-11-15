<?php

namespace LaraSlice\Tests\Feature\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Support\NamingSeries;
use LaraSlice\Support\SliceRecordOptions;
use LaraSlice\Tests\TestCase;

/**
 * Seat 1 (Stage D) runtime pieces: naming-series
 * generation, the in_list_view column fallback, and
 * the fetch_from resolution helpers.
 */
class Seat1RuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('laraslice_counters')) {
            Schema::create('laraslice_counters', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
            });
        }
    }

    public function test_naming_series_mints_sequential_padded_numbers(): void
    {
        $autoname = ['field' => 'name', 'pattern' => 'CRM-LEAD-.YYYY.-#####'];
        $year = now()->format('Y');
        $this->assertSame("CRM-LEAD-{$year}-00001", NamingSeries::next('Leads', $autoname));
        $this->assertSame("CRM-LEAD-{$year}-00002", NamingSeries::next('Leads', $autoname));
    }

    public function test_naming_series_counters_are_scoped_per_slice_and_prefix(): void
    {
        $a = ['pattern' => 'INV-####'];
        $b = ['pattern' => 'QUO-####'];
        $this->assertSame('INV-0001', NamingSeries::next('Invoices', $a));
        $this->assertSame('QUO-0001', NamingSeries::next('Invoices', $b));
        $this->assertSame('INV-0001', NamingSeries::next('Quotes', $a));
        $this->assertSame('INV-0002', NamingSeries::next('Invoices', $a));
    }

    public function test_in_list_view_fields_become_directory_columns(): void
    {
        app(SliceGenerator::class)->generate('Widget', [
            ['name' => 'title', 'type' => 'string', 'in_list_view' => true],
            ['name' => 'sku', 'type' => 'string', 'in_list_view' => true],
            ['name' => 'internal_note', 'type' => 'string'],
        ]);
        app(SliceManager::class)->discover(true);

        // The fallback governs manifests that declare no
        // ui.directory.columns of their own: strip the
        // generated ones and drop the manifest cache.
        $path = \LaraSlice\Support\DirectoryUi::class;
        $manifestPath = null;
        foreach ([config('laraslice.slices_path').'/Widgets/slice.yaml',
                     ...(glob(config('laraslice.slices_path').'/*/*/slice.yaml') ?: []),
                     ...(glob(config('laraslice.slices_path').'/*/*/*/slice.yaml') ?: [])] as $candidate) {
            if (is_file($candidate) && str_contains((string) file_get_contents($candidate), 'Widgets')) {
                $manifestPath = $candidate;
                break;
            }
        }
        $this->assertNotNull($manifestPath);
        $manifest = \Symfony\Component\Yaml\Yaml::parseFile($manifestPath);
        unset($manifest['ui']['directory']['columns']);
        file_put_contents($manifestPath, \Symfony\Component\Yaml\Yaml::dump($manifest, 6, 2));
        $prop = new \ReflectionProperty($path, 'manifestDataBySlice');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $columns = DirectoryUi::columnsForSlice('Widgets', [['key' => 'fallback']]);
        $keys = array_column($columns, 'key');
        $this->assertContains('title', $keys);
        $this->assertContains('sku', $keys);
        $this->assertNotContains('internal_note', $keys);
        $this->assertNotContains('fallback', $keys);
    }

    public function test_fetch_resolution_helpers(): void
    {
        $user = $this->makeUser(['name' => 'Fetch Source', 'email' => 'fetch@example.com']);
        $fields = [
            'owner' => ['name' => 'owner', 'type' => 'integer', 'relation' => 'user'],
            'owner_email' => ['name' => 'owner_email', 'type' => 'string', 'fetch_from' => 'owner.email'],
        ];
        $this->assertSame('Users', SliceRecordOptions::viaTargetSlice($fields, 'owner'));
        $this->assertSame('fetch@example.com', SliceRecordOptions::recordValue('Users', $user->id, 'email'));
        $this->assertNull(SliceRecordOptions::recordValue('Users', $user->id, 'no_such_column'));
    }

    public function test_manifest_fields_extraction_from_both_heart_shapes(): void
    {
        $map = SliceRecordOptions::manifestFieldsOf([
            'fields' => ['status' => ['name' => 'status', 'type' => 'string']],
        ]);
        $this->assertArrayHasKey('status', $map);

        $list = SliceRecordOptions::manifestFieldsOf([
            'models' => [['root' => true, 'fields' => [['handle' => 'status', 'type' => 'string']]]],
        ]);
        $this->assertArrayHasKey('status', $list);
    }
}
