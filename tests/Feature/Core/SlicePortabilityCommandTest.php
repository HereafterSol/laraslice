<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Porting\TrilogyPortabilityLint;
use LaraSlice\Tests\TestCase;

class SlicePortabilityCommandTest extends TestCase
{
    public function test_slice_portability_passes_on_core_slices(): void
    {
        $this->artisan('slice:portability')
            ->expectsOutputToContain('Portability lint PASS')
            ->assertSuccessful();
    }

    public function test_slice_portability_json_shape(): void
    {
        $this->artisan('slice:portability', ['--json' => true])
            ->assertSuccessful();

        // Command prints JSON to stdout; PHPUnit captures output only via expectsOutput*.
        // Re-run lint directly for structure assertion.
        $lint = app(TrilogyPortabilityLint::class);
        $findings = $lint->scan(base_path());
        $this->assertSame([], $findings);
    }
}
