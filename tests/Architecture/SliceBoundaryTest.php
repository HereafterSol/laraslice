<?php

namespace LaraSlice\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Architecture Boundary Test
 *
 * Enforces strict bounded context isolation between Slices.
 * Slices must NEVER directly import another Slice's internal Models or private classes.
 * Cross-slice communication is only allowed via Contracts (DTOs) or public Services.
 */
class SliceBoundaryTest extends TestCase
{
    public function test_slices_do_not_import_internal_models_of_other_slices(): void
    {
        $slicesDir = realpath(__DIR__.'/../../src/Slices');
        if (! $slicesDir || ! is_dir($slicesDir)) {
            $this->markTestSkipped('Slices directory not found for boundary testing.');
        }

        $sliceNames = array_map('basename', glob($slicesDir.'/*', GLOB_ONLYDIR) ?: []);
        $violations = [];

        foreach ($sliceNames as $sliceName) {
            $slicePath = $slicesDir.'/'.$sliceName;
            $phpFiles = $this->getPhpFiles($slicePath);

            foreach ($phpFiles as $file) {
                $content = file_get_contents($file);
                $lines = explode("\n", $content);

                foreach ($lines as $lineNum => $line) {
                    $trimmed = trim($line);
                    if (! str_starts_with($trimmed, 'use ')) {
                        continue;
                    }

                    foreach ($sliceNames as $otherSlice) {
                        if ($otherSlice === $sliceName) {
                            continue;
                        }

                        // Users, Roles, and Auth form the core authentication cluster
                        $authCluster = ['Users', 'Roles', 'Auth'];
                        if (in_array($sliceName, $authCluster, true) && in_array($otherSlice, $authCluster, true)) {
                            continue;
                        }

                        // Check for forbidden import of other slice's internal Models
                        // e.g. use LaraSlice\Slices\Products\Models\Product;
                        if (preg_match('/use\s+[\\\\A-Za-z0-9_]*Slices\\\\'.preg_quote($otherSlice, '/').'\\\\Models\\\\/i', $trimmed)) {
                            $relPath = str_replace(realpath($slicesDir).DIRECTORY_SEPARATOR, '', $file);
                            $violations[] = "[Boundary Violation] {$relPath}:".($lineNum + 1)." imports internal Model from '{$otherSlice}': {$trimmed}";
                        }
                    }
                }
            }
        }

        $this->assertEmpty($violations, "Cross-slice boundary violations detected:\n".implode("\n", $violations)."\n\nUse Contracts (DTOs) or public Services instead.");
    }

    protected function getPhpFiles(string $dir): array
    {
        $files = [];
        $items = glob($dir.'/*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $files = array_merge($files, $this->getPhpFiles($item));
            } elseif (str_ends_with($item, '.php')) {
                $files[] = $item;
            }
        }

        return $files;
    }
}
