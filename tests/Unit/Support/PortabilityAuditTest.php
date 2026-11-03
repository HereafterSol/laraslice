<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\PortabilityAudit;
use LaraSlice\Support\SystemOperate;
use LaraSlice\Tests\TestCase;

/**
 * The runtime-portability audit: declarations (Mind,
 * Bodies, Hearts) must read the same on any runtime.
 * Planted Laravel/PHP leakage is found with its file,
 * line and a plain-words fix; neutral declarations pass
 * clean; an app with no declarations reports honestly.
 */
class PortabilityAuditTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
        $this->roots = [];
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files  relative path => contents
     */
    private function makeRoot(array $files): string
    {
        $root = sys_get_temp_dir().'/ls-portability-'.uniqid();
        mkdir($root, 0777, true);
        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            mkdir(dirname($path), 0777, true);
            file_put_contents($path, $contents);
        }
        $this->roots[] = $root;

        return $root;
    }

    public function test_neutral_declarations_pass_clean(): void
    {
        $root = $this->makeRoot([
            'porting/system.yaml' => <<<'YAML'
                system:
                  name: Workshop Ops
                  purpose: Run the workshop floor.
                actors:
                  - name: Manager
                    kind: internal
                journeys:
                  - name: Order to delivery
                    steps: [Receive, Pack, Ship]
                YAML,
            'porting/ui-spec/widgets.ui.yaml' => <<<'YAML'
                slice: Widgets
                pages:
                  - name: Widget list
                    shows: A table of widgets with their titles.
                YAML,
            'app/Slices/ERP/Widgets/slice.yaml' => <<<'YAML'
                name: Widgets
                model: Widget
                fields:
                  - name: title
                    type: string
                YAML,
        ]);

        $audit = PortabilityAudit::run($root);

        $this->assertTrue($audit['available']);
        $this->assertSame(3, $audit['files_scanned']);
        $this->assertSame([], $audit['findings']);
        $this->assertTrue($audit['clean']);
    }

    public function test_planted_leakage_is_found_with_file_line_and_term(): void
    {
        $root = $this->makeRoot([
            'porting/system.yaml' => <<<'YAML'
                system:
                  name: Workshop Ops
                  home: route('dashboard')
                YAML,
            'porting/ui-spec/widgets.ui.yaml' => <<<'YAML'
                slice: Widgets
                pages:
                  - name: Widget list
                    template: widgets/index.blade.php
                YAML,
            'app/Slices/ERP/Widgets/slice.yaml' => <<<'YAML'
                name: Widgets
                model: Widget
                owner_class: App\Models\User
                YAML,
        ]);

        $audit = PortabilityAudit::run($root);

        $this->assertTrue($audit['available']);
        $this->assertFalse($audit['clean']);
        $this->assertCount(3, $audit['findings']);

        $byFile = [];
        foreach ($audit['findings'] as $finding) {
            $byFile[$finding['file']] = $finding;
        }

        $heart = $byFile['app/Slices/ERP/Widgets/slice.yaml'];
        $this->assertSame(3, $heart['line']);
        $this->assertSame('App\Models\User', $heart['term']);
        $this->assertSame('name the concept, not the PHP class', $heart['suggestion']);

        $body = $byFile['porting/ui-spec/widgets.ui.yaml'];
        $this->assertSame(4, $body['line']);
        $this->assertSame('.blade.php', $body['term']);
        $this->assertSame('reference the page, not the template file', $body['suggestion']);

        $mind = $byFile['porting/system.yaml'];
        $this->assertSame(3, $mind['line']);
        $this->assertSame('route', $mind['term']);
        $this->assertSame('declare the value or the intent, not the framework call', $mind['suggestion']);

        $this->assertSame(1, $audit['by_term']['App\Models\User']);
        $this->assertSame(1, $audit['by_term']['.blade.php']);
        $this->assertSame(1, $audit['by_term']['route']);
    }

    public function test_heart_namespace_lines_are_bindings_not_leakage(): void
    {
        // A Heart's namespace: line is its declared home in
        // THIS runtime — the binding between the neutral
        // declaration and this runtime's code. It is counted
        // as a binding, never as leakage; a class named on
        // any OTHER line of the same file still flags.
        $root = $this->makeRoot([
            'app/Slices/ERP/Widgets/slice.yaml' => <<<'YAML'
                name: Widgets
                model: Widget
                namespace: App\Slices\ERP\Widgets
                YAML,
        ]);

        $audit = PortabilityAudit::run($root);

        $this->assertTrue($audit['available']);
        $this->assertSame([], $audit['findings']);
        $this->assertSame(1, $audit['bindings']);
        $this->assertTrue($audit['clean']);
    }

    public function test_an_app_without_declarations_reports_unavailable(): void
    {
        $root = $this->makeRoot([]);

        $audit = PortabilityAudit::run($root);

        $this->assertFalse($audit['available']);
        $this->assertSame('No declarations tracked yet.', $audit['reason']);
    }

    public function test_operate_quality_carries_the_portability_block(): void
    {
        $quality = SystemOperate::quality();

        $this->assertArrayHasKey('portability', $quality);
        $this->assertIsArray($quality['portability']);
        $this->assertArrayHasKey('available', $quality['portability']);
        $this->assertIsBool($quality['portability']['available']);
    }
}
