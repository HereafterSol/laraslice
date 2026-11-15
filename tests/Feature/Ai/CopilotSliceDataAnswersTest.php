<?php

namespace LaraSlice\Tests\Feature\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Tests\TestCase;

/**
 * Stage C-2: slice data questions — counts, option-filtered
 * counts, sums and "where do I …" navigation — answered live
 * from the database and the navigation registry. The Stage B
 * AI audit scored the built-in copilot 1/5 because these
 * advertised phrasings had no handler: counts fell to the
 * help fallback and a total was answered with registry facts.
 */
class CopilotSliceDataAnswersTest extends TestCase
{
    /** @var string[] */
    private array $slicePaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('invoices', function ($table) {
            $table->id();
            $table->string('number');
            $table->string('status')->default('unpaid');
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });
        DB::table('invoices')->insert([
            ['number' => 'INV-1', 'status' => 'unpaid', 'total' => 100.50],
            ['number' => 'INV-2', 'status' => 'paid', 'total' => 200.00],
            ['number' => 'INV-3', 'status' => 'unpaid', 'total' => 50.00],
        ]);

        Schema::create('workers', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        DB::table('workers')->insert([['name' => 'A'], ['name' => 'B']]);

        Schema::create('leaves', function ($table) {
            $table->id();
            $table->timestamps();
        });

        $this->makeSlice('Invoices', [
            'name' => 'Invoices',
            'title' => 'Invoices',
            'version' => '1.0.0',
            'active' => true,
            'domain' => 'Finance',
            'tables' => ['invoices'],
            'models' => [[
                'handle' => 'invoice',
                'table' => 'invoices',
                'root' => true,
                'fields' => [
                    'number' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'options' => ['unpaid' => 'Unpaid', 'paid' => 'Paid']],
                    'total' => ['type' => 'decimal', 'label' => 'Total'],
                ],
            ]],
        ]);
        $this->makeSlice('Workers', [
            'name' => 'Workers',
            'title' => 'Workers',
            'version' => '1.0.0',
            'active' => true,
            'domain' => 'HR',
            'tables' => ['workers'],
            'models' => [[
                'handle' => 'worker',
                'table' => 'workers',
                'root' => true,
                'fields' => ['name' => ['type' => 'string']],
            ]],
        ]);
        $this->makeSlice('Leaves', [
            'name' => 'Leaves',
            'title' => 'Leaves',
            'version' => '1.0.0',
            'active' => true,
            'domain' => 'HR',
            'tables' => ['leaves'],
            'navigation' => ['group' => 'HR'],
            'models' => [[
                'handle' => 'leave',
                'table' => 'leaves',
                'root' => true,
                'fields' => [],
            ]],
        ]);

        app(SliceManager::class)->discover(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->slicePaths as $path) {
            app('files')->deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function makeSlice(string $dir, array $manifest): void
    {
        $path = config('laraslice.slices_path').DIRECTORY_SEPARATOR.$dir;
        @mkdir($path, 0755, true);
        ManifestRepository::writeYaml($path, $manifest);
        $this->slicePaths[] = $path;
    }

    private function ask(object $user, string $message): string
    {
        return (string) $this->actingAs($user)
            ->postJson('/laraslice/ai/chat', ['message' => $message])
            ->assertOk()
            ->json('reply');
    }

    public function test_how_many_count_is_answered_from_the_live_table(): void
    {
        $reply = $this->ask($this->makeSuperAdmin(), 'How many invoices are there?');

        $this->assertStringContainsString('**3**', $reply);
        $this->assertStringContainsString('Invoices', $reply);
        $this->assertStringContainsString('live count', $reply);
    }

    public function test_count_resolves_the_slice_by_title_and_singular(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->assertStringContainsString('**2**', $this->ask($admin, 'How many workers are there?'));
        $this->assertStringContainsString('**2**', $this->ask($admin, 'How many worker records are there?'));
    }

    public function test_filtered_count_uses_the_declared_status_options(): void
    {
        $reply = $this->ask($this->makeSuperAdmin(), 'How many unpaid invoices are there?');

        $this->assertStringContainsString('**2** of **3**', $reply);
        $this->assertStringContainsString('Unpaid', $reply);
        $this->assertStringContainsString('`status` = `unpaid`', $reply);
    }

    public function test_total_sums_the_named_numeric_field(): void
    {
        $reply = $this->ask($this->makeSuperAdmin(), 'What is the total of all invoices?');

        $this->assertStringContainsString('**350.5**', $reply);
        $this->assertStringContainsString('live sum', $reply);
    }

    public function test_navigation_question_gets_the_directory_link(): void
    {
        $reply = $this->ask($this->makeSuperAdmin(), 'Where do I apply for leave?');

        $this->assertStringContainsString('Leaves', $reply);
        $this->assertStringContainsString('/leaves', $reply);
    }

    public function test_inventory_question_is_not_hijacked_by_the_data_handler(): void
    {
        $reply = $this->ask($this->makeSuperAdmin(), 'How many slices do we have?');

        $this->assertStringContainsString('across', $reply);
        $this->assertStringNotContainsString('live count on', $reply);
    }
}
