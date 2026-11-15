<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Blueprint;

use Illuminate\Support\Facades\Artisan;
use LaraSlice\Blueprint\BlueprintLoader;
use LaraSlice\Blueprint\BlueprintPlanner;
use LaraSlice\Blueprint\BlueprintValidator;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * F1 (Stage A cold port, Stage C-6 fix): running
 * slice:blueprint:apply with stdin as a PIPE (any AI
 * runner, CI job, or background exec) blocked forever at
 * the "Generate the reviewed files?" confirm — files
 * written, migrations pending, process asleep on read.
 * The console's isInteractive flag did not prevent it.
 *
 * Pinned behaviour: in a non-interactive session the
 * command refuses fast with the remedy (--yes) instead of
 * prompting; the migrate confirm falls back to its
 * default through safeConfirm rather than reading stdin.
 */
final class BlueprintApplyNonInteractiveTest extends TestCase
{
    private string $tmp;

    private string $slicesPath;

    private string $blueprintPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/ls-f1-'.uniqid();
        $this->slicesPath = $this->tmp.'/Slices';
        mkdir($this->slicesPath, 0777, true);
        config()->set('laraslice.slices_path', $this->slicesPath);

        $this->blueprintPath = $this->tmp.'/probe.slice.yaml';
        file_put_contents($this->blueprintPath, <<<'YAML'
schema_version: 1
name: 'Probe Gadget'
handle: probe_gadget
description: 'F1 non-interactive probe slice.'
models:
  -
    handle: probe_gadget
    table: probe_gadgets
    root: true
    fields:
      -
        handle: label
        label: Label
        type: string
        required: true
YAML);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmp);
        parent::tearDown();
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

    public function test_apply_without_yes_in_a_non_interactive_session_refuses_fast(): void
    {
        $blueprint = (new BlueprintValidator)->validate((new BlueprintLoader)->load($this->blueprintPath));
        $plan = (new BlueprintPlanner)->plan($blueprint, $this->slicesPath);

        $buffer = new BufferedOutput;
        $exit = Artisan::call('slice:blueprint:apply', [
            'path' => $this->blueprintPath,
            '--plan-hash' => $plan['plan_hash'],
            '--no-interaction' => true,
        ], $buffer);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Non-interactive session', $buffer->fetch());
        $this->assertDirectoryDoesNotExist($this->slicesPath.'/ProbeGadgets');
    }
}
