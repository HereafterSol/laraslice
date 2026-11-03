<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Http\Request;
use InvalidArgumentException;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Support\BlueprintLibrary;
use LaraSlice\Support\DeclarationHistory;
use LaraSlice\Tests\TestCase;

/**
 * The team blueprint library: named blueprints as files
 * under porting/blueprints/, shared through Git. Class
 * behaviour is tested against a temp root (never the real
 * app); the controller uses the default root, so its tests
 * write into the test app's base path and clean up after
 * themselves in tearDown.
 */
final class BlueprintLibraryTest extends TestCase
{
    private const YAML_SINGLE = <<<'YAML'
        name: Appointments
        handle: appointments
        domain: Clinic
        models:
          - name: Appointment
            fields:
              - name: title
                type: string
          - name: Room
            fields:
              - name: label
                type: string
        YAML;

    private const YAML_SUITE = <<<'YAML'
        name: Clinic Suite
        domain: Clinic
        slices:
          - name: Appointments
          - name: Rooms
          - name: Billing
        YAML;

    private string $root;

    /** @var list<string> slugs the controller tests created under base_path() */
    private array $controllerSlugs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-library-'.uniqid();
    }

    protected function tearDown(): void
    {
        // Controller tests: remove what they saved into the
        // test app's base path, then the dirs — rmdir only
        // succeeds when empty, so nothing pre-existing can
        // be lost here.
        $dir = base_path('porting/blueprints');
        foreach ($this->controllerSlugs as $slug) {
            @unlink($dir.'/'.$slug.'.blueprint.yaml');
        }
        @rmdir($dir);
        @rmdir(base_path('porting'));

        if (is_dir($this->root)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function library(): BlueprintLibrary
    {
        return new BlueprintLibrary($this->root);
    }

    public function test_save_all_get_round_trip(): void
    {
        $library = $this->library();
        $this->assertSame([], $library->all(), 'an untouched library is empty, not an error');

        $entry = $library->save('Appointments', self::YAML_SINGLE);
        $this->assertSame('appointments', $entry['slug']);
        $this->assertSame('Appointments', $entry['name'], 'the display name comes from the YAML itself');
        $this->assertSame(2, $entry['slices'], 'a single blueprint counts its models');
        $this->assertGreaterThan(0, $entry['size']);
        $this->assertNotEmpty($entry['updated_at']);

        $library->save('Clinic Suite', self::YAML_SUITE);
        $all = $library->all();
        $this->assertCount(2, $all);
        $this->assertSame('Appointments', $all[0]['name'], 'entries sort by name');
        $this->assertSame('Clinic Suite', $all[1]['name']);
        $this->assertSame(3, $all[1]['slices'], 'a suite counts its slices');

        $got = $library->get('appointments');
        $this->assertNotNull($got);
        $this->assertSame('Appointments', $got['name']);
        $this->assertSame(self::YAML_SINGLE, $got['yaml'], 'the stored YAML comes back verbatim');
        $this->assertNull($library->get('no-such-blueprint'));
    }

    public function test_invalid_or_shapeless_yaml_is_refused(): void
    {
        $library = $this->library();

        try {
            $library->save('Broken', "name: [unclosed\n");
            $this->fail('unparseable YAML must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('does not parse', $e->getMessage());
        }

        try {
            $library->save('Shapeless', "foo: bar\n");
            $this->fail('a document declaring nothing must be refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('must declare', $e->getMessage());
        }

        $this->assertSame([], $library->all(), 'a refused save writes nothing');
    }

    public function test_slugs_cannot_escape_the_library_dir(): void
    {
        $library = $this->library();

        $this->assertNull($library->get('../secret'));
        $this->assertNull($library->get('a/b'));
        $this->assertNull($library->get('foo.bar'));
        $this->assertNull($library->get('..'));
        $this->assertFalse($library->delete('../../x'));

        // A hostile NAME is fine, though: it slugifies into
        // the library and nowhere else.
        $entry = $library->save('../../Evil Plan', self::YAML_SINGLE);
        $this->assertSame('evil-plan', $entry['slug']);
        $this->assertFileExists($library->dir().'/evil-plan.blueprint.yaml');
        $this->assertFileDoesNotExist($this->root.'/evil-plan.blueprint.yaml');
    }

    public function test_delete_removes_the_file(): void
    {
        $library = $this->library();
        $library->save('Appointments', self::YAML_SINGLE);

        $this->assertTrue($library->delete('appointments'));
        $this->assertNull($library->get('appointments'));
        $this->assertSame([], $library->all());
        $this->assertFalse($library->delete('appointments'), 'deleting twice is a plain false, not an error');
    }

    public function test_overwrite_updates_the_same_slug(): void
    {
        $library = $this->library();
        $library->save('Appointments', self::YAML_SINGLE);

        $v2 = <<<'YAML'
            name: Appointments
            handle: appointments
            domain: Clinic
            models:
              - name: Appointment
                fields:
                  - name: title
                    type: string
              - name: Room
                fields:
                  - name: label
                    type: string
              - name: Invoice
                fields:
                  - name: total
                    type: decimal
            YAML;
        $entry = $library->save('Appointments', $v2);

        $this->assertSame('appointments', $entry['slug']);
        $this->assertSame(3, $entry['slices'], 'the update replaces the model list');
        $this->assertCount(1, $library->all(), 'an overwrite is one entry, not two');
        $this->assertSame($v2, $library->get('appointments')['yaml']);
    }

    public function test_declaration_history_tracks_library_blueprints(): void
    {
        $this->library()->save('Appointments', self::YAML_SINGLE);

        $entry = (new DeclarationHistory($this->root))->record('test');
        $this->assertNotNull($entry, 'a new library blueprint is a declaration change');
        $paths = [];
        foreach ($entry['changes'] as $change) {
            $paths[$change['path']] = $change['kind'];
        }
        $this->assertSame('added', $paths['porting/blueprints/appointments.blueprint.yaml'] ?? null);
    }

    public function test_controller_round_trip(): void
    {
        $controller = new BlueprintStudioController;
        $this->controllerSlugs[] = 'appointments';

        $stored = $controller->blueprintLibraryStore(Request::create('/laraslice/studio/blueprint/library', 'POST', [
            'name' => 'Appointments',
            'yaml' => self::YAML_SINGLE,
        ]));
        $this->assertSame(200, $stored->getStatusCode());
        $entry = json_decode($stored->getContent(), true);
        $this->assertSame('appointments', $entry['slug']);

        $index = json_decode($controller->blueprintLibraryIndex()->getContent(), true);
        $this->assertContains('appointments', array_column($index['blueprints'], 'slug'));

        $shown = json_decode($controller->blueprintLibraryShow('appointments')->getContent(), true);
        $this->assertSame(self::YAML_SINGLE, $shown['yaml']);

        $destroyed = $controller->blueprintLibraryDestroy('appointments');
        $this->assertSame(200, $destroyed->getStatusCode());
        $this->assertTrue(json_decode($destroyed->getContent(), true)['deleted']);

        $this->assertSame(404, $controller->blueprintLibraryShow('appointments')->getStatusCode());
        $this->assertSame(404, $controller->blueprintLibraryDestroy('appointments')->getStatusCode());
    }

    public function test_controller_store_refuses_invalid_yaml_with_422(): void
    {
        $controller = new BlueprintStudioController;

        $response = $controller->blueprintLibraryStore(Request::create('/laraslice/studio/blueprint/library', 'POST', [
            'name' => 'Broken',
            'yaml' => "name: [unclosed\n",
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotEmpty(json_decode($response->getContent(), true)['message']);
        $index = json_decode($controller->blueprintLibraryIndex()->getContent(), true);
        $this->assertNotContains('broken', array_column($index['blueprints'], 'slug'));
    }
}
