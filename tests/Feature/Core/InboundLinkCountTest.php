<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Support\RecordWorkplace;
use LaraSlice\Tests\TestCase;
use ReflectionMethod;

/**
 * D-081: the delete guard's link counter — the same
 * discovery the workplace Connections tab renders. A
 * record with inbound links must count them; a record
 * without counts zero (and discovery gaps never invent
 * links).
 */
final class InboundLinkCountTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/ls-linkcount-'.uniqid();
        mkdir($this->root, 0777, true);

        Schema::create('link_targets', function ($table) {
            $table->increments('id');
            $table->string('title')->nullable();
        });
        Schema::create('linkers', function ($table) {
            $table->increments('id');
            $table->integer('target')->nullable();
        });
        DB::table('link_targets')->insert(['id' => 1, 'title' => 'Target One']);
        DB::table('linkers')->insert([
            ['target' => 1],
            ['target' => 1],
            ['target' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_counts_inbound_links_and_zero_for_unlinked(): void
    {
        $manager = $this->managerWith(
            $this->manifest('Targets', 'LinkTargets', 'link_targets', ['title' => ['name' => 'title', 'type' => 'string']]),
            $this->manifest('Linkers', 'Linkers', 'linkers', ['target' => ['name' => 'target', 'type' => 'integer', 'relation' => 'link_target']]),
        );

        $this->assertSame(2, RecordWorkplace::inboundLinkCount($manager, 'LinkTargets', 1));
        $this->assertSame(0, RecordWorkplace::inboundLinkCount($manager, 'LinkTargets', 99));
    }

    private function manifest(string $dir, string $name, string $table, array $fields): SliceManifest
    {
        $path = $this->root.'/'.$dir;
        mkdir($path, 0777, true);
        $data = [
            'name' => $name,
            'version' => '1.0.0',
            'fields' => $fields,
            'models' => [['name' => $name, 'table' => $table, 'root' => true]],
        ];
        file_put_contents($path.'/slice.yaml', json_encode($data));

        return new SliceManifest($path.'/slice.yaml');
    }

    private function managerWith(SliceManifest ...$manifests): SliceManager
    {
        $manager = new SliceManager($this->app instanceof Application ? $this->app : app());
        $register = new ReflectionMethod($manager, 'registerManifest');
        $register->setAccessible(true);
        foreach ($manifests as $manifest) {
            $register->invoke($manager, $manifest);
        }

        return $manager;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
