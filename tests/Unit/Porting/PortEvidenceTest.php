<?php

namespace LaraSlice\Tests\Unit\Porting;

use LaraSlice\Porting\PortEvidence;
use PHPUnit\Framework\TestCase;

/**
 * Port evidence loading: the conformance gate's second
 * measurement source. Malformed files are excluded WITH
 * an error — never silently counted either way.
 */
class PortEvidenceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ls-evidence-'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function write(string $name, array $data): void
    {
        file_put_contents($this->dir.'/'.$name, json_encode($data));
    }

    public function test_missing_directory_is_an_empty_summary(): void
    {
        $summary = PortEvidence::load($this->dir.'/nope');
        $this->assertSame(0, $summary['checks']);
        $this->assertNull($summary['percent']);
        $this->assertSame([], $summary['errors']);
    }

    public function test_valid_suites_summarize_with_failed_ids(): void
    {
        $this->write('gate-t.json', [
            'suite' => 'Gate T',
            'recorded_at' => '2026-11-02T17:59:00+05:00',
            'checks' => [
                ['id' => 'A1', 'label' => 'login', 'status' => 'pass'],
                ['id' => 'A2', 'label' => 'edit', 'status' => 'fail', 'detail' => '500'],
                ['id' => 'A3', 'label' => 'list', 'status' => 'pass'],
            ],
        ]);
        $summary = PortEvidence::load($this->dir);
        $this->assertSame(3, $summary['checks']);
        $this->assertSame(2, $summary['passed']);
        $this->assertSame(1, $summary['failed_count']);
        $this->assertSame(66.7, $summary['percent']);
        $this->assertSame('A2', $summary['suites'][0]['failed'][0]['id']);
        $this->assertSame([], $summary['errors']);
    }

    public function test_malformed_files_are_excluded_with_errors(): void
    {
        file_put_contents($this->dir.'/broken.json', '{not json');
        $this->write('no-suite.json', ['checks' => [['id' => 'X', 'status' => 'pass']]]);
        $this->write('dup.json', [
            'suite' => 'Dup',
            'checks' => [
                ['id' => 'A1', 'status' => 'pass'],
                ['id' => 'A1', 'status' => 'pass'],
            ],
        ]);
        $this->write('good.json', [
            'suite' => 'Good',
            'checks' => [['id' => 'G1', 'status' => 'pass']],
        ]);
        $summary = PortEvidence::load($this->dir);
        $this->assertSame(1, $summary['checks'], 'only the valid suite counts');
        $this->assertCount(3, $summary['errors']);
    }
}
