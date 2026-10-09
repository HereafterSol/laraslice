<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Generator\SliceSeederService;
use LaraSlice\Tests\TestCase;

class SliceSeederServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        app('files')->deleteDirectory(config('laraslice.slices_path'));
        parent::tearDown();
    }

    /** Generate a slice and run its migrations, as `slice:make` + `migrate` would in an app. */
    private function makeMigratedSlice(string $name, array $fields, ?string $domain = null): void
    {
        $args = ['name' => [$name], '--field' => $fields];
        if ($domain) {
            $args['--domain'] = $domain;
        }
        $this->artisan('slice:make', $args)->assertSuccessful();
        app(SliceManager::class)->discover();

        foreach (glob(config('laraslice.slices_path').'/**/Migrations', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->artisan('migrate', ['--path' => $dir, '--realpath' => true])->assertSuccessful();
        }
        foreach (glob(config('laraslice.slices_path').'/*/*/Migrations', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->artisan('migrate', ['--path' => $dir, '--realpath' => true])->assertSuccessful();
        }
    }

    public function test_seeding_fills_every_column_with_a_value_of_its_type(): void
    {
        $this->makeMigratedSlice('Ticket', ['priority:integer', 'price:decimal', 'urgent:boolean', 'due_date:date']);
        $this->assertTrue(Schema::hasTable('tickets'));

        $result = app(SliceSeederService::class)->seedSlice('Ticket', 6);

        $this->assertTrue($result['success']);
        $this->assertSame(6, $result['tables']['tickets']);
        $rows = DB::table('tickets')->get();
        $this->assertCount(6, $rows);
        foreach ($rows as $row) {
            $this->assertNotSame('', (string) $row->title);
            $this->assertIsNumeric($row->priority);
            $this->assertIsNumeric($row->price);
            $this->assertContains((int) $row->urgent, [0, 1]);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}/', (string) $row->due_date);
        }
    }

    public function test_wipe_empties_the_slice_tables_and_keeps_the_code(): void
    {
        $this->makeMigratedSlice('Ticket', ['priority:integer']);
        $service = app(SliceSeederService::class);
        $service->seedSlice('Ticket', 3);

        $result = $service->wipeSlice('Ticket');

        $this->assertSame(['tickets'], $result['tables']);
        $this->assertSame(0, DB::table('tickets')->count());
        $this->assertNotNull(app(SliceManager::class)->getSlice('Ticket'));
    }

    public function test_seed_and_wipe_a_whole_domain(): void
    {
        $this->makeMigratedSlice('Lead', [], 'Sales');
        $this->makeMigratedSlice('Quote', ['amount:decimal'], 'Sales');
        $service = app(SliceSeederService::class);

        $seeded = $service->seedDomain('sales', 4);
        $this->assertSame(8, $seeded['totalSeeded']);

        $service->wipeDomain('Sales');
        $this->assertSame(0, DB::table('leads')->count() + DB::table('quotes')->count());
    }

    public function test_core_slices_and_unknown_slices_are_refused(): void
    {
        $service = app(SliceSeederService::class);

        $this->expectException(\RuntimeException::class);
        try {
            $service->seedSlice('Nope');
        } catch (\InvalidArgumentException $e) {
            $service->wipeSlice('Users');
        }
    }

    public function test_select_fields_are_seeded_with_their_option_keys(): void
    {
        (new SliceGenerator(config('laraslice.slices_path'), 'App\Slices'))->generate('Deal', [
            ['name' => 'stage', 'type' => 'select', 'options' => ['new' => 'New', 'won' => 'Won', 'lost' => 'Lost']],
        ]);
        app(SliceManager::class)->discover();
        foreach (glob(config('laraslice.slices_path').'/*/Migrations', GLOB_ONLYDIR) as $dir) {
            $this->artisan('migrate', ['--path' => $dir, '--realpath' => true])->assertSuccessful();
        }

        app(SliceSeederService::class)->seedSlice('Deal', 6);

        $this->assertSame([], DB::table('deals')->whereNotIn('stage', ['new', 'won', 'lost'])->pluck('stage')->all());
    }
}
