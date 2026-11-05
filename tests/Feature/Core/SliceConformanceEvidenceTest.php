<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use LaraSlice\Tests\TestCase;

/**
 * Conformance wired to port evidence (2 Nov 2026): a port
 * with recorded journey evidence but no anatomy specs is
 * MEASURED by its evidence — and a failing recorded check
 * fails the gate. Companion to SliceConformanceHonestyTest
 * (no evidence of any kind stays NOT MEASURED).
 */
class SliceConformanceEvidenceTest extends TestCase
{
    private string $evidenceDir;

    private bool $createdPorting = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createdPorting = ! is_dir(base_path('porting'));
        $this->evidenceDir = base_path('porting/evidence');
        if (! is_dir($this->evidenceDir)) {
            mkdir($this->evidenceDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->evidenceDir.'/*.json') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->evidenceDir)) {
            rmdir($this->evidenceDir);
        }
        if ($this->createdPorting && is_dir(base_path('porting'))) {
            rmdir(base_path('porting'));
        }
        parent::tearDown();
    }

    private function writeEvidence(array $checks, string $file = 'gate-t.json'): void
    {
        file_put_contents($this->evidenceDir.'/'.$file, json_encode([
            'suite' => 'Gate T',
            'recorded_at' => '2026-11-02T17:59:00+05:00',
            'checks' => $checks,
        ]));
    }

    public function test_all_passing_evidence_measures_the_port(): void
    {
        $this->writeEvidence([
            ['id' => 'A1', 'label' => 'login', 'status' => 'pass'],
            ['id' => 'A2', 'label' => 'edit', 'status' => 'pass'],
        ]);
        $code = Artisan::call('slice:conformance', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $code);
        $this->assertTrue($payload['measured']);
        $this->assertSame('evidence', $payload['measured_by']);
        $this->assertEquals(100.0, $payload['percent']);
        $this->assertSame(2, $payload['evidence']['checks']);
    }

    public function test_a_failing_recorded_check_fails_the_gate(): void
    {
        $this->writeEvidence([
            ['id' => 'A1', 'label' => 'login', 'status' => 'pass'],
            ['id' => 'A2', 'label' => 'edit', 'status' => 'pass'],
            ['id' => 'A3', 'label' => 'delete', 'status' => 'fail', 'detail' => 'dialog missing'],
        ]);
        $code = Artisan::call('slice:conformance', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $code);
        $this->assertEquals(66.7, $payload['percent']);
        $this->assertSame(['A3'], $payload['evidence']['suites'][0]['failed']);
    }

    public function test_a_malformed_evidence_file_fails_the_gate(): void
    {
        $this->writeEvidence([['id' => 'A1', 'label' => 'login', 'status' => 'pass']]);
        file_put_contents($this->evidenceDir.'/broken.json', '{not json');
        $code = Artisan::call('slice:conformance', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $code);
        $this->assertNotSame([], $payload['evidence']['errors']);
    }
}
