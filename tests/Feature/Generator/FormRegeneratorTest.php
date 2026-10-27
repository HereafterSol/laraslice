<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\FormRegenerator;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;

/**
 * Form regeneration (foundation backlog E1, 24 Oct 2026):
 * an existing slice's form views are rebuilt from its
 * Heart via staging-generate + transplant. The diff must
 * name surfaces the Heart cannot re-derive (hand-added
 * inputs, options variables) before anything is written,
 * and apply() must back the originals up.
 */
final class FormRegeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-regen-'.uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
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

    public function test_regen_reports_losses_and_restores_the_generated_form(): void
    {
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('RegenWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'starts_on', 'type' => 'date', 'nullable' => true],
        ]);
        $manifest = new SliceManifest($sliceDir.'/slice.yaml');
        $formPath = $sliceDir.'/Resources/views/form.blade.php';
        $this->assertFileExists($formPath);

        // A hand edit the Heart cannot re-derive.
        file_put_contents(
            $formPath,
            file_get_contents($formPath)."\n<input name=\"hand_added\">\n<!-- {{ \$legacyOptions ?? [] }} -->\n"
        );

        $regenerator = new FormRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged['files']);
            $form = collect($report)->firstWhere('view', 'form.blade.php');
            $this->assertSame('changed', $form['status']);
            $this->assertContains('input:hand_added', $form['lost']);
            $this->assertContains('options:$legacyOptions', $form['lost']);

            $backupDir = $regenerator->apply($manifest, $staged['files'], $this->root.'/backups');
            $this->assertFileExists($backupDir.'/form.blade.php');
            $this->assertStringContainsString('hand_added', (string) file_get_contents($backupDir.'/form.blade.php'));
            $this->assertStringNotContainsString('hand_added', (string) file_get_contents($formPath));

            $after = $regenerator->diff($manifest, $staged['files']);
            $this->assertSame('identical', collect($after)->firstWhere('view', 'form.blade.php')['status']);
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }
    }
}
