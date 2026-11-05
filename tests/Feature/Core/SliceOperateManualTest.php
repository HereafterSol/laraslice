<?php

namespace LaraSlice\Tests\Feature\Core;

use LaraSlice\Tests\TestCase;

/**
 * CLI parity for the Studio's Operate mode: the
 * "Generate manual" button had no terminal twin, so an
 * AI agent or a cron job could not produce the operator
 * manual. slice:operate manual closes that gap over the
 * same SystemOperate composition.
 */
class SliceOperateManualTest extends TestCase
{
    public function test_manual_action_writes_the_operator_manual(): void
    {
        $path = sys_get_temp_dir().'/laraslice-manual-'.uniqid().'.md';
        try {
            $this->artisan('slice:operate', ['action' => 'manual', '--output' => $path])
                ->assertExitCode(0);
            $this->assertFileExists($path);
            $this->assertStringContainsString('Operator Manual', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }
}
