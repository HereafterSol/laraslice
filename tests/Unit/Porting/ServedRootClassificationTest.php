<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\SlicePortService;
use LaraSlice\Tests\TestCase;

/**
 * Root-vs-child classification corroboration: a table the source
 * serves through its own model/controller is a standalone module,
 * and a name-inferred "foreign key" whose column is not an integer
 * type is a label, not containment evidence. (The real-world case:
 * a holidays table whose TEXT event_id column got it classified as
 * a child of Events, so no Holidays module was ever ported.)
 */
final class ServedRootClassificationTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraslice-served-'.bin2hex(random_bytes(6));
        mkdir($this->fixtureDir.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations', 0755, true);
        mkdir($this->fixtureDir.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Models', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->fixtureDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeMigration(string $eventIdColumn): void
    {
        $migration = <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('events', function (Blueprint \$table) {
            \$table->id();
            \$table->string('title')->nullable();
            \$table->timestamps();
        });
        Schema::create('holidays', function (Blueprint \$table) {
            \$table->id();
            {$eventIdColumn}
            \$table->string('occassion')->nullable();
            \$table->date('date')->nullable();
            \$table->timestamps();
        });
    }
};
PHP;
        file_put_contents(
            $this->fixtureDir.'/database/migrations/2024_01_01_000000_create_tables.php',
            $migration
        );
    }

    public function test_text_fk_column_does_not_demote_a_served_table_to_child(): void
    {
        $this->writeMigration("\$table->string('event_id')->nullable();");
        file_put_contents($this->fixtureDir.'/app/Models/Holiday.php', "<?php\n\nclass Holiday {}\n");

        $inspection = (new SlicePortService)->inspect($this->fixtureDir, 'HR');

        $this->assertArrayHasKey('Holidays', $inspection['slice_mapping']);
        $this->assertSame('holidays', $inspection['slice_mapping']['Holidays']['primary_table']);
        $eventsChildren = $inspection['slice_mapping']['Events']['child_tables'] ?? [];
        $this->assertNotContains('holidays', $eventsChildren);
    }

    public function test_text_fk_alone_still_keeps_the_table_a_root(): void
    {
        $this->writeMigration("\$table->string('event_id')->nullable();");

        $inspection = (new SlicePortService)->inspect($this->fixtureDir, 'HR');

        $this->assertArrayHasKey('Holidays', $inspection['slice_mapping']);
    }

    public function test_integer_fk_without_served_surface_still_classifies_as_child(): void
    {
        $this->writeMigration("\$table->unsignedBigInteger('event_id');");

        $inspection = (new SlicePortService)->inspect($this->fixtureDir, 'HR');

        $this->assertArrayNotHasKey('Holidays', $inspection['slice_mapping']);
        $this->assertContains('holidays', $inspection['slice_mapping']['Events']['child_tables'] ?? []);
    }
}
