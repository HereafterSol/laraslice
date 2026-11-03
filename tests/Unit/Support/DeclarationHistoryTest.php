<?php

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\DeclarationHistory;
use PHPUnit\Framework\TestCase;

/**
 * Declaration history: the ledger over Mind/Body/Heart.
 * Baseline → change → diff must round-trip from plain
 * files, and a corrupt index must refuse loudly rather
 * than silently re-baselining the system's definition.
 */
class DeclarationHistoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ls-history-'.uniqid();
        mkdir($this->root.'/porting/ui-spec', 0777, true);
        mkdir($this->root.'/app/Slices/ERP/Orders', 0777, true);
        file_put_contents($this->root.'/porting/system.yaml', "name: Test\nactors: []\n");
        file_put_contents($this->root.'/porting/ui-spec/orders.ui.yaml', "slice: Orders\npages: []\n");
        file_put_contents($this->root.'/app/Slices/ERP/Orders/slice.yaml', "name: Orders\nversion: 1.0.0\n");
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function test_baseline_then_change_then_diff(): void
    {
        $history = new DeclarationHistory($this->root);

        $baseline = $history->record('test');
        $this->assertNotNull($baseline);
        $this->assertCount(3, $baseline['changes']);
        $this->assertSame(['added'], array_values(array_unique(array_column($baseline['changes'], 'kind'))));

        // Nothing changed: no new entry.
        $this->assertNull($history->record('test'));

        // One modification, one removal.
        file_put_contents($this->root.'/app/Slices/ERP/Orders/slice.yaml', "name: Orders\nversion: 1.1.0\n");
        unlink($this->root.'/porting/ui-spec/orders.ui.yaml');
        $second = $history->record('test');
        $this->assertNotNull($second);
        $kinds = [];
        foreach ($second['changes'] as $change) {
            $kinds[$change['path']] = $change['kind'];
        }
        $this->assertSame('modified', $kinds['app/Slices/ERP/Orders/slice.yaml']);
        $this->assertSame('removed', $kinds['porting/ui-spec/orders.ui.yaml']);

        // Latest first.
        $entries = $history->entries();
        $this->assertSame($second['id'], $entries[0]['id']);
        $this->assertSame($baseline['id'], $entries[1]['id']);

        // The diff names the actual line change.
        $diffs = $history->diff($second['id']);
        $this->assertStringContainsString('-version: 1.0.0', $diffs['app/Slices/ERP/Orders/slice.yaml']);
        $this->assertStringContainsString('+version: 1.1.0', $diffs['app/Slices/ERP/Orders/slice.yaml']);

        // Unknown entry diffs to nothing.
        $this->assertSame([], $history->diff('nope'));
    }

    public function test_the_person_is_recorded_alongside_the_tool(): void
    {
        $history = new DeclarationHistory($this->root);

        $entry = $history->record('slice:cache', 'Abdur Rehman <a@example.com>');
        $this->assertNotNull($entry);
        $this->assertSame('slice:cache', $entry['actor'], 'the tool keeps its own field');
        $this->assertSame('Abdur Rehman <a@example.com>', $entry['by'], 'the person is recorded with the entry');

        // And it persists: a fresh reader sees the same entry whole.
        $entries = (new DeclarationHistory($this->root))->entries();
        $this->assertSame('Abdur Rehman <a@example.com>', $entries[0]['by']);
    }

    public function test_an_entry_without_a_person_reads_as_null_not_missing(): void
    {
        $history = new DeclarationHistory($this->root);

        $entry = $history->record('slice:cache');
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('by', $entry);
        $this->assertNull($entry['by'], 'an unknown person is an honest null — displays fall back to the tool');

        // currentPerson never invents: with no signed-in user it
        // is the OS account or null, never an empty string.
        $person = DeclarationHistory::currentPerson();
        $this->assertTrue($person === null || $person !== '');
    }

    public function test_corrupt_index_refuses_loudly(): void
    {
        $history = new DeclarationHistory($this->root);
        $history->record('test');
        file_put_contents($this->root.'/porting/history/index.json', '{corrupt');
        $this->expectException(\RuntimeException::class);
        $history->record('test');
    }
}
