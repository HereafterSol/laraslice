<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Support\Facades\Artisan;
use LaraSlice\Tests\TestCase;

/**
 * A conformance gate with zero recorded pages must report
 * NOT MEASURED — never a fabricated 100%. Regression pin for
 * the post-port audit (2 Nov 2026): on a real ported app the
 * gate printed pages=0, percent=100, exit=0.
 */
class SliceConformanceHonestyTest extends TestCase
{
    public function test_no_specs_means_not_measured_never_100_percent(): void
    {
        // The test app ships no porting/ui-spec anatomy specs.
        Artisan::call('slice:conformance', ['--format' => 'json']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame(0, $payload['pages']);
        $this->assertNull($payload['percent']);
        $this->assertFalse($payload['measured']);
        $this->assertStringContainsString('NOT MEASURED', $payload['note'] ?? '');
    }
}
